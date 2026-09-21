<?php

namespace App\Console\Commands;

use App\Jobs\RunBackupJob;
use App\Models\Stack;
use Illuminate\Console\Command;

class BackupFiles extends Command
{
    protected $signature = 'backup:files {--stack= : One stack id, otherwise every active stack}';

    protected $description = 'Rsync each active stack\'s file volumes to the control plane';

    public function handle(): int
    {
        $stacks = Stack::query()
            ->with('client')
            ->when($this->option('stack'), fn ($q) => $q->whereKey($this->option('stack')))
            ->when(! $this->option('stack'), fn ($q) => $q->where('status', 'active'))
            ->get();

        foreach ($stacks as $stack) {
            RunBackupJob::dispatch('files', $stack->id);
            $this->line("Queued file backup for {$stack->label()}.");
        }

        $this->info("Queued {$stacks->count()} file backup(s).");

        return self::SUCCESS;
    }
}
