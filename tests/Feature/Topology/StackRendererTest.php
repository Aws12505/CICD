<?php

use App\Models\Client;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceEnvDefinition;
use App\Models\Stack;
use App\Models\StackEnvValue;
use App\Models\StackService;
use App\Services\Build\BuildFiles;
use App\Services\Stack\StackRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'deploy.ports.base' => 40000,
        'deploy.ports.block_size' => 100,
        'deploy.root' => '/srv',
        'deploy.registry' => 'ghcr.io/rnd-experts-team',
    ]);
});

function renderableStack(string $tier = Stack::TIER_LIVE): Stack
{
    $client = Client::factory()->create(['slug' => 'acme', 'domain' => 'acme.cloud']);

    return Stack::factory()->create([
        'client_id' => $client->id,
        'server_id' => Server::factory()->create()->id,
        'tier' => $tier,
        'compose_project' => 'acme_'.$tier,
        'port_range_start' => 40000,
    ]);
}

function enable(Stack $stack, array $attributes = [], array $entitlementAttributes = []): StackService
{
    $service = Service::factory()->create(array_merge([
        'slug' => 'hiring',
        'subdomain' => 'hiring',
        'database_name' => 'hiringpizza',
    ], $attributes));

    return StackService::create(array_merge([
        'stack_id' => $stack->id,
        'service_id' => $service->id,
        'enabled' => true,
    ], $entitlementAttributes));
}

/*
|--------------------------------------------------------------------------
| Compose
|--------------------------------------------------------------------------
*/

test('the compose file names the stack and binds ports to localhost only', function () {
    $stack = renderableStack();
    enable($stack);

    $compose = app(StackRenderer::class)->compose($stack);

    expect($compose)->toContain('name: acme_live');
    // nginx is the only thing that should reach a service; publishing on
    // 0.0.0.0 would expose every container straight to the internet.
    expect($compose)->toContain('"127.0.0.1:40000:80"');
    expect($compose)->toContain('container_name: acme_live_hiring');
});

test('persistent data is a host bind path, not a named volume', function () {
    $stack = renderableStack();
    enable($stack);

    // The weekly backup rsyncs this path directly. A named volume would put
    // it somewhere only Docker can reach.
    expect(app(StackRenderer::class)->compose($stack))
        ->toContain('/srv/acme_live/storage/hiring:/var/www/html/storage/app');
});

test('each process gets its own container from the same image', function () {
    $stack = renderableStack();
    enable($stack, ['processes' => [['name' => 'queue', 'command' => 'php artisan queue:work']]]);

    $compose = app(StackRenderer::class)->compose($stack);

    expect($compose)->toContain('container_name: acme_live_hiring_queue');
    // Same image: a process is the same code with a different command.
    expect(substr_count($compose, 'ghcr.io/rnd-experts-team/hiring'))->toBe(2);
});

test('a customised template changes what every stack of that service gets', function () {
    $stack = renderableStack();
    $entitlement = enable($stack, ['processes' => [['name' => 'queue', 'command' => 'php artisan queue:work']]]);
    $files = app(BuildFiles::class);

    $entitlement->service->files()->create([
        'name' => 'compose.process.yml',
        'source' => 'custom',
        'contents' => str_replace('    restart: unless-stopped', "    restart: unless-stopped\n    stop_grace_period: 60s", $files->defaultContents($entitlement->service, 'compose.process.yml')),
    ]);

    $compose = app(StackRenderer::class)->compose($stack);

    // Only the process block changed; the web container keeps the default.
    expect(substr_count($compose, 'stop_grace_period: 60s'))->toBe(1);
    expect($compose)->toContain('container_name: acme_live_hiring_queue');
});

test('only enabled services are rendered', function () {
    $stack = renderableStack();
    enable($stack, ['slug' => 'hiring', 'subdomain' => 'hiring']);
    enable($stack, ['slug' => 'inventory', 'subdomain' => 'inventory'], ['enabled' => false]);

    $compose = app(StackRenderer::class)->compose($stack);

    expect($compose)->toContain('acme_live_hiring');
    expect($compose)->not->toContain('inventory');
});

