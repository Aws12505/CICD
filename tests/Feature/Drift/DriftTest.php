<?php

use App\Models\DriftObservation;
use App\Models\Server;
use App\Models\Service;
use App\Models\Stack;
use App\Models\StackService;
use App\Services\Drift\DriftPoller;
use App\Services\Remote\CommandResult;
use App\Services\Remote\CommandRunner;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(RoleSeeder::class));

function pollerReturning(?string $output, bool $fails = false): DriftPoller
{
    return new DriftPoller(new class($output, $fails) extends CommandRunner
    {
        public function __construct(private ?string $output, private bool $fails) {}

        public function run($target, string $command, ?string $cwd = null, array $env = [], int $timeout = 60, ?Closure $onOutput = null): CommandResult
        {
            return $this->fails
                ? new CommandResult(255, '', 'connection timed out', 1)
                : new CommandResult(0, (string) $this->output, '', 1);
        }
    });
}

function driftStack(): Stack
{
    return Stack::factory()->create([
        'server_id' => Server::factory()->verified()->create()->id,
        'compose_project' => 'acme_live',
        'status' => 'active',
    ]);
}

function deployed(Stack $stack, string $slug, ?string $digest): StackService
{
    return StackService::create([
        'stack_id' => $stack->id,
        'service_id' => Service::factory()->create(['slug' => $slug, 'subdomain' => $slug])->id,
        'enabled' => true,
        'desired_digest' => $digest,
        'current_digest' => $digest,
        'state' => 'deployed',
    ]);
}

test('a server running what we asked for produces no observations', function () {
    $stack = driftStack();
    deployed($stack, 'hiring', 'sha256:expected');

    $observations = pollerReturning("/acme_live_hiring sha256:expected\n")->poll($stack, new NullTarget);

    expect($observations)->toBeEmpty();
});

test('a different image is recorded as drift and marks the service', function () {
    $stack = driftStack();
    $entitlement = deployed($stack, 'hiring', 'sha256:expected');

    $observations = pollerReturning("/acme_live_hiring sha256:something-else\n")->poll($stack, new NullTarget);

    expect($observations)->toHaveCount(1);
    expect($observations[0]->expected_digest)->toBe('sha256:expected');
    expect($observations[0]->observed_digest)->toBe('sha256:something-else');
    expect($entitlement->fresh()->state)->toBe('drifted');
});

test('a container that is gone entirely is drift too', function () {
    $stack = driftStack();
    deployed($stack, 'hiring', 'sha256:expected');

    $observations = pollerReturning('')->poll($stack, new NullTarget);

    expect($observations)->toHaveCount(1);
    // A service that died is as much a difference from intent as one running
    // the wrong image.
    expect($observations[0]->isMissing())->toBeTrue();
});

test('an unreachable server is not reported as every service having drifted', function () {
    $stack = driftStack();
    deployed($stack, 'hiring', 'sha256:expected');
    deployed($stack, 'inventory', 'sha256:expected2');

    $observations = pollerReturning(null, fails: true)->poll($stack, new NullTarget);

    // Reporting two drifts because SSH timed out would bury the real thing.
    expect($observations)->toBeEmpty();
    expect($stack->server->fresh()->status)->toBe('unreachable');
});

test('a service that has never been deployed is not drift', function () {
    $stack = driftStack();
    deployed($stack, 'hiring', null);

    expect(pollerReturning('')->poll($stack, new NullTarget))->toBeEmpty();
});

test('the same difference is updated rather than recorded every five minutes', function () {
    $stack = driftStack();
    deployed($stack, 'hiring', 'sha256:expected');

    $poller = pollerReturning("/acme_live_hiring sha256:other\n");

    $poller->poll($stack, new NullTarget);
    $poller->poll($stack, new NullTarget);
    $poller->poll($stack, new NullTarget);

    // Otherwise one problem becomes a wall of rows within the hour.
    expect(DriftObservation::count())->toBe(1);
});

test('drift that resolves itself clears the flag', function () {
    $stack = driftStack();
    $entitlement = deployed($stack, 'hiring', 'sha256:expected');

    pollerReturning("/acme_live_hiring sha256:other\n")->poll($stack, new NullTarget);
    expect($entitlement->fresh()->state)->toBe('drifted');

    pollerReturning("/acme_live_hiring sha256:expected\n")->poll($stack, new NullTarget);
    expect($entitlement->fresh()->state)->toBe('deployed');
});

test('converge puts back what the control plane intended', function () {
    $stack = driftStack();
    $entitlement = deployed($stack, 'hiring', 'sha256:expected');

    $poller = pollerReturning("/acme_live_hiring sha256:manual-fix\n");
    $observation = $poller->poll($stack, new NullTarget)[0];

    $poller->converge($observation, actorWithRole('admin'), 'Nobody knows where that came from');

    expect($entitlement->fresh()->desired_digest)->toBe('sha256:expected');
    expect($entitlement->fresh()->state)->toBe('pending');
    expect($observation->fresh()->resolution)->toBe('converge');
});

test('adopt accepts what is running, because sometimes the field fix was right', function () {
    $stack = driftStack();
    $entitlement = deployed($stack, 'hiring', 'sha256:expected');

    $poller = pollerReturning("/acme_live_hiring sha256:manual-fix\n");
    $observation = $poller->poll($stack, new NullTarget)[0];

    $poller->adopt($observation, actorWithRole('admin'), 'Hotfix applied during the incident; keeping it');

    // A system that only ever says "you were wrong" gets worked around.
    expect($entitlement->fresh()->desired_digest)->toBe('sha256:manual-fix');
    expect($entitlement->fresh()->state)->toBe('deployed');
    expect($observation->fresh()->resolution)->toBe('adopt');
    expect($observation->fresh()->resolution_reason)->toContain('Hotfix');
    expect($observation->fresh()->resolved_by)->not->toBeNull();
});

test('the digest is read from the reference compose started the container with, not the local image id', function () {
    $stack = driftStack();
    deployed($stack, 'hiring', 'sha256:expected');

    // `{{.Config.Image}}` is the `repo@sha256:…` the compose file named, kept
    // verbatim; `{{.Image}}` is the daemon's local ID, which never equals a
    // registry digest. Comparing the latter would call every deploy drift.
    $running = "/acme_live_hiring ghcr.io/rnd-experts-team/hiring@sha256:expected sha256:0123456789abcdef\n";

    expect(pollerReturning($running)->poll($stack, new NullTarget))->toBeEmpty();
});

test('a process container that is missing is drift even when the web container is right', function () {
    $stack = driftStack();
    $service = Service::factory()->create([
        'slug' => 'hiring',
        'subdomain' => 'hiring',
        'processes' => [['name' => 'queue', 'command' => 'php artisan queue:work']],
    ]);
    StackService::create([
        'stack_id' => $stack->id, 'service_id' => $service->id, 'enabled' => true,
        'desired_digest' => 'sha256:expected', 'current_digest' => 'sha256:expected', 'state' => 'deployed',
    ]);

    $web = "/acme_live_hiring ghcr.io/x/hiring@sha256:expected sha256:local\n";
    $queue = "/acme_live_hiring_queue ghcr.io/x/hiring@sha256:expected sha256:local\n";

    expect(pollerReturning($web.$queue)->poll($stack, new NullTarget))->toBeEmpty();

    // The queue worker's container was removed by hand: the service is not
    // running what the control plane deployed, so it is drift, not a metric.
    $observations = pollerReturning($web)->poll($stack, new NullTarget);

    expect($observations)->toHaveCount(1);
    expect($observations[0]->isMissing())->toBeTrue();
});
