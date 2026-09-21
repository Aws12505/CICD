<?php

namespace App\Jobs;

use App\Models\Stack;
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
 */
class RunBackupJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 7200;

    public int $tries = 1;

    public function __construct(
        public readonly string $kind,
        public readonly ?int $stackId = null,
    ) {}

    public function handle(BackupService $backups): void
    {
        if ($this->kind === 'control_plane') {
            $backups->controlPlane();

            return;
        }

        $stack = Stack::with('server', 'client')->find($this->stackId);

        if ($stack === null) {
            return;
        }

        match ($this->kind) {
            'database' => $backups->databases($stack),
            'files' => $backups->files($stack),
            default => null,
        };
    }
}
