<?php

use App\Models\Build;
use App\Models\Client;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceEnvDefinition;
use App\Models\Setting;
use App\Models\Stack;
use App\Models\StackService;
use App\Services\Guide\SetupGuide;
use App\Services\Stack\EnvResolver;
use App\Services\Stack\StackRenderer;
use Database\Seeders\RoleSeeder;
use Database\Seeders\ServiceCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    config(['deploy.root' => '/srv', 'deploy.ports.base' => 40000, 'deploy.ports.block_size' => 100]);
});

function settingsFilled(): void
{
    foreach ([Setting::GITHUB_TOKEN, Setting::REGISTRY_USERNAME, Setting::REGISTRY_PUSH_TOKEN, Setting::REGISTRY_PULL_TOKEN, Setting::GITHUB_WEBHOOK_SECRET] as $key) {
        Setting::put($key, 'value-'.$key);
    }
}

function nextStep(): ?array
{
    return app(SetupGuide::class)->next();
}

/*
|--------------------------------------------------------------------------
| The dashboard says what to do next
|--------------------------------------------------------------------------
*/

test('a fresh control plane is told to connect GitHub first, then the rest in order', function () {
    expect(nextStep())->key->toBe('tokens')->href->toBe('/control-plane/settings');

    settingsFilled();
    expect(nextStep())->key->toBe('services')->action->toBe('seed-services');

    (new ServiceCatalogSeeder)->run();
    expect(nextStep())->key->toBe('server')->href->toBe('/servers/create');

    $server = Server::factory()->verified()->create();
    expect(nextStep())->key->toBe('server')->href->toBe("/servers/{$server->id}")->cta->toBe('Open the server');

    $server->update(['provisioned_at' => now()]);
    expect(nextStep())->key->toBe('client')->href->toBe('/clients/create');
});

test('a client is walked from choosing services to live', function () {
    settingsFilled();
    (new ServiceCatalogSeeder)->run();
    $server = Server::factory()->verified()->create(['provisioned_at' => now()]);
    $client = Client::factory()->create(['name' => 'ACME', 'slug' => 'acme', 'domain' => 'acme.cloud']);
    $testing = Stack::factory()->create(['client_id' => $client->id, 'server_id' => $server->id, 'tier' => 'testing', 'compose_project' => 'acme_testing']);
    $live = Stack::factory()->create(['client_id' => $client->id, 'server_id' => $server->id, 'tier' => 'live', 'compose_project' => 'acme_live']);
    $guide = app(SetupGuide::class);

    $next = $guide->next();
    expect($next)->scope->toBe('ACME')->key->toBe('services')->href->toBe('/clients/acme');

    $auth = Service::where('slug', 'pizzasys')->first();
    $screens = Service::where('slug', 'screens')->first();
    StackService::create(['stack_id' => $testing->id, 'service_id' => $auth->id, 'enabled' => true]);
    StackService::create(['stack_id' => $testing->id, 'service_id' => $screens->id, 'enabled' => true]);

    // Screens needs LiveKit credentials nobody has typed.
    $next = $guide->next();
    expect($next)->key->toBe('env')->href->toBe('/clients/acme/env');
    expect($next['detail'])->toContain('Screens: LIVEKIT');

    StackService::where('service_id', $screens->id)->delete();
    expect($guide->next())->key->toBe('builds')->href->toBe('/builds');

    Build::create(['service_id' => $auth->id, 'branch' => 'main', 'commit_sha' => str_repeat('a', 40), 'image_tag' => 'aaaaaaa', 'digest' => 'sha256:a', 'status' => 'success', 'built_at' => now()]);
    expect($guide->next())->key->toBe('testing')->href->toBe("/stacks/{$testing->id}/plan");

    StackService::where('stack_id', $testing->id)->update(['current_digest' => 'sha256:a']);
    expect($guide->next())->key->toBe('live')->cta->toBe('Enable services on live')->href->toBe('/clients/acme');

    StackService::create(['stack_id' => $live->id, 'service_id' => $auth->id, 'enabled' => true]);
    expect($guide->next())->key->toBe('live')->cta->toBe('Promote to live')->href->toBe("/stacks/{$live->id}/plan");

    StackService::where('stack_id', $live->id)->update(['current_digest' => 'sha256:a']);
    expect($guide->next())->toBeNull();

    $summary = $guide->clients()[0];
    expect($summary)->done->toBe(6)->total->toBe(6)->behind->toBe(0);

    Build::create(['service_id' => $auth->id, 'branch' => 'main', 'commit_sha' => str_repeat('b', 40), 'image_tag' => 'bbbbbbb', 'digest' => 'sha256:b', 'status' => 'success', 'built_at' => now()->addMinute()]);
    expect($guide->clients()[0]['behind'])->toBe(1);
});

