<?php

namespace App\Console\Commands;

use App\Jobs\CollectMetricsJob;
use App\Models\Server;
use App\Models\ServerMetric;
use Illuminate\Console\Command;

/**
 * Every minute: queue a sample of every verified server, and drop samples
 * older than seven days.
 */
class CollectMetrics extends Command
{
    protected $signature = 'servers:collect-metrics';

    protected $description = 'Sample CPU, memory, disk and containers on every server';

    public function handle(): int
    {
        Server::query()
            ->whereNotNull('host_key')
            ->pluck('id')
            ->each(fn (int $id) => CollectMetricsJob::dispatch($id));

        ServerMetric::query()->where('recorded_at', '<', now()->subDays(7))->delete();

        return self::SUCCESS;
    }
}
