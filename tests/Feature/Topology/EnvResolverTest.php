<?php

use App\Models\Client;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceEnvDefinition;
use App\Models\Stack;
use App\Models\StackEnvValue;
use App\Models\StackService;
use App\Services\Stack\EnvResolver;
use Database\Seeders\ServiceCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function entitlement(string $tier = Stack::TIER_LIVE, array $serviceAttributes = []): StackService
{
    $client = Client::factory()->create(['domain' => 'acme.cloud', 'name' => 'ACME']);
    $server = Server::factory()->create();

    $stack = Stack::factory()->create([
        'client_id' => $client->id,
        'server_id' => $server->id,
        'tier' => $tier,
        'compose_project' => 'acme_'.$tier,
    ]);

    $service = Service::factory()->create(array_merge([
        'slug' => 'hiring',
        'subdomain' => 'hiring',
        'database_name' => 'hiringpizza',
    ], $serviceAttributes));

    return StackService::create([
        'stack_id' => $stack->id,
        'service_id' => $service->id,
        'enabled' => true,
    ]);
}

function defineEnv(Service $service, string $key, string $source, ?string $default = null, bool $required = true): ServiceEnvDefinition
{
    return ServiceEnvDefinition::create([
        'service_id' => $service->id,
        'key' => $key,
        'source' => $source,
        'default_value' => $default,
        'required' => $required,
    ]);
}

/*
|--------------------------------------------------------------------------
| Derived values
|--------------------------------------------------------------------------
*/

test('the tier decides the database name and the domain', function () {
    $live = entitlement(Stack::TIER_LIVE);
    defineEnv($live->service, 'DB_DATABASE', ServiceEnvDefinition::SOURCE_STACK, 'database');
    defineEnv($live->service, 'APP_URL', ServiceEnvDefinition::SOURCE_STACK, 'app_url');

    $resolved = app(EnvResolver::class)->resolve($live->fresh());

    expect($resolved['DB_DATABASE'])->toBe('hiringpizza');
    expect($resolved['APP_URL'])->toBe('https://hiring.acme.cloud');
});

test('the testing tier is suffixed so both tiers can share one server', function () {
    $testing = entitlement(Stack::TIER_TESTING);
    defineEnv($testing->service, 'DB_DATABASE', ServiceEnvDefinition::SOURCE_STACK, 'database');
    defineEnv($testing->service, 'APP_URL', ServiceEnvDefinition::SOURCE_STACK, 'app_url');
    defineEnv($testing->service, 'REDIS_DB', ServiceEnvDefinition::SOURCE_STACK, 'redis_index');
    defineEnv($testing->service, 'DEV_MODE', ServiceEnvDefinition::SOURCE_STACK, 'dev_mode');

    $resolved = app(EnvResolver::class)->resolve($testing->fresh());

    // Matching the existing estate: hiring.lcportal.cloud is live,
    // hiringtesting.lcportal.cloud is not.
    expect($resolved['DB_DATABASE'])->toBe('hiringpizza_testing');
    expect($resolved['APP_URL'])->toBe('https://hiringtesting.acme.cloud');
    // A shared Redis, kept apart by index (and by an ACL user on the host).
    expect($resolved['REDIS_DB'])->toBe('1');
    // What the services already use to pick their NATS stream twins.
    expect($resolved['DEV_MODE'])->toBe('1');
});

test('one service can be pointed at another without anyone typing a URL', function () {
    $entitlement = entitlement();

    Service::factory()->create(['slug' => 'auth', 'subdomain' => 'auth']);
    defineEnv($entitlement->service, 'AUTH_SERVER_BASE_URL', ServiceEnvDefinition::SOURCE_SERVICE_URL, 'auth:/api/v1');

    $resolved = app(EnvResolver::class)->resolve($entitlement->fresh());

    // Three different spellings of this path exist across the real services
    // today. Deriving it is how that stops happening.
    expect($resolved['AUTH_SERVER_BASE_URL'])->toBe('https://auth.acme.cloud/api/v1');
});

test('sibling URLs follow the tier too', function () {
    $entitlement = entitlement(Stack::TIER_TESTING);

    Service::factory()->create(['slug' => 'auth', 'subdomain' => 'auth']);
    defineEnv($entitlement->service, 'AUTH_SERVER_BASE_URL', ServiceEnvDefinition::SOURCE_SERVICE_URL, 'auth:/api/v1');

    expect(app(EnvResolver::class)->resolve($entitlement->fresh())['AUTH_SERVER_BASE_URL'])
        ->toBe('https://authtesting.acme.cloud/api/v1');
});

/*
|--------------------------------------------------------------------------
| Overrides
|--------------------------------------------------------------------------
*/

