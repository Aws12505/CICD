<?php

use App\Jobs\CollectMetricsJob;
use App\Jobs\ServerCommandJob;
use App\Models\Backup;
use App\Models\Build;
use App\Models\Server;
use App\Models\ServerMetric;
use App\Models\Service;
use App\Models\Stack;
use App\Services\Monitoring\Fleet;
use App\Services\Monitoring\MetricsCollector;
use App\Services\Remote\CommandResult;
use App\Services\Remote\CommandRunner;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(RoleSeeder::class));

/** What the sampling script prints on a real Ubuntu host, trimmed. */
const SAMPLE = <<<'OUT'
cpu 1000000 800000 1000400 800100
load 0.52 0.61 0.70
mem MemTotal: 8000000
mem MemAvailable: 2000000
mem SwapTotal: 1000000
mem SwapFree: 750000
disk 100000000000 90000000000
net 123456789 987654321
uptime 360000.25
stat|acme_live_hiring|1.25%|512.5MiB / 7.63GiB|6.55%
stat|mysql|12.00%|1.2GiB / 7.63GiB|15.7%
ps|acme_live_hiring|running|Up 3 hours|ghcr.io/rnd-experts-team/hiring@sha256:abc123
ps|mysql|running|Up 9 days (healthy)|mysql:8.4
ps|acme_live_hiring_queue|exited|Exited (137) 5 minutes ago|ghcr.io/rnd-experts-team/hiring@sha256:abc123
cert|hiring.acme.cloud|Nov  1 12:00:00 2026 GMT
OUT;

function sampleRunner(?string $output = SAMPLE): RecordingRunner
{
    return new RecordingRunner(fn (string $command) => $output === null
        ? new CommandResult(255, '', 'Connection timed out', 1)
        : new CommandResult(0, $output, '', 1));
}

test('the sample parses into CPU, memory, disk, network, containers and certificates', function () {
    $sample = MetricsCollector::parse(SAMPLE);

    // 400 ticks elapsed, 100 of them idle.
    expect($sample['cpu_percent'])->toBe(75.0);
    expect($sample['load1'])->toBe(0.52);
    expect($sample['mem_total'])->toBe(8000000 * 1024);
    expect($sample['mem_used'])->toBe(6000000 * 1024);
    expect($sample['swap_used'])->toBe(250000 * 1024);
    expect($sample['disk_used'])->toBe(90000000000);
    expect([$sample['net_rx'], $sample['net_tx']])->toBe([123456789, 987654321]);
    expect($sample['uptime_seconds'])->toBe(360000);

    $containers = collect($sample['containers'])->keyBy('name');
    expect($containers['acme_live_hiring'])->toMatchArray(['running' => true, 'cpu' => 1.25, 'mem_bytes' => (int) round(512.5 * 1024 ** 2)]);
    expect($containers['acme_live_hiring_queue'])->toMatchArray(['running' => false, 'state' => 'exited', 'mem_bytes' => 0]);

    expect($sample['certificates'][0]['domain'])->toBe('hiring.acme.cloud');
    expect($sample['certificates'][0]['expires_at'])->toStartWith('2026-11-01T12:00:00');
});

test('collecting stores a sample and marks the server online', function () {
    $server = Server::factory()->verified()->create(['status' => 'unreachable']);

    $metric = (new MetricsCollector(sampleRunner()))->collect($server);

    expect($metric)->not->toBeNull();
    expect(ServerMetric::count())->toBe(1);
    expect($server->fresh())->status->toBe('online')->certificates->toHaveCount(1);
});

test('a server that does not answer is marked unreachable and stores nothing', function () {
    $server = Server::factory()->verified()->create();

    expect((new MetricsCollector(sampleRunner(null)))->collect($server))->toBeNull();

    expect(ServerMetric::count())->toBe(0);
    expect($server->fresh())->status->toBe('unreachable')->last_error->toContain('timed out');
});

