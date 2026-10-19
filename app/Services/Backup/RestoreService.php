<?php

namespace App\Services\Backup;

use App\Models\Backup;
use App\Models\BackupRestore;
use App\Models\Stack;
use App\Models\StackService;
use App\Models\Task;
use App\Services\Remote\CommandRunner;
use App\Services\Stack\StackRenderer;
use App\Support\Shell;
use App\Targets\LocalTarget;
use Throwable;

/**
 * Puts a backup back. Always a person's decision, admin-only, recorded with
 * who and why (decision: restores are never automatic).
 *
 * A database restore first takes a fresh dump of what is there now, so a
 * restore of the wrong backup can itself be undone. A targeted pre-release
 * dump only replaces the tables it contains. Files are copied back without
 * deleting anything newer.
 */
class RestoreService
{
    public function __construct(
        private readonly CommandRunner $runner,
        private readonly BackupService $backups,
        private readonly StackRenderer $renderer,
    ) {}

    public function restore(BackupRestore $restore, Task $task): bool
    {
        $backup = $restore->backup()->with('stack.server', 'stack.client', 'service')->firstOrFail();
        $stack = $backup->stack;
        $target = $stack->server->target();

        $task->start();

        try {
            $failure = match ($backup->kind) {
                Backup::KIND_DATABASE => $this->database($backup, $stack, $target, $task),
                Backup::KIND_FILES => $this->files($backup, $stack, $target, $task),
                default => 'Only database and file backups of a stack can be restored here.',
            };
        } catch (Throwable $e) {
            report($e);
            $failure = 'Unexpected error: '.$e->getMessage();
        } finally {
            $target->cleanup();
        }

        $restore->update([
            'status' => $failure ? 'failed' : 'success',
            'error' => $failure,
            'finished_at' => now(),
        ]);

        $failure ? $task->fail($failure) : $task->succeed('Restored.');

        return $failure === null;
    }

    private function database(Backup $backup, Stack $stack, $target, Task $task): ?string
    {
        $database = $backup->service ? $stack->databaseFor($backup->service) : null;

        if ($database === null) {
            return 'The service this backup belongs to has no database on this stack any more.';
        }

        if ($failure = $this->verify($backup, $task)) {
            return $failure;
        }

        $entitlement = StackService::query()
            ->with('service')
            ->where('stack_id', $stack->id)
            ->where('service_id', $backup->service_id)
            ->first();

        $task->step("Backing up {$database} as it is now, before replacing it");

        $safety = $this->backups->tables($stack, $entitlement, $backup->tables ?? [], $target, null, $task->logger(), 'manual');

        if ($safety->status !== 'success') {
            return 'The safety backup failed, so nothing was restored: '.$safety->error;
        }

        $task->log("Safety backup: {$safety->path}\n");
        $task->step($backup->tables ? 'Restoring tables '.implode(', ', $backup->tables) : "Restoring {$database}");

        // Streamed from here into the shared MySQL; the root password stays in
        // that container's environment.
        $remote = 'docker exec -i '.Shell::quote((string) config('backup.mysql_container'))
            .' sh -c '.Shell::quote('exec mysql -uroot -p"$MYSQL_ROOT_PASSWORD" '.Shell::quote($database));

        $result = $this->runner->run(
            new LocalTarget,
            'set -o pipefail; gunzip -c '.Shell::quote($backup->path).' | '.Shell::join([...$target->prefix(), $remote]),
            timeout: 7200,
            onOutput: $task->logger(),
        );

        return $result->failed() ? 'The restore failed: '.$result->failureReason() : null;
    }

    private function files(Backup $backup, Stack $stack, $target, Task $task): ?string
    {
        $ssh = $target->prefix();
        $address = array_pop($ssh);
        $root = $this->renderer->rootPath($stack);

        $task->step('Copying files back to the server');

        $result = $this->runner->run(
            new LocalTarget,
            implode(' ', [
                // No --delete: files written since the backup stay.
                'rsync -az --numeric-ids',
                '-e', Shell::quote(Shell::join($ssh)),
                Shell::quote(rtrim($backup->path, '/').'/'),
                Shell::quote("{$address}:{$root}/storage/"),
            ]),
            timeout: 7200,
            onOutput: $task->logger(),
        );

        return $result->failed() ? 'Copying files failed: '.$result->failureReason() : null;
    }

    /** The file must still be there, intact, and be the one we recorded. */
    private function verify(Backup $backup, Task $task): ?string
    {
        $result = $this->runner->run(new LocalTarget, 'gzip -t '.Shell::quote($backup->path), timeout: 1800);

        if ($result->failed()) {
            return 'The backup file is missing or damaged: '.$result->failureReason();
        }

        if ($backup->checksum_sha256 && is_file($backup->path) && hash_file('sha256', $backup->path) !== $backup->checksum_sha256) {
            return 'The backup file does not match the checksum recorded when it was taken.';
        }

        $task->log("Backup file verified.\n");

        return null;
    }
}
