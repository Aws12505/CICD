<?php

use App\Models\Client;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceFile;
use App\Models\Stack;
use App\Models\StackService;
use App\Services\Build\BuildFiles;
use App\Services\Stack\StackRenderer;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    config(['deploy.root' => '/srv', 'deploy.ports.base' => 40000, 'deploy.ports.block_size' => 100, 'deploy.registry' => 'ghcr.io/rnd-experts-team']);
});

function stackRunning(Service $service, string $slug = 'acme'): Stack
{
    $client = Client::factory()->create(['slug' => $slug, 'domain' => "{$slug}.cloud"]);
    $stack = Stack::factory()->create([
        'client_id' => $client->id,
        'server_id' => Server::factory()->create()->id,
        'tier' => 'live',
        'compose_project' => "{$slug}_live",
        'port_range_start' => 40000,
    ]);
    StackService::create(['stack_id' => $stack->id, 'service_id' => $service->id, 'enabled' => true]);

    return $stack;
}

test('the service page lists every build and run file with where it comes from', function () {
    $service = Service::factory()->create(['slug' => 'hiring']);
    $service->files()->create(['name' => 'Dockerfile', 'source' => ServiceFile::SOURCE_REPO, 'repo_path' => 'docker/Dockerfile']);

    $this->actingAs(actorWithRole('viewer'))
        ->get('/services/hiring')
        ->assertInertia(fn (Assert $page) => $page
            ->component('services/show')
            ->has('files', 6)
            ->where('files.0.name', 'Dockerfile')
            ->where('files.0.source', 'repo')
            ->where('files.0.repo_path', 'docker/Dockerfile')
            ->where('files.3.name', 'compose.service.yml')
            ->where('files.3.source', 'default')
            ->where('files.3.template', true));
});

test('a file page shows the default contents, the placeholders and whether it is in use', function () {
    $service = Service::factory()->create(['slug' => 'hiring']);
    $service->files()->create(['name' => 'Dockerfile', 'source' => ServiceFile::SOURCE_REPO, 'repo_path' => 'docker/Dockerfile']);

    $this->actingAs(actorWithRole('viewer'))
        ->get('/services/hiring/files/compose.service.yml')
        ->assertInertia(fn (Assert $page) => $page
            ->component('services/file')
            ->where('file.source', 'default')
            ->where('file.template', true)
            ->where('file.inert', false)
            ->where('file.placeholders.scalars', fn ($s) => in_array('slug', $s->all(), true) && in_array('image', $s->all(), true))
            ->where('file.contents', fn ($c) => str_contains($c, 'image: {{image}}')));

    // A repository Dockerfile does not copy the control plane's entrypoint.
    $this->actingAs(actorWithRole('viewer'))
        ->get('/services/hiring/files/entrypoint.sh')
        ->assertInertia(fn (Assert $page) => $page->where('file.inert', true)->where('file.buildFile', true));
});

test('a file the service does not have is not found', function () {
    Service::factory()->create(['slug' => 'hiring']);
    Service::factory()->perStackBuild()->create(['slug' => 'dashboard']);

    $this->actingAs(actorWithRole('viewer'))->get('/services/hiring/files/supervisord.conf')->assertNotFound();
    $this->actingAs(actorWithRole('viewer'))->get('/services/dashboard/files/entrypoint.sh')->assertNotFound();
});

test('an operator customises a template and every stack of that service gets it', function () {
    $service = Service::factory()->create(['slug' => 'hiring', 'subdomain' => 'hiring']);
    $stack = stackRunning($service);
    $contents = app(BuildFiles::class)->defaultContents($service, 'compose.service.yml')."    # tuned for hiring\n";

    $this->actingAs(actorWithRole('operator'))
        ->put('/services/hiring/files/compose.service.yml', ['source' => 'custom', 'contents' => $contents])
        ->assertRedirect()
        ->assertSessionHas('success');

    expect($service->files()->where('name', 'compose.service.yml')->value('source'))->toBe('custom');
    expect(app(StackRenderer::class)->compose($stack))->toContain('# tuned for hiring');
});

