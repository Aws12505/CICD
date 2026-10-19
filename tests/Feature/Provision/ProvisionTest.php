<?php

use App\Contracts\Target;
use App\Jobs\ProvisionServerJob;
use App\Models\Server;
use App\Models\Service;
use App\Models\Setting;
use App\Models\Stack;
use App\Models\StackService;
use App\Models\Task;
use App\Services\Provision\InfraRenderer;
use App\Services\Provision\ServerProvisioner;
use App\Services\Provision\StackProvisioner;
use App\Services\Remote\CommandResult;
use App\Services\Remote\ServerProbe;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

/** A server with one client's two stacks, each running Hiring. */
function provisionableServer(): Server
{
    $server = Server::factory()->verified()->create(['ssh_user' => 'root']);

    $hiring = Service::factory()->create([
        'slug' => 'hiring',
        'database_name' => 'hiringpizza',
        'nats_publishes' => ['HIRING'],
        'nats_consumes' => [['stream' => 'AUTH', 'durable' => 'HIRING_AUTH_CONSUMER']],
    ]);

    foreach ([Stack::TIER_LIVE, Stack::TIER_TESTING] as $tier) {
        $stack = Stack::factory()->create([
            'server_id' => $server->id,
            'tier' => $tier,
            'compose_project' => "acme_{$tier}",
        ]);

        StackService::create(['stack_id' => $stack->id, 'service_id' => $hiring->id, 'enabled' => true]);
    }

    return $server->fresh();
}

function provisionerWith(RecordingRunner $runner): ServerProvisioner
{
    return new ServerProvisioner($runner, new InfraRenderer, new StackProvisioner($runner), new ServerProbe($runner));
}

function healthyMysql(): Closure
{
    return fn (string $command) => str_contains($command, 'State.Health.Status')
        ? new CommandResult(0, "healthy\n", '', 1)
        : null;
}

/*
|--------------------------------------------------------------------------
| The "Set up server" button
|--------------------------------------------------------------------------
*/

test('set up server queues a task and opens it', function () {
    Bus::fake();
    $server = provisionableServer();

    $response = $this->actingAs(actorWithRole('admin'))->post("/servers/{$server->id}/provision");

    $task = Task::sole();
    $response->assertRedirect("/tasks/{$task->id}");
    expect($task->type)->toBe(Task::TYPE_PROVISION);
    expect($task->subject->is($server))->toBeTrue();
    Bus::assertDispatched(ProvisionServerJob::class, fn ($job) => $job->serverId === $server->id && $job->taskId === $task->id);
});

test('a second click opens the setup already running instead of starting another', function () {
    Bus::fake();
    $server = provisionableServer();
    $admin = actorWithRole('admin');

    $this->actingAs($admin)->post("/servers/{$server->id}/provision");
    $this->actingAs($admin)->post("/servers/{$server->id}/provision")
        ->assertRedirect('/tasks/'.Task::sole()->id);

    Bus::assertDispatchedTimes(ProvisionServerJob::class, 1);
});

test('a server that has not been verified cannot be set up', function () {
    Bus::fake();
    $server = Server::factory()->create();

    $this->actingAs(actorWithRole('admin'))
        ->post("/servers/{$server->id}/provision")
        ->assertSessionHas('error');

    Bus::assertNothingDispatched();
});

