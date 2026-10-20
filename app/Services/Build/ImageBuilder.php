<?php

namespace App\Services\Build;

use App\Models\Build;
use App\Models\Service;
use App\Models\Setting;
use App\Models\Stack;
use App\Models\Task;
use App\Models\User;
use App\Services\Guide\SetupGuide;
use App\Services\Remote\CommandRunner;
use App\Services\Remote\RemoteFiles;
use App\Services\Stack\EnvResolver;
use App\Support\Shell;
use App\Targets\LocalTarget;
use Throwable;

/**
 * Builds one image, on the control plane, and pushes it to the registry.
 *
 * Building centrally rather than on each client server is the point: it is
 * what lets every stack pull the identical digest, and it keeps our source
 * code and a credential that can read our private repos off machines we do
 * not fully control.
 *
 * A build only builds (decision 18). Nothing is deployed until someone
 * clicks to update a client's testing stack.
 */
class ImageBuilder
{
    public function __construct(
        private readonly CommandRunner $runner,
        private readonly MigrationClassifier $classifier,
        private readonly EnvResolver $env,
    ) {}

    /**
     * Record a build and its task. The caller queues BuildJob for it.
     *
     * @param  string|null  $commitSha  known for a webhook push; null for
     *                                  "Build now", which builds the branch head
     */
    public function create(
        Service $service,
        string $branch,
        ?string $commitSha = null,
        ?Stack $stack = null,
        string $trigger = Build::TRIGGER_MANUAL,
        ?User $user = null,
    ): Build {
        $build = Build::create([
            'service_id' => $service->id,
            'edition' => Build::editionFor($branch),
            'stack_id' => $stack?->id,
            'branch' => $branch,
            'commit_sha' => $commitSha,
            'status' => 'pending',
            'trigger' => $trigger,
            'user_id' => $user?->id,
        ]);

        $title = "Build {$service->name} from {$branch}".($stack ? " for stack {$stack->id}" : '');
        $task = Task::begin(Task::TYPE_BUILD, $title, $build, $user);

        $build->update(['task_id' => $task->id]);

        return $build;
    }

    /**
     * Fetch, classify, build and push. Every step is written to the task.
     */
    public function run(Build $build, Task $task): Build
    {
        $build->loadMissing('service', 'stack');
        $service = $build->service;
        $workspace = $this->workspacePath($service);
        $local = new LocalTarget;

        $task->start();
        $task->mask(
            Setting::get(Setting::GITHUB_TOKEN),
            Setting::get(Setting::REGISTRY_PUSH_TOKEN),
            ...array_values($this->gitAuthEnv()),
        );
        $build->update(['status' => 'running']);

        try {
            $task->step("Fetching {$service->repo_full_name}");

            if (! $this->step($build, $task, 'fetch', $this->fetchScript($service, $workspace), $this->gitAuthEnv())) {
                return $build;
            }

            $sha = $build->commit_sha ?: $this->headOf($workspace, $build->branch);

            if ($sha === null) {
                return $this->fail($build, $task, "fetch: branch {$build->branch} does not exist.");
            }

            if (! $this->step($build, $task, 'checkout', $this->checkoutScript($workspace, $sha))) {
                return $build;
            }

            $this->describe($build, $sha);
            $task->log("\n{$build->label()} · {$build->migration_kind} · ".count($build->changelog ?? [])." commits\n");

            foreach ($build->changelog ?? [] as $commit) {
                $task->log("  {$commit['sha']}  {$commit['subject']}  ({$commit['author']})\n");
            }

            foreach ($build->migration_reasons ?? [] as $reason) {
                $task->log("  migration: {$reason}\n");
            }

            $task->step('Preparing the build context');

            if (! $this->step($build, $task, 'context', $this->contextScript($build, $workspace))) {
                return $build;
            }

            $reference = $service->imageRepository().':'.$build->image_tag;

            $task->step("Building {$reference}");

            $steps = [
                'login' => [$this->loginScript(), $this->registryEnv()],
                'build' => [$this->buildScript($build, $workspace, $reference), ['DOCKER_BUILDKIT' => '1']],
                'push' => ['docker push '.Shell::quote($reference), []],
            ];

            foreach ($steps as $name => [$script, $env]) {
                if (! $this->step($build, $task, $name, $script, $env)) {
                    return $build;
                }
            }

            $digest = $this->readDigest($reference);

            if ($digest === null) {
                return $this->fail($build, $task, 'Pushed, but no digest could be read back.');
            }

            $build->update(['status' => 'success', 'digest' => $digest, 'built_at' => now()]);
            SetupGuide::forget();
            $task->succeed("{$build->label()} is ready to deploy ({$digest}).");

            return $build;
        } catch (Throwable $e) {
            report($e);

            return $this->fail($build, $task, 'Unexpected error: '.$e->getMessage());
        }
    }

