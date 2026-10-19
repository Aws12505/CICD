<?php

namespace App\Jobs;

use App\Models\Build;
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
    }

    public function failed(\Throwable $e): void
    {
        $build = Build::with('task')->find($this->buildId);

        $build?->update(['status' => 'failed', 'error' => 'The job stopped unexpectedly: '.$e->getMessage()]);
        $build?->task?->fail('The job stopped unexpectedly: '.$e->getMessage());
    }
}
