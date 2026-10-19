<?php

namespace App\Jobs;

use App\Models\Backup;
use App\Models\Stack;
use App\Models\Task;
use App\Services\Backup\BackupService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Backups run on the queue, not inside the scheduler.
 *
 * The old command ran synchronously from schedule:run, so a multi-gigabyte
 * dump blocked the worker's sixty-second loop for its entire duration and
 * every other scheduled task waited behind it.
 *
 * "Back up now" passes a task, and the run streams to it.
 */
class RunBackupJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 7200;

    public int $tries = 1;

    public function __construct(
        public readonly string $kind,
        public readonly ?int $stackId = null,
        public readonly ?int $taskId = null,
    ) {}

    public function handle(BackupService $backups): void
    {
        $task = $this->taskId ? Task::find($this->taskId) : null;
        $task?->start();

        if ($this->kind === 'control_plane') {
            $backups->controlPlane();
            $task?->succeed('Control plane backed up.');

            return;
        }

        $stack = Stack::with('server', 'client')->find($this->stackId);

        if ($stack === null) {
            $task?->fail('The stack no longer exists.');

            return;
        }

        $trigger = $task ? 'manual' : 'schedule';
        $log = $task?->logger();

        $results = match ($this->kind) {
            'database' => $backups->databases($stack, null, $trigger, $log),
            'files' => [$backups->files($stack, null, $trigger, $log)],
            default => [],
        };

        if ($task === null) {
            return;
        }

        $failed = collect($results)->filter(fn (Backup $b) => $b->status !== 'success');

        $failed->isEmpty()
            ? $task->succeed(count($results).' backup(s) taken.')
            : $task->fail($failed->map(fn (Backup $b) => basename($b->path).': '.$b->error)->implode('; '));
    }

    public function failed(\Throwable $e): void
    {
        if ($this->taskId) {
            Task::find($this->taskId)?->fail('The job stopped unexpectedly: '.$e->getMessage());
        }
    }
}
