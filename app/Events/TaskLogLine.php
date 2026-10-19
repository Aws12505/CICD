<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

class TaskLogLine implements ShouldBroadcastNow
{
    public function __construct(
        public int $taskId,
        public string $line,
    ) {}

    public function broadcastOn(): Channel
    {
        return new PrivateChannel("task.{$this->taskId}");
    }

    public function broadcastAs(): string
    {
        return 'TaskLogLine';
    }

    /**
     * @return array<string, string>
     */
    public function broadcastWith(): array
    {
        return ['line' => $this->line];
    }
}
