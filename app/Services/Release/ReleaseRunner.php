<?php

namespace App\Services\Release;

use App\Contracts\Target;
use App\Models\Release;
use App\Models\ReleaseItem;
use App\Models\Service;
use App\Models\Stack;
use App\Models\StackService;
use App\Services\Backup\BackupService;
use App\Services\GitHub\GitHub;
use App\Services\Guide\SetupGuide;
use App\Services\Provision\StackProvisioner;
use App\Services\Remote\CommandRunner;
use App\Services\Stack\CertificateIssuer;
use App\Services\Stack\EnvResolver;
use App\Services\Stack\StackRenderer;
use App\Services\Stack\StackSync;
use App\Support\Shell;
use Closure;
use RuntimeException;
use Throwable;

/**
 * Applies a release to a stack.
 *
 * Two paths. The live path swaps containers one service at a time while the
 * stack keeps serving. The window path is for destructive migrations: the
 * client has agreed a time, a maintenance page goes up, only the affected
 * tables are dumped, and the migration runs unconstrained.
 *
 * Around the swaps, every release also makes the stack whole: databases,
 * users and NATS streams for whatever is enabled, a service's first-run and
 * every-deploy commands, the identity provider's service clients and first
 * admin, and certificates. All of it is idempotent, so the first deploy of a
 * client and the hundredth run the same code.
 */
class ReleaseRunner
{
    public function __construct(
        private readonly CommandRunner $runner,
        private readonly StackSync $sync,
        private readonly StackRenderer $renderer,
        private readonly EnvResolver $env,
        private readonly BackupService $backups,
        private readonly StackProvisioner $provisioner,
        private readonly CertificateIssuer $certificates,
        private readonly GitHub $github,
    ) {}

    /**
     * @param  Closure(string):void|null  $onOutput
     */
    public function run(Release $release, ?Target $target = null, ?Closure $onOutput = null): Release
    {
        $release->load('items.service', 'items.build', 'stack.server', 'stack.client');

        $log = $onOutput ?? fn (string $line) => null;

        try {
            $this->assertRunnable($release);
        } catch (RuntimeException $e) {
            $log($e->getMessage()."\n");
            $release->update(['status' => 'failed', 'finished_at' => now()]);

            return $release;
        }

        $target ??= $release->stack->server->target();

        $release->update(['status' => 'running', 'started_at' => now()]);

        // Intent is recorded before anything moves, so a crash mid-release
        // still leaves a complete record of what was being attempted.
        $this->recordIntent($release);

        $window = $release->needsWindow();
        $stack = $release->stack;

        try {
            $log("\n==> Databases, users and streams\n");

            if ($failure = $this->provisioner->prepare($stack, $target, $log)) {
                return $this->fail($release, $failure, $log);
            }

            // Rendered after recordIntent, so the compose file already names
            // the images this release is moving to.
            $pushFailure = $this->sync->push($stack, $target);

            if ($pushFailure !== null) {
                return $this->fail($release, 'Could not write the stack files: '.$pushFailure->failureReason(), $log);
            }

            if ($window) {
                $log("Destructive migration — entering maintenance window.\n");
                $release->update(['window_started_at' => now()]);
                $this->enterMaintenance($release, $target, $log);

                // A destructive migration never runs without its safety net.
                $dumpFailure = $this->dumpBeforeMigrating($release, $target, $log);

                if ($dumpFailure !== null) {
                    return $this->fail($release, "Pre-migration backup failed, nothing was migrated: {$dumpFailure}", $log);
                }
            }

            foreach ($this->ordered($release) as $item) {
                if (! $this->runItem($release, $item, $target, $log)) {
                    // Stop here. Items that already succeeded stay up and are
                    // each revertable on their own; half a release running is
                    // a state we can describe, a half-applied one is not.
                    return $this->fail($release, "Stopped at {$item->service->slug}.", $log);
                }
            }

            if ($failure = $this->registerServiceClients($stack, $target, $log)) {
                return $this->fail($release, $failure, $log);
            }

            $log("\n==> Certificates\n");
            $this->certificates->ensure($stack, $target, $log);

            $release->update(['status' => 'succeeded', 'finished_at' => now()]);
            SetupGuide::forget();

            // A stack is only active once something has actually been
            // deployed to it. Scheduled backups, drift polls and alerts select
            // active stacks, so this is what switches them on.
            if ($stack->status !== 'active') {
                $stack->update(['status' => 'active']);
            }

            if ($stack->isLive()) {
                $this->advanceMain($release, $log);
            }

            $log("\nRelease succeeded.\n");
        } catch (Throwable $e) {
            report($e);

            return $this->fail($release, 'Unexpected error: '.$e->getMessage(), $log);
        } finally {
            if ($window) {
                $this->leaveMaintenance($release, $target, $log);
                $release->update(['window_ended_at' => now()]);
            }

            $target->cleanup();
        }

        return $release;
    }

