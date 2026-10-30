<?php

namespace App\Services\Build;

use App\Models\Build;
use App\Models\Service;
use App\Models\ServiceFile;
use App\Models\Setting;
use App\Models\Stack;
use App\Models\Task;
use App\Models\User;
use App\Services\GitHub\AccessCheck;
use App\Services\GitHub\GitHub;
use App\Services\Guide\SetupGuide;
use App\Services\Remote\CommandRunner;
use App\Services\Remote\RemoteFiles;
use App\Services\Stack\EnvResolver;
use App\Support\Shell;
use App\Support\TokenInfo;
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
        private readonly BuildFiles $files,
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
        $build->loadMissing('service.files', 'stack');
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
            $task->log('GitHub token: '.(TokenInfo::describe(Setting::get(Setting::GITHUB_TOKEN)) ?? 'none saved')."\n");

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

            if (! $this->step($build, $task, 'login', $this->loginScript(), $this->registryEnv())) {
                return $build;
            }

            if (BuildFiles::recipeFor($service) === 'laravel' && ! $this->ensurePhpBase($build, $task, $workspace)) {
                return $build;
            }

            $steps = [
                'build' => [$this->buildScript($build, $workspace, $reference), ['DOCKER_BUILDKIT' => '1']],
                // Nothing that fails here is ever pushed.
                'test' => [$this->testScript($build, $reference), []],
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

            $size = $this->readSize($reference);
            $budget = $this->budgetMb($service);

            if ($size !== null && $size > $budget * 1024 * 1024) {
                $task->log(sprintf("\nThe image is %d MB, over the %d MB budget for this kind of service.\n", intdiv($size, 1024 * 1024), $budget));
            }

            $build->update(['status' => 'success', 'digest' => $digest, 'size_bytes' => $size, 'built_at' => now()]);
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
     * model, and a per-stack build is distinguished by the stack's id. A
     * rebuild of the same commit (attempt 2 and on) gets its own tag, so it
     * never moves a tag an earlier release recorded.
     */
    public function tagFor(Service $service, string $commitSha, ?string $edition = null, ?Stack $stack = null, int $attempt = 1): string
    {
        $tag = substr($commitSha, 0, 7);

        if (filled($edition)) {
            $tag .= '-'.$edition;
        }

        if ($stack !== null) {
            $tag .= '-s'.$stack->id;
        }

        if ($attempt > 1) {
            $tag .= '-r'.$attempt;
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
        $git = $this->gitIn($this->workspacePath($service));

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
        $attempt = $this->attemptFor($build, $sha);

        $build->update([
            'commit_sha' => $sha,
            'number' => $this->numberFor($build, $sha),
            'attempt' => $attempt,
            'image_tag' => $this->tagFor($service, $sha, $build->edition, $build->stack, $attempt),
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
     * 1 + the successful builds of this commit for the same stack and edition
     * already made: a rebuild after its build-time values changed.
     */
    private function attemptFor(Build $build, string $sha): int
    {
        return Build::query()
            ->where('service_id', $build->service_id)
            ->where('edition', $build->edition)
            ->where('stack_id', $build->stack_id)
            ->where('commit_sha', $sha)
            ->where('status', 'success')
            ->whereKeyNot($build->id)
            ->count() + 1;
    }

    /**
     * @return list<array{sha: string, author: string, subject: string}>
     */
    private function changelog(Service $service, ?string $fromSha, string $toSha): array
    {
        $result = $this->runner->run(
            new LocalTarget,
            $this->gitIn($this->workspacePath($service))
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

    /**
     * `git -C <workspace>` that trusts the workspace whoever owns it.
     *
     * The web container and the queue worker can run as different users (and a
     * workspace cloned before an update keeps its old owner), and git refuses a
     * repository owned by someone else ("dubious ownership"). The workspace is
     * the control plane's own clone, so it is safe to trust; on the command line
     * this is honoured, and nothing is written to any git config.
     */
    private function gitIn(string $workspace): string
    {
        return 'git -c '.Shell::quote('safe.directory=*').' -C '.Shell::quote($workspace);
    }

    private function headOf(string $workspace, string $branch): ?string
    {
        $result = $this->runner->run(
            new LocalTarget,
            $this->gitIn($workspace).' rev-parse --verify '.Shell::quote('refs/remotes/origin/'.$branch.'^{commit}'),
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
            $reason = "{$name}: ".$result->failureReason();

            if ($name === 'fetch' && $this->looksLikeRefusedAccess($result->stdout.$result->stderr)) {
                $explanation = $this->refusedAccessMessage($build->service);
                $task->log("\n".$explanation."\n");
                $reason = $explanation."\n\n".$reason;
            }

            $this->fail($build, $task, $reason);

            return false;
        }

        return true;
    }

    /** What git prints when GitHub will not give it the repository. */
    private function looksLikeRefusedAccess(string $output): bool
    {
        return (bool) preg_match('/could not read Username|Authentication failed|Repository not found|terminal prompts disabled|returned error: (401|403|404)/i', $output);
    }

    private function refusedAccessMessage(Service $service): string
    {
        if (! Setting::has(Setting::GITHUB_TOKEN)) {
            return "No GitHub token is saved, and {$service->repo_full_name} is private. Add one in Settings (the Guide shows how), then build again.";
        }

        // Ask GitHub why, with the same authentication the clone used.
        try {
            $check = app(AccessCheck::class)->service($service);
        } catch (Throwable) {
            $check = null;
        }

        $why = $check !== null && ! $check['ok']
            ? $check['message']
            : 'Open Settings and click "Test GitHub access" for the reason.';

        return "GitHub would not let the saved token read {$service->repo_full_name}. {$why}";
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
        $git = $this->gitIn($workspace);

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
        $git = $this->gitIn($workspace);

        return implode("\n", [
            'set -e',
            // Detached checkout of the exact commit: a branch name could move
            // between classification and build.
            $git.' checkout --force '.Shell::quote($sha),
            $git.' clean -fdx',
        ]);
    }

    /**
     * The service's build files (decision 27) into the checkout, and for a
     * per-stack build that stack's build-time values.
     *
     * Dockerfile and entrypoint go to .control-plane/ unless the service
     * takes them from its repository. The .dockerignore replaces the
     * repository's when it is the control plane's (default or custom); a
     * repository-sourced one is left alone.
     */
    private function contextScript(Build $build, string $workspace): string
    {
        $service = $build->service;
        $commands = ['set -e'];

        foreach ([BuildFiles::DOCKERFILE, BuildFiles::ENTRYPOINT] as $name) {
            $contents = $this->files->resolve($service, $name);

            if ($contents !== null) {
                $commands[] = RemoteFiles::write($workspace.'/.control-plane/'.$name, $contents, '644');
            }
        }

        $ignore = $this->files->resolve($service, BuildFiles::DOCKERIGNORE);

        if ($ignore !== null) {
            $commands[] = RemoteFiles::write($workspace.'/.dockerignore', $ignore, '644');
        }

        if ($build->stack_id !== null) {
            $entitlement = $build->stack->services()->where('service_id', $service->id)->first();
            $args = $entitlement ? $this->env->buildArgs($entitlement) : [];
            $lines = [];

            // What this image is built with, so a later change to any of
            // these values shows it is out of date.
            $build->update(['build_args_hash' => EnvResolver::hashBuildArgs($args)]);

            foreach ($args as $key => $value) {
                $lines[] = $key.'='.str_replace("\n", '', (string) $value);
            }

            // Highest-priority env file Next.js reads at build time, and one
            // the repository should never commit.
            $commands[] = RemoteFiles::write($workspace.'/.env.production.local', implode("\n", $lines)."\n", '600');
        }

        return implode("\n", $commands);
    }

    /**
     * Make the shared PHP base image if the registry does not have it yet.
     *
     * The first Laravel build after the base file changes pays the few minutes
     * it takes to compile PHP's extensions; every build after that, of any
     * service, finds it and moves on.
     */
    private function ensurePhpBase(Build $build, Task $task, string $workspace): bool
    {
        $task->step('Checking the PHP base image');

        $found = $this->runner->run(new LocalTarget, PhpBase::existsScript(), timeout: 120);

        if ($found->successful()) {
            $task->log(PhpBase::reference()." is already built.\n");

            return true;
        }

        $task->step('Building the PHP base image '.PhpBase::reference().' (once, a few minutes)');

        return $this->step($build, $task, 'base', PhpBase::buildScript($workspace), ['DOCKER_BUILDKIT' => '1']);
    }

    /** The Dockerfile to build with: the repository's, or the one written to .control-plane/. */
    private function dockerfilePath(Service $service, string $workspace): string
    {
        $source = $this->files->source($service, BuildFiles::DOCKERFILE);

        return $source['source'] === ServiceFile::SOURCE_REPO
            ? $workspace.'/'.ltrim((string) $source['repo_path'], '/')
            : $workspace.'/.control-plane/'.BuildFiles::DOCKERFILE;
    }

    private function buildScript(Build $build, string $workspace, string $reference): string
    {
        $service = $build->service;

        $laravel = BuildFiles::recipeFor($service) === 'laravel';

        return implode(' ', array_filter([
            // A Laravel service starts from the shared base image, an immutable
            // tag, so there is nothing newer to pull and no reason to ask the
            // registry about it on every build. Other recipes track their
            // upstream image.
            $laravel ? 'docker build' : 'docker build --pull',
            '-f '.Shell::quote($this->dockerfilePath($service, $workspace)),
            '--label '.Shell::quote('org.opencontainers.image.revision='.$build->commit_sha),
            '--label '.Shell::quote('org.opencontainers.image.source=https://github.com/'.$service->repo_full_name),
            '--label '.Shell::quote('org.opencontainers.image.version='.$build->number),
            // The Laravel recipe builds Vite assets only when asked: an API
            // service never builds a front end it does not serve.
            $laravel ? '--build-arg '.Shell::quote('BUILD_ASSETS='.($service->build_assets ? '1' : '0')) : '',
            $laravel ? '--build-arg '.Shell::quote('PHP_BASE='.PhpBase::reference()) : '',
            $laravel ? '--build-arg '.Shell::quote('HEALTH_PATH='.($service->health_path ?: '/up')) : '',
            '-t '.Shell::quote($reference),
            Shell::quote($workspace),
        ]));
    }

    /** Required in every Laravel image; the base checks the same list. */
    public const REQUIRED_EXTENSIONS = ['bcmath', 'gd', 'intl', 'mbstring', 'mysqli', 'pcntl', 'pdo_mysql', 'redis', 'sockets', 'zip'];

    /**
     * Start the image the way a server would, with a throwaway environment,
     * and see it answer.
     *
     * Laravel: the extensions are there, it does not run as root, and its
     * health path answers 200 with no database, cache or queue behind it
     * (all set to in-memory drivers). Next.js: it answers below 500.
     */
    private function testScript(Build $build, string $reference): string
    {
        $service = $build->service;
        $laravel = BuildFiles::recipeFor($service) === 'laravel';
        $image = Shell::quote($reference);
        $name = Shell::quote('cp-test-'.$build->id.'-'.bin2hex(random_bytes(3)));
        $path = $service->health_path ?: ($laravel ? '/up' : '/');

        $lines = ['set -e', "image={$image}", "name={$name}"];

        if ($laravel) {
            $lines[] = 'modules=$(docker run --rm --entrypoint php "$image" -m | tr "[:upper:]" "[:lower:]")';
            $lines[] = 'for ext in '.implode(' ', self::REQUIRED_EXTENSIONS).'; do echo "$modules" | grep -qx "$ext" || { echo "missing PHP extension: $ext"; exit 1; }; done';
            $lines[] = 'echo "PHP extensions present."';
        }

        $lines[] = '[ "$(docker run --rm --entrypoint id "$image" -u)" != "0" ] || { echo "the image runs as root"; exit 1; }';

        $env = $laravel
            ? '-e APP_KEY="base64:$(head -c 32 /dev/urandom | base64)" -e APP_ENV=production -e APP_DEBUG=false '
                .'-e CACHE_STORE=array -e SESSION_DRIVER=array -e QUEUE_CONNECTION=sync -e LOG_CHANNEL=stderr -e BROADCAST_CONNECTION=log'
            : '';

        $probe = $laravel
            ? 'docker exec "$name" curl -fsS -o /dev/null '.Shell::quote('http://127.0.0.1'.$path)
            : 'docker exec "$name" node -e '.Shell::quote("fetch('http://127.0.0.1:3000{$path}',{redirect:'manual'}).then(r=>process.exit(r.status<500?0:1)).catch(()=>process.exit(1))");

        return implode("\n", [
            ...$lines,
            "docker run -d --name \"\$name\" {$env} \"\$image\" >/dev/null",
            'ok=0',
            'trap \'[ "$ok" = 1 ] || docker logs --tail 80 "$name" 2>&1; docker rm -f "$name" >/dev/null 2>&1 || true\' EXIT',
            'for i in $(seq 1 45); do',
            "  if {$probe} 2>/dev/null; then ok=1; echo \"It answered on {$path}.\"; exit 0; fi",
            '  sleep 1',
            'done',
            "echo \"It did not answer on {$path} within 45 seconds. Its log:\"",
            'exit 1',
        ]);
    }

    private function readSize(string $reference): ?int
    {
        $result = $this->runner->run(new LocalTarget, 'docker image inspect --format \'{{.Size}}\' '.Shell::quote($reference), timeout: 60);
        $size = trim($result->stdout);

        return $result->successful() && ctype_digit($size) ? (int) $size : null;
    }

    private function budgetMb(Service $service): int
    {
        return (int) config('deploy.image_budget_mb.'.BuildFiles::recipeFor($service), 700);
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

    /** @return array<string, string> */
    private function gitAuthEnv(): array
    {
        return GitHub::gitEnv();
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
