<?php

use App\Models\Build;
use App\Models\Client;
use App\Models\Release;
use App\Models\ReleaseItem;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceEnvDefinition;
use App\Models\Stack;
use App\Models\StackService;
use App\Services\Backup\BackupService;
use App\Services\Release\ReleasePlanner;
use App\Services\Release\ReleaseRunner;
use App\Services\Remote\CommandResult;
use App\Services\Remote\CommandRunner;
use App\Services\Stack\EnvResolver;
use App\Services\Stack\StackRenderer;
use App\Services\Stack\StackSync;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'deploy.ports.base' => 40000,
        'deploy.ports.block_size' => 100,
        'deploy.root' => '/srv',
        'deploy.registry' => 'ghcr.io/rnd-experts-team',
        'deploy.health.attempts' => 2,
        'deploy.health.interval_seconds' => 0,
    ]);
});

/** A client with both tiers on one server. */
function twoTierClient(): Client
{
    $client = Client::factory()->create(['slug' => 'acme', 'domain' => 'acme.cloud']);
    $server = Server::factory()->verified()->create();

    foreach ([Stack::TIER_LIVE, Stack::TIER_TESTING] as $i => $tier) {
        Stack::factory()->create([
            'client_id' => $client->id,
            'server_id' => $server->id,
            'tier' => $tier,
            'compose_project' => 'acme_'.$tier,
            'port_range_start' => 40000 + ($i * 100),
        ]);
    }

    return $client->fresh('stacks');
}

function entitle(Stack $stack, Service $service, ?string $digest = null): StackService
{
    return StackService::create([
        'stack_id' => $stack->id,
        'service_id' => $service->id,
        'enabled' => true,
        'host_port' => 40001,
        'current_digest' => $digest,
        'state' => $digest ? 'deployed' : 'pending',
    ]);
}

function successfulBuild(Service $service, string $digest, string $kind = 'additive', array $tables = []): Build
{
    return Build::create([
        'service_id' => $service->id,
        'branch' => 'testing',
        'commit_sha' => substr(md5($digest), 0, 40),
        'image_tag' => substr($digest, 7, 7),
        'digest' => $digest,
        'migration_kind' => $kind,
        'affected_tables' => $tables,
        'status' => 'success',
        'built_at' => now(),
    ]);
}

/** A runner that answers everything successfully and records commands. */
function fakeRunner(array $failOn = []): CommandRunner
{
    return new class($failOn) extends CommandRunner
    {
        public array $commands = [];

        public function __construct(private array $failOn) {}

        public function run($target, string $command, ?string $cwd = null, array $env = [], int $timeout = 60, ?Closure $onOutput = null): CommandResult
        {
            $this->commands[] = $command;

            foreach ($this->failOn as $needle) {
                if (str_contains($command, $needle)) {
                    return new CommandResult(1, '', "failed: {$needle}", 1);
                }
            }

            return new CommandResult(0, 'ok', '', 1);
        }
    };
}

function runnerWith(CommandRunner $commands): ReleaseRunner
{
    return new ReleaseRunner(
        $commands,
        new StackSync(app(StackRenderer::class), $commands),
        app(StackRenderer::class),
        app(EnvResolver::class),
        new BackupService($commands, app(StackRenderer::class)),
    );
}

/*
|--------------------------------------------------------------------------
| Planning
|--------------------------------------------------------------------------
*/

test('a normal release only includes services with something new to ship', function () {
    $client = twoTierClient();
    $live = $client->liveStack();

    $moving = Service::factory()->create(['slug' => 'hiring', 'subdomain' => 'hiring']);
    $settled = Service::factory()->create(['slug' => 'inventory', 'subdomain' => 'inventory']);

    entitle($live, $moving, 'sha256:old');
    entitle($live, $settled, 'sha256:same');

    successfulBuild($moving, 'sha256:new');
    successfulBuild($settled, 'sha256:same');

    $release = app(ReleasePlanner::class)->normal($live);

    expect($release->items)->toHaveCount(1);
    expect($release->items->first()->service_id)->toBe($moving->id);
});

