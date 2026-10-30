<?php

use App\Models\Server;
use App\Models\ServerMetric;
use App\Models\Service;
use App\Models\Stack;
use App\Models\StackService;
use App\Services\Monitoring\Fleet;
use App\Services\Monitoring\MetricsCollector;
use App\Services\Provision\InfraRenderer;
use App\Services\Provision\MysqlSizing;
use App\Services\Provision\StackProvisioner;
use Database\Seeders\RoleSeeder;
use Database\Seeders\ServiceCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

/** A server with both tiers of one client running the given services. */
function serverRunning(array $services): Server
{
    $server = Server::factory()->verified()->create(['ssh_user' => 'root']);

    foreach ([Stack::TIER_LIVE, Stack::TIER_TESTING] as $tier) {
        $stack = Stack::factory()->create(['server_id' => $server->id, 'tier' => $tier, 'compose_project' => "acme_{$tier}"]);

        foreach ($services as $service) {
            StackService::create(['stack_id' => $stack->id, 'service_id' => $service->id, 'enabled' => true]);
        }
    }

    return $server->fresh();
}

test('the ceiling covers every web worker, process and scheduled burst on the server', function () {
    $hiring = Service::factory()->create([
        'slug' => 'hiring', 'database_name' => 'hiringpizza',
        'processes' => [['name' => 'queue', 'command' => 'php artisan queue:work'], ['name' => 'nats', 'command' => 'php artisan nats:consume']],
    ]);
    $data = Service::factory()->create([
        'slug' => 'data', 'database_name' => 'lc_pizza_data',
        'db_connections_per_process' => 4, 'db_scheduler_burst' => 10,
        'processes' => [['name' => 'scheduler', 'command' => 'php artisan schedule:work']],
    ]);
    // No database: opens no MySQL connections at all.
    $dashboard = Service::factory()->perStackBuild()->create(['slug' => 'dashboard', 'database_name' => null]);
    $server = serverRunning([$hiring, $data, $dashboard]);
    $sizing = app(MysqlSizing::class);

    // Per stack: hiring (150 + 2) × 1 = 152; data (150 + 1 + 10) × 4 = 644.
    $demand = collect($sizing->demand($server));
    expect($demand)->toHaveCount(4);
    expect($demand->where('service', $data->name)->first()['connections'])->toBe(644);
    expect($demand->sum('connections'))->toBe(2 * (152 + 644));

    // A quarter on top and a reserve: never just enough.
    expect($sizing->ceiling($server))->toBe((int) ceil(2 * (152 + 644) * 1.25) + 50);
});

test('a stack that sets fewer Apache workers needs fewer connections', function () {
    $hiring = Service::factory()->create(['slug' => 'hiring', 'database_name' => 'hiringpizza', 'processes' => []]);
    $server = serverRunning([$hiring]);
    $before = app(MysqlSizing::class)->ceiling($server);

    $hiring->envDefinitions()->create(['key' => 'APACHE_MAX_WORKERS', 'source' => 'literal', 'default_value' => '400']);

    expect(app(MysqlSizing::class)->ceiling($server))->toBeGreaterThan($before);
});

test('an empty server still gets more than MySQL\'s stock 151', function () {
    expect(app(MysqlSizing::class)->ceiling(Server::factory()->create()))->toBe(MysqlSizing::MINIMUM);
});

test('every stack preparation applies the ceiling without restarting MySQL', function () {
    $hiring = Service::factory()->create(['slug' => 'hiring', 'database_name' => 'hiringpizza', 'processes' => []]);
    $server = serverRunning([$hiring]);
    $runner = new RecordingRunner;

    (new StackProvisioner($runner))->prepare($server->stacks->first(), new NullTarget, fn () => null);

    $ceiling = app(MysqlSizing::class)->ceiling($server);
    expect($runner->transcript())->toContain("SET PERSIST max_connections = {$ceiling};");
});

test('MySQL is configured for many short connections, sized to the server', function () {
    $server = Server::factory()->create();
    // 8 GiB of RAM.
    $files = (new InfraRenderer)->files($server, 8 * 1024 * 1024, 1234);

    $cnf = $files['/srv/_infra/mysql.cnf'][0];
    expect($files['/srv/_infra/mysql.cnf'][1])->toBe('644');
    expect($cnf)
        ->toContain('max_connections = 1234')
        ->toContain('wait_timeout = 600')
        ->toContain('thread_cache_size = 256')
        ->toContain('innodb_buffer_pool_size = 3200M');

    $compose = $files['/srv/_infra/docker-compose.yml'][0];
    expect($compose)
        ->toContain('/srv/_infra/mysql.cnf:/etc/mysql/conf.d/control-plane.cnf:ro')
        ->toContain('nofile: {soft: 65535, hard: 65535}');
});

test('the catalog gives Data its own default connection and its real connection count', function () {
    (new ServiceCatalogSeeder)->run();
    $data = Service::where('slug', 'data')->first();

    expect($data->db_connections_per_process)->toBe(4);
    expect($data->envDefinitions()->where('key', 'DB_CONNECTION')->value('default_value'))->toBe('operational');
    // The others keep the common value.
    expect(Service::where('slug', 'hiring')->first()->envDefinitions()->where('key', 'DB_CONNECTION')->value('default_value'))->toBe('mysql');
    // Queue workers recycle hourly.
    expect(collect(Service::where('slug', 'hiring')->first()->processes)->firstWhere('name', 'queue')['command'])->toContain('--max-time=3600');
});

test('the metrics sample reads MySQL connection counts', function () {
    $sample = MetricsCollector::parse(implode("\n", [
        'mysql Aborted_connects 3',
        'mysql Max_used_connections 412',
        'mysql Threads_connected 380',
        'mysql Threads_running 7',
        'mysql max_connections 500',
    ]));

    expect($sample['mysql'])->toBe(['aborted' => 3, 'max_used' => 412, 'connected' => 380, 'running' => 7, 'max_connections' => 500]);
    expect(Str::contains(MetricsCollector::SCRIPT, 'Threads_connected'))->toBeTrue();
});

test('connections near the ceiling raise an alert long before anyone is refused', function () {
    $server = Server::factory()->verified()->create(['last_seen_at' => now()]);
    $metric = ServerMetric::create([
        'server_id' => $server->id, 'recorded_at' => now(),
        'cpu_percent' => 1, 'load1' => 0, 'load5' => 0, 'load15' => 0, 'mem_total' => 100, 'mem_used' => 10,
        'swap_total' => 0, 'swap_used' => 0, 'disk_total' => 100, 'disk_used' => 10, 'net_rx' => 0, 'net_tx' => 0,
        'uptime_seconds' => 1, 'containers' => [], 'mysql' => ['connected' => 410, 'max_connections' => 500],
    ]);

    $alerts = collect(app(Fleet::class)->alerts($server, $metric))->pluck('message');

    expect($alerts->implode(' '))->toContain('MySQL connections at 82%');
});
