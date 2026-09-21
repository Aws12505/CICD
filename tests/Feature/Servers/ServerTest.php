<?php

use App\Models\Server;
use App\Services\Remote\CommandResult;
use App\Services\Remote\ServerProbe;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);

    // Nothing in this suite should shell out to ssh-keyscan or open a socket.
    $this->probe = Mockery::mock(ServerProbe::class);
    $this->probe->shouldReceive('captureHostKey')->andReturnUsing(function (Server $server) {
        $server->forceFill(['host_key' => 'h ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIExample'])->save();

        return new CommandResult(0, 'h ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIExample', '', 5);
    })->byDefault();
    $this->probe->shouldReceive('check')->andReturnUsing(function (Server $server) {
        $server->markOnline();

        return new CommandResult(0, 'deploy@box Linux 6.1', '', 5);
    })->byDefault();

    $this->app->instance(ServerProbe::class, $this->probe);
});

function serverPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'ACME live',
        'hostname' => 'acme.example.com',
        'ip_address' => '10.0.0.4',
        'ssh_user' => 'deploy',
        'ssh_port' => 22,
        'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nsecret\n-----END OPENSSH PRIVATE KEY-----",
    ], $overrides);
}

/*
|--------------------------------------------------------------------------
| Access
|--------------------------------------------------------------------------
*/

test('servers are admin-only, even to look at', function () {
    $server = Server::factory()->create();

    foreach (['viewer', 'operator'] as $role) {
        $this->actingAs(actorWithRole($role));

        // The list alone reveals the whole client estate.
        $this->get('/servers')->assertForbidden();
        $this->get("/servers/{$server->id}")->assertForbidden();
        $this->post('/servers', serverPayload())->assertForbidden();
        $this->post("/servers/{$server->id}/check")->assertForbidden();
    }

    $this->actingAs(actorWithRole('admin'))->get('/servers')->assertOk();
});

/*
|--------------------------------------------------------------------------
| Key custody
|--------------------------------------------------------------------------
*/

test('the private key is encrypted at rest', function () {
    $this->actingAs(actorWithRole('admin'))
        ->post('/servers', serverPayload())
        ->assertRedirect();

    $stored = DB::table('servers')->value('ssh_private_key');

    expect($stored)->not->toContain('BEGIN OPENSSH PRIVATE KEY');
    expect(Server::first()->ssh_private_key)->toContain('BEGIN OPENSSH PRIVATE KEY');
});

test('the private key never reaches the browser', function () {
    $server = Server::factory()->verified()->create();

    $response = $this->actingAs(actorWithRole('admin'))->get("/servers/{$server->id}");

    $response->assertOk();
    expect($response->getContent())->not->toContain('not-a-real-key');

    $response->assertInertia(fn (Assert $page) => $page
        ->missing('server.ssh_private_key')
        ->missing('server.host_key')
        // The fingerprint is what you compare out of band; it is safe to show.
        ->has('server.host_key_fingerprint')
    );
});

test('a new server has its host key captured immediately', function () {
    $this->actingAs(actorWithRole('admin'))->post('/servers', serverPayload());

    // A server whose key was never captured is one every later connection
    // would have to trust blindly.
    expect(Server::first()->isVerified())->toBeTrue();
});

test('moving a server to a different host discards the pinned key', function () {
    $server = Server::factory()->verified()->create(['hostname' => 'old.example.com']);
    $original = $server->host_key;

    $this->actingAs(actorWithRole('admin'))->put("/servers/{$server->id}", serverPayload([
        'hostname' => 'new.example.com',
        'ssh_private_key' => '',
    ]));

    // The old key belongs to the old host. Keeping it would either reject the
    // new host or silently match something else, so it must be replaced.
    expect($server->fresh()->hostname)->toBe('new.example.com');
    expect($server->fresh()->host_key)
        ->not->toBe($original)
        ->toBe('h ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIExample');
});

test('editing a server without re-pasting the key keeps the existing one', function () {
    $server = Server::factory()->verified()->create();
    $original = $server->ssh_private_key;

    $this->actingAs(actorWithRole('admin'))->put("/servers/{$server->id}", serverPayload([
        'hostname' => $server->hostname,
        'ssh_port' => $server->ssh_port,
        'ssh_private_key' => '',
    ]));

    expect($server->fresh()->ssh_private_key)->toBe($original);
});

/*
|--------------------------------------------------------------------------
| Targets built from a server
|--------------------------------------------------------------------------
*/

test('a server with a recorded host key always pins it', function () {
    $server = Server::factory()->verified()->create();

    $target = $server->target();
    $argv = $target->invoke('whoami', null, [])->argv;

    expect($argv)->toContain('StrictHostKeyChecking=yes');

    $target->cleanup();
});

test('only an explicit trust-on-first-use target will accept an unknown host', function () {
    $server = Server::factory()->verified()->create();

    $target = $server->target(trustOnFirstUse: true);
    $argv = $target->invoke('whoami', null, [])->argv;

    expect($argv)->toContain('StrictHostKeyChecking=accept-new');

    $target->cleanup();
});

test('a server that has never been scanned cannot silently pin nothing', function () {
    $server = Server::factory()->create(['host_key' => null]);

    expect($server->isVerified())->toBeFalse();

    // It still builds a target, but one that announces it is unpinned rather
    // than pretending to be verified.
    $target = $server->target();
    expect($target->invoke('whoami', null, [])->argv)->toContain('StrictHostKeyChecking=accept-new');

    $target->cleanup();
});