    /**
     * Everything that must be true before a single container moves.
     */
    public function assertRunnable(Release $release): void
    {
        if (! $release->stack->server?->isProvisioned()) {
            throw new RuntimeException('The server has not been set up. Run "Set up server" first.');
        }

        if ($release->isEmergency() && blank($release->reason)) {
            throw new RuntimeException('An emergency release needs a typed reason.');
        }

        if ($release->needsWindow() && $release->scheduled_for === null) {
            throw new RuntimeException(
                'This release drops or rewrites data and needs a scheduled window agreed with the client.'
            );
        }

        // Decision 7. The alternative is a container that boots, falls back to
        // a hardcoded default, and quietly talks to the wrong backend.
        $missing = [];

        foreach ($release->items as $item) {
            $entitlement = $this->entitlement($release, $item);

            if ($entitlement === null || ! $entitlement->enabled) {
                throw new RuntimeException("{$item->service->slug} is not enabled on this stack.");
            }

            foreach ($this->env->missingRequired($entitlement) as $key) {
                $missing[] = "{$item->service->slug}.{$key}";
            }
        }

        if ($missing !== []) {
            throw new RuntimeException('Missing required settings: '.implode(', ', $missing));
        }
    }

    /**
     * Write every item's from/to digest, and point the stack at the new
     * images, before the first swap.
     */
    private function recordIntent(Release $release): void
    {
        foreach ($release->items as $item) {
            $entitlement = $this->entitlement($release, $item);
            $to = $item->to_digest ?? $item->build?->digest;

            $item->update([
                'from_digest' => $entitlement?->current_digest,
                'to_digest' => $to,
            ]);

            // What the compose file renders. current_digest only moves once
            // the new container has answered its health check.
            $entitlement?->update(['desired_digest' => $to]);
        }

        $release->load('items.service', 'items.build');
    }

    /**
     * The identity provider first: every other service checks its tokens
     * against it, so it has to be up before they are.
     *
     * @return list<ReleaseItem>
     */
    private function ordered(Release $release): array
    {
        return $release->items
            ->sortBy(fn (ReleaseItem $item) => [$item->service->identity_provider ? 0 : 1, $item->position])
            ->values()
            ->all();
    }

    private function runItem(Release $release, ReleaseItem $item, Target $target, Closure $log): bool
    {
        $entitlement = $this->entitlement($release, $item);
        $service = $item->service;
        $compose = $this->compose($release->stack);
        $firstRun = $entitlement->initialized_at === null;

        $item->update(['status' => 'running', 'started_at' => now()]);
        $log("\n==> {$service->name}: ".($item->build?->label() ?? $item->to_digest).($firstRun ? ' (first deploy)' : '')."\n");

        $steps = ['pull' => $compose.' pull '.Shell::join($service->composeServices())];

        if ($this->migrates($service)) {
            // Every deploy, not only builds classified as carrying
            // migrations: a first deploy has a whole history to apply to an
            // empty database, and on anything else it is a no-op.
            $steps['migrate'] = $this->oneOff($compose, $service, 'php artisan migrate --force');
        }

        $commands = [
            ...($firstRun ? ($service->first_run_commands ?? []) : []),
            ...($service->every_deploy_commands ?? []),
        ];

        foreach (array_values($commands) as $i => $command) {
            $steps['command '.($i + 1)] = $this->oneOff($compose, $service, $command);
        }

        // The web container and its process containers share one image, so
        // they move together.
        $steps['swap'] = $compose.' up -d --no-deps '.Shell::join($service->composeServices());

        foreach ($steps as $name => $command) {
            $log("--- {$name}\n");
            $result = $this->runner->run($target, $command, timeout: 1800, onOutput: $log);

            if ($result->failed()) {
                return $this->itemFailed($item, "{$name}: ".$result->failureReason());
            }
        }

        if (! $this->healthy($entitlement, $target, $log)) {
            return $this->itemFailed($item, 'Health check never passed.');
        }

        if ($service->identity_provider && $firstRun && ($failure = $this->createFirstAdmin($release->stack, $service, $target, $log))) {
            return $this->itemFailed($item, $failure);
        }

        $item->update(['status' => 'succeeded', 'finished_at' => now()]);

        $entitlement->update([
            'current_digest' => $item->to_digest,
            'desired_digest' => $item->to_digest,
            'state' => 'deployed',
            'last_deployed_at' => now(),
            'initialized_at' => $entitlement->initialized_at ?? now(),
        ]);

        return true;
    }

