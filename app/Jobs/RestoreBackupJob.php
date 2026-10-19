<?php

namespace App\Jobs;

use App\Models\BackupRestore;
use App\Models\Task;
use App\Services\Backup\RestoreService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

class RestoreBackupJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 7500;

    public int $tries = 1;

    public function __construct(
        public readonly int $restoreId,
        public readonly int $taskId,
    ) {}

    public function handle(RestoreService $restores): void
    {
        $restores->restore(BackupRestore::findOrFail($this->restoreId), Task::findOrFail($this->taskId));
    }

    public function failed(\Throwable $e): void
    {
        BackupRestore::find($this->restoreId)?->update(['status' => 'failed', 'error' => $e->getMessage(), 'finished_at' => now()]);
        Task::find($this->taskId)?->fail('The job stopped unexpectedly: '.$e->getMessage());
    }
}
