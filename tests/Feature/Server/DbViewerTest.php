<?php

use App\Models\DbViewerSession;
use App\Models\Server;
use App\Models\Service;
use App\Models\Stack;
use App\Models\StackService;
use App\Services\Provision\StackProvisioner;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

const VIEWER_SECRET = 'gateway-secret-gateway-secret-gateway-secret';

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    config(['terminal.gateway_secret' => VIEWER_SECRET, 'terminal.gateway_url' => 'http://terminal-gateway:8090']);
});

function viewerStack(): Stack
{
    $server = Server::factory()->verified()->create(['provisioned_at' => now(), 'ssh_user' => 'root']);

    return Stack::factory()->create(['server_id' => $server->id, 'compose_project' => 'acme_live', 'tier' => 'live']);
}

function ticketFrom(string $location): string
{
    return Str::after($location, 'ticket=');
}

test('an operator opens the database read-only and an admin with write access', function () {
    $stack = viewerStack();

    $operator = $this->actingAs(actorWithRole('operator'))->get("/stacks/{$stack->id}/database");
    $operator->assertRedirect();
    expect($operator->headers->get('Location'))->toStartWith('/db-viewer/?ticket=');
    expect(DbViewerSession::latest('id')->first())->mode->toBe('read')->server_id->toBe($stack->server_id);

    $this->actingAs(actorWithRole('admin'))->get("/stacks/{$stack->id}/database");
    expect(DbViewerSession::latest('id')->first()->mode)->toBe('write');

    $this->actingAs(actorWithRole('viewer'))->get("/stacks/{$stack->id}/database")->assertForbidden();
});

test('a ticket is redeemed once for a tunnel and the credentials of the role\'s MySQL user', function () {
    Http::fake(['terminal-gateway:8090/internal/tunnels' => Http::response(['port' => 41234])]);
    $stack = viewerStack();
    $ticket = ticketFrom($this->actingAs(actorWithRole('operator'))->get("/stacks/{$stack->id}/database")->headers->get('Location'));

    $redeem = fn () => $this->withHeader('X-Gateway-Secret', VIEWER_SECRET)->postJson('/internal/db-viewer/redeem', ['ticket' => $ticket]);

    $redeem()->assertOk()->assertJson([
        'host' => 'terminal-gateway',
        'port' => 41234,
        'username' => 'cp_viewer_ro',
        'mode' => 'read',
        'password' => $stack->server->fresh()->secret('mysql_viewer_ro'),
    ]);

    // The gateway got the server's pinned key, never the browser.
    Http::assertSent(fn ($request) => $request->hasHeader('X-Gateway-Secret', VIEWER_SECRET) && $request['host_key'] === $stack->server->host_key);

    // Single use.
    $redeem()->assertNotFound();
});

test('an expired ticket, or a caller without the secret, gets nothing', function () {
    Http::fake();
    $stack = viewerStack();
    $ticket = ticketFrom($this->actingAs(actorWithRole('admin'))->get("/stacks/{$stack->id}/database")->headers->get('Location'));

    $this->withHeader('X-Gateway-Secret', 'wrong')->postJson('/internal/db-viewer/redeem', ['ticket' => $ticket])->assertNotFound();

    $this->travel(2)->minutes();
    $this->withHeader('X-Gateway-Secret', VIEWER_SECRET)->postJson('/internal/db-viewer/redeem', ['ticket' => $ticket])->assertNotFound();

    Http::assertNothingSent();
});

test('a tunnel that cannot be opened ends the session with the reason', function () {
    Http::fake(['*' => Http::response(['error' => 'MySQL is not running on that server'], 502)]);
    $stack = viewerStack();
    $ticket = ticketFrom($this->actingAs(actorWithRole('admin'))->get("/stacks/{$stack->id}/database")->headers->get('Location'));

    $this->withHeader('X-Gateway-Secret', VIEWER_SECRET)->postJson('/internal/db-viewer/redeem', ['ticket' => $ticket])->assertStatus(502);

    expect(DbViewerSession::sole())->ended_at->not->toBeNull()->end_reason->toContain('tunnel failed');
});

test('the gateway records how a session ended', function () {
    Http::fake(['*' => Http::response(['port' => 41234])]);
    $stack = viewerStack();
    $ticket = ticketFrom($this->actingAs(actorWithRole('admin'))->get("/stacks/{$stack->id}/database")->headers->get('Location'));
    $this->withHeader('X-Gateway-Secret', VIEWER_SECRET)->postJson('/internal/db-viewer/redeem', ['ticket' => $ticket]);

    $this->withHeader('X-Gateway-Secret', VIEWER_SECRET)
        ->postJson('/internal/db-viewer/finish', ['session_id' => DbViewerSession::sole()->id, 'reason' => 'idle timeout'])
        ->assertOk();

    expect(DbViewerSession::sole()->end_reason)->toBe('idle timeout');
});

test('the viewer\'s MySQL users see only the stacks\' databases, and the read-only one changes nothing', function () {
    $stack = viewerStack();
    $service = Service::factory()->create(['slug' => 'hiring', 'database_name' => 'hiringpizza']);
    StackService::create(['stack_id' => $stack->id, 'service_id' => $service->id, 'enabled' => true]);

    $sql = base64_decode(Str::between((new StackProvisioner(new RecordingRunner))->mysql($stack, collect([$service])), "printf '%s' '", "'"));

    expect($sql)
        ->toContain("GRANT SELECT, SHOW VIEW ON `hiringpizza`.* TO 'cp_viewer_ro'@'%';")
        ->toContain("ON `hiringpizza`.* TO 'cp_viewer_rw'@'%';")
        // Never every database: that would include mysql.user and its hashes.
        ->not->toContain('ON *.* TO');
    expect(Str::between($sql, "TO 'cp_viewer_ro'", "\n"))->not->toContain('INSERT');
});
