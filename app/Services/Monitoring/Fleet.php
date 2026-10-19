<?php

namespace App\Services\Monitoring;

use App\Models\Backup;
use App\Models\DriftObservation;
use App\Models\Server;
use App\Models\ServerMetric;
use App\Models\Stack;
use Carbon\CarbonImmutable;

/**
 * The fleet at a glance, and what is wrong with it.
 *
 * Alerts are derived on read from the latest sample and the stacks' state,
 * so there is nothing to keep in sync: server unreachable, disk over 85 %,
 * memory over 90 %, a stale backup, open drift, a certificate expiring
 * within 14 days.
 */
class Fleet
{
    public const DISK_ALERT = 85;

    public const MEMORY_ALERT = 90;

    public const CERT_DAYS = 14;

    /**
     * @return list<array<string, mixed>>
     */
    public function servers(): array
    {
        return Server::query()
            ->with('stacks.client')
            ->orderBy('name')
            ->get()
            ->map(fn (Server $server) => $this->summary($server))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(Server $server): array
    {
        $latest = ServerMetric::query()->where('server_id', $server->id)->latest('recorded_at')->first();

        return [
            'id' => $server->id,
            'name' => $server->name,
            'hostname' => $server->hostname,
            'status' => $server->status,
            'provisioned' => $server->isProvisioned(),
            'last_seen_at' => $server->last_seen_at?->toIso8601String(),
            'sample' => $latest ? self::sample($latest) : null,
            'stacks' => $server->stacks->map(fn (Stack $s) => $s->label())->values(),
            'alerts' => $this->alerts($server, $latest),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function sample(ServerMetric $m): array
    {
        return [
            'recorded_at' => $m->recorded_at->toIso8601String(),
            'cpu' => $m->cpu_percent,
            'mem' => $m->memPercent(),
            'mem_used' => $m->mem_used,
            'mem_total' => $m->mem_total,
            'disk' => $m->diskPercent(),
            'disk_used' => $m->disk_used,
            'disk_total' => $m->disk_total,
            'load' => [$m->load1, $m->load5, $m->load15],
            'uptime_seconds' => $m->uptime_seconds,
            'containers_total' => count($m->containers ?? []),
            'containers_down' => collect($m->containers ?? [])->where('running', false)->count(),
        ];
    }

    /**
     * @return list<array{level: string, message: string}>
     */
    public function alerts(Server $server, ?ServerMetric $latest): array
    {
        $alerts = [];

        if ($server->status === 'unreachable') {
            $alerts[] = ['level' => 'critical', 'message' => 'Unreachable: '.($server->last_error ?? 'no answer')];
        } elseif ($server->isProvisioned() && ($latest === null || $latest->recorded_at->lt(now()->subMinutes(5)))) {
            $alerts[] = ['level' => 'warning', 'message' => 'No metrics for over five minutes'];
        }

        if ($latest) {
            if ($latest->diskPercent() > self::DISK_ALERT) {
                $alerts[] = ['level' => 'critical', 'message' => "Disk {$latest->diskPercent()}% full"];
            }

            if ($latest->memPercent() > self::MEMORY_ALERT) {
                $alerts[] = ['level' => 'critical', 'message' => "Memory {$latest->memPercent()}% used"];
            }

            foreach ($latest->containers ?? [] as $container) {
                if (! $container['running'] && ! str_contains((string) $container['status'], 'Exited (0)')) {
                    $alerts[] = ['level' => 'critical', 'message' => "Container {$container['name']} is {$container['state']}"];
                }
            }
        }

        foreach ($server->certificates ?? [] as $certificate) {
            $expires = CarbonImmutable::parse($certificate['expires_at']);

            if ($expires->lt(now()->addDays(self::CERT_DAYS))) {
                $alerts[] = ['level' => 'warning', 'message' => "Certificate for {$certificate['domain']} expires ".$expires->diffForHumans()];
            }
        }

        foreach ($server->stacks->where('status', 'active') as $stack) {
            $lastBackup = Backup::query()
                ->where('stack_id', $stack->id)
                ->where('kind', 'database')
                ->where('status', 'success')
                ->latest('finished_at')
                ->value('finished_at');

            if ($lastBackup === null || CarbonImmutable::parse($lastBackup)->lt(now()->subHours(26))) {
                $alerts[] = ['level' => 'warning', 'message' => "{$stack->label()}: no database backup in the last day"];
            }

            $drift = DriftObservation::query()->where('stack_id', $stack->id)->whereNull('resolution')->count();

            if ($drift > 0) {
                $alerts[] = ['level' => 'warning', 'message' => "{$stack->label()}: {$drift} service(s) drifted"];
            }
        }

        return $alerts;
    }
}
