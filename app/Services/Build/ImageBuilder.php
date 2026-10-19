<?php

namespace App\Services\Build;

use App\Models\Build;
use App\Models\Service;
use App\Models\Stack;
use App\Services\Remote\CommandRunner;
use App\Services\Stack\EnvResolver;
use App\Support\Shell;
use App\Targets\LocalTarget;
use Closure;

/**
 * Builds one image, on the control plane, and pushes it to the registry.
 *
 * Building centrally rather than on each client server is the point: it is
 * what lets every stack pull the identical digest, and it keeps our source
 * code and a credential that can read our private repos off machines we do
 * not fully control.
 */
class ImageBuilder
{
    public function __construct(
        private readonly CommandRunner $runner,
        private readonly MigrationClassifier $classifier,
        private readonly EnvResolver $env,
    ) {}

    /**
     * Prepare a Build row for a commit, classifying its migrations.
     *
     * Classification happens here, at build time, so nobody discovers that
     * tonight's release needs a maintenance window at six on a Friday.
     */
    public function prepare(Service $service, string $branch, string $commitSha, ?string $edition = null, ?Stack $stack = null): Build
    {
        $previous = Build::query()
            ->where('service_id', $service->id)
            ->where('edition', $edition)
            ->where('status', 'success')
            ->latest('built_at')
            ->first();

        $migrations = $this->migrationsBetween($service, $previous?->commit_sha, $commitSha);
        $classified = $this->classifier->classify($migrations);

        return Build::create([
            'service_id' => $service->id,
            'edition' => $edition,
            'stack_id' => $stack?->id,
            'branch' => $branch,
            'commit_sha' => $commitSha,
            'image_tag' => $this->tagFor($service, $commitSha, $edition, $stack),
            'migration_kind' => $classified['kind'],
            'affected_tables' => $classified['tables'],
            'migration_reasons' => $classified['reasons'],
            'status' => 'pending',
        ]);
    }

    /**
     * Build and push. Returns the build with its digest recorded.
     *
     * @param  Closure(string):void|null  $onOutput
     */
    public function build(Build $build, ?Closure $onOutput = null): Build
    {
        $build->update(['status' => 'running']);

        $service = $build->service;
        $workspace = $this->workspacePath($service);
        $reference = $service->imageRepository().':'.$build->image_tag;

        $steps = [
            'fetch' => $this->fetchScript($service, $build->commit_sha, $workspace),
            'build' => $this->buildScript($build, $workspace, $reference),
            'push' => "docker push {Shell::quote($reference)}",
        ];

        foreach ($steps as $name => $script) {
            $result = $this->runner->run(new LocalTarget, $script, timeout: 3600, onOutput: $onOutput);

            if ($result->failed()) {
                $build->update([
                    'status' => 'failed',
                    'error' => "{$name}: ".$result->failureReason(),
                ]);

                return $build;
            }
        }

        $digest = $this->readDigest($reference);

        if ($digest === null) {
            $build->update(['status' => 'failed', 'error' => 'Pushed, but no digest could be read back.']);

            return $build;
        }

        $build->update(['status' => 'success', 'digest' => $digest, 'built_at' => now()]);

        return $build;
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
     * Migration files added between two commits.
     *
     * Against the last successful build rather than the parent commit, so a
     * release covering several merges still sees every migration it will run.
     *
     * @return array<string, string>
     */
    public function migrationsBetween(Service $service, ?string $fromSha, string $toSha): array
    {
        $workspace = $this->workspacePath($service);

        $range = $fromSha === null
            // No previous build: only this commit's own migrations, or a first
            // build would classify the entire history as destructive.
            ? Shell::quote($toSha).'^!'
            : Shell::quote($fromSha).'..'.Shell::quote($toSha);

        $listed = $this->runner->run(
            new LocalTarget,
            "git -C {Shell::quote($workspace)} diff --name-only --diff-filter=AM {$range} -- 'database/migrations/*'",
            timeout: 60,
        );

        if ($listed->failed()) {
            return [];
        }

        $files = array_filter(array_map('trim', explode("\n", $listed->stdout)));
        $migrations = [];

        foreach ($files as $path) {
            $shown = $this->runner->run(
                new LocalTarget,
                "git -C {Shell::quote($workspace)} show {Shell::quote($toSha)}:{Shell::quote($path)}",
                timeout: 60,
            );

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

    private function fetchScript(Service $service, string $commitSha, string $workspace): string
    {
        $repo = 'https://github.com/'.$service->repo_full_name.'.git';

        return implode("\n", [
            'set -e',
            'mkdir -p '.Shell::quote(dirname($workspace)),
            'if [ ! -d '.Shell::quote($workspace.'/.git').' ]; then',
            '  git clone --no-checkout '.Shell::quote($repo).' '.Shell::quote($workspace),
            'fi',
            'git -C '.Shell::quote($workspace).' fetch --all --prune --tags',
            // Detached checkout of the exact commit: a branch name could move
            // between classification and build.
            'git -C '.Shell::quote($workspace).' checkout --force '.Shell::quote($commitSha),
            'git -C '.Shell::quote($workspace).' clean -fdx',
        ]);
    }

    private function buildScript(Build $build, string $workspace, string $reference): string
    {
        $service = $build->service;

        $args = '';

        // Build arguments only exist for services whose client-specific values
        // are frozen at build time — which is why those services need an image
        // per stack rather than one shared one.
        if ($build->stack_id !== null) {
            $entitlement = $build->stack->services()->where('service_id', $service->id)->first();

            if ($entitlement !== null) {
                foreach ($this->env->buildArgs($entitlement) as $key => $value) {
                    $args .= ' --build-arg '.Shell::quote($key.'='.$value);
                }
            }
        }

        $dockerfile = filled($service->dockerfile_path)
            ? ' -f '.Shell::quote($service->dockerfile_path)
            : '';

        return 'docker build'.$dockerfile.$args
            .' -t '.Shell::quote($reference)
            .' '.Shell::quote($workspace);
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