    private function itemFailed(ReleaseItem $item, string $error): bool
    {
        $item->update(['status' => 'failed', 'error' => $error, 'finished_at' => now()]);

        return false;
    }

    private function migrates(Service $service): bool
    {
        return $service->build_recipe !== Service::RECIPE_NEXTJS && filled($service->database_name);
    }

    /**
     * A command in a throwaway container from the new image, before the swap.
     *
     * The entrypoint is bypassed: images built from a repository's own
     * Dockerfile often ignore their arguments and start the web server.
     */
    private function oneOff(string $compose, Service $service, string $command): string
    {
        return $compose.' run --rm --no-deps -T --entrypoint sh '.Shell::quote($service->slug).' -c '.Shell::quote($command);
    }

    /**
     * Register every enabled service as a client of the identity provider,
     * with the token the control plane generated for it (the same value that
     * service's AUTH_SERVER_CALL_TOKEN resolves to). Runs on every release,
     * so a service enabled later is registered on its first deploy.
     *
     * Needs pizzasys's `service-client:upsert --token-stdin`.
     */
    private function registerServiceClients(Stack $stack, Target $target, Closure $log): ?string
    {
        $entitlements = StackService::query()
            ->with('service')
            ->where('stack_id', $stack->id)
            ->where('enabled', true)
            ->get();

        $provider = $entitlements->first(fn (StackService $s) => $s->service->identity_provider && filled($s->current_digest));

        if ($provider === null) {
            return null;
        }

        $clients = $entitlements->filter(fn (StackService $s) => ! $s->service->identity_provider && filled($s->service->auth_service_name));

        if ($clients->isEmpty()) {
            return null;
        }

        $log("\n==> Service clients on {$provider->service->name}\n");
        $compose = $this->compose($stack);

        foreach ($clients as $client) {
            $token = $this->env->serviceToken($stack, $client->service);

            $result = $this->runner->run(
                $target,
                "printf '%s' ".Shell::quote($token).' | '.$compose.' exec -T -u www-data '.Shell::quote($provider->service->slug)
                    .' php artisan service-client:upsert '.Shell::quote($client->service->auth_service_name).' --token-stdin',
                timeout: 120,
                onOutput: $log,
            );

            if ($result->failed()) {
                return "Registering {$client->service->auth_service_name} with {$provider->service->name} failed: ".$result->failureReason();
            }
        }

        return null;
    }

    /**
     * The client's first admin, on the identity provider's first deploy.
     * Needs pizzasys's `app:create-admin --password-stdin`, which leaves an
     * existing account untouched.
     */
    private function createFirstAdmin(Stack $stack, Service $service, Target $target, Closure $log): ?string
    {
        $client = $stack->client;

        if (blank($client->admin_email)) {
            $log("No first admin email on the client; skipping admin creation.\n");

            return null;
        }

        $log("--- first admin {$client->admin_email}\n");

        $result = $this->runner->run(
            $target,
            "printf '%s' ".Shell::quote($stack->secret('admin_password', 24)).' | '.$this->compose($stack)
                .' exec -T -u www-data '.Shell::quote($service->slug)
                .' php artisan app:create-admin '.Shell::quote($client->admin_email).' '.Shell::quote($client->admin_name ?: $client->name.' admin').' --password-stdin',
            timeout: 120,
            onOutput: $log,
        );

        return $result->failed() ? 'Creating the first admin failed: '.$result->failureReason() : null;
    }

