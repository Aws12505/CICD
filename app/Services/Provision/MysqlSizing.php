<?php

namespace App\Services\Provision;

use App\Models\Server;
use App\Models\StackService;
use App\Services\Stack\EnvResolver;
use App\Support\Shell;

/**
 * How many connections MySQL must accept on a server, worked out from what
 * is deployed there.
 *
 * One MySQL per server serves every stack, and no project is ever capped:
 * the ceiling is set above the most the deployed services can ask for at
 * once, and recomputed whenever that changes. A connection only costs
 * memory while it is in use, so a ceiling sized to the worst case is cheap;
 * one left at MySQL's stock 151 is what refused every service at once.
 *
 * Per service, per stack:
 *
 *   web       Apache workers (APACHE_MAX_WORKERS, the image default if unset)
 *   process   one per process container (queue, scheduler, NATS consumer...)
 *   burst     scheduled tasks that run side by side in their own processes
 *
 * each times the connections one process of that service holds, then a
 * quarter on top and a fixed reserve for admin sessions, backups and the
 * database viewer.
 */
class MysqlSizing
{
    /** Apache's MaxRequestWorkers in the control plane's Laravel image. */
    public const DEFAULT_WEB_WORKERS = 150;

    public const HEADROOM = 1.25;

    public const RESERVE = 50;

    /** Never below MySQL's own default, rounded up. */
    public const MINIMUM = 300;

    public function __construct(private readonly EnvResolver $env) {}

    /**
     * @return list<array{stack: string, service: string, web: int, processes: int, burst: int, per_process: int, connections: int}>
     */
    public function demand(Server $server): array
    {
        $rows = [];

        $entitlements = StackService::query()
            ->with('service.envDefinitions', 'stack.client', 'stack.server')
            ->whereHas('stack', fn ($q) => $q->where('server_id', $server->id))
            ->where('enabled', true)
            ->get();

        foreach ($entitlements as $entitlement) {
            $service = $entitlement->service;

            if (blank($service->database_name)) {
                continue;
            }

            $workers = (int) ($this->env->resolve($entitlement)['APACHE_MAX_WORKERS'] ?? 0) ?: self::DEFAULT_WEB_WORKERS;
            $processes = count($service->processes ?? []);
            $burst = (int) $service->db_scheduler_burst;
            $perProcess = max(1, (int) $service->db_connections_per_process);

            $rows[] = [
                'stack' => $entitlement->stack->label(),
                'service' => $service->name,
                'web' => $workers,
                'processes' => $processes,
                'burst' => $burst,
                'per_process' => $perProcess,
                'connections' => ($workers + $processes + $burst) * $perProcess,
            ];
        }

        return $rows;
    }

    public function ceiling(Server $server): int
    {
        $total = array_sum(array_column($this->demand($server), 'connections'));

        return max(self::MINIMUM, (int) ceil($total * self::HEADROOM) + self::RESERVE);
    }

    /**
     * Raise or lower it without a restart. SET PERSIST also writes it to
     * mysqld-auto.cnf, so a restart keeps it.
     */
    public function applyScript(int $ceiling): string
    {
        $sql = "SET PERSIST max_connections = {$ceiling};\n";

        return "printf '%s' '".base64_encode($sql)."' | base64 -d"
            .' | docker exec -i mysql sh -c '.Shell::quote('exec mysql -uroot -p"$MYSQL_ROOT_PASSWORD"');
    }
}