    /**
     * The image tag.
     *
     * Never contains a client's name. An edition is named for an operating
     * model, and a per-stack build is distinguished by the stack's id.
     */
    public function tagFor(Service $service, string $commitSha, ?string $edition = null, ?Stack $stack = null): string
    {
        $tag = substr($commitSha, 0, 7);

        if (filled($edition)) {
            $tag .= '-'.$edition;
        }

        if ($stack !== null) {
            $tag .= '-s'.$stack->id;
        }

        return $tag;
    }

    /**
     * Migration files added or changed between two commits.
     *
     * Against the last successful build rather than the parent commit, so a
     * release covering several merges still sees every migration it will run.
     *
     * @return array<string, string>
     */
    public function migrationsBetween(Service $service, ?string $fromSha, string $toSha): array
    {
        $git = 'git -C '.Shell::quote($this->workspacePath($service));

        $listed = $this->runner->run(
            new LocalTarget,
            $git.' diff --name-only --diff-filter=AM '.$this->range($fromSha, $toSha)." -- 'database/migrations/*'",
            timeout: 60,
        );

        if ($listed->failed()) {
            return [];
        }

        $migrations = [];

        foreach (array_filter(array_map('trim', explode("\n", $listed->stdout))) as $path) {
            $shown = $this->runner->run(new LocalTarget, $git.' show '.Shell::quote($toSha.':'.$path), timeout: 60);

            if ($shown->successful()) {
                $migrations[$path] = $shown->stdout;
            }
        }

        return $migrations;
    }

    public function workspacePath(Service $service): string
    {
        return storage_path('app/private/workspaces/'.$service->slug);
    }

    /** The recipe folder used when the repository has no Dockerfile. */
    public static function recipeFor(Service $service): string
    {
        return $service->build_recipe === Service::RECIPE_NEXTJS ? 'nextjs' : 'laravel';
    }

    /**
     * Number, tag, migration classification and changelog, once the commit
     * is known.
     */
    private function describe(Build $build, string $sha): void
    {
        $service = $build->service;

        $previous = Build::query()
            ->where('service_id', $service->id)
            ->where('edition', $build->edition)
            ->where('stack_id', $build->stack_id)
            ->where('status', 'success')
            ->whereKeyNot($build->id)
            ->latest('built_at')
            ->first();

        $classified = $this->classifier->classify($this->migrationsBetween($service, $previous?->commit_sha, $sha));

        $build->update([
            'commit_sha' => $sha,
            'number' => $this->numberFor($build, $sha),
            'image_tag' => $this->tagFor($service, $sha, $build->edition, $build->stack),
            'migration_kind' => $classified['kind'],
            'affected_tables' => $classified['tables'],
            'migration_reasons' => $classified['reasons'],
            'changelog' => $this->changelog($service, $previous?->commit_sha, $sha),
        ]);
    }

