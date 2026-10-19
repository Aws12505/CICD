<?php

namespace App\Console\Commands;

use App\Jobs\ReleaseJob;
use App\Models\Release;
use Illuminate\Console\Command;

/**
 * Starts releases whose agreed maintenance window has arrived.
 *
 * A destructive release is scheduled, not run, when it is planned; this is
 * what runs it at the time the client agreed to.
 */
class RunDueReleases extends Command
{
    protected $signature = 'releases:run-due';

    protected $description = 'Start scheduled releases whose window has opened';

    public function handle(): int
    {
        $due = Release::query()
            ->where('status', 'scheduled')
            ->whereNull('task_id')
            ->where('scheduled_for', '<=', now())
            ->get();

        foreach ($due as $release) {
            ReleaseJob::launch($release, $release->creator);
            $this->info("Started release #{$release->id}.");
        }

        return self::SUCCESS;
    }
}
