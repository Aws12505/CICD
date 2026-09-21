<?php

namespace App\Console\Commands;

use App\Jobs\RunBackupJob;
use Illuminate\Console\Command;

class BackupControlPlane extends Command
{
    protected $signature = 'backup:control-plane';

    protected $description = 'Back up the control plane\'s own database';

    public function handle(): int
    {
        RunBackupJob::dispatch('control_plane');

        // Worth saying out loud every time it runs: this lands on the machine
        // it protects. Losing that machine loses every client's config,
        // secrets and release history while every client server keeps running.
        $this->warn('Queued. Note this backup currently lives on the control plane itself.');

        return self::SUCCESS;
    }
}
