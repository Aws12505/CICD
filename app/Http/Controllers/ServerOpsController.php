<?php

namespace App\Http\Controllers;

use App\Jobs\ServerCommandJob;
use App\Models\Build;
use App\Models\Server;
use App\Models\ServerMetric;
use App\Models\Task;
use App\Services\Monitoring\Fleet;
use App\Services\Monitoring\MetricsCollector;
use App\Services\Provision\InfraRenderer;
use App\Services\Remote\CommandRunner;
use App\Support\Shell;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Watching and nudging a server (decision 22, workflow 4): live metrics,
 * history, container logs and restarts, and a few quick actions.
 *
 * Admin-only, like everything else about servers. Every command run here is
 * built from a fixed list; a request only picks which.
 */
class ServerOpsController extends Controller
{
    /** action => [label, command] */
    public const ACTIONS = [
        'nginx-reload' => ['Test and reload nginx', 'nginx -t && systemctl reload nginx'],
        'certs-renew' => ['Renew certificates', 'certbot renew --no-random-sleep-on-renew'],
        'prune-images' => ['Remove images unused for a week', 'docker image prune -af --filter until=168h'],
        'restart-infra' => ['Restart MySQL, Redis and NATS', 'docker compose -f '.InfraRenderer::ROOT.'/docker-compose.yml restart'],
    ];

    public function __construct(
        private readonly MetricsCollector $collector,
        private readonly Fleet $fleet,
        private readonly CommandRunner $runner,
    ) {}

    /**
     * A fresh sample, for the server page's five-second refresh. Shared by
     * everyone looking at the same server for four seconds.
     */
    public function live(Server $server): JsonResponse
    {
        $metric = Cache::remember("server-live:{$server->id}", 4, fn () => $this->collector->collect($server)?->id);

        $metric = $metric ? ServerMetric::find($metric) : null;
        $server->refresh();

        return response()->json([
            'status' => $server->status,
            'sample' => $metric ? Fleet::sample($metric) : null,
            'containers' => $metric ? $this->containers($metric) : [],
            'alerts' => $this->fleet->alerts($server->load('stacks.client'), $metric),
            'certificates' => $server->certificates ?? [],
        ]);
    }

    /** History for the charts, at most ~300 points. */
    public function history(Request $request, Server $server): JsonResponse
    {
        $hours = match ($request->string('range')->toString()) {
            '1h' => 1,
            '7d' => 168,
            default => 24,
        };

        $rows = ServerMetric::query()
            ->where('server_id', $server->id)
            ->where('recorded_at', '>=', now()->subHours($hours))
            ->orderBy('recorded_at')
            ->get(['recorded_at', 'cpu_percent', 'mem_used', 'mem_total', 'disk_used', 'disk_total', 'load1', 'net_rx', 'net_tx']);

        $every = max(1, (int) ceil($rows->count() / 300));
        $points = [];
        $previous = null;

        foreach ($rows as $i => $row) {
            $seconds = $previous ? max(1, $row->recorded_at->diffInSeconds($previous->recorded_at, true)) : null;

            if ($i % $every === 0) {
                $points[] = [
                    't' => $row->recorded_at->toIso8601String(),
                    'cpu' => $row->cpu_percent,
                    'mem' => $row->memPercent(),
                    'disk' => $row->diskPercent(),
                    'load' => $row->load1,
                    // Counters reset on reboot; a negative rate means that.
                    'rx' => $seconds ? max(0, ($row->net_rx - $previous->net_rx) / $seconds) : 0,
                    'tx' => $seconds ? max(0, ($row->net_tx - $previous->net_tx) / $seconds) : 0,
                ];
            }

            $previous = $row;
        }

        return response()->json(['points' => $points]);
    }

    /** The last lines of a container's log. */
    public function logs(Request $request, Server $server, string $container): JsonResponse
    {
        $this->assertContainer($server, $container);
        $tail = min(2000, max(50, $request->integer('tail', 300)));
        $target = $server->target();

        try {
            $result = $this->runner->run(
                $target,
                'docker logs --timestamps --tail '.$tail.' '.Shell::quote($container).' 2>&1',
                timeout: 30,
            );
        } finally {
            $target->cleanup();
        }

        return response()->json([
            'ok' => $result->successful(),
            'log' => $result->successful() ? $result->stdout : $result->failureReason(),
        ]);
    }

    public function restartContainer(Request $request, Server $server, string $container): RedirectResponse
    {
        $this->assertContainer($server, $container);

        return $this->runAsTask($request, $server, "Restart {$container} on {$server->name}", 'docker restart '.Shell::quote($container));
    }

    public function action(Request $request, Server $server, string $action): RedirectResponse
    {
        abort_unless(isset(self::ACTIONS[$action]), 404);

        [$label, $command] = self::ACTIONS[$action];

        return $this->runAsTask($request, $server, "{$label} on {$server->name}", $command);
    }

    private function runAsTask(Request $request, Server $server, string $title, string $command): RedirectResponse
    {
        $task = Task::begin(Task::TYPE_COMMAND, $title, $server, $request->user());

        ServerCommandJob::dispatch($server->id, $task->id, $command);

        return redirect()->route('tasks.show', $task);
    }

    /**
     * Only containers the server reported in its latest sample, so a request
     * can never name something else.
     */
    private function assertContainer(Server $server, string $container): void
    {
        abort_unless((bool) preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$/', $container), 404);

        $latest = ServerMetric::query()->where('server_id', $server->id)->latest('recorded_at')->first();

        abort_unless(collect($latest?->containers ?? [])->pluck('name')->contains($container), 404);
    }

    /**
     * Containers with the build each one runs, matched by image digest.
     *
     * @return list<array<string, mixed>>
     */
    private function containers(ServerMetric $metric): array
    {
        $containers = $metric->containers ?? [];
        $digests = collect($containers)
            ->map(fn ($c) => str_contains($c['image'], '@') ? explode('@', $c['image'], 2)[1] : null)
            ->filter()
            ->unique()
            ->values();

        $builds = Build::query()->with('service')->whereIn('digest', $digests)->get()->keyBy('digest');

        return array_map(function (array $c) use ($builds) {
            $digest = str_contains($c['image'], '@') ? explode('@', $c['image'], 2)[1] : null;

            return $c + ['build' => $digest && $builds->has($digest) ? $builds[$digest]->label() : null];
        }, $containers);
    }
}
