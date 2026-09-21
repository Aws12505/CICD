<?php

namespace App\Services\Backup;

use App\Contracts\Target;
use App\Models\Backup;
use App\Models\Stack;
use App\Models\StackService;
use App\Services\Remote\CommandResult;
use App\Services\Remote\CommandRunner;
use App\Services\Stack\StackRenderer;
use App\Targets\LocalTarget;
use Illuminate\Support\Collection;

/**
 * Takes backups from client servers and pulls them to the control plane.
 *
 * Databases nightly, file volumes weekly. The asymmetry is deliberate: a
 * database dump is megabytes and a media volume is not, and a weekly transfer
 * of the latter is the difference between a backup policy that runs and one
 * that gets turned off for using too much disk.
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
     */
    public function databases(Stack $stack, ?Target $target = null, string $trigger = 'schedule'): array
    {
        $target ??= $stack->server->target();
        $results = [];

        foreach ($this->databaseServices($stack) as $entitlement) {
            $database = $stack->databaseFor($entitlement->service);

            if ($database === null) {
                continue;
            }

            $results[] = $this->dumpDatabase($stack, $entitlement, $database, $target, $trigger);
        }

        $target->cleanup();

        return $results;
    }

    /**
     * Dump a specific set of tables — used before a destructive migration.
     */
    public function tables(Stack $stack, StackService $entitlement, array $tables, ?Target $target = null, ?int $releaseId = null): Backup
    {
        return $this->dumpDatabase(
            $stack,
            $entitlement,
            $stack->databaseFor($entitlement->service) ?? '',
            $target ?? $stack->server->target(),
            'pre_release',
            $tables,
            $releaseId,
        );
    }

    /**
     * Mirror a stack's file volumes with rsync.
     *
     * --backup-dir is what makes this a backup rather than a mirror: a plain
     * --delete mirror has no history, so a file deleted on Monday is gone
     * from Sunday's copy too.
     */
    public function files(Stack $stack, ?Target $target = null, string $trigger = 'schedule'): Backup
    {
        $root = $this->renderer->rootPath($stack);
        $destination = $this->stackRoot($stack).'/files';
        $deleted = $this->stackRoot($stack).'/files-deleted/'.now()->format('Y-m-d');

        $backup = $this->start($stack, Backup::KIND_FILES, $destination, $trigger);

        $server = $stack->server;

        // rsync runs on the control plane and reaches out, so the client
        // server needs no credentials of ours beyond the one public key.
        $command = sprintf(
            'mkdir -p %s %s && rsync -az --numeric-ids --delete --backup --backup-dir=%s -e %s %s %s',
            escapeshellarg($destination),
            escapeshellarg($deleted),
            escapeshellarg($deleted),
            escapeshellarg($this->sshCommandFor($stack)),
            escapeshellarg("{$server->ssh_user}@{$server->hostname}:{$root}/storage/"),
            escapeshellarg($destination.'/'),
        );

        $result = $this->runner->run(new LocalTarget, $command, timeout: 7200);

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

        $command = sprintf(
            'set -o pipefail; mkdir -p %s && mysqldump --host=%s --port=%s --user=%s --single-transaction --quick --routines --triggers --hex-blob %s | gzip -9 > %s',
            escapeshellarg(dirname($path)),
            escapeshellarg((string) config('backup.mysql.host')),
            escapeshellarg((string) config('backup.mysql.port')),
            escapeshellarg((string) config('backup.mysql.username')),
            escapeshellarg((string) config('database.connections.mysql.database')),
            escapeshellarg($path),
        );

        $result = $this->runner->run(
            new LocalTarget,
            $command,
            env: ['MYSQL_PWD' => (string) config('backup.mysql.password')],
            timeout: 3600,
        );

        return $this->finish($backup, $result, $path);
    }

    private function dumpDatabase(
        Stack $stack,
        StackService $entitlement,
        string $database,
        Target $target,
        string $trigger,
        array $tables = [],
        ?int $releaseId = null,
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

        $remote = 'set -o pipefail; docker exec '
            .escapeshellarg((string) config('backup.mysql_container'))
            .' sh -c '
            .escapeshellarg('exec mysqldump --single-transaction --quick --routines --triggers --hex-blob '
                .$database.($tables === [] ? '' : ' '.implode(' ', $tables)));

        // The dump is streamed over the SSH connection and gzipped here, so
        // nothing lands on the client's disk at all.
        $command = sprintf(
            'set -o pipefail; mkdir -p %s && %s | gzip -9 > %s.part && gzip -t %s.part && mv %s.part %s',
            escapeshellarg(dirname($path)),
            $this->sshPipe($stack, $remote),
            escapeshellarg($path),
            escapeshellarg($path),
            escapeshellarg($path),
            escapeshellarg($path),
        );

        $result = $this->runner->run(new LocalTarget, $command, timeout: 7200);

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
                'rm -f '.escapeshellarg($backup->path).' '.escapeshellarg($backup->path.'.part'),
                timeout: 60,
            );

            $backup->delete();
        }

        $this->runner->run(
            new LocalTarget,
            'find '.escapeshellarg($this->root()).' -name "*.part" -mtime +1 -delete',
            timeout: 120,
        );

        return $expired->count();
    }

    /** Stacks whose last successful backup is older than the policy allows. */
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
            'test -e '.escapeshellarg($path).' && du -sb '.escapeshellarg($path).' | cut -f1',
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
    private function databaseServices(Stack $stack)
    {
        return StackService::query()
            ->with('service')
            ->where('stack_id', $stack->id)
            ->where('enabled', true)
            ->get()
            ->filter(fn (StackService $s) => filled($s->service->database_name))
            ->values();
    }

    private function sshCommandFor(Stack $stack): string
    {
        return 'ssh -p '.$stack->server->ssh_port.' -o BatchMode=yes -o StrictHostKeyChecking=yes';
    }

    private function sshPipe(Stack $stack, string $remoteCommand): string
    {
        $server = $stack->server;

        return 'ssh -p '.$server->ssh_port
            .' -o BatchMode=yes '
            .escapeshellarg($server->ssh_user.'@'.$server->hostname).' '
            .escapeshellarg($remoteCommand);
    }

    private function stackRoot(Stack $stack): string
    {
        return $this->root().'/'.$stack->client->slug.'/'.$stack->tier;
    }

    private function root(): string
    {
        return rtrim((string) config('backup.root'), '/');
    }
}
