<?php

namespace App\Services\Backup;

use App\Contracts\Target;
use App\Models\Backup;
use App\Models\Stack;
use App\Models\StackService;
use App\Services\Remote\CommandResult;
use App\Services\Remote\CommandRunner;
use App\Services\Stack\StackRenderer;
use App\Support\Shell;
use App\Targets\LocalTarget;
use Closure;
use Illuminate\Support\Collection;

/**
 * Takes backups from client servers and pulls them to the control plane.
 *
 * Databases nightly, file volumes weekly. The asymmetry is deliberate: a
 * database dump is megabytes and a media volume is not, and a weekly transfer
 * of the latter is the difference between a backup policy that runs and one
 * that gets turned off for using too much disk.
 *
 * Every connection to the server goes through the server's own Target, so it
 * uses that server's key and pinned host key. An earlier version ran a bare
 * `ssh`, which only worked if the worker happened to have its own key and
 * known_hosts — i.e. it would have failed against every real server.
 */
class BackupService
{
    public function __construct(
        private readonly CommandRunner $runner,
        private readonly StackRenderer $renderer,
    ) {}

    /**
     * Dump every database on a stack and stream it back here.
     *
     * Streamed rather than dumped-then-copied: no temporary file on the
     * client's disk, which matters most on the server that is already short
     * of space — the one where a backup is about to be needed.
     *
     * @param  Closure(string):void|null  $log
     * @return list<Backup>
     */
    public function databases(Stack $stack, ?Target $target = null, string $trigger = 'schedule', ?Closure $log = null): array
    {
        $ownTarget = $target === null;
        $target ??= $stack->server->target();
        $results = [];

        try {
            foreach ($this->databaseServices($stack) as $entitlement) {
                $database = $stack->databaseFor($entitlement->service);

                if ($database === null) {
                    continue;
                }

                $log && $log("Dumping {$database}…\n");
                $results[] = $this->dumpDatabase($stack, $entitlement, $database, $target, $trigger, log: $log);
            }
        } finally {
            if ($ownTarget) {
                $target->cleanup();
            }
        }

        return $results;
    }

    /**
     * Dump a specific set of tables — taken before a destructive migration.
     * The caller owns the target and cleans it up.
     *
     * @param  list<string>  $tables
     * @param  Closure(string):void|null  $log
     */
    public function tables(Stack $stack, StackService $entitlement, array $tables, Target $target, ?int $releaseId = null, ?Closure $log = null): Backup
    {
        return $this->dumpDatabase(
            $stack,
            $entitlement,
            $stack->databaseFor($entitlement->service) ?? '',
            $target,
            'pre_release',
            $tables,
            $releaseId,
            $log,
        );
    }

    /**
     * Mirror a stack's file volumes with rsync.
     *
     * --backup-dir is what makes this a backup rather than a mirror: a plain
     * --delete mirror has no history, so a file deleted on Monday is gone
     * from Sunday's copy too.
     *
     * @param  Closure(string):void|null  $log
     */
    public function files(Stack $stack, ?Target $target = null, string $trigger = 'schedule', ?Closure $log = null): Backup
    {
        $ownTarget = $target === null;
        $target ??= $stack->server->target();

        $root = $this->renderer->rootPath($stack);
        $destination = $this->stackRoot($stack).'/files';
        $deleted = $this->stackRoot($stack).'/files-deleted/'.now()->format('Y-m-d');

        $backup = $this->start($stack, Backup::KIND_FILES, $destination, $trigger);

        // rsync runs here and reaches out through the server's own key and
        // pinned host key: `-e` is the target's ssh command, the source is its
        // address.
        $ssh = $target->prefix();
        $address = array_pop($ssh);

        $command = implode(' ', [
            'mkdir -p', Shell::quote($destination), Shell::quote($deleted), '&&',
            'rsync -az --numeric-ids --delete --backup',
            '--backup-dir='.Shell::quote($deleted),
            '-e', Shell::quote(Shell::join($ssh)),
            Shell::quote("{$address}:{$root}/storage/"),
            Shell::quote($destination.'/'),
        ]);

        try {
            $result = $this->runner->run(new LocalTarget, $command, timeout: 7200, onOutput: $log);
        } finally {
            if ($ownTarget) {
                $target->cleanup();
            }
        }

        return $this->finish($backup, $result, $destination);
    }

    /**
     * The control plane's own database.
     *
     * Open risk, recorded here as well as in the plan: this currently lands
     * on the machine it protects. Losing that machine loses every client's
     * config, secrets and release history while every client server keeps
     * running perfectly well. It moves offsite when object storage exists.
     */
    public function controlPlane(string $trigger = 'schedule'): Backup
    {
        $path = $this->root().'/_control-plane/'.now()->format('Y-m-d_His').'.sql.gz';

        $backup = Backup::create([
            'kind' => Backup::KIND_CONTROL_PLANE,
            'status' => 'running',
            'triggered_by' => $trigger,
            'path' => $path,
            'started_at' => now(),
            'retained_until' => now()->addDays((int) config('backup.retention.control_plane_days')),
        ]);

        $command = 'set -o pipefail; mkdir -p '.Shell::quote(dirname($path))
            .' && mysqldump'
            .' --host='.Shell::quote((string) config('backup.mysql.host'))
            .' --port='.Shell::quote((string) config('backup.mysql.port'))
            .' --user='.Shell::quote((string) config('backup.mysql.username'))
            .' --single-transaction --quick --routines --triggers --hex-blob '
            .Shell::quote((string) config('database.connections.mysql.database'))
            .' | gzip -9 > '.Shell::quote($path);

        $result = $this->runner->run(
            new LocalTarget,
            $command,
            // The password travels in the environment, never in argv where
            // `ps` would show it.
            env: ['MYSQL_PWD' => (string) config('backup.mysql.password')],
            timeout: 3600,
        );

        return $this->finish($backup, $result, $path);
    }

