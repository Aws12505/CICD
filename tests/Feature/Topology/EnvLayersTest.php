<?php

use App\Jobs\ApplyStackJob;
use App\Models\Client;
use App\Models\ClientSetting;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceEnvDefinition;
use App\Models\Stack;
use App\Models\StackEnvValue;
use App\Models\StackService;
use App\Models\Task;
use App\Services\Remote\CommandResult;
use App\Services\Stack\EnvResolver;
use App\Services\Stack\StackApplier;
use App\Services\Stack\StackRenderer;
use App\Services\Stack\StackSync;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    config(['deploy.root' => '/srv']);
});

/** ACME with both tiers on one provisioned server. */
function envClient(): Client
{
    $client = Client::factory()->create(['slug' => 'acme', 'domain' => 'acme.cloud']);
    $server = Server::factory()->verified()->create(['provisioned_at' => now()]);

    foreach ([Stack::TIER_TESTING, Stack::TIER_LIVE] as $i => $tier) {
        Stack::factory()->create([
            'client_id' => $client->id,
            'server_id' => $server->id,
            'tier' => $tier,
            'compose_project' => "acme_{$tier}",
            'port_range_start' => 40000 + $i * 100,
        ]);
    }

    return $client->fresh();
}

function envService(string $slug, array $definitions, array $attributes = []): Service
{
    $service = Service::factory()->create(array_merge(['slug' => $slug, 'subdomain' => $slug, 'database_name' => $slug], $attributes));

    foreach ($definitions as $key => [$source, $default]) {
        ServiceEnvDefinition::create([
            'service_id' => $service->id,
            'key' => $key,
            'source' => $source,
            'default_value' => $default,
            'required' => true,
            'is_build_arg' => false,
        ]);
    }

    return $service;
}

function envEntitle(Stack $stack, Service $service, array $attributes = []): StackService
{
    return StackService::create(array_merge([
        'stack_id' => $stack->id,
        'service_id' => $service->id,
        'enabled' => true,
    ], $attributes))->load('service.envDefinitions', 'stack.server', 'stack.client');
}

function envTier(Client $client, string $tier): Stack
{
    return $client->stacks()->where('tier', $tier)->firstOrFail();
}

/*
|--------------------------------------------------------------------------
| Generated and derived values
|--------------------------------------------------------------------------
*/

test('an APP_KEY is generated once per stack and service and never changes', function () {
    $client = envClient();
    $hiring = envService('hiring', ['APP_KEY' => ['generated', 'app_key']]);
    $ops = envService('operations', ['APP_KEY' => ['generated', 'app_key']]);
    $resolver = app(EnvResolver::class);

    $testing = $resolver->resolve(envEntitle(envTier($client, 'testing'), $hiring))['APP_KEY'];
    $again = $resolver->resolve(StackService::first()->load('service.envDefinitions', 'stack.server'))['APP_KEY'];
    $live = $resolver->resolve(envEntitle(envTier($client, 'live'), $hiring))['APP_KEY'];
    $other = $resolver->resolve(envEntitle(envTier($client, 'testing'), $ops))['APP_KEY'];

    expect($testing)->toStartWith('base64:');
    expect(strlen(base64_decode(substr($testing, 7))))->toBe(32);
    // Re-rendering must never rotate it: every encrypted column would die.
    expect($again)->toBe($testing);
    expect($live)->not->toBe($testing);
    expect($other)->not->toBe($testing);
});

test('a service and pizzasys get the same token for that service, per stack', function () {
    $client = envClient();
    $hiring = envService('hiring', ['AUTH_SERVER_CALL_TOKEN' => ['generated', 'service_token']]);
    $pizzasys = envService('pizzasys', ['HIRING_SERVICE_TOKEN' => ['generated', 'service_token:hiring']]);
    $resolver = app(EnvResolver::class);
    $testing = envTier($client, 'testing');

    $own = $resolver->resolve(envEntitle($testing, $hiring))['AUTH_SERVER_CALL_TOKEN'];
    $seen = $resolver->resolve(envEntitle($testing, $pizzasys))['HIRING_SERVICE_TOKEN'];

    expect($own)->toBe($seen)->toHaveLength(64);
    expect($resolver->serviceToken($testing->fresh(), $hiring))->toBe($own);
    expect($resolver->serviceToken(envTier($client, 'live'), $hiring))->not->toBe($own);
});

