<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PipelineLogLine implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $runId,
        public string $line,
    ) {}

    public function broadcastOn(): Channel
    {
        return new PrivateChannel("pipeline.run.{$this->runId}");
    }

    public function broadcastAs(): string
    {
        return 'PipelineLogLine';
    }

    public function broadcastWith(): array
    {
        return [
            'run_id' => $this->runId,
            'line' => $this->line,
        ];
    }
}
