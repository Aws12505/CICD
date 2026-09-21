<?php

namespace App\Console\Commands;

use App\Services\Backup\BackupService;
use Illuminate\Console\Command;

class BackupPrune extends Command
{
    protected $signature = 'backup:prune';

    protected $description = 'Delete expired backups and clean up interrupted ones';

    public function handle(BackupService $backups): int
    {
        $removed = $backups->prune();
        $this->info("Removed {$removed} expired backup(s).");

        foreach ($backups->stale() as $stack) {
            // Silent failure is the normal way a backup system fails, so a
            // stale stack is reported rather than left to be discovered
            // during a restore.
            $this->warn("No recent successful backup for {$stack->label()}.");
        }

        return self::SUCCESS;
    }
}