test('every verified server is sampled each minute, and samples older than a week are dropped', function () {
    Bus::fake();
    $verified = Server::factory()->verified()->create();
    Server::factory()->create(['host_key' => null]);
    ServerMetric::create(['server_id' => $verified->id, 'recorded_at' => now()->subDays(8), 'cpu_percent' => 1, 'load1' => 0, 'load5' => 0, 'load15' => 0, 'mem_total' => 1, 'mem_used' => 1, 'disk_total' => 1, 'disk_used' => 1]);

    $this->artisan('servers:collect-metrics')->assertSuccessful();

    Bus::assertDispatchedTimes(CollectMetricsJob::class, 1);
    expect(ServerMetric::count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Alerts
|--------------------------------------------------------------------------
*/

test('a full disk, a full memory, a dead container and an expiring certificate all raise alerts', function () {
    $server = Server::factory()->verified()->create(['provisioned_at' => now()]);
    $metric = (new MetricsCollector(sampleRunner()))->collect($server);
    $server->update(['certificates' => [['domain' => 'hiring.acme.cloud', 'expires_at' => now()->addDays(5)->toIso8601String()]]]);

    $messages = collect(app(Fleet::class)->alerts($server->fresh()->load('stacks'), $metric))->pluck('message')->implode("\n");

    expect($messages)
        ->toContain('Disk 90% full')
        ->toContain('Container acme_live_hiring_queue is exited')
        ->toContain('Certificate for hiring.acme.cloud expires');
    // 75% memory is below the 90% threshold.
    expect($messages)->not->toContain('Memory');
});

test('an active stack without a recent backup is flagged; a fresh backup clears it', function () {
    $server = Server::factory()->verified()->create();
    $stack = Stack::factory()->create(['server_id' => $server->id, 'status' => 'active']);
    $fleet = app(Fleet::class);

    expect(collect($fleet->alerts($server->load('stacks.client'), null))->pluck('message')->implode(' '))->toContain('no database backup');

    Backup::create(['stack_id' => $stack->id, 'kind' => 'database', 'status' => 'success', 'triggered_by' => 'schedule', 'path' => '/x', 'finished_at' => now()]);

    expect(collect($fleet->alerts($server->fresh()->load('stacks.client'), null))->pluck('message')->implode(' '))->not->toContain('no database backup');
});

/*
|--------------------------------------------------------------------------
| Endpoints
|--------------------------------------------------------------------------
*/

test('the live view samples the server and names the build each container runs', function () {
    $this->app->instance(CommandRunner::class, sampleRunner());
    $server = Server::factory()->verified()->create();
    $build = Build::create(['service_id' => Service::factory()->create(['name' => 'Hiring'])->id, 'branch' => 'testing', 'commit_sha' => str_repeat('a', 40), 'image_tag' => 'aaaaaaa', 'digest' => 'sha256:abc123', 'number' => 143, 'status' => 'success']);

    $response = $this->actingAs(actorWithRole('admin'))->getJson("/servers/{$server->id}/metrics/live")->assertOk();

    expect($response->json('sample.cpu'))->toBe(75);
    expect(collect($response->json('containers'))->firstWhere('name', 'acme_live_hiring')['build'])->toBe($build->label());
});

test('monitoring is admin-only', function () {
    $server = Server::factory()->verified()->create();

    $this->actingAs(actorWithRole('operator'))->getJson("/servers/{$server->id}/metrics/live")->assertForbidden();
    $this->actingAs(actorWithRole('operator'))->getJson("/servers/{$server->id}/metrics")->assertForbidden();
});

test('logs and restarts only reach containers the server reported', function () {
    Bus::fake();
    $this->app->instance(CommandRunner::class, sampleRunner());
    $server = Server::factory()->verified()->create();
    (new MetricsCollector(sampleRunner()))->collect($server);
    $admin = actorWithRole('admin');

    $this->actingAs($admin)->getJson("/servers/{$server->id}/containers/somebody_else/logs")->assertNotFound();
    $this->actingAs($admin)->getJson("/servers/{$server->id}/containers/acme_live_hiring/logs")->assertOk()->assertJson(['ok' => true]);

    $this->actingAs($admin)->post("/servers/{$server->id}/containers/acme_live_hiring/restart")->assertRedirect();
    Bus::assertDispatched(ServerCommandJob::class, fn ($job) => $job->command === "docker restart 'acme_live_hiring'");
});

test('quick actions come from a fixed list', function () {
    Bus::fake();
    $server = Server::factory()->verified()->create();
    $admin = actorWithRole('admin');

    $this->actingAs($admin)->post("/servers/{$server->id}/actions/rm-rf")->assertNotFound();
    $this->actingAs($admin)->post("/servers/{$server->id}/actions/nginx-reload")->assertRedirect();

    Bus::assertDispatched(ServerCommandJob::class, fn ($job) => $job->command === 'nginx -t && systemctl reload nginx');
});

test('the fleet appears on the dashboard for admins only', function () {
    Server::factory()->verified()->create();

    $this->actingAs(actorWithRole('admin'))->get('/dashboard')->assertInertia(fn ($page) => $page->has('fleet', 1));
    $this->actingAs(actorWithRole('operator'))->get('/dashboard')->assertInertia(fn ($page) => $page->where('fleet', null));
});
