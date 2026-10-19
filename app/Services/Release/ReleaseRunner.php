<?php

namespace App\Services\Release;

use App\Contracts\Target;
use App\Models\Release;
use App\Models\ReleaseItem;
use App\Models\StackService;
use App\Services\Backup\BackupService;
use App\Services\Remote\CommandRunner;
use App\Services\Stack\EnvResolver;
use App\Services\Stack\StackRenderer;
use App\Services\Stack\StackSync;
use App\Support\Shell;
use Closure;
use RuntimeException;

/**
 * Applies a release to a stack.
 *
 * Two paths. The live path swaps containers one service at a time while the
 * stack keeps serving. The window path is for destructive migrations: the
 * client has agreed a time, a maintenance page goes up, only the affected
 * tables are dumped, and the migration runs unconstrained.
 */
class ReleaseRunner
{
    public function __construct(
        private readonly CommandRunner $runner,
        private readonly StackSync $sync,
        private readonly StackRenderer $renderer,
        private readonly EnvResolver $env,
        private readonly BackupService $backups,
    ) {}

    /**
     * @param  Closure(string):void|null  $onOutput
     */
    public function run(Release $release, ?Target $target = null, ?Closure $onOutput = null): Release
    {
        $release->load('items.service', 'items.build', 'stack.server', 'stack.client');

        $target ??= $release->stack->server->target();
        $log = $onOutput ?? fn (string $line) => null;

        try {
            $this->assertRunnable($release);
        } catch (RuntimeException $e) {
            $log($e->getMessage()."\n");
            $release->update(['status' => 'failed', 'finished_at' => now()]);

            return $release;
        }

        $release->update(['status' => 'running', 'started_at' => now()]);

        // Intent is recorded before anything moves, so a crash mid-release
        // still leaves a complete record of what was being attempted.
        $this->recordIntent($release);

        $window = $release->needsWindow();

        try {
            $pushFailure = $this->sync->push($release->stack, $target);

            if ($pushFailure !== null) {
                return $this->fail($release, 'Could not write the stack files: '.$pushFailure->failureReason(), $log);
            }

            if ($window) {
                $log("Destructive migration — entering maintenance window.\n");
                $release->update(['window_started_at' => now()]);
                $this->enterMaintenance($release, $target, $log);

                // A destructive migration never runs without its safety net.
                // This used to ignore the dump's result, so a failed dump went
                // straight on to drop columns with nothing to restore from.
                $dumpFailure = $this->dumpBeforeMigrating($release, $target, $log);

                if ($dumpFailure !== null) {
                    return $this->fail($release, "Pre-migration backup failed, nothing was migrated: {$dumpFailure}", $log);
                }
            }

            foreach ($release->items as $item) {
                if (! $this->runItem($release, $item, $target, $log)) {
                    // Stop here. Items that already succeeded stay up and are
                    // each revertable on their own; half a release running is
                    // a state we can describe, a half-applied one is not.
                    return $this->fail($release, "Stopped at {$item->service->slug}.", $log);
                }
            }

            $release->update(['status' => 'succeeded', 'finished_at' => now()]);

            // A stack is only active once something has actually been
            // deployed to it. Scheduled backups, drift polls and alerts select
            // active stacks, so this is what switches them on.
            if ($release->stack->status !== 'active') {
                $release->stack->update(['status' => 'active']);
            }

            $log("Release succeeded.\n");
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

            if ($entitlement === null) {
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
     * Write every item's from/to digest before the first swap.
     */
    private function recordIntent(Release $release): void
    {
        foreach ($release->items as $item) {
            $entitlement = $this->entitlement($release, $item);

            $item->update([
                'from_digest' => $entitlement?->current_digest,
                'to_digest' => $item->to_digest ?? $item->build?->digest,
            ]);
        }

        $release->load('items.service', 'items.build');
    }

    private function runItem(Release $release, ReleaseItem $item, Target $target, Closure $log): bool
    {
        $entitlement = $this->entitlement($release, $item);
        $service = $item->service;
        $root = $this->renderer->rootPath($release->stack);

        $item->update(['status' => 'running', 'started_at' => now()]);
        $log("\n--- {$service->slug}: {$item->from_digest} -> {$item->to_digest}\n");

        $compose = 'docker compose -f '.Shell::quote($root.'/docker-compose.yml');

        $steps = [
            'pull' => $compose.' pull '.Shell::quote($service->slug),
            'migrate' => $this->migrateCommand($item, $compose),
            'swap' => $compose.' up -d --no-deps '.Shell::quote($service->slug),
        ];

        foreach (array_filter($steps) as $name => $command) {
            $result = $this->runner->run($target, $command, timeout: 900, onOutput: $log);

            if ($result->failed()) {
                $item->update([
                    'status' => 'failed',
                    'error' => "{$name}: ".$result->failureReason(),
                    'finished_at' => now(),
                ]);

                return false;
            }
        }

        if (! $this->healthy($entitlement, $target, $log)) {
            $item->update([
                'status' => 'failed',
                'error' => 'Health check never passed.',
                'finished_at' => now(),
            ]);

            return false;
        }

        $item->update(['status' => 'succeeded', 'finished_at' => now()]);

        $entitlement?->update([
            'current_digest' => $item->to_digest,
            'desired_digest' => $item->to_digest,
            'state' => 'deployed',
            'last_deployed_at' => now(),
        ]);

        return true;
    }

    /**
     * Only Laravel services migrate, and only when the build actually carries
     * migrations — running artisan on a Next.js container would just fail.
     */
    private function migrateCommand(ReleaseItem $item, string $compose): ?string
    {
        if ($item->build === null || $item->build->migration_kind === 'none') {
            return null;
        }

        if ($item->service->build_recipe === 'nextjs') {
            return null;
        }

        return $compose.' run --rm --no-deps '.Shell::quote($item->service->slug).' php artisan migrate --force';
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
            $result = $this->runner->run($target, 'curl -fsS -m 5 '.Shell::quote($url), timeout: 15);

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
     * Per service because each service has its own database: an earlier
     * version dumped every affected table from the first item's database, and
     * wrote it to the client's own disk with no record of it.
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
            ->with('service', 'stack.client')
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
