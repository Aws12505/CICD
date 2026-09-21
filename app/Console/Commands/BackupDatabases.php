<?php

namespace App\Console\Commands;

use App\Jobs\RunBackupJob;
use App\Models\Stack;
use Illuminate\Console\Command;

class BackupDatabases extends Command
{
    protected $signature = 'backup:databases {--stack= : One stack id, otherwise every active stack}';

    protected $description = 'Dump every database on each active stack and pull it to the control plane';

    public function handle(): int
    {
        $stacks = $this->stacks();

        foreach ($stacks as $stack) {
            // Queued, so a large dump never blocks the scheduler loop.
            RunBackupJob::dispatch('database', $stack->id);
            $this->line("Queued database backup for {$stack->label()}.");
        }

        $this->info("Queued {$stacks->count()} database backup(s).");

        return self::SUCCESS;
    }

    private function stacks()
    {
        return Stack::query()
            ->with('client')
            ->when($this->option('stack'), fn ($q) => $q->whereKey($this->option('stack')))
            ->when(! $this->option('stack'), fn ($q) => $q->where('status', 'active'))
            ->get();
    }
}