test('a template that would not render safely is refused with the reason', function () {
    $service = Service::factory()->create(['slug' => 'hiring']);

    $this->actingAs(actorWithRole('operator'))
        ->put('/services/hiring/files/nginx.conf', ['source' => 'custom', 'contents' => "server { {{bogus}} }\n"])
        ->assertSessionHasErrors('contents');

    expect(session('errors')->first('contents'))->toContain('{{bogus}}')->toContain('Allowed:')->toContain('{{domain}}');

    $this->actingAs(actorWithRole('operator'))
        ->put('/services/hiring/files/Dockerfile', ['source' => 'custom', 'contents' => "  \n"])
        ->assertSessionHasErrors('contents');

    expect($service->files()->count())->toBe(0);
});

test('reset to default removes the override', function () {
    $service = Service::factory()->create(['slug' => 'hiring']);
    $service->files()->create(['name' => 'Dockerfile', 'source' => ServiceFile::SOURCE_CUSTOM, 'contents' => "FROM scratch\n"]);

    $this->actingAs(actorWithRole('operator'))
        ->put('/services/hiring/files/Dockerfile', ['source' => 'default'])
        ->assertRedirect();

    expect($service->files()->count())->toBe(0);
});

test('a build file can come from the repository; a rendered file cannot', function () {
    $service = Service::factory()->create(['slug' => 'hiring']);

    $this->actingAs(actorWithRole('operator'))
        ->put('/services/hiring/files/Dockerfile', ['source' => 'repo', 'repo_path' => '/docker/Dockerfile.prod'])
        ->assertRedirect()
        ->assertSessionHas('success');

    expect($service->files()->where('name', 'Dockerfile')->first())
        ->source->toBe('repo')
        ->repo_path->toBe('docker/Dockerfile.prod')
        ->contents->toBeNull();

    $this->actingAs(actorWithRole('operator'))
        ->put('/services/hiring/files/Dockerfile', ['source' => 'repo', 'repo_path' => '../../etc/passwd'])
        ->assertSessionHasErrors('repo_path');

    $this->actingAs(actorWithRole('operator'))
        ->put('/services/hiring/files/compose.service.yml', ['source' => 'repo', 'repo_path' => 'compose.yml'])
        ->assertSessionHasErrors('source');
});

test('a viewer can read the files but not change them', function () {
    Service::factory()->create(['slug' => 'hiring']);

    $this->actingAs(actorWithRole('viewer'))->get('/services/hiring/files/Dockerfile')->assertOk();
    $this->actingAs(actorWithRole('viewer'))
        ->put('/services/hiring/files/Dockerfile', ['source' => 'custom', 'contents' => 'FROM scratch'])
        ->assertForbidden();
});

test('the preview renders what a stack would get, only where the service is enabled', function () {
    $service = Service::factory()->create(['slug' => 'hiring', 'subdomain' => 'hiring', 'processes' => [['name' => 'queue', 'command' => 'php artisan queue:work']]]);
    $stack = stackRunning($service);
    $other = stackRunning(Service::factory()->create(['slug' => 'inventory', 'subdomain' => 'inventory']), 'bcad');

    $this->actingAs(actorWithRole('viewer'))
        ->getJson("/services/hiring/files/preview/{$stack->id}")
        ->assertOk()
        ->assertJson(fn ($json) => $json
            ->where('compose', fn ($c) => str_contains($c, 'container_name: acme_live_hiring_queue'))
            ->where('nginx', fn ($n) => str_contains($n, 'server_name hiring.acme.cloud')));

    $this->actingAs(actorWithRole('viewer'))
        ->getJson("/services/hiring/files/preview/{$other->id}")
        ->assertNotFound();
});
