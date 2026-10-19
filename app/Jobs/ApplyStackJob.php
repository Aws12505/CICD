<?php

namespace App\Jobs;

use App\Models\Stack;
use App\Models\Task;
use App\Services\Stack\StackApplier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

class ApplyStackJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 900;

    public int $tries = 1;

    public function __construct(
        public readonly int $stackId,
        public readonly int $taskId,
    ) {}

    public function handle(StackApplier $applier): void
    {
        $applier->apply(Stack::findOrFail($this->stackId), Task::findOrFail($this->taskId));
    }

    public function failed(\Throwable $e): void
    {
        Task::find($this->taskId)?->fail('The job stopped unexpectedly: '.$e->getMessage());
    }
}
