<?php

namespace App\Jobs;

use App\Models\Build;
use App\Models\Release;
use App\Models\ReleaseItem;
use App\Services\Build\ImageBuilder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;

class BuildJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 3700;

    public int $tries = 1;

    public function __construct(public readonly int $buildId) {}

    public function handle(ImageBuilder $builder): void
    {
        $build = Build::with('service', 'task')->findOrFail($this->buildId);

        // One checkout per service: two builds of the same repository wait
        // for each other rather than switching commits under one another.
        Cache::lock('build-workspace:'.$build->service->slug, 3700)
            ->block(3600, fn () => $builder->run($build, $build->task));

        self::continueRedeploys($build->refresh());
    }

    public function failed(\Throwable $e): void
    {
        $build = Build::with('task')->find($this->buildId);

        $build?->update(['status' => 'failed', 'error' => 'The job stopped unexpectedly: '.$e->getMessage()]);
        $build?->task?->fail('The job stopped unexpectedly: '.$e->getMessage());

        if ($build !== null) {
            self::continueRedeploys($build);
        }
    }

    /**
     * A "Rebuild & redeploy" waits for this build: start it now that the
     * image exists, or fail it with the build's reason.
     */
    public static function continueRedeploys(Build $build): void
    {
        $waiting = ReleaseItem::query()
            ->with('release.creator')
            ->where('build_id', $build->id)
            ->whereNull('to_digest')
            ->whereHas('release', fn ($q) => $q->where('kind', Release::KIND_REDEPLOY)->where('status', 'draft'))
            ->get();

        foreach ($waiting as $item) {
            if ($build->status === 'success' && filled($build->digest)) {
                $item->update(['to_digest' => $build->digest]);
                ReleaseJob::launch($item->release, $item->release->creator);
            } elseif ($build->status === 'failed') {
                $item->update(['status' => 'failed', 'error' => 'The rebuild failed: '.$build->error]);
                $item->release->update(['status' => 'failed', 'finished_at' => now()]);
            }
        }
    }
}