test('only admins can set up a server', function () {
    $server = provisionableServer();

    $this->actingAs(actorWithRole('operator'))
        ->post("/servers/{$server->id}/provision")
        ->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| The provisioning run
|--------------------------------------------------------------------------
*/

test('a full run prepares the machine, starts the shared services and prepares every stack', function () {
    Setting::put(Setting::REGISTRY_USERNAME, 'rnd-bot');
    Setting::put(Setting::REGISTRY_PULL_TOKEN, 'ghp_readonlytoken123');
    $server = provisionableServer();
    $task = Task::begin(Task::TYPE_PROVISION, 'Set up', $server);
    $runner = new RecordingRunner(healthyMysql());

    expect(provisionerWith($runner)->provision($server, $task))->toBeTrue();

    $all = $runner->transcript();

    // The bootstrap script went over, with the read-only token in its env.
    expect($all)->toContain('apt-get install')->toContain('docker network create pizza_shared');
    expect($runner->envs[0])->toMatchArray(['REGISTRY_USER' => 'rnd-bot', 'REGISTRY_TOKEN' => 'ghp_readonlytoken123']);

    // Shared services, then a user per stack, then streams per tier.
    expect($all)
        ->toContain('/srv/_infra/docker-compose.yml up -d')
        ->toContain("CREATE USER IF NOT EXISTS 'acme_live'@'%'")
        ->toContain("CREATE USER IF NOT EXISTS 'acme_testing'@'%'")
        ->toContain('nats stream add HIRING_EVENTS')
        ->toContain('nats stream add HIRING_TESTING_EVENTS');

    $server->refresh();
    expect($task->fresh()->status)->toBe('succeeded');
    expect($server->isProvisioned())->toBeTrue();
    expect($server->capabilities)->toHaveKey('docker');
});

test('no generated password or token is written to the task log', function () {
    Setting::put(Setting::REGISTRY_PULL_TOKEN, 'ghp_readonlytoken123');
    $server = provisionableServer();
    $task = Task::begin(Task::TYPE_PROVISION, 'Set up', $server);

    // A server that echoes everything it is sent straight back.
    $echoing = new class(healthyMysql()) extends RecordingRunner
    {
        public function run(Target $target, string $command, ?string $cwd = null, array $env = [], int $timeout = 60, ?Closure $onOutput = null): CommandResult
        {
            $onOutput && $onOutput(implode(' ', $env).' '.RecordingRunner::decode($command));

            return parent::run($target, $command, $cwd, $env, $timeout, $onOutput);
        }
    };

    provisionerWith($echoing)->provision($server, $task);

    $log = $task->fresh()->contents();
    $secrets = [
        ...$server->fresh()->allSecrets(),
        ...$server->stacks->flatMap(fn (Stack $s) => $s->fresh()->allSecrets()),
        'ghp_readonlytoken123',
    ];

    expect($secrets)->not->toBeEmpty();
    foreach ($secrets as $secret) {
        expect($log)->not->toContain($secret);
    }
    expect($log)->toContain('******');
});

test('a failed bootstrap stops the run before anything is started', function () {
    $server = provisionableServer();
    $task = Task::begin(Task::TYPE_PROVISION, 'Set up', $server);
    $runner = new RecordingRunner(fn (string $command) => str_contains($command, 'base64 -d | bash')
        ? new CommandResult(1, '', 'Unsupported OS', 1)
        : null);

    expect(provisionerWith($runner)->provision($server, $task))->toBeFalse();

    expect($runner->transcript())->not->toContain('docker-compose.yml up');
    expect($task->fresh()->status)->toBe('failed');
    expect($server->fresh()->isProvisioned())->toBeFalse();
});

test('a MySQL that never turns healthy fails the run instead of preparing stacks against it', function () {
    $server = provisionableServer();
    $task = Task::begin(Task::TYPE_PROVISION, 'Set up', $server);
    $runner = new RecordingRunner(fn (string $command) => str_contains($command, 'State.Health.Status')
        ? new CommandResult(0, 'starting', '', 1)
        : null);

    expect(provisionerWith($runner)->provision($server, $task))->toBeFalse();

    expect($runner->transcript())->not->toContain('CREATE USER');
    expect($task->fresh()->error)->toContain('MySQL');
});

test('running it twice keeps every password', function () {
    $server = provisionableServer();

    provisionerWith(new RecordingRunner(healthyMysql()))->provision($server, Task::begin(Task::TYPE_PROVISION, 'one', $server));
    $first = [$server->fresh()->secrets, $server->stacks->map(fn ($s) => $s->fresh()->secrets)->all()];

    provisionerWith(new RecordingRunner(healthyMysql()))->provision($server->fresh(), Task::begin(Task::TYPE_PROVISION, 'two', $server));
    $second = [$server->fresh()->secrets, $server->stacks->map(fn ($s) => $s->fresh()->secrets)->all()];

    // Rotating a password under a running database would take it down.
    expect($second)->toBe($first);
});

/*
|--------------------------------------------------------------------------
| Shared infra
|--------------------------------------------------------------------------
*/

test('the shared services publish no ports and the Redis ACL file is written once', function () {
    $server = Server::factory()->create();
    $files = (new InfraRenderer)->files($server);

    expect($files['/srv/_infra/docker-compose.yml'][0])->not->toContain('ports:');
    // Rewriting it would delete every stack user ACL SAVE has written.
    expect($files['/srv/_infra/redis/users.acl'][2])->toBeTrue();
    expect($files['/srv/_infra/mysql.env'][1])->toBe('600');
    expect($files['/srv/_infra/nats.conf'][0])->toContain($server->secret('nats_token'));
});

/*
|--------------------------------------------------------------------------
| Per-stack isolation
|--------------------------------------------------------------------------
*/

test('a testing stack is granted only its own databases', function () {
    $server = provisionableServer();
    $testing = $server->stacks->firstWhere('tier', Stack::TIER_TESTING);
    $provisioner = new StackProvisioner(new RecordingRunner);

    $sql = base64_decode(Str::between($provisioner->mysql($testing, collect([Service::first()])), "printf '%s' '", "'"));

    expect($sql)
        ->toContain('GRANT ALL PRIVILEGES ON `hiringpizza_testing`.*')
        ->not->toContain('`hiringpizza`.*')
        // The root password stays in the container's environment.
        ->not->toContain('MYSQL_ROOT_PASSWORD');
});

test('the testing Redis user is confined to its prefix and cannot flush', function () {
    $server = provisionableServer();
    $testing = $server->stacks->firstWhere('tier', Stack::TIER_TESTING);

    $command = (new StackProvisioner(new RecordingRunner))->redis($testing);
    $acl = base64_decode(Str::between($command, "printf '%s' '", "'"));

    expect($acl)
        ->toContain('ACL SETUSER acme_testing reset on')
        ->toContain('~acme_testing:*')
        ->toContain('-flushall')
        ->toContain('-flushdb')
        ->toContain('ACL SAVE')
        ->not->toContain('~*');
});

test('testing gets the testing stream twins and every consumer replays from the start', function () {
    $server = provisionableServer();
    $testing = $server->stacks->firstWhere('tier', Stack::TIER_TESTING);
    $runner = new RecordingRunner;

    $runner->commands[] = (new StackProvisioner($runner))->nats($testing, collect([Service::first()]));
    $script = $runner->transcript();

    expect($script)
        ->toContain('nats stream add HIRING_TESTING_EVENTS --subjects \'hiring.testing.v1.>\'')
        ->toContain('nats stream add AUTH_TESTING_EVENTS')
        ->toContain('nats consumer add AUTH_TESTING_EVENTS HIRING_AUTH_TESTING_CONSUMER --pull --deliver all')
        // Re-running creates nothing twice.
        ->toContain('nats stream info HIRING_TESTING_EVENTS')
        ->not->toContain('HIRING_EVENTS ');
});

test('a stack name that cannot be a database user is refused before any SQL runs', function () {
    $server = provisionableServer();
    $stack = $server->stacks->first();
    $stack->forceFill(['compose_project' => "bad'name"])->save();
    $runner = new RecordingRunner;

    $failure = (new StackProvisioner($runner))->prepare($stack, new NullTarget, fn () => null);

    expect($failure)->toContain('cannot be a database user');
    expect($runner->commands)->toBeEmpty();
});

/*
|--------------------------------------------------------------------------
| Settings
|--------------------------------------------------------------------------
*/

test('settings are admin-only and tokens are write-only', function () {
    Setting::put(Setting::GITHUB_TOKEN, 'github_pat_secretvalue');
    Setting::put(Setting::ACME_EMAIL, 'ops@example.com');

    $this->actingAs(actorWithRole('operator'))->get('/control-plane/settings')->assertForbidden();

    $response = $this->actingAs(actorWithRole('admin'))->get('/control-plane/settings')->assertOk();

    expect($response->getContent())
        ->not->toContain('github_pat_secretvalue')
        ->toContain('ops@example.com');
});

test('saving with a token left blank keeps it, and remove clears it', function () {
    Setting::put(Setting::GITHUB_TOKEN, 'github_pat_secretvalue');
    Setting::put(Setting::REGISTRY_PULL_TOKEN, 'ghp_pull');
    $admin = actorWithRole('admin');

    $this->actingAs($admin)->put('/control-plane/settings', [
        Setting::GITHUB_TOKEN => '',
        Setting::ACME_EMAIL => 'certs@example.com',
        'clear' => [Setting::REGISTRY_PULL_TOKEN],
    ])->assertSessionHas('success');

    expect(Setting::get(Setting::GITHUB_TOKEN))->toBe('github_pat_secretvalue');
    expect(Setting::get(Setting::ACME_EMAIL))->toBe('certs@example.com');
    expect(Setting::has(Setting::REGISTRY_PULL_TOKEN))->toBeFalse();
});
