<?php

namespace App\Jobs;

use App\Models\Server;
use App\Models\Task;
use App\Services\Remote\CommandRunner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * A quick action on a server (reload nginx, restart a container…), streamed
 * to a task. The command is always built by the control plane from a fixed
 * list, never taken from a request.
 */
class ServerCommandJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 1200;

    public int $tries = 1;

    public function __construct(
        public readonly int $serverId,
        public readonly int $taskId,
        public readonly string $command,
    ) {}

    public function handle(CommandRunner $runner): void
    {
        $server = Server::findOrFail($this->serverId);
        $task = Task::findOrFail($this->taskId);
        $target = $server->target();

        $task->start();
        $task->log('$ '.$this->command."\n");

        try {
            $result = $runner->run($target, $this->command, timeout: 1100, onOutput: $task->logger());
        } finally {
            $target->cleanup();
        }

        $result->successful()
            ? $task->succeed('Done.')
            : $task->fail($result->failureReason() ?? 'The command failed.');
    }

    public function failed(\Throwable $e): void
    {
        Task::find($this->taskId)?->fail('The job stopped unexpectedly: '.$e->getMessage());
    }
}
