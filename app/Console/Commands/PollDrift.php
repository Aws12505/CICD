<?php

namespace App\Console\Commands;

use App\Models\Stack;
use App\Services\Drift\DriftPoller;
use Illuminate\Console\Command;

class PollDrift extends Command
{
    protected $signature = 'stacks:poll-drift {--stack= : One stack id, otherwise every active stack}';

    protected $description = 'Compare what each server is running against what the control plane intends';

    public function handle(DriftPoller $poller): int
    {
        $stacks = Stack::query()
            ->with('server', 'client')
            ->when($this->option('stack'), fn ($q) => $q->whereKey($this->option('stack')))
            ->when(! $this->option('stack'), fn ($q) => $q->where('status', 'active'))
            ->get();

        $total = 0;

        foreach ($stacks as $stack) {
            $observations = $poller->poll($stack);
            $total += count($observations);

            foreach ($observations as $observation) {
                $this->warn(sprintf(
                    '%s · %s is running %s, expected %s',
                    $stack->label(),
                    $observation->service->slug,
                    $observation->observed_digest ?? 'nothing',
                    $observation->expected_digest,
                ));
            }
        }

        $total === 0
            ? $this->info('Everything matches.')
            : $this->warn("{$total} service(s) differ from what the control plane intends.");

        return self::SUCCESS;
    }
}