test('an image is pinned by digest once one is known', function () {
    $stack = renderableStack();
    $entitlement = enable($stack, [], [
        'desired_digest' => 'sha256:abc123',
    ]);

    // A tag can be repointed at different bytes; a digest cannot. This is
    // what makes "testing and live ran the same image" a fact.
    expect(app(StackRenderer::class)->imageReference($entitlement))
        ->toBe('ghcr.io/rnd-experts-team/hiring@sha256:abc123');
});

test('an edition pulls its own image, and the tag carries the edition not the client', function () {
    $stack = renderableStack();
    $entitlement = enable($stack, [], ['edition' => 'franchisee']);

    $reference = app(StackRenderer::class)->imageReference($entitlement);

    expect($reference)->toContain('franchisee');
    expect($reference)->not->toContain('acme');
});

/*
|--------------------------------------------------------------------------
| Env files
|--------------------------------------------------------------------------
*/

test('the env file contains the resolved values for that tier', function () {
    $stack = renderableStack(Stack::TIER_TESTING);
    $entitlement = enable($stack);

    ServiceEnvDefinition::create([
        'service_id' => $entitlement->service_id,
        'key' => 'DB_DATABASE',
        'source' => ServiceEnvDefinition::SOURCE_STACK,
        'default_value' => 'database',
    ]);

    expect(app(StackRenderer::class)->env($entitlement->fresh()))
        ->toContain('DB_DATABASE=hiringpizza_testing');
});

test('values needing quotes get them, and values that do not are left alone', function () {
    $stack = renderableStack();
    $entitlement = enable($stack);

    StackEnvValue::create([
        'stack_id' => $stack->id,
        'key' => 'SIMPLE',
        'value' => 'https://example.com/api',
    ]);
    StackEnvValue::create([
        'stack_id' => $stack->id,
        'key' => 'SPACED',
        'value' => 'a value with spaces',
    ]);

    $env = app(StackRenderer::class)->env($entitlement->fresh());

    // Docker's env_file parser does not strip quotes the way a shell does,
    // so quoting everything would embed literal quote characters.
    expect($env)->toContain('SIMPLE=https://example.com/api');
    expect($env)->toContain('SPACED="a value with spaces"');
});

/*
|--------------------------------------------------------------------------
| nginx
|--------------------------------------------------------------------------
*/

test('the vhost proxies to the allocated port and redirects http to https', function () {
    $stack = renderableStack();
    $entitlement = enable($stack);

    $conf = app(StackRenderer::class)->nginx($entitlement);

    expect($conf)->toContain('server_name hiring.acme.cloud;');
    expect($conf)->toContain('proxy_pass http://127.0.0.1:40000;');
    expect($conf)->toContain('return 301 https://$host$request_uri;');
    expect($conf)->toContain('/etc/letsencrypt/live/hiring.acme.cloud/fullchain.pem');
});

test('the testing tier gets its own hostname on the same server', function () {
    $stack = renderableStack(Stack::TIER_TESTING);
    $entitlement = enable($stack);

    expect(app(StackRenderer::class)->nginx($entitlement))
        ->toContain('server_name hiringtesting.acme.cloud;');
});

test('maintenance mode serves a page instead of proxying', function () {
    $stack = renderableStack();
    $entitlement = enable($stack);

    $conf = app(StackRenderer::class)->nginx($entitlement, maintenance: true);

    // During a destructive migration the app must not be reachable at all,
    // or it will write to a schema that is being rebuilt underneath it.
    expect($conf)->not->toContain('proxy_pass');
    expect($conf)->toContain('=503');
});

/*
|--------------------------------------------------------------------------
| The whole set
|--------------------------------------------------------------------------
*/

test('files() returns everything the stack needs, keyed by path', function () {
    $stack = renderableStack();
    enable($stack);

    $files = app(StackRenderer::class)->files($stack);

    expect(array_keys($files))->toBe([
        'docker-compose.yml',
        'env/hiring.env',
        'nginx/hiring.acme.cloud.conf',
        'nginx/hiring.acme.cloud.http',
    ]);
});

test('rendering is deterministic, so drift means something changed', function () {
    $stack = renderableStack();
    enable($stack, ['slug' => 'b-service', 'subdomain' => 'b']);
    enable($stack, ['slug' => 'a-service', 'subdomain' => 'a']);

    $renderer = app(StackRenderer::class);

    // Identical input has to produce identical bytes, or every poll would
    // report a difference that is only ordering.
    expect($renderer->compose($stack->fresh()))->toBe($renderer->compose($stack->fresh()));
});