    /**
     * @param  list<string>  $tables
     * @param  Closure(string):void|null  $log
     */
    private function dumpDatabase(
        Stack $stack,
        StackService $entitlement,
        string $database,
        Target $target,
        string $trigger,
        array $tables = [],
        ?int $releaseId = null,
        ?Closure $log = null,
    ): Backup {
        $label = $tables === [] ? $database : $database.'-tables';
        $path = $this->stackRoot($stack)."/db/{$entitlement->service->slug}/".now()->format('Y-m-d_His')."-{$label}.sql.gz";

        $backup = Backup::create([
            'stack_id' => $stack->id,
            'service_id' => $entitlement->service_id,
            'kind' => Backup::KIND_DATABASE,
            'status' => 'running',
            'triggered_by' => $trigger,
            'release_id' => $releaseId,
            'path' => $path,
            'tables' => $tables ?: null,
            'started_at' => now(),
            'retained_until' => now()->addDays((int) config('backup.retention.database_days')),
        ]);

        // Runs inside the shared MySQL container, which already holds the root
        // password in its own environment — so the password never appears in
        // any command line on either machine.
        $mysqldump = 'exec mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD"'
            .' --single-transaction --quick --routines --triggers --hex-blob '
            .Shell::join([$database, ...$tables]);

        $remote = 'docker exec '.Shell::quote((string) config('backup.mysql_container'))
            .' sh -c '.Shell::quote($mysqldump);

        // Streamed over the SSH connection and gzipped here, so nothing lands
        // on the client's disk. pipefail is the point: without it the exit
        // status is gzip's, and a dump that dies halfway looks like success.
        $command = implode(' ', [
            'set -o pipefail;',
            'mkdir -p', Shell::quote(dirname($path)), '&&',
            Shell::join([...$target->prefix(), $remote]),
            '| gzip -9 >', Shell::quote($path.'.part'),
            '&& gzip -t', Shell::quote($path.'.part'),
            '&& mv', Shell::quote($path.'.part'), Shell::quote($path),
        ]);

        $result = $this->runner->run(new LocalTarget, $command, timeout: 7200, onOutput: $log);

        return $this->finish($backup, $result, $path);
    }

    /**
     * Prune expired backups, including the .part files a killed dump leaves.
     *
     * The old command filtered on *.sql.gz, so an interrupted dump left a
     * .part behind forever and nothing ever noticed.
     */
    public function prune(): int
    {
        $expired = Backup::query()
            ->whereNotNull('retained_until')
            ->where('retained_until', '<', now())
            ->get();

        foreach ($expired as $backup) {
            $this->runner->run(
                new LocalTarget,
                'rm -rf '.Shell::quote($backup->path).' '.Shell::quote($backup->path.'.part'),
                timeout: 60,
            );

            $backup->delete();
        }

        $this->runner->run(
            new LocalTarget,
            'find '.Shell::quote($this->root()).' -name "*.part" -mtime +1 -delete',
            timeout: 120,
        );

        return $expired->count();
    }

    /**
     * Stacks whose last successful backup is older than the policy allows.
     *
     * @return list<Stack>
     */
    public function stale(): array
    {
        $cutoff = now()->subHours((int) config('backup.stale_after_hours'));

        return Stack::query()
            ->where('status', 'active')
            ->get()
            ->filter(function (Stack $stack) use ($cutoff) {
                $last = Backup::query()
                    ->where('stack_id', $stack->id)
                    ->where('kind', Backup::KIND_DATABASE)
                    ->where('status', 'success')
                    ->latest('finished_at')
                    ->value('finished_at');

                return $last === null || $last->lt($cutoff);
            })
            ->values()
            ->all();
    }

    public function stackRoot(Stack $stack): string
    {
        return $this->root().'/'.$stack->client->slug.'/'.$stack->tier;
    }

    private function start(Stack $stack, string $kind, string $path, string $trigger): Backup
    {
        return Backup::create([
            'stack_id' => $stack->id,
            'kind' => $kind,
            'status' => 'running',
            'triggered_by' => $trigger,
            'path' => $path,
            'started_at' => now(),
            'retained_until' => now()->addDays((int) config('backup.retention.files_days')),
        ]);
    }

    private function finish(Backup $backup, CommandResult $result, string $path): Backup
    {
        if ($result->failed()) {
            $backup->update([
                'status' => 'failed',
                'error' => $result->failureReason(),
                'finished_at' => now(),
            ]);

            return $backup;
        }

        $stat = $this->runner->run(
            new LocalTarget,
            'test -e '.Shell::quote($path).' && du -sb '.Shell::quote($path).' | cut -f1',
            timeout: 120,
        );

        $checksum = is_file($path) ? hash_file('sha256', $path) : null;

        $backup->update([
            'status' => 'success',
            'size_bytes' => (int) trim($stat->stdout) ?: null,
            'checksum_sha256' => $checksum,
            'finished_at' => now(),
        ]);

        return $backup;
    }

    /**
     * @return Collection<int, StackService>
     */
    private function databaseServices(Stack $stack): Collection
    {
        return StackService::query()
            ->with('service')
            ->where('stack_id', $stack->id)
            ->where('enabled', true)
            ->get()
            ->filter(fn (StackService $s) => filled($s->service->database_name))
            ->values();
    }

    private function root(): string
    {
        return rtrim((string) config('backup.root'), '/');
    }
}
