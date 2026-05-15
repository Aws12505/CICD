<?php

namespace App\Events;

use App\Models\PipelineRun;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PipelineRunStatusChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public PipelineRun $run) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("pipeline.run.{$this->run->id}"),
            new PrivateChannel('pipeline.runs'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'PipelineRunStatusChanged';
    }

    public function broadcastWith(): array
    {
        return [
            'run' => [
                'id' => $this->run->id,
                'pipeline_id' => $this->run->pipeline_id,
                'status' => $this->run->status,
                'commit_sha' => $this->run->commit_sha,
                'commit_message' => $this->run->commit_message,
                'pusher' => $this->run->pusher,
                'triggered_by' => $this->run->triggered_by,
                'started_at' => optional($this->run->started_at)->toIso8601String(),
                'finished_at' => optional($this->run->finished_at)->toIso8601String(),
                'duration_ms' => $this->run->duration_ms,
            ],
            'steps' => $this->run->stepResults->map(fn ($step) => [
                'id' => $step->id,
                'step_index' => $step->step_index,
                'name' => $step->name,
                'status' => $step->status,
                'exit_code' => $step->exit_code,
                'duration_ms' => $step->duration_ms,
            ]),
        ];
    }
}
