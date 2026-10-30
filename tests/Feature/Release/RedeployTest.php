<?php

use App\Jobs\BuildJob;
use App\Jobs\ReleaseJob;
use App\Models\Build;
use App\Models\Release;
use App\Models\Service;
use App\Models\ServiceEnvDefinition;
use App\Services\Build\BuildFiles;
use App\Services\Build\ImageBuilder;
use App\Services\Build\MigrationClassifier;
use App\Services\Release\ReleasePlanner;
use App\Services\Stack\EnvResolver;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    config([
        'deploy.ports.base' => 40000,
        'deploy.ports.block_size' => 100,
        'deploy.root' => '/srv',
        'deploy.registry' => 'ghcr.io/rnd-experts-team',
        'deploy.health.attempts' => 2,
        'deploy.health.interval_seconds' => 0,
    ]);
});

/** A per-stack dashboard running a build made with the given values hash. */
function runningDashboard($stack, ?string $hash = null): array
{
    $dashboard = Service::factory()->perStackBuild()->create(['slug' => 'dashboard', 'subdomain' => 'app']);
    ServiceEnvDefinition::create([
        'service_id' => $dashboard->id, 'key' => 'NEXT_PUBLIC_FLAG',
        'source' => ServiceEnvDefinition::SOURCE_LITERAL, 'default_value' => 'on', 'is_build_arg' => true,
    ]);
    $entitlement = entitle($stack, $dashboard, 'sha256:dash');
    $build = successfulBuild($dashboard, 'sha256:dash');
    $build->update(['stack_id' => $stack->id, 'commit_sha' => str_repeat('d', 40), 'build_args_hash' => $hash]);

    return [$dashboard, $entitlement->fresh(), $build->fresh()];
}

test('a redeploy plans what already runs, on testing and on live', function () {
    $client = twoTierClient();
    $hiring = Service::factory()->create(['slug' => 'hiring', 'subdomain' => 'hiring']);
    $never = Service::factory()->create(['slug' => 'qa', 'subdomain' => 'qa']);
    entitle($client->liveStack(), $hiring, 'sha256:running');
    entitle($client->liveStack(), $never);
    successfulBuild($hiring, 'sha256:running');

    $release = app(ReleasePlanner::class)->redeploy($client->liveStack());

    // Unlike an update, the digest already running is the whole point; a
    // service never deployed has nothing to put back.
    expect($release->kind)->toBe(Release::KIND_REDEPLOY);
    expect($release->items)->toHaveCount(1);
    expect($release->items->first())->to_digest->toBe('sha256:running')->service_id->toBe($hiring->id);
});

test('a redeploy recreates the containers and never asks for a window', function () {
    $client = twoTierClient();
    $hiring = Service::factory()->create(['slug' => 'hiring', 'subdomain' => 'hiring']);
    entitle($client->testingStack(), $hiring, 'sha256:running');
    // The commit carried a destructive migration, which ran when it was first deployed.
    successfulBuild($hiring, 'sha256:running', 'destructive', ['users']);
    $runner = new RecordingRunner;

    $release = app(ReleasePlanner::class)->redeploy($client->testingStack());
    expect($release->needsWindow())->toBeFalse();

    runnerWith($runner)->run($release, new NullTarget);

    expect($release->fresh()->status)->toBe('succeeded');
    // Same image: without --force-recreate compose would leave the old container.
    expect($runner->transcript())->toContain("up -d --no-deps --force-recreate 'hiring'");
});

test('an ordinary update does not force a recreate', function () {
    $client = twoTierClient();
    $hiring = Service::factory()->create(['slug' => 'hiring', 'subdomain' => 'hiring']);
    entitle($client->testingStack(), $hiring, 'sha256:old');
    $runner = new RecordingRunner;

    runnerWith($runner)->run(app(ReleasePlanner::class)->normal($client->testingStack()->load('client'), null), new NullTarget);
    successfulBuild($hiring, 'sha256:new');
    runnerWith($runner)->run(app(ReleasePlanner::class)->normal($client->testingStack()), new NullTarget);

    expect($runner->transcript())->toContain("up -d --no-deps 'hiring'")->not->toContain('--force-recreate');
});