test('a service-scoped value beats a stack-wide one, which beats a default', function () {
    $entitlement = entitlement();
    defineEnv($entitlement->service, 'LOG_LEVEL', ServiceEnvDefinition::SOURCE_LITERAL, 'info');

    expect(app(EnvResolver::class)->resolve($entitlement->fresh())['LOG_LEVEL'])->toBe('info');

    StackEnvValue::create([
        'stack_id' => $entitlement->stack_id,
        'service_id' => null,
        'key' => 'LOG_LEVEL',
        'value' => 'warning',
    ]);

    expect(app(EnvResolver::class)->resolve($entitlement->fresh())['LOG_LEVEL'])->toBe('warning');

    StackEnvValue::create([
        'stack_id' => $entitlement->stack_id,
        'service_id' => $entitlement->service_id,
        'key' => 'LOG_LEVEL',
        'value' => 'debug',
    ]);

    expect(app(EnvResolver::class)->resolve($entitlement->fresh())['LOG_LEVEL'])->toBe('debug');
});

test('stack env values are encrypted at rest', function () {
    $entitlement = entitlement();

    StackEnvValue::create([
        'stack_id' => $entitlement->stack_id,
        'key' => 'DB_PASSWORD',
        'value' => 'hunter2',
        'is_secret' => true,
    ]);

    $stored = DB::table('stack_env_values')->value('value');

    expect($stored)->not->toBe('hunter2');
    expect(StackEnvValue::first()->value)->toBe('hunter2');
});

/*
|--------------------------------------------------------------------------
| The check that runs before anything swaps
|--------------------------------------------------------------------------
*/

test('a required value with nowhere to come from is reported, not defaulted', function () {
    $entitlement = entitlement();

    defineEnv($entitlement->service, 'HOOKS_API_KEY', ServiceEnvDefinition::SOURCE_SECRET, null, required: true);
    defineEnv($entitlement->service, 'OPTIONAL_THING', ServiceEnvDefinition::SOURCE_LITERAL, null, required: false);

    // This is the defect from the dashboard generalised: a missing value must
    // stop the deploy rather than silently fall back to a hardcoded default
    // that points at somebody else's production backend.
    expect(app(EnvResolver::class)->missingRequired($entitlement->fresh()))
        ->toBe(['HOOKS_API_KEY']);
});

test('a required value supplied by the stack is not missing', function () {
    $entitlement = entitlement();
    defineEnv($entitlement->service, 'HOOKS_API_KEY', ServiceEnvDefinition::SOURCE_SECRET, null, required: true);

    StackEnvValue::create([
        'stack_id' => $entitlement->stack_id,
        'service_id' => $entitlement->service_id,
        'key' => 'HOOKS_API_KEY',
        'value' => 'supplied',
        'is_secret' => true,
    ]);

    expect(app(EnvResolver::class)->missingRequired($entitlement->fresh()))->toBe([]);
});

test('an empty string counts as missing', function () {
    $entitlement = entitlement();
    defineEnv($entitlement->service, 'REQUIRED_URL', ServiceEnvDefinition::SOURCE_LITERAL, '', required: true);

    expect(app(EnvResolver::class)->missingRequired($entitlement->fresh()))->toBe(['REQUIRED_URL']);
});

test('build arguments are separated from runtime values', function () {
    $entitlement = entitlement(Stack::TIER_LIVE, ['per_stack_build' => true]);

    defineEnv($entitlement->service, 'RUNTIME_THING', ServiceEnvDefinition::SOURCE_LITERAL, 'a');

    ServiceEnvDefinition::create([
        'service_id' => $entitlement->service_id,
        'key' => 'NEXT_PUBLIC_API_URL',
        'source' => ServiceEnvDefinition::SOURCE_STACK,
        'default_value' => 'app_url',
        'is_build_arg' => true,
    ]);

    // Next.js freezes these into the bundle, so changing one means a rebuild,
    // not a restart. Keeping them distinct is what lets the release runner
    // tell the difference.
    expect(app(EnvResolver::class)->buildArgs($entitlement->fresh()))
        ->toHaveKey('NEXT_PUBLIC_API_URL')
        ->not->toHaveKey('RUNTIME_THING');
});

test('a dashboard seeded with the old backend addresses is corrected by the migration', function () {
    (new ServiceCatalogSeeder)->run();
    $dashboard = Service::where('slug', 'dashboard')->first();

    // What the catalog used to say: the browser got the auth host, and every
    // backend base was a bare host.
    $dashboard->envDefinitions()->where('key', 'NEXT_PUBLIC_API_URL')->update(['default_value' => 'pizzasys']);
    $dashboard->envDefinitions()->where('key', 'AUTH_API_URL')->update(['default_value' => 'pizzasys']);
    $dashboard->envDefinitions()->where('key', 'TOOLBOX_API_URL')->update(['default_value' => 'toolbox']);

    (require database_path('migrations/2026_10_22_100000_fix_dashboard_backend_urls.php'))->up();

    $definitions = $dashboard->envDefinitions()->pluck('default_value', 'key');
    expect($definitions['NEXT_PUBLIC_API_URL'])->toBe('pizzasys:/api/v1');
    expect($definitions['DSPR_API_URL'])->toBe('data:/api/reports/dspr');
    expect($definitions['AUTH_API_URL'])->toBe('pizzasys:/api/v1');
    expect($definitions['TOOLBOX_API_URL'])->toBe('toolbox:/api/v1');
});