test('the dashboard and the client page carry the steps', function () {
    settingsFilled();
    $client = Client::factory()->create(['slug' => 'acme']);
    Stack::factory()->create(['client_id' => $client->id, 'tier' => 'testing']);

    $this->actingAs(actorWithRole('operator'))->get('/dashboard')->assertInertia(fn (Assert $page) => $page
        ->where('guide.next.key', 'services')
        ->has('guide.setup', 5)
        ->has('guide.clients', 1));

    $this->actingAs(actorWithRole('operator'))->get('/clients/acme')->assertInertia(fn (Assert $page) => $page
        ->has('steps', 6)
        ->where('steps.1.key', 'services'));

    $this->actingAs(actorWithRole('viewer'))->get('/guide')->assertOk();
});

/*
|--------------------------------------------------------------------------
| The standard catalog
|--------------------------------------------------------------------------
*/

test('the standard services can be added with one click and refreshed without losing edits', function () {
    $this->actingAs(actorWithRole('operator'))->post('/services/seed')->assertRedirect('/services');

    expect(Service::count())->toBe(11);
    expect(Service::where('identity_provider', true)->value('slug'))->toBe('pizzasys');
    expect(Service::where('slug', 'pizzasys')->first())->default_branch->toBe('events_testing')->main_branch->toBe('events');
    expect(Service::where('slug', 'qa')->first())->default_branch->toBe('events_testing')->main_branch->toBe('events');

    $hiring = Service::where('slug', 'hiring')->first();
    $hiring->update(['default_branch' => 'release']);
    $hiring->envDefinitions()->where('key', 'TCP_DRIVER')->update(['default_value' => 'tcp']);

    $this->post('/services/seed')->assertSessionHas('success', 'The catalog is up to date.');

    $hiring->refresh();
    // Service rows follow the repositories; env keys keep what was typed.
    expect($hiring->default_branch)->toBe('testing');
    expect($hiring->envDefinitions()->where('key', 'TCP_DRIVER')->value('default_value'))->toBe('tcp');
    $this->actingAs(actorWithRole('viewer'))->post('/services/seed')->assertForbidden();
});