test('redeploy is a button on testing and on live', function () {
    Bus::fake();
    $client = twoTierClient();
    $hiring = Service::factory()->create(['slug' => 'hiring', 'subdomain' => 'hiring']);
    entitle($client->liveStack(), $hiring, 'sha256:running');

    $this->actingAs(actorWithRole('operator'))
        ->post("/stacks/{$client->liveStack()->id}/redeploy", ['service_ids' => [$hiring->id]])
        ->assertRedirect();

    expect(Release::sole()->kind)->toBe(Release::KIND_REDEPLOY);
    Bus::assertDispatched(ReleaseJob::class);

    $this->actingAs(actorWithRole('viewer'))
        ->post("/stacks/{$client->liveStack()->id}/redeploy")
        ->assertForbidden();
});

test('a per-stack image built with values that have since changed is stale', function () {
    $client = twoTierClient();
    [, $entitlement] = runningDashboard($client->testingStack());
    $resolver = app(EnvResolver::class);
    $current = EnvResolver::hashBuildArgs($resolver->buildArgs($entitlement));

    expect($resolver->buildArgsStale($entitlement, Build::sole()))->toBeFalse(); // no record: unknown, not stale

    Build::sole()->update(['build_args_hash' => $current]);
    expect($resolver->buildArgsStale($entitlement, Build::sole()))->toBeFalse();

    $entitlement->service->envDefinitions()->update(['default_value' => 'off']);
    expect($resolver->buildArgsStale($entitlement->fresh(), Build::sole()))->toBeTrue();
});

test('rebuild and redeploy builds the running commit again and deploys it once built', function () {
    Bus::fake();
    $client = twoTierClient();
    [, $entitlement] = runningDashboard($client->testingStack(), 'old-hash');

    $this->actingAs(actorWithRole('operator'))
        ->post("/stacks/{$client->testingStack()->id}/services/{$entitlement->id}/rebuild")
        ->assertRedirect();

    $rebuild = Build::latest('id')->first();
    expect($rebuild)->commit_sha->toBe(str_repeat('d', 40))->stack_id->toBe($client->testingStack()->id)->status->toBe('pending');
    Bus::assertDispatched(BuildJob::class, fn ($job) => $job->buildId === $rebuild->id);

    $release = Release::sole();
    expect($release)->kind->toBe(Release::KIND_REDEPLOY)->status->toBe('draft');
    expect($release->items->first()->to_digest)->toBeNull();

    // The build finishes: the waiting redeploy starts with its digest.
    $rebuild->update(['status' => 'success', 'digest' => 'sha256:rebuilt']);
    BuildJob::continueRedeploys($rebuild->fresh());

    expect($release->items()->first()->to_digest)->toBe('sha256:rebuilt');
    Bus::assertDispatched(ReleaseJob::class, fn ($job) => $job->releaseId === $release->id);
});

test('a failed rebuild fails the redeploy waiting for it', function () {
    Bus::fake();
    $client = twoTierClient();
    [, $entitlement] = runningDashboard($client->testingStack(), 'old-hash');

    $this->actingAs(actorWithRole('operator'))->post("/stacks/{$client->testingStack()->id}/services/{$entitlement->id}/rebuild");

    $rebuild = Build::latest('id')->first();
    $rebuild->update(['status' => 'failed', 'error' => 'npm ci failed']);
    BuildJob::continueRedeploys($rebuild->fresh());

    expect(Release::sole()->status)->toBe('failed');
    expect(Release::sole()->items->first()->error)->toContain('npm ci failed');
    Bus::assertNotDispatched(ReleaseJob::class);
});

test('a shared image cannot be rebuilt per stack', function () {
    $client = twoTierClient();
    $hiring = Service::factory()->create(['slug' => 'hiring', 'subdomain' => 'hiring']);
    $entitlement = entitle($client->testingStack(), $hiring, 'sha256:running');

    $this->actingAs(actorWithRole('operator'))
        ->post("/stacks/{$client->testingStack()->id}/services/{$entitlement->id}/rebuild")
        ->assertSessionHas('error');

    expect(Build::count())->toBe(0);
});

test('a rebuild of the same commit gets its own tag and label', function () {
    $client = twoTierClient();
    [$dashboard] = runningDashboard($client->testingStack(), 'old-hash');
    $builder = new ImageBuilder(new RecordingRunner, new MigrationClassifier, app(EnvResolver::class), app(BuildFiles::class));

    $tag = $builder->tagFor($dashboard, str_repeat('d', 40), null, $client->testingStack(), 2);
    expect($tag)->toBe('ddddddd-s'.$client->testingStack()->id.'-r2');

    $rebuild = successfulBuild($dashboard, 'sha256:rebuilt');
    $rebuild->update(['number' => 7, 'attempt' => 2]);
    expect($rebuild->fresh()->label())->toContain('#7 r2');
});