    /**
     * `main` follows the newest commit live for any client (decision 19).
     * GitHub refuses anything but a fast-forward, which is exactly when main
     * is already ahead; either way the release has already succeeded.
     */
    private function advanceMain(Release $release, Closure $log): void
    {
        if (! $this->github->configured()) {
            return;
        }

        foreach ($release->items as $item) {
            $build = $item->build;

            if ($item->status !== 'succeeded' || $build === null || $build->edition !== null || blank($build->commit_sha)) {
                continue;
            }

            $main = $item->service->main_branch ?: 'main';

            try {
                $response = $this->github->fastForward($item->service->repo_full_name, $main, $build->commit_sha);

                $log($response->successful()
                    ? "{$main} on {$item->service->repo_full_name} now at {$build->shortSha()}.\n"
                    : "{$main} on {$item->service->repo_full_name} left where it is ({$response->status()}): it is already ahead or has diverged.\n");
            } catch (Throwable $e) {
                $log("Could not update {$main} on {$item->service->repo_full_name}: {$e->getMessage()}\n");
            }
        }
    }

    private function compose(Stack $stack): string
    {
        return 'docker compose -f '.Shell::quote($this->renderer->rootPath($stack).'/docker-compose.yml');
    }

    private function healthy(?StackService $entitlement, Target $target, Closure $log): bool
    {
        if ($entitlement === null) {
            return false;
        }

        $attempts = (int) config('deploy.health.attempts');
        $interval = (int) config('deploy.health.interval_seconds');
        $url = "http://127.0.0.1:{$entitlement->host_port}{$entitlement->service->health_path}";

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $result = $this->runner->run($target, 'curl -fsS -m 5 -o /dev/null '.Shell::quote($url), timeout: 15);

            if ($result->successful()) {
                $log("Health check passed on attempt {$attempt}.\n");

                return true;
            }

            if ($attempt < $attempts) {
                $this->runner->run($target, "sleep {$interval}", timeout: $interval + 5);
            }
        }

        $log("Health check failed after {$attempts} attempts.\n");

        return false;
    }

    private function enterMaintenance(Release $release, Target $target, Closure $log): void
    {
        foreach ($release->items as $item) {
            $entitlement = $this->entitlement($release, $item);

            if ($entitlement === null) {
                continue;
            }

            $conf = $this->renderer->nginx($entitlement, maintenance: true);
            $domain = $release->stack->domainFor($item->service);
            $path = $this->renderer->rootPath($release->stack)."/nginx/{$domain}.conf";

            $this->runner->run(
                $target,
                "printf '%s' '".base64_encode($conf)."' | base64 -d > ".Shell::quote($path),
                timeout: 30,
            );
        }

        $this->sync->reloadNginx($release->stack, $target);
        $log("Maintenance page is up.\n");
    }

    private function leaveMaintenance(Release $release, Target $target, Closure $log): void
    {
        // Re-render from the database: whatever went wrong, the correct end
        // state is what the control plane says it should be.
        $this->sync->push($release->stack, $target);
        $this->sync->reloadNginx($release->stack, $target);
        $log("Maintenance page is down.\n");
    }

    /**
     * Dump only the tables each destructive migration touches, per service,
     * into the control plane's backup store, recorded as a Backup row.
     *
     * Returns the reason when a dump failed, so the caller stops before
     * anything is migrated.
     */
    private function dumpBeforeMigrating(Release $release, Target $target, Closure $log): ?string
    {
        foreach ($release->items as $item) {
            if (! ($item->build?->needsWindow() ?? false)) {
                continue;
            }

            $tables = $item->build->affected_tables ?? [];
            $entitlement = $this->entitlement($release, $item);

            if ($tables === [] || $entitlement === null || $release->stack->databaseFor($item->service) === null) {
                continue;
            }

            $log("Backing up {$item->service->slug}: ".implode(', ', $tables)."\n");

            $backup = $this->backups->tables($release->stack, $entitlement, $tables, $target, $release->id, $log);

            if ($backup->status !== 'success') {
                return "{$item->service->slug}: ".($backup->error ?? 'unknown error');
            }
        }

        return null;
    }

    private function entitlement(Release $release, ReleaseItem $item): ?StackService
    {
        return StackService::query()
            ->with('service', 'stack.client', 'stack.server')
            ->where('stack_id', $release->stack_id)
            ->where('service_id', $item->service_id)
            ->first();
    }

    private function fail(Release $release, string $message, Closure $log): Release
    {
        $log("\n{$message}\n");
        $release->update(['status' => 'failed', 'finished_at' => now()]);

        return $release;
    }
}
