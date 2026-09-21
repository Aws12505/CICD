<?php

use App\Models\Client;
use App\Models\Server;
use App\Models\Service;
use App\Models\Stack;
use App\Models\StackService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    config(['deploy.ports.base' => 40000, 'deploy.ports.block_size' => 100]);
});

test('creating a client creates both tiers on one server', function () {
    $server = Server::factory()->verified()->create();

    $this->actingAs(actorWithRole('operator'))->post('/clients', [
        'name' => 'ACME Pizza',
        'slug' => 'acme',
        'domain' => 'acme.cloud',
        'server_id' => $server->id,
    ])->assertRedirect();

    $client = Client::firstWhere('slug', 'acme');

    expect($client->stacks)->toHaveCount(2);
    expect($client->liveStack()->compose_project)->toBe('acme_live');
    expect($client->testingStack()->compose_project)->toBe('acme_testing');

    // Both on one machine today; moving testing elsewhere is a server_id edit.
    expect($client->testingStack()->server_id)->toBe($server->id);

    // Separate port blocks, so the two tiers cannot collide on the host.
    expect($client->liveStack()->port_range_start)
        ->not->toBe($client->testingStack()->port_range_start);
});

test('testing can be placed on its own server from the start', function () {
    $live = Server::factory()->create();
    $testing = Server::factory()->create();

    $this->actingAs(actorWithRole('operator'))->post('/clients', [
        'name' => 'ACME', 'slug' => 'acme', 'domain' => 'acme.cloud',
        'server_id' => $live->id,
        'testing_server_id' => $testing->id,
    ]);

    $client = Client::firstWhere('slug', 'acme');

    expect($client->liveStack()->server_id)->toBe($live->id);
    expect($client->testingStack()->server_id)->toBe($testing->id);
});

test('enabling a service records intent and reserves a port, but deploys nothing', function () {
    $stack = Stack::factory()->create(['port_range_start' => 40000]);
    $service = Service::factory()->create();

    $this->actingAs(actorWithRole('operator'))
        ->post("/stacks/{$stack->id}/entitlements", [
            'service_id' => $service->id,
            'enabled' => true,
        ])->assertRedirect();

    $entitlement = StackService::first();

    expect($entitlement->enabled)->toBeTrue();
    expect($entitlement->host_port)->toBe(40000);
    // Enabling creates databases, certificates and containers, so it happens
    // in a release — not on save.
    expect($entitlement->state)->toBe('pending');
    expect($entitlement->current_digest)->toBeNull();
});

test('disabling a service keeps its port so it comes back on the same one', function () {
    $stack = Stack::factory()->create(['port_range_start' => 40000]);
    $service = Service::factory()->create();

    $this->actingAs(actorWithRole('operator'));

    $this->post("/stacks/{$stack->id}/entitlements", ['service_id' => $service->id, 'enabled' => true]);
    $port = StackService::first()->host_port;

    $this->post("/stacks/{$stack->id}/entitlements", ['service_id' => $service->id, 'enabled' => false]);

    expect(StackService::first()->enabled)->toBeFalse();
    expect(StackService::first()->host_port)->toBe($port);
});

test('a viewer can read the topology but cannot change who runs what', function () {
    $client = Client::factory()->create();
    $stack = Stack::factory()->create(['client_id' => $client->id]);
    $service = Service::factory()->create();

    $this->actingAs(actorWithRole('viewer'));

    $this->get('/clients')->assertOk();
    $this->get("/clients/{$client->slug}")->assertOk();
    $this->get('/services')->assertOk();

    $this->post("/stacks/{$stack->id}/entitlements", [
        'service_id' => $service->id, 'enabled' => true,
    ])->assertForbidden();

    $this->post('/clients', [])->assertForbidden();
    $this->delete("/clients/{$client->slug}")->assertForbidden();
});

test('the client page reports required values a stack cannot supply', function () {
    $client = Client::factory()->create(['domain' => 'acme.cloud']);
    $stack = Stack::factory()->create(['client_id' => $client->id, 'tier' => Stack::TIER_LIVE]);
    $service = Service::factory()->create(['slug' => 'hiring', 'subdomain' => 'hiring']);

    $service->envDefinitions()->create([
        'key' => 'HOOKS_API_KEY',
        'source' => 'secret',
        'required' => true,
    ]);

    StackService::create([
        'stack_id' => $stack->id,
        'service_id' => $service->id,
        'enabled' => true,
    ]);

    // Surfacing this before a release beats discovering it when a container
    // boots and silently falls back to somebody else's backend.
    $this->actingAs(actorWithRole('operator'))
        ->get("/clients/{$client->slug}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('stacks.0.entitlements.0.missing_env', ['HOOKS_API_KEY'])
        );
});

test('environment keys must be upper snake case', function () {
    $service = Service::factory()->create();

    $this->actingAs(actorWithRole('operator'))
        ->post("/services/{$service->slug}/env", [
            'key' => 'lower-case-key',
            'source' => 'literal',
            'required' => true,
            'is_build_arg' => false,
        ])
        ->assertSessionHasErrors('key');
});

test('declaring the same key twice replaces it rather than duplicating', function () {
    $service = Service::factory()->create();

    $this->actingAs(actorWithRole('operator'));

    foreach (['first', 'second'] as $value) {
        $this->post("/services/{$service->slug}/env", [
            'key' => 'LOG_LEVEL',
            'source' => 'literal',
            'required' => true,
            'is_build_arg' => false,
            'default_value' => $value,
        ]);
    }

    expect($service->envDefinitions()->count())->toBe(1);
    expect($service->envDefinitions()->first()->default_value)->toBe('second');
});

test('a client slug never ends up in an image name', function () {
    config(['deploy.registry' => 'ghcr.io/rnd-experts-team']);

    $service = Service::factory()->create(['slug' => 'hiring']);

    // The governing rule: no repository, branch or image carries a client's
    // name. Per-stack builds are distinguished by the stack id in the tag.
    expect($service->imageRepository())->toBe('ghcr.io/rnd-experts-team/hiring');
});