test('promotion copies the digest testing is running, without rebuilding', function () {
    $client = twoTierClient();
    $service = Service::factory()->create(['slug' => 'hiring', 'subdomain' => 'hiring']);

    entitle($client->testingStack(), $service, 'sha256:proven');
    entitle($client->liveStack(), $service, 'sha256:older');

    $build = successfulBuild($service, 'sha256:proven');

    $release = app(ReleasePlanner::class)->promote($client->testingStack());

    expect($release->kind)->toBe(Release::KIND_PROMOTION);
    expect($release->stack_id)->toBe($client->liveStack()->id);
    // The whole point: what passed testing is bit-for-bit what goes live.
    expect($release->items->first()->to_digest)->toBe('sha256:proven');
    // And the migration classification travels with it.
    expect($release->items->first()->build_id)->toBe($build->id);
});

test('promotion refuses to run from a live stack', function () {
    $client = twoTierClient();

    app(ReleasePlanner::class)->promote($client->liveStack());
})->throws(RuntimeException::class, 'not a live one');

test('a rollback targets the digests the previous release recorded', function () {
    $client = twoTierClient();
    $live = $client->liveStack();
    $service = Service::factory()->create(['slug' => 'hiring', 'subdomain' => 'hiring']);
    entitle($live, $service, 'sha256:new');

    $previous = Release::create(['stack_id' => $live->id, 'kind' => Release::KIND_NORMAL, 'status' => 'succeeded']);
    ReleaseItem::create([
        'release_id' => $previous->id,
        'service_id' => $service->id,
        'from_digest' => 'sha256:known-good',
        'to_digest' => 'sha256:new',
        'status' => 'succeeded',
    ]);

    $rollback = app(ReleasePlanner::class)->rollback($previous);

    expect($rollback->kind)->toBe(Release::KIND_ROLLBACK);
    expect($rollback->items->first()->to_digest)->toBe('sha256:known-good');
    expect($rollback->source_release_id)->toBe($previous->id);
});

test('an emergency needs a typed reason and is permanently marked', function () {
    $client = twoTierClient();
    $service = Service::factory()->create(['slug' => 'hiring', 'subdomain' => 'hiring']);
    entitle($client->liveStack(), $service, 'sha256:old');
    successfulBuild($service, 'sha256:hotfix');

    $release = app(ReleasePlanner::class)->emergency($client->liveStack(), 'Payments are down');

    expect($release->kind)->toBe(Release::KIND_EMERGENCY);
    expect($release->reason)->toBe('Payments are down');
});

test('an emergency with a blank reason is refused', function () {
    $client = twoTierClient();

    app(ReleasePlanner::class)->emergency($client->liveStack(), '   ');
})->throws(RuntimeException::class, 'typed reason');

test('an emergency queues the same deploy onto testing so the tiers cannot diverge', function () {
    $client = twoTierClient();
    $service = Service::factory()->create(['slug' => 'hiring', 'subdomain' => 'hiring']);
    entitle($client->liveStack(), $service, 'sha256:old');
    entitle($client->testingStack(), $service, 'sha256:old');
    successfulBuild($service, 'sha256:hotfix');

    $planner = app(ReleasePlanner::class);
    $emergency = $planner->emergency($client->liveStack(), 'Payments are down');
    $followUp = $planner->emergencyFollowUp($emergency);

    // Live skipped testing. Without this, testing is permanently behind and
    // the two tiers stop being comparable.
    expect($followUp->stack_id)->toBe($client->testingStack()->id);
    expect($followUp->items->first()->to_digest)->toBe('sha256:hotfix');
});

/*
|--------------------------------------------------------------------------
| Refusing to start
|--------------------------------------------------------------------------
*/

test('a release stops before anything swaps when a required setting is missing', function () {
    $client = twoTierClient();
    $live = $client->liveStack();
    $service = Service::factory()->create(['slug' => 'hiring', 'subdomain' => 'hiring']);
    entitle($live, $service);

    ServiceEnvDefinition::create([
        'service_id' => $service->id,
        'key' => 'HOOKS_API_KEY',
        'source' => ServiceEnvDefinition::SOURCE_SECRET,
        'required' => true,
    ]);

    $release = Release::create(['stack_id' => $live->id, 'kind' => Release::KIND_NORMAL]);
    ReleaseItem::create([
        'release_id' => $release->id,
        'service_id' => $service->id,
        'to_digest' => 'sha256:new',
    ]);

    $commands = fakeRunner();
    $result = runnerWith($commands)->run($release->fresh(), new NullTarget);

    expect($result->status)->toBe('failed');
    // Nothing at all should have run: this is the check that stops a container
    // booting and quietly falling back to somebody else's backend.
    expect($commands->commands)->toBeEmpty();
});