test('every standard service resolves fully on a stack, except the credentials someone must type', function () {
    (new ServiceCatalogSeeder)->run();
    $server = Server::factory()->verified()->create(['provisioned_at' => now()]);
    $client = Client::factory()->create(['slug' => 'acme', 'domain' => 'acme.cloud']);
    $testing = Stack::factory()->create(['client_id' => $client->id, 'server_id' => $server->id, 'tier' => 'testing', 'compose_project' => 'acme_testing', 'port_range_start' => 40000]);
    $resolver = app(EnvResolver::class);
    $missing = [];

    foreach (Service::all() as $service) {
        $entitlement = StackService::create(['stack_id' => $testing->id, 'service_id' => $service->id, 'enabled' => true])
            ->load('service.envDefinitions', 'stack.server', 'stack.client');

        foreach ($resolver->missingRequired($entitlement) as $key) {
            $missing[] = "{$service->slug}.{$key}";
        }

        $env = $resolver->resolve($entitlement);

        // Nothing points at anyone else's backend.
        foreach ($env as $key => $value) {
            expect($value)->not->toContain('lcportal.cloud');
        }

        if ($service->slug !== 'dashboard' && $service->slug !== 'pizzasys') {
            expect($env['AUTH_SERVER_BASE_URL'])->toBe('https://authtesting.acme.cloud');
            expect($env['AUTH_SERVER_VERIFY_PATH'])->toBe('/api/v1/auth/token-verify');
            expect($env['AUTH_SERVER_SERVICE_NAME'])->toBe($service->auth_service_name);
            expect($env['DEV_MODE'] ?? '1')->toBe('1');
        }
    }

    // Only LiveKit has to be typed in by a person.
    expect($missing)->toBe(['screens.LIVEKIT_API_KEY', 'screens.LIVEKIT_API_SECRET', 'screens.LIVEKIT_URL']);

    $dashboard = StackService::whereHas('service', fn ($q) => $q->where('slug', 'dashboard'))->first()->load('service.envDefinitions', 'stack.server', 'stack.client');
    $args = $resolver->buildArgs($dashboard);
    // Its code appends /auth/login, which on pizzasys lives under /api/v1.
    expect($args['NEXT_PUBLIC_API_URL'])->toBe('https://authtesting.acme.cloud/api/v1');
    $dashboardEnv = $resolver->resolve($dashboard);
    expect($dashboardEnv['AUTH_API_URL'])->toBe('https://authtesting.acme.cloud/api/v1');
    expect($dashboardEnv['HIRING_API_URL'])->toBe('https://hiringtesting.acme.cloud/api');
    expect($dashboardEnv['TOOLBOX_API_URL'])->toBe('https://toolboxtesting.acme.cloud/api/v1');
    expect($dashboardEnv['NEW_MAINTENANCE_API_URL'])->toBe('https://maintenancetesting.acme.cloud/api');
    // LiveKit: the CSP allows only this host, and the dashboard needs it over both schemes.
    expect($args['SCREEN_PROJECT_LIVEKIT_DOMAIN'])->toBe('https://screenstesting.acme.cloud');
    expect($args['SCREEN_PROJECT_LIVEKIT_WSS'])->toBe('wss://screenstesting.acme.cloud');
    expect($dashboardEnv['OPERATIONS_TIMEOUT_MS'])->toBe('30000');
    expect($dashboardEnv)->not->toHaveKey('QA_TIMEOUT_MS');
    expect($dashboardEnv['DSPR_API_URL'])->toBe('https://datatesting.acme.cloud/api/reports/dspr');
    expect($args['NEXT_PUBLIC_REVERB_AUTH_ENDPOINT'])->toBe('https://notificationstesting.acme.cloud/api/broadcasting/auth');
    expect($args['NEXT_PUBLIC_REVERB_WS_HOST'])->toBe('notificationstesting.acme.cloud');

    $notifications = StackService::whereHas('service', fn ($q) => $q->where('slug', 'notifications'))->first()->load('service.envDefinitions', 'stack.server', 'stack.client');
    $env = $resolver->resolve($notifications);
    expect($env['REVERB_APP_KEY'])->toBe($args['NEXT_PUBLIC_REVERB_KEY']);
    expect($env['REVERB_HOST'])->toBe('acme_testing_notifications_reverb');
});

test('a listening process gets its own host port and an nginx location', function () {
    (new ServiceCatalogSeeder)->run();
    $client = Client::factory()->create(['slug' => 'acme', 'domain' => 'acme.cloud']);
    $live = Stack::factory()->create(['client_id' => $client->id, 'tier' => 'live', 'compose_project' => 'acme_live', 'port_range_start' => 40000]);
    $notifications = Service::where('slug', 'notifications')->first();
    $entitlement = StackService::create(['stack_id' => $live->id, 'service_id' => $notifications->id, 'enabled' => true]);
    $renderer = app(StackRenderer::class);

    $compose = $renderer->compose($live);
    $entitlement->refresh();

    expect($entitlement->host_port)->toBe(40000);
    expect($entitlement->process_ports)->toBe(['reverb' => 40001]);
    expect($compose)
        ->toContain('container_name: acme_live_notifications_reverb')
        ->toContain('"127.0.0.1:40001:8080"')
        ->toContain('php artisan reverb:start');

    $vhost = $renderer->nginx($entitlement->load('service', 'stack.client'));
    expect($vhost)->toContain('location /app {')->toContain('proxy_pass http://127.0.0.1:40001;');
    expect($renderer->nginxHttpOnly($entitlement))->toContain('location /app {');
    // Not while the maintenance page is up.
    expect($renderer->nginx($entitlement, maintenance: true))->not->toContain('location /app {');
});

test('the catalog form accepts a listening process', function () {
    $service = Service::factory()->create(['slug' => 'notifications']);

    $this->actingAs(actorWithRole('operator'))->put('/services/notifications/catalog', [
        'processes' => [['name' => 'reverb', 'command' => 'php artisan reverb:start', 'port' => 8080, 'path' => '/app']],
    ])->assertSessionHas('success');

    expect($service->fresh()->processList()[0])->toMatchArray(['port' => 8080, 'path' => '/app']);
    expect(ServiceEnvDefinition::count())->toBe(0);
});
