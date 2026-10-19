<?php

use App\Models\Server;
use App\Models\ServerMetric;
use App\Models\TerminalSession;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

const GATEWAY_SECRET = 'a-gateway-secret-that-is-long-enough-000000';

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    config(['terminal.gateway_secret' => GATEWAY_SECRET]);
    Storage::fake('local');
});

function issueToken(Server $server, array $body = []): array
{
    return test()->actingAs(actorWithRole('admin'))
        ->postJson("/servers/{$server->id}/terminal", $body)
        ->assertOk()
        ->json();
}

function redeem(string $token, string $secret = GATEWAY_SECRET): TestResponse
{
    return test()->postJson('/internal/terminal/redeem', ['token' => $token], ['X-Gateway-Secret' => $secret]);
}

test('only admins can open a terminal', function () {
    $server = Server::factory()->verified()->create();

    foreach (['viewer', 'operator'] as $role) {
        $this->actingAs(actorWithRole($role))->get("/servers/{$server->id}/terminal")->assertForbidden();
        $this->actingAs(actorWithRole($role))->postJson("/servers/{$server->id}/terminal")->assertForbidden();
    }
});

test('a token buys one connection: the gateway gets the key, the browser never does', function () {
    $server = Server::factory()->verified()->create();

    $issued = issueToken($server);

    expect($issued)->toHaveKeys(['token', 'url'])->not->toHaveKey('private_key');
    expect(json_encode($issued))->not->toContain('PRIVATE KEY');

    $details = redeem($issued['token'])->assertOk()->json();
    expect($details['private_key'])->toContain('PRIVATE KEY');
    expect($details['host_key'])->toBe($server->host_key);

    // Used once, never again.
    redeem($issued['token'])->assertNotFound();
});

test('a token expires after a minute', function () {
    $server = Server::factory()->verified()->create();
    $issued = issueToken($server);

    $this->travel(61)->seconds();

    redeem($issued['token'])->assertNotFound();
});

test('redeeming needs the gateway secret, and fails the same way without it', function () {
    $server = Server::factory()->verified()->create();
    $issued = issueToken($server);

    redeem($issued['token'], 'wrong-secret')->assertNotFound();

    config(['terminal.gateway_secret' => null]);
    redeem($issued['token'], '')->assertNotFound();

    // Still unused, so the real gateway can still redeem it.
    config(['terminal.gateway_secret' => GATEWAY_SECRET]);
    redeem($issued['token'])->assertOk();
});

test('the token itself is never stored', function () {
    $server = Server::factory()->verified()->create();
    $issued = issueToken($server);

    expect(TerminalSession::sole()->token_hash)->toBe(hash('sha256', $issued['token']));
    expect(json_encode(TerminalSession::sole()->getAttributes()))->not->toContain($issued['token']);
});

test('a container shell is only offered for containers the server reported', function () {
    $server = Server::factory()->verified()->create();
    ServerMetric::create(['server_id' => $server->id, 'recorded_at' => now(), 'cpu_percent' => 1, 'load1' => 0, 'load5' => 0, 'load15' => 0, 'mem_total' => 1, 'mem_used' => 1, 'disk_total' => 1, 'disk_used' => 1, 'containers' => [['name' => 'acme_live_hiring', 'running' => true]]]);

    $this->actingAs(actorWithRole('admin'))->postJson("/servers/{$server->id}/terminal", ['container' => 'something; rm -rf /'])->assertNotFound();
    $this->actingAs(actorWithRole('admin'))->postJson("/servers/{$server->id}/terminal", ['container' => 'unknown'])->assertNotFound();

    $issued = issueToken($server, ['container' => 'acme_live_hiring']);
    expect(redeem($issued['token'])->json('container'))->toBe('acme_live_hiring');
});

test('the transcript is stored when the session ends and can be replayed by admins', function () {
    $server = Server::factory()->verified()->create();
    $issued = issueToken($server);
    $id = redeem($issued['token'])->json('session_id');
    $cast = "{\"version\":2,\"width\":120,\"height\":32}\n[0.5,\"o\",\"root@box:~# \"]\n";

    $this->postJson('/internal/terminal/finish', ['session_id' => $id, 'reason' => 'session ended', 'recording_b64' => base64_encode($cast)], ['X-Gateway-Secret' => GATEWAY_SECRET])->assertOk();

    $session = TerminalSession::find($id);
    expect($session)->ended_at->not->toBeNull()->end_reason->toBe('session ended')->recording_bytes->toBe(strlen($cast));

    $this->actingAs(actorWithRole('admin'))->get("/servers/{$server->id}/terminal/sessions")->assertOk();
    expect($this->actingAs(actorWithRole('admin'))->get("/servers/{$server->id}/terminal/sessions/{$id}/recording")->streamedContent())->toBe($cast);
    $this->actingAs(actorWithRole('operator'))->get("/servers/{$server->id}/terminal/sessions/{$id}/recording")->assertForbidden();
});

test('without a gateway secret the terminal is switched off', function () {
    config(['terminal.gateway_secret' => null]);
    $server = Server::factory()->verified()->create();

    $this->actingAs(actorWithRole('admin'))->postJson("/servers/{$server->id}/terminal")->assertStatus(503);
});