    /**
     * Per service and edition. Per-stack builds of one commit share a number,
     * so "Dashboard #40" means the same code on every client.
     */
    private function numberFor(Build $build, string $sha): int
    {
        $same = Build::query()
            ->where('service_id', $build->service_id)
            ->where('edition', $build->edition)
            ->where('commit_sha', $sha)
            ->whereNotNull('number')
            ->value('number');

        if ($same !== null) {
            return (int) $same;
        }

        return (int) Build::query()
            ->where('service_id', $build->service_id)
            ->where('edition', $build->edition)
            ->max('number') + 1;
    }

    /**
     * @return list<array{sha: string, author: string, subject: string}>
     */
    private function changelog(Service $service, ?string $fromSha, string $toSha): array
    {
        $result = $this->runner->run(
            new LocalTarget,
            'git -C '.Shell::quote($this->workspacePath($service))
                ." log --format='%h%x09%an%x09%s' -n 50 "
                .($fromSha === null ? Shell::quote($toSha).' -n 1' : Shell::quote($fromSha).'..'.Shell::quote($toSha)),
            timeout: 60,
        );

        if ($result->failed()) {
            return [];
        }

        return collect(explode("\n", trim($result->stdout)))
            ->filter()
            ->map(function (string $line) {
                [$sha, $author, $subject] = array_pad(explode("\t", $line, 3), 3, '');

                return ['sha' => $sha, 'author' => $author, 'subject' => $subject];
            })
            ->values()
            ->all();
    }

    private function range(?string $fromSha, string $toSha): string
    {
        // No previous build: only this commit's own migrations, or a first
        // build would classify the entire history as destructive.
        return $fromSha === null
            ? Shell::quote($toSha.'^!')
            : Shell::quote($fromSha).'..'.Shell::quote($toSha);
    }

    private function headOf(string $workspace, string $branch): ?string
    {
        $result = $this->runner->run(
            new LocalTarget,
            'git -C '.Shell::quote($workspace).' rev-parse --verify '.Shell::quote('refs/remotes/origin/'.$branch.'^{commit}'),
            timeout: 30,
        );

        $sha = trim($result->stdout);

        return $result->successful() && preg_match('/^[0-9a-f]{40}$/', $sha) ? $sha : null;
    }

    /**
     * @param  array<string, string>  $env
     */
    private function step(Build $build, Task $task, string $name, string $script, array $env = []): bool
    {
        $result = $this->runner->run(new LocalTarget, $script, env: $env, timeout: 3600, onOutput: $task->logger());

        if ($result->failed()) {
            $this->fail($build, $task, "{$name}: ".$result->failureReason());

            return false;
        }

        return true;
    }

    private function fail(Build $build, Task $task, string $reason): Build
    {
        $build->update(['status' => 'failed', 'error' => $reason]);
        $task->fail($reason);

        return $build;
    }

    private function fetchScript(Service $service, string $workspace): string
    {
        $repo = 'https://github.com/'.$service->repo_full_name.'.git';
        $git = 'git -C '.Shell::quote($workspace);

        return implode("\n", [
            'set -e',
            'mkdir -p '.Shell::quote(dirname($workspace)),
            'if [ ! -d '.Shell::quote($workspace.'/.git').' ]; then',
            '  git clone --no-checkout '.Shell::quote($repo).' '.Shell::quote($workspace),
            'fi',
            $git.' remote set-url origin '.Shell::quote($repo),
            $git.' fetch --prune --tags origin '.Shell::quote('+refs/heads/*:refs/remotes/origin/*'),
        ]);
    }

    private function checkoutScript(string $workspace, string $sha): string
    {
        $git = 'git -C '.Shell::quote($workspace);

        return implode("\n", [
            'set -e',
            // Detached checkout of the exact commit: a branch name could move
            // between classification and build.
            $git.' checkout --force '.Shell::quote($sha),
            $git.' clean -fdx',
        ]);
    }