test('a destructive release refuses to run without a scheduled window', function () {
    $client = twoTierClient();
    $live = $client->liveStack();
    $service = Service::factory()->create(['slug' => 'hiring', 'subdomain' => 'hiring']);
    entitle($live, $service);

    $build = successfulBuild($service, 'sha256:new', 'destructive', ['goals']);

    $release = Release::create(['stack_id' => $live->id, 'kind' => Release::KIND_NORMAL]);
    ReleaseItem::create([
        'release_id' => $release->id,
        'service_id' => $service->id,
        'build_id' => $build->id,
        'to_digest' => 'sha256:new',
    ]);

    $commands = fakeRunner();
    $result = runnerWith($commands)->run($release->fresh(), new NullTarget);

    expect($result->status)->toBe('failed');
    expect($commands->commands)->toBeEmpty();
});

/*
|--------------------------------------------------------------------------
| Running
|--------------------------------------------------------------------------
*/

test('the from digest is recorded before any container moves', function () {
    $client = twoTierClient();
    $live = $client->liveStack();
    $service = Service::factory()->create(['slug' => 'hiring', 'subdomain' => 'hiring']);
    entitle($live, $service, 'sha256:previous');

    $release = Release::create(['stack_id' => $live->id, 'kind' => Release::KIND_NORMAL]);
    ReleaseItem::create([
        'release_id' => $release->id,
        'service_id' => $service->id,
        'to_digest' => 'sha256:new',
    ]);

    runnerWith(fakeRunner())->run($release->fresh(), new NullTarget);

    // Recorded up front: if it were written after the swap, a crash mid-deploy
    // would leave nothing to roll back to.
    expect($release->items()->first()->from_digest)->toBe('sha256:previous');
});

test('a successful item updates what the stack is running', function () {
    $client = twoTierClient();
    $live = $client->liveStack();
    $service = Service::factory()->create(['slug' => 'hiring', 'subdomain' => 'hiring']);
    $entitlement = entitle($live, $service, 'sha256:previous');

    $release = Release::create(['stack_id' => $live->id, 'kind' => Release::KIND_NORMAL]);
    ReleaseItem::create([
        'release_id' => $release->id,
        'service_id' => $service->id,
        'to_digest' => 'sha256:new',
    ]);

    $result = runnerWith(fakeRunner())->run($release->fresh(), new NullTarget);

    expect($result->status)->toBe('succeeded');
    expect($entitlement->fresh()->current_digest)->toBe('sha256:new');
    expect($entitlement->fresh()->state)->toBe('deployed');
});

test('a failed health check fails the item and leaves the stack digest alone', function () {
    $client = twoTierClient();
    $live = $client->liveStack();
    $service = Service::factory()->create(['slug' => 'hiring', 'subdomain' => 'hiring']);
    $entitlement = entitle($live, $service, 'sha256:previous');

    $release = Release::create(['stack_id' => $live->id, 'kind' => Release::KIND_NORMAL]);
    ReleaseItem::create([
        'release_id' => $release->id,
        'service_id' => $service->id,
        'to_digest' => 'sha256:new',
    ]);

    $result = runnerWith(fakeRunner(failOn: ['curl']))->run($release->fresh(), new NullTarget);

    expect($result->status)->toBe('failed');
    expect($release->items()->first()->error)->toContain('Health check');
    // We do not claim to be running something that never answered.
    expect($entitlement->fresh()->current_digest)->toBe('sha256:previous');
});