test('database, Redis and NATS settings come from the stack and match what provisioning created', function () {
    $client = envClient();
    $hiring = envService('hiring', [
        'DB_HOST' => ['stack', 'db_host'],
        'DB_DATABASE' => ['stack', 'database'],
        'DB_USERNAME' => ['stack', 'db_username'],
        'DB_PASSWORD' => ['stack', 'db_password'],
        'ANALYTICS_DB' => ['stack', 'database:hiring_analytics'],
        'REDIS_USERNAME' => ['stack', 'redis_username'],
        'REDIS_PASSWORD' => ['stack', 'redis_password'],
        'REDIS_PREFIX' => ['stack', 'redis_prefix'],
        'NATS_URL' => ['stack', 'nats_url'],
        'AUTH_SERVER_SERVICE_NAME' => ['stack', 'auth_service_name'],
        'AUTH_SERVER_VERIFY_PATH' => ['stack', 'auth_verify_path'],
    ], ['database_name' => 'hiringpizza', 'auth_service_name' => 'Hiring']);
    $testing = envTier($client, 'testing');

    $env = app(EnvResolver::class)->resolve(envEntitle($testing, $hiring));
    $testing->refresh();

    expect($env)->toMatchArray([
        'DB_HOST' => 'mysql',
        'DB_DATABASE' => 'hiringpizza_testing',
        'DB_USERNAME' => 'acme_testing',
        'DB_PASSWORD' => $testing->secret('mysql_password'),
        'ANALYTICS_DB' => 'hiring_analytics_testing',
        'REDIS_USERNAME' => 'acme_testing',
        'REDIS_PASSWORD' => $testing->secret('redis_password'),
        'REDIS_PREFIX' => 'acme_testing:',
        'NATS_URL' => 'nats://'.$testing->server->secret('nats_token').'@nats:4222',
        'AUTH_SERVER_SERVICE_NAME' => 'Hiring',
        'AUTH_SERVER_VERIFY_PATH' => '/api/v1/auth/token-verify',
    ]);
});

/*
|--------------------------------------------------------------------------
| Layers
|--------------------------------------------------------------------------
*/

test('stack beats client, and a service-scoped row beats an every-service one', function () {
    $client = envClient();
    $hiring = envService('hiring', ['A' => ['literal', 'definition'], 'B' => ['literal', 'definition'], 'C' => ['literal', 'definition'], 'D' => ['literal', 'definition']]);
    $testing = envTier($client, 'testing');

    ClientSetting::create(['client_id' => $client->id, 'key' => 'A', 'value' => 'client-all']);
    ClientSetting::create(['client_id' => $client->id, 'key' => 'B', 'value' => 'client-all']);
    ClientSetting::create(['client_id' => $client->id, 'service_id' => $hiring->id, 'key' => 'B', 'value' => 'client-hiring']);
    ClientSetting::create(['client_id' => $client->id, 'key' => 'C', 'value' => 'client-all']);
    StackEnvValue::create(['stack_id' => $testing->id, 'key' => 'C', 'value' => 'stack-all']);
    StackEnvValue::create(['stack_id' => $testing->id, 'key' => 'D', 'value' => 'stack-all']);
    StackEnvValue::create(['stack_id' => $testing->id, 'service_id' => $hiring->id, 'key' => 'D', 'value' => 'stack-hiring']);

    $env = app(EnvResolver::class)->resolve(envEntitle($testing, $hiring));
    $live = app(EnvResolver::class)->resolve(envEntitle(envTier($client, 'live'), $hiring));

    expect($env)->toMatchArray(['A' => 'client-all', 'B' => 'client-hiring', 'C' => 'stack-all', 'D' => 'stack-hiring']);
    // Testing's overrides stay on testing.
    expect($live)->toMatchArray(['C' => 'client-all', 'D' => 'definition']);
});

test('a vendor credential set once for both tiers satisfies a required key on both', function () {
    $client = envClient();
    $ops = envService('operations', ['HUMANITY_API_KEY' => ['secret', null]]);
    $resolver = app(EnvResolver::class);

    expect($resolver->missingRequired(envEntitle(envTier($client, 'testing'), $ops)))->toBe(['HUMANITY_API_KEY']);

    ClientSetting::create(['client_id' => $client->id, 'key' => 'HUMANITY_API_KEY', 'value' => 'hk_123', 'is_secret' => true]);

    foreach (StackService::all() as $entitlement) {
        expect($resolver->missingRequired($entitlement->load('service.envDefinitions', 'stack.server')))->toBe([]);
    }
    expect($resolver->missingRequired(envEntitle(envTier($client, 'live'), $ops)))->toBe([]);
});