    /**
     * The control plane's Dockerfile when the repository has none, a
     * .dockerignore when it has none, and for a per-stack build that stack's
     * build-time values.
     */
    private function contextScript(Build $build, string $workspace): string
    {
        $service = $build->service;
        $commands = ['set -e'];

        if (! $this->usesOwnDockerfile($service)) {
            $recipe = resource_path('build-recipes/'.self::recipeFor($service));

            foreach (['Dockerfile', 'entrypoint.sh', 'supervisord.conf'] as $file) {
                if (is_file($recipe.'/'.$file)) {
                    $commands[] = RemoteFiles::write($workspace.'/.control-plane/'.$file, (string) file_get_contents($recipe.'/'.$file), '644');
                }
            }

            if (is_file($recipe.'/dockerignore')) {
                $commands[] = RemoteFiles::writeIfMissing($workspace.'/.dockerignore', (string) file_get_contents($recipe.'/dockerignore'), '644');
            }
        }

        if ($build->stack_id !== null) {
            $entitlement = $build->stack->services()->where('service_id', $service->id)->first();
            $lines = [];

            foreach ($entitlement ? $this->env->buildArgs($entitlement) : [] as $key => $value) {
                $lines[] = $key.'='.str_replace("\n", '', (string) $value);
            }

            // Highest-priority env file Next.js reads at build time, and one
            // the repository should never commit.
            $commands[] = RemoteFiles::write($workspace.'/.env.production.local', implode("\n", $lines)."\n", '600');
        }

        return implode("\n", $commands);
    }

    private function usesOwnDockerfile(Service $service): bool
    {
        return filled($service->dockerfile_path);
    }

    private function buildScript(Build $build, string $workspace, string $reference): string
    {
        $service = $build->service;

        $dockerfile = $this->usesOwnDockerfile($service)
            ? $workspace.'/'.ltrim($service->dockerfile_path, '/')
            : $workspace.'/.control-plane/Dockerfile';

        return implode(' ', [
            'docker build --pull',
            '-f '.Shell::quote($dockerfile),
            '--label '.Shell::quote('org.opencontainers.image.revision='.$build->commit_sha),
            '--label '.Shell::quote('org.opencontainers.image.source=https://github.com/'.$service->repo_full_name),
            '--label '.Shell::quote('org.opencontainers.image.version='.$build->number),
            '-t '.Shell::quote($reference),
            Shell::quote($workspace),
        ]);
    }

    private function loginScript(): string
    {
        return 'printf \'%s\' "$REGISTRY_TOKEN" | docker login "$REGISTRY_HOST" -u "$REGISTRY_USER" --password-stdin';
    }

    /** @return array<string, string> */
    private function registryEnv(): array
    {
        return [
            'REGISTRY_HOST' => explode('/', (string) config('deploy.registry'), 2)[0],
            'REGISTRY_USER' => (string) Setting::get(Setting::REGISTRY_USERNAME, ''),
            'REGISTRY_TOKEN' => (string) Setting::get(Setting::REGISTRY_PUSH_TOKEN, ''),
        ];
    }

    /**
     * The GitHub token goes to git as an HTTP header through the environment,
     * so it is never written into the workspace's git config or a URL.
     *
     * @return array<string, string>
     */
    private function gitAuthEnv(): array
    {
        $token = Setting::get(Setting::GITHUB_TOKEN);

        if (blank($token)) {
            return [];
        }

        return [
            'GIT_TERMINAL_PROMPT' => '0',
            'GIT_CONFIG_COUNT' => '1',
            'GIT_CONFIG_KEY_0' => 'http.https://github.com/.extraheader',
            'GIT_CONFIG_VALUE_0' => 'AUTHORIZATION: basic '.base64_encode('x-access-token:'.$token),
        ];
    }

    private function readDigest(string $reference): ?string
    {
        $result = $this->runner->run(
            new LocalTarget,
            'docker inspect --format \'{{index .RepoDigests 0}}\' '.Shell::quote($reference),
            timeout: 60,
        );

        if ($result->failed() || ! str_contains($result->output(), '@')) {
            return null;
        }

        return trim(explode('@', $result->output(), 2)[1]);
    }
}