test('a partial failure leaves earlier services running and stops the rest', function () {
    $client = twoTierClient();
    $live = $client->liveStack();

    $first = Service::factory()->create(['slug' => 'aaa', 'subdomain' => 'aaa', 'position' => 0]);
    $second = Service::factory()->create(['slug' => 'bbb', 'subdomain' => 'bbb', 'position' => 1]);
    $third = Service::factory()->create(['slug' => 'ccc', 'subdomain' => 'ccc', 'position' => 2]);

    foreach ([$first, $second, $third] as $service) {
        entitle($live, $service, 'sha256:old');
    }

    $release = Release::create(['stack_id' => $live->id, 'kind' => Release::KIND_NORMAL]);
    foreach ([$first, $second, $third] as $position => $service) {
        ReleaseItem::create([
            'release_id' => $release->id,
            'service_id' => $service->id,
            'to_digest' => 'sha256:new',
            'position' => $position,
        ]);
    }

    $result = runnerWith(fakeRunner(failOn: ["up -d --no-deps 'bbb'"]))->run($release->fresh(), new NullTarget);

    $items = $release->items()->orderBy('position')->get();

    expect($result->status)->toBe('failed');
    expect($items[0]->status)->toBe('succeeded');
    expect($items[1]->status)->toBe('failed');
    // Never attempted: half a release we can describe, a half-applied one we
    // cannot.
    expect($items[2]->status)->toBe('pending');
    // The one that did land stays up and is revertable on its own.
    expect($items[0]->isRevertable())->toBeTrue();
});

test('a destructive release dumps only the affected tables, with pipefail', function () {
    $client = twoTierClient();
    $live = $client->liveStack();
    $service = Service::factory()->create([
        'slug' => 'hiring', 'subdomain' => 'hiring', 'database_name' => 'hiringpizza',
    ]);
    entitle($live, $service, 'sha256:old');

    $build = successfulBuild($service, 'sha256:new', 'destructive', ['goals', 'goal_metrics']);

    $release = Release::create([
        'stack_id' => $live->id,
        'kind' => Release::KIND_NORMAL,
        'scheduled_for' => now(),
    ]);
    ReleaseItem::create([
        'release_id' => $release->id,
        'service_id' => $service->id,
        'build_id' => $build->id,
        'to_digest' => 'sha256:new',
    ]);

    $commands = fakeRunner();
    runnerWith($commands)->run($release->fresh(), new NullTarget);

    $dump = collect($commands->commands)->first(fn ($c) => str_contains($c, 'mysqldump'));

    // Only the touched tables: a full dump of the reporting database would put
    // the window into tens of minutes, and nobody schedules one of those.
    expect($dump)->toContain('goals')->toContain('goal_metrics');
    // Into the control plane's backup store, not onto the client's disk.
    expect($dump)->toContain('/var/backups/control-plane/');
    expect($dump)->not->toContain('--all-databases');
    // Without pipefail the exit status is gzip's, so a dump that dies halfway
    // still looks successful and restores to half a table.
    expect($dump)->toContain('set -o pipefail');
});

test('a maintenance window is opened and always closed again', function () {
    $client = twoTierClient();
    $live = $client->liveStack();
    $service = Service::factory()->create(['slug' => 'hiring', 'subdomain' => 'hiring', 'database_name' => 'h']);
    entitle($live, $service, 'sha256:old');

    $build = successfulBuild($service, 'sha256:new', 'destructive', ['goals']);

    $release = Release::create([
        'stack_id' => $live->id, 'kind' => Release::KIND_NORMAL, 'scheduled_for' => now(),
    ]);
    ReleaseItem::create([
        'release_id' => $release->id, 'service_id' => $service->id,
        'build_id' => $build->id, 'to_digest' => 'sha256:new',
    ]);

    // Fail the swap, so the window has to be closed on the way out.
    runnerWith(fakeRunner(failOn: ['up -d --no-deps']))->run($release->fresh(), new NullTarget);

    $release->refresh();

    expect($release->status)->toBe('failed');
    expect($release->window_started_at)->not->toBeNull();
    // A failed release that leaves the maintenance page up is an outage.
    expect($release->window_ended_at)->not->toBeNull();
});

test('a next.js service is never asked to run artisan migrate', function () {
    $client = twoTierClient();
    $live = $client->liveStack();
    $service = Service::factory()->perStackBuild()->create(['slug' => 'dashboard', 'subdomain' => 'app']);
    entitle($live, $service, 'sha256:old');

    $build = successfulBuild($service, 'sha256:new');

    $release = Release::create(['stack_id' => $live->id, 'kind' => Release::KIND_NORMAL]);
    ReleaseItem::create([
        'release_id' => $release->id, 'service_id' => $service->id,
        'build_id' => $build->id, 'to_digest' => 'sha256:new',
    ]);

    $commands = fakeRunner();
    runnerWith($commands)->run($release->fresh(), new NullTarget);

    expect(implode("\n", $commands->commands))->not->toContain('artisan migrate');
});