/*
|--------------------------------------------------------------------------
| The environment screen
|--------------------------------------------------------------------------
*/

test('the environment screen never shows a secret', function () {
    $client = envClient();
    $ops = envService('operations', [
        'HUMANITY_API_KEY' => ['secret', null],
        'DB_PASSWORD' => ['stack', 'db_password'],
        'APP_KEY' => ['generated', 'app_key'],
        'HUMANITY_URL' => ['literal', 'https://www.humanity.com'],
    ]);
    $entitlement = envEntitle(envTier($client, 'testing'), $ops);
    ClientSetting::create(['client_id' => $client->id, 'key' => 'HUMANITY_API_KEY', 'value' => 'hk_supersecret', 'is_secret' => true]);

    $env = app(EnvResolver::class)->resolve($entitlement);
    $response = $this->actingAs(actorWithRole('operator'))->get('/clients/acme/env')->assertOk();

    expect($response->getContent())
        ->not->toContain('hk_supersecret')
        ->not->toContain($env['DB_PASSWORD'])
        ->not->toContain($env['APP_KEY'])
        ->toContain('www.humanity.com');
});

test('viewers cannot open or change a client environment', function () {
    envClient();

    $this->actingAs(actorWithRole('viewer'))->get('/clients/acme/env')->assertForbidden();
    $this->actingAs(actorWithRole('viewer'))->put('/clients/acme/env', ['entries' => []])->assertForbidden();
});

test('saving: values are stored per layer, a blank secret is kept, a blank value inherits', function () {
    $client = envClient();
    $ops = envService('operations', ['HUMANITY_API_KEY' => ['secret', null], 'LABEL' => ['literal', null]]);
    ClientSetting::create(['client_id' => $client->id, 'key' => 'HUMANITY_API_KEY', 'value' => 'old', 'is_secret' => true]);
    StackEnvValue::create(['stack_id' => envTier($client, 'live')->id, 'key' => 'LABEL', 'value' => 'Live label']);

    $this->actingAs(actorWithRole('operator'))->put('/clients/acme/env', ['entries' => [
        ['scope' => 'client', 'service_id' => null, 'key' => 'HUMANITY_API_KEY', 'value' => '', 'is_secret' => true],
        ['scope' => 'testing', 'service_id' => $ops->id, 'key' => 'LABEL', 'value' => 'Testing label', 'is_secret' => false],
        ['scope' => 'live', 'service_id' => null, 'key' => 'LABEL', 'value' => '', 'is_secret' => false],
    ]])->assertSessionHas('success');

    expect(ClientSetting::sole()->value)->toBe('old');
    expect(StackEnvValue::sole())
        ->stack_id->toBe(envTier($client, 'testing')->id)
        ->service_id->toBe($ops->id)
        ->value->toBe('Testing label');
});

test('clear removes a stored secret', function () {
    $client = envClient();
    ClientSetting::create(['client_id' => $client->id, 'key' => 'TCP_TOKEN', 'value' => 'abc', 'is_secret' => true]);

    $this->actingAs(actorWithRole('admin'))->put('/clients/acme/env', ['entries' => [
        ['scope' => 'client', 'service_id' => null, 'key' => 'TCP_TOKEN', 'value' => '', 'is_secret' => true, 'remove' => true],
    ]]);

    expect(ClientSetting::count())->toBe(0);
});

test('keys must be upper snake case', function () {
    envClient();

    $this->actingAs(actorWithRole('admin'))->put('/clients/acme/env', ['entries' => [
        ['scope' => 'client', 'service_id' => null, 'key' => 'bad key', 'value' => 'x'],
    ]])->assertSessionHasErrors('entries.0.key');
});

/*
|--------------------------------------------------------------------------
| Apply
|--------------------------------------------------------------------------
*/

test('apply queues a task for that tier', function () {
    Bus::fake();
    $client = envClient();
    $testing = envTier($client, 'testing');

    $this->actingAs(actorWithRole('operator'))
        ->post("/stacks/{$testing->id}/apply")
        ->assertRedirect('/tasks/'.Task::sole()->id);

    Bus::assertDispatched(ApplyStackJob::class, fn ($job) => $job->stackId === $testing->id);
});

test('apply refuses a server that has not been set up', function () {
    Bus::fake();
    $client = envClient();
    $testing = envTier($client, 'testing');
    $testing->server->forceFill(['provisioned_at' => null])->save();

    $this->actingAs(actorWithRole('operator'))
        ->post("/stacks/{$testing->id}/apply")
        ->assertSessionHas('error');

    Bus::assertNothingDispatched();
});

