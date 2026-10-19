<?php

namespace App\Jobs;

use App\Models\Server;
use App\Services\Monitoring\MetricsCollector;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * One server's minute sample. Unique per server, so a slow or hanging
 * server never piles up samples behind itself.
 */
class CollectMetricsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 60;

    public int $tries = 1;

    public int $uniqueFor = 90;

    public function __construct(public readonly int $serverId) {}

    public function uniqueId(): string
    {
        return (string) $this->serverId;
    }

    public function handle(MetricsCollector $collector): void
    {
        $server = Server::find($this->serverId);

        if ($server !== null) {
            $collector->collect($server);
        }
    }
}
