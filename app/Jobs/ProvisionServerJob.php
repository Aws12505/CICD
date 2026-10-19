<?php

namespace App\Jobs;

use App\Models\Server;
use App\Models\Task;
use App\Services\Provision\ServerProvisioner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

class ProvisionServerJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 3600;

    public int $tries = 1;

    public function __construct(
        public readonly int $serverId,
        public readonly int $taskId,
    ) {}

    public function handle(ServerProvisioner $provisioner): void
    {
        $provisioner->provision(
            Server::with('stacks.client')->findOrFail($this->serverId),
            Task::findOrFail($this->taskId),
        );
    }

    public function failed(\Throwable $e): void
    {
        Task::find($this->taskId)?->fail('The job stopped unexpectedly: '.$e->getMessage());
    }
}
