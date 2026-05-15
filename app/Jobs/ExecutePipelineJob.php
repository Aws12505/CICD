<?php

namespace App\Jobs;

use App\Models\PipelineRun;
use App\Services\PipelineExecutorService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ExecutePipelineJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600;

    public int $tries = 1;

    public function __construct(public int $runId) {}

    public function handle(PipelineExecutorService $executor): void
    {
        $run = PipelineRun::with('pipeline.project')->findOrFail($this->runId);

        $executor->execute($run);
    }

    public function failed(Throwable $exception): void
    {
        PipelineRun::where('id', $this->runId)->update([
            'status' => 'failed',
            'finished_at' => now(),
        ]);
    }
}