function applier(RecordingRunner $runner): StackApplier
{
    return new StackApplier(
        new StackSync(app(StackRenderer::class), $runner),
        app(StackRenderer::class),
        app(EnvResolver::class),
        $runner,
    );
}

test('apply restarts only services running what they were released with', function () {
    $client = envClient();
    $testing = envTier($client, 'testing');
    envEntitle($testing, envService('hiring', [], ['processes' => [['name' => 'queue', 'command' => 'php artisan queue:work']]]), ['desired_digest' => 'sha256:a', 'current_digest' => 'sha256:a']);
    envEntitle($testing, envService('audit', []), ['desired_digest' => 'sha256:new', 'current_digest' => 'sha256:old']);
    envEntitle($testing, envService('toolbox', []));
    $task = Task::begin(Task::TYPE_APPLY, 'Apply', $testing);
    $runner = new RecordingRunner;

    expect(applier($runner)->apply($testing, $task))->toBeTrue();

    $up = collect($runner->commands)->first(fn ($c) => str_contains($c, 'up -d'));
    expect($up)->toContain("'hiring' 'hiring_queue'")
        ->not->toContain('audit')
        ->not->toContain('toolbox');
    expect($task->fresh()->status)->toBe('succeeded');
});

test('apply on a stack with nothing deployed only writes the files', function () {
    $client = envClient();
    $testing = envTier($client, 'testing');
    envEntitle($testing, envService('hiring', []));
    $task = Task::begin(Task::TYPE_APPLY, 'Apply', $testing);
    $runner = new RecordingRunner;

    applier($runner)->apply($testing, $task);

    expect(collect($runner->commands)->filter(fn ($c) => str_contains($c, 'up -d')))->toBeEmpty();
    expect($task->fresh()->contents())->toContain('first deploy');
});

test('a failed restart fails the task', function () {
    $client = envClient();
    $testing = envTier($client, 'testing');
    envEntitle($testing, envService('hiring', []), ['desired_digest' => 'sha256:a', 'current_digest' => 'sha256:a']);
    $task = Task::begin(Task::TYPE_APPLY, 'Apply', $testing);

    applier(new RecordingRunner(fn ($c) => str_contains($c, 'up -d') ? new CommandResult(1, '', 'no space left', 1) : null))
        ->apply($testing, $task);

    expect($task->fresh())->status->toBe('failed')->error->toContain('no space left');
});

/*
|--------------------------------------------------------------------------
| Catalog: processes and deploy commands
|--------------------------------------------------------------------------
*/

test('each process becomes its own container from the same image', function () {
    $client = envClient();
    $ops = envService('operations', [], ['processes' => [
        ['name' => 'queue', 'command' => 'php artisan queue:work --queue=humanity,default'],
        ['name' => 'scheduler', 'command' => 'php artisan schedule:work'],
    ]]);
    envEntitle(envTier($client, 'live'), $ops, ['desired_digest' => 'sha256:abc']);

    $compose = app(StackRenderer::class)->compose(envTier($client, 'live'));

    expect($compose)
        ->toContain('container_name: acme_live_operations_queue')
        ->toContain('command: ["sh","-c","php artisan queue:work --queue=humanity,default"]')
        ->toContain('container_name: acme_live_operations_scheduler');
    expect(substr_count($compose, 'image: ghcr.io/'))->toBe(substr_count($compose, '@sha256:abc'));
    expect($ops->composeServices())->toBe(['operations', 'operations_queue', 'operations_scheduler']);
});

test('the catalog form saves processes and commands', function () {
    $service = Service::factory()->create(['slug' => 'operations']);

    $this->actingAs(actorWithRole('operator'))->put('/services/operations/catalog', [
        'auth_service_name' => 'Operations',
        'extra_databases' => ['ops_archive'],
        'processes' => [['name' => 'queue', 'command' => 'php artisan queue:work --queue=humanity,default']],
        'first_run_commands' => [],
        'every_deploy_commands' => ['php artisan db:seed --class=AuthRulesSeeder --force'],
    ])->assertSessionHas('success');

    expect($service->fresh())
        ->auth_service_name->toBe('Operations')
        ->extra_databases->toBe(['ops_archive'])
        ->every_deploy_commands->toBe(['php artisan db:seed --class=AuthRulesSeeder --force']);

    $this->put('/services/operations/catalog', ['processes' => [['name' => 'Bad Name', 'command' => 'x']]])
        ->assertSessionHasErrors('processes.0.name');
});
