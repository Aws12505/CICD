<?php

namespace App\Jobs;

use App\Models\Release;
use App\Models\Task;
use App\Models\User;
use App\Services\Release\ReleasePlanner;
use App\Services\Release\ReleaseRunner;
use App\Services\Stack\EnvResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;

class ReleaseJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 7200;

    public int $tries = 1;

    public function __construct(public readonly int $releaseId) {}

    /**
     * Queue a release and the task its log streams to.
     */
    public static function launch(Release $release, ?User $user = null): Task
    {
        $release->loadMissing('stack.client');

        $task = Task::begin(
            Task::TYPE_RELEASE,
            ucfirst($release->kind)." release #{$release->id} to {$release->stack->label()}",
            $release,
            $user,
        );

        $release->update(['task_id' => $task->id]);

        self::dispatch($release->id);

        return $task;
    }

    public function handle(ReleaseRunner $runner, ReleasePlanner $planner, EnvResolver $env): void
    {
        $release = Release::with('stack.server', 'stack.client', 'task')->findOrFail($this->releaseId);
        $task = $release->task;

        // One release at a time per stack: two would race on the same
        // compose project.
        Cache::lock('release-stack:'.$release->stack_id, 7300)->block(7200, function () use ($runner, $planner, $env, $release, $task) {
            $task->start();
            $task->mask(...$env->secretsFor($release->stack));

            $runner->run($release, null, $task->logger());
            $release->refresh();

            if ($release->status !== 'succeeded') {
                $task->fail('The release failed. Items that finished stay up and can be rolled back one by one.');

                return;
            }

            // An emergency skipped testing, so testing is now behind. The
            // catch-up is planned, not run: nothing deploys without a click.
            if ($release->isEmergency() && ($followUp = $planner->emergencyFollowUp($release->load('items.service', 'items.build'), $release->creator))) {
                $task->log("\nPlanned release #{$followUp->id} to bring testing level with live.\n");
            }

            $task->succeed("Release #{$release->id} is live on {$release->stack->label()}.");
        });
    }

    public function failed(\Throwable $e): void
    {
        $release = Release::with('task')->find($this->releaseId);

        if ($release && ! in_array($release->status, ['succeeded', 'failed'], true)) {
            $release->update(['status' => 'failed', 'finished_at' => now()]);
        }

        $release?->task?->fail('The job stopped unexpectedly: '.$e->getMessage());
    }
}
