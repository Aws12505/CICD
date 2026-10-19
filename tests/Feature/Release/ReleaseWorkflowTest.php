<?php

use App\Jobs\BuildJob;
use App\Jobs\ReleaseJob;
use App\Models\Build;
use App\Models\Release;
use App\Models\ReleaseItem;
use App\Models\Service;
use App\Models\Setting;
use App\Services\Release\ReleasePlanner;
use App\Services\Stack\EnvResolver;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    config([
        'deploy.ports.base' => 40000,
        'deploy.ports.block_size' => 100,
        'deploy.root' => '/srv',
        'deploy.registry' => 'ghcr.io/rnd-experts-team',
        'deploy.health.attempts' => 2,
        'deploy.health.interval_seconds' => 0,
    ]);
});

/** A release of one build onto a stack, ready to run. */
function releaseOf($stack, Service $service, Build $build, string $kind = Release::KIND_NORMAL): Release
{
    $release = Release::create(['stack_id' => $stack->id, 'kind' => $kind]);
    ReleaseItem::create(['release_id' => $release->id, 'service_id' => $service->id, 'build_id' => $build->id, 'to_digest' => $build->digest]);

    return $release->fresh();
}

function position(array $commands, string $needle): int
{
    foreach ($commands as $i => $command) {
        if (str_contains($command, $needle)) {
            return $i;
        }
    }

    return PHP_INT_MAX;
}

/*
|--------------------------------------------------------------------------
| What a release does on the server
|--------------------------------------------------------------------------
*/

test('the compose file already names the new image when it is written', function () {
    $client = twoTierClient();
    $service = Service::factory()->create(['slug' => 'hiring', 'subdomain' => 'hiring']);
    entitle($client->testingStack(), $service, 'sha256:old');
    $runner = new RecordingRunner;

    runnerWith($runner)->run(releaseOf($client->testingStack(), $service, successfulBuild($service, 'sha256:new')), new NullTarget);

    // Otherwise `up -d` would recreate the container from the old image.
    expect($runner->transcript())->toContain('image: ghcr.io/rnd-experts-team/hiring@sha256:new');
});

test('databases, users and streams are prepared before anything is swapped', function () {
    $client = twoTierClient();
    $service = Service::factory()->create(['slug' => 'hiring', 'subdomain' => 'hiring', 'database_name' => 'hiringpizza']);
    entitle($client->testingStack(), $service);
    $runner = new RecordingRunner;

    runnerWith($runner)->run(releaseOf($client->testingStack(), $service, successfulBuild($service, 'sha256:new')), new NullTarget);

    $commands = explode("\n\u{0}", implode("\n\u{0}", array_map(fn ($c) => RecordingRunner::decode($c), $runner->commands)));
    expect(position($commands, 'CREATE DATABASE IF NOT EXISTS `hiringpizza_testing`'))
        ->toBeLessThan(position($commands, 'up -d --no-deps'));
});

test('every Laravel deploy migrates, even a build classified as carrying none', function () {
    $client = twoTierClient();
    $service = Service::factory()->create(['slug' => 'hiring', 'subdomain' => 'hiring']);
    entitle($client->testingStack(), $service);
    $runner = new RecordingRunner;

    // A first deploy classifies only its own commit, but the empty database
    // needs the whole history.
    runnerWith($runner)->run(releaseOf($client->testingStack(), $service, successfulBuild($service, 'sha256:new', 'none')), new NullTarget);

    expect($runner->transcript())->toContain("run --rm --no-deps -T --entrypoint sh 'hiring' -c 'php artisan migrate --force'");
});

test('first-run commands run once, every-deploy commands every time', function () {
    $client = twoTierClient();
    $service = Service::factory()->create([
        'slug' => 'toolbox', 'subdomain' => 'toolbox',
        'first_run_commands' => ['php artisan db:seed --class=CatalogSeeder --force'],
        'every_deploy_commands' => ['php artisan cache:clear'],
    ]);
    $entitlement = entitle($client->testingStack(), $service);

    $first = new RecordingRunner;
    runnerWith($first)->run(releaseOf($client->testingStack(), $service, successfulBuild($service, 'sha256:one')), new NullTarget);

    expect($first->transcript())->toContain('CatalogSeeder')->toContain('cache:clear');
    expect($entitlement->fresh()->initialized_at)->not->toBeNull();

    $second = new RecordingRunner;
    runnerWith($second)->run(releaseOf($client->testingStack(), $service, successfulBuild($service, 'sha256:two')), new NullTarget);

    expect($second->transcript())->not->toContain('CatalogSeeder')->toContain('cache:clear');
});

test('a first deploy that fails runs its first-run commands again next time', function () {
    $client = twoTierClient();
    $service = Service::factory()->create(['slug' => 'toolbox', 'subdomain' => 'toolbox', 'first_run_commands' => ['php artisan db:seed --class=CatalogSeeder --force']]);
    $entitlement = entitle($client->testingStack(), $service);

    runnerWith(fakeRunner(failOn: ['curl']))->run(releaseOf($client->testingStack(), $service, successfulBuild($service, 'sha256:one')), new NullTarget);

    expect($entitlement->fresh()->initialized_at)->toBeNull();
});

test('the identity provider goes first, registers every service with its token, and gets a first admin', function () {
    $client = twoTierClient();
    $client->update(['admin_email' => 'owner@acme.test', 'admin_name' => 'Owner']);
    $testing = $client->testingStack();
    $hiring = Service::factory()->create(['slug' => 'hiring', 'subdomain' => 'hiring', 'position' => 0, 'auth_service_name' => 'Hiring']);
    $pizzasys = Service::factory()->create(['slug' => 'pizzasys', 'subdomain' => 'auth', 'position' => 9, 'identity_provider' => true]);
    entitle($testing, $hiring);
    entitle($testing, $pizzasys);

    $release = Release::create(['stack_id' => $testing->id, 'kind' => Release::KIND_NORMAL]);
    ReleaseItem::create(['release_id' => $release->id, 'service_id' => $hiring->id, 'build_id' => successfulBuild($hiring, 'sha256:h')->id, 'to_digest' => 'sha256:h', 'position' => 0]);
    ReleaseItem::create(['release_id' => $release->id, 'service_id' => $pizzasys->id, 'build_id' => successfulBuild($pizzasys, 'sha256:p')->id, 'to_digest' => 'sha256:p', 'position' => 1]);
    $runner = new RecordingRunner;

    $result = runnerWith($runner)->run($release->fresh(), new NullTarget);

    expect($result->status)->toBe('succeeded');
    expect(position($runner->commands, "up -d --no-deps 'pizzasys'"))->toBeLessThan(position($runner->commands, "up -d --no-deps 'hiring'"));

    $token = app(EnvResolver::class)->serviceToken($testing->fresh(), $hiring);
    $register = $runner->commands[position($runner->commands, 'service-client:upsert')];
    expect($register)->toContain("'Hiring' --token-stdin")->toContain($token)->toContain("exec -T -u www-data 'pizzasys'");

    $admin = $runner->commands[position($runner->commands, 'app:create-admin')];
    expect($admin)->toContain("'owner@acme.test' 'Owner' --password-stdin")->toContain($testing->fresh()->secret('admin_password'));
});

test('a certificate that cannot be issued yet does not fail the release', function () {
    $client = twoTierClient();
    $service = Service::factory()->create(['slug' => 'hiring', 'subdomain' => 'hiring']);
    entitle($client->testingStack(), $service);
    $logged = '';

    $result = runnerWith(fakeRunner(failOn: ['certbot']))->run(
        releaseOf($client->testingStack(), $service, successfulBuild($service, 'sha256:new')),
        new NullTarget,
        function (string $line) use (&$logged) {
            $logged .= $line;
        },
    );

    expect($result->status)->toBe('succeeded');
    expect($logged)->toContain('No certificate for hiringtesting.acme.cloud yet');
});

test('a release refuses a server that has not been set up', function () {
    $client = twoTierClient();
    $client->testingStack()->server->update(['provisioned_at' => null]);
    $service = Service::factory()->create(['slug' => 'hiring', 'subdomain' => 'hiring']);
    entitle($client->testingStack(), $service);
    $runner = new RecordingRunner;

    $result = runnerWith($runner)->run(releaseOf($client->testingStack(), $service, successfulBuild($service, 'sha256:new')), new NullTarget);

    expect($result->status)->toBe('failed');
    expect($runner->commands)->toBeEmpty();
});

test('going live advances main on GitHub, only as a fast-forward; testing never does', function () {
    Setting::put(Setting::GITHUB_TOKEN, 'github_pat_x');
    Http::fake(['api.github.com/*' => Http::response([], 200)]);
    $client = twoTierClient();
    $service = Service::factory()->create(['slug' => 'hiring', 'subdomain' => 'hiring', 'repo_full_name' => 'RnD-Experts-Team/HiringPizza']);
    entitle($client->testingStack(), $service);
    entitle($client->liveStack(), $service);
    $build = successfulBuild($service, 'sha256:new');

    runnerWith(new RecordingRunner)->run(releaseOf($client->testingStack(), $service, $build), new NullTarget);
    Http::assertNothingSent();

    runnerWith(new RecordingRunner)->run(releaseOf($client->liveStack(), $service, $build, Release::KIND_PROMOTION), new NullTarget);

    Http::assertSent(fn ($request) => $request->method() === 'PATCH'
        && str_ends_with($request->url(), 'repos/RnD-Experts-Team/HiringPizza/git/refs/heads/main')
        && $request['sha'] === $build->commit_sha
        && $request['force'] === false);
});

/*
|--------------------------------------------------------------------------
| Planning per client
|--------------------------------------------------------------------------
*/

test('a client on an edition is only offered that edition builds', function () {
    $client = twoTierClient();
    $data = Service::factory()->create(['slug' => 'data', 'subdomain' => 'data']);
    $entitlement = entitle($client->testingStack(), $data);
    $entitlement->update(['edition' => 'franchisee']);
    $template = successfulBuild($data, 'sha256:template');
    $edition = successfulBuild($data, 'sha256:edition');
    $edition->update(['edition' => 'franchisee']);

    $planner = app(ReleasePlanner::class);

    expect($planner->candidates($entitlement->fresh())->pluck('id')->all())->toBe([$edition->id]);
    expect(fn () => $planner->chosen($client->testingStack(), [$data->id => $template->id]))
        ->toThrow(RuntimeException::class, 'cannot run');
});

test('promoting a per-stack service needs the same commit built for live', function () {
    $client = twoTierClient();
    $dashboard = Service::factory()->perStackBuild()->create(['slug' => 'dashboard', 'subdomain' => 'app']);
    entitle($client->testingStack(), $dashboard, 'sha256:testing-build');
    entitle($client->liveStack(), $dashboard);
    $testingBuild = successfulBuild($dashboard, 'sha256:testing-build');
    $testingBuild->update(['stack_id' => $client->testingStack()->id, 'commit_sha' => str_repeat('c', 40)]);
    $planner = app(ReleasePlanner::class);

    // Testing's image carries testing's URLs; it must never be copied to live.
    expect($planner->promotionRows($client->testingStack())[0]['status'])->toBe('needs_build');
    expect($planner->promote($client->testingStack())->items)->toBeEmpty();

    $liveBuild = successfulBuild($dashboard, 'sha256:live-build');
    $liveBuild->update(['stack_id' => $client->liveStack()->id, 'commit_sha' => str_repeat('c', 40)]);

    $release = $planner->promote($client->testingStack());
    expect($release->items->first()->to_digest)->toBe('sha256:live-build');
});

test('build for live queues the missing per-stack build of the same commit', function () {
    Bus::fake();
    $client = twoTierClient();
    $dashboard = Service::factory()->perStackBuild()->create(['slug' => 'dashboard', 'subdomain' => 'app']);
    entitle($client->testingStack(), $dashboard, 'sha256:testing-build');
    entitle($client->liveStack(), $dashboard);
    successfulBuild($dashboard, 'sha256:testing-build')->update(['stack_id' => $client->testingStack()->id, 'commit_sha' => str_repeat('c', 40)]);

    $this->actingAs(actorWithRole('operator'))->post("/stacks/{$client->liveStack()->id}/build-for-live");

    $build = Build::latest('id')->first();
    expect($build)->stack_id->toBe($client->liveStack()->id)->commit_sha->toBe(str_repeat('c', 40))->status->toBe('pending');
    Bus::assertDispatched(BuildJob::class);
});

/*
|--------------------------------------------------------------------------
| The buttons
|--------------------------------------------------------------------------
*/

test('update testing starts a release of the chosen builds and opens it', function () {
    Bus::fake();
    $client = twoTierClient();
    $service = Service::factory()->create(['slug' => 'hiring', 'subdomain' => 'hiring']);
    entitle($client->testingStack(), $service, 'sha256:old');
    $build = successfulBuild($service, 'sha256:new');

    $response = $this->actingAs(actorWithRole('operator'))->post("/stacks/{$client->testingStack()->id}/releases", [
        'mode' => 'update',
        'selection' => [$service->id => $build->id],
    ]);

    $release = Release::sole();
    $response->assertRedirect("/releases/{$release->id}");
    expect($release->task_id)->not->toBeNull();
    Bus::assertDispatched(ReleaseJob::class, fn ($job) => $job->releaseId === $release->id);
});

test('live is only reached by promotion or an emergency', function () {
    Bus::fake();
    $client = twoTierClient();
    $service = Service::factory()->create(['slug' => 'hiring', 'subdomain' => 'hiring']);
    entitle($client->liveStack(), $service, 'sha256:old');
    $build = successfulBuild($service, 'sha256:new');

    $this->actingAs(actorWithRole('operator'))
        ->post("/stacks/{$client->liveStack()->id}/releases", ['mode' => 'update', 'selection' => [$service->id => $build->id]])
        ->assertSessionHas('error');

    $this->post("/stacks/{$client->liveStack()->id}/releases", ['mode' => 'emergency', 'selection' => [$service->id => $build->id], 'reason' => ''])
        ->assertSessionHas('error');

    expect(Release::count())->toBe(0);

    $this->post("/stacks/{$client->liveStack()->id}/releases", ['mode' => 'emergency', 'selection' => [$service->id => $build->id], 'reason' => 'Payroll export is broken'])
        ->assertRedirect();

    expect(Release::sole())->kind->toBe(Release::KIND_EMERGENCY)->reason->toBe('Payroll export is broken');
});

test('a destructive update is scheduled for the agreed time instead of starting', function () {
    Bus::fake();
    $client = twoTierClient();
    $service = Service::factory()->create(['slug' => 'hiring', 'subdomain' => 'hiring']);
    entitle($client->testingStack(), $service, 'sha256:old');
    $build = successfulBuild($service, 'sha256:new', 'destructive', ['goals']);
    $operator = actorWithRole('operator');

    $this->actingAs($operator)
        ->post("/stacks/{$client->testingStack()->id}/releases", ['mode' => 'update', 'selection' => [$service->id => $build->id]])
        ->assertSessionHasErrors('scheduled_for');

    $this->actingAs($operator)->post("/stacks/{$client->testingStack()->id}/releases", [
        'mode' => 'update',
        'selection' => [$service->id => $build->id],
        'scheduled_for' => now()->addHour()->toDateTimeString(),
    ]);

    expect(Release::sole())->status->toBe('scheduled')->task_id->toBeNull();
    Bus::assertNotDispatched(ReleaseJob::class);

    $this->travel(2)->hours();
    $this->artisan('releases:run-due')->assertSuccessful();

    expect(Release::sole()->task_id)->not->toBeNull();
    Bus::assertDispatched(ReleaseJob::class);
});

test('multi-client update testing skips pinned clients', function () {
    Bus::fake();
    $client = twoTierClient();
    $service = Service::factory()->create(['slug' => 'hiring', 'subdomain' => 'hiring']);
    entitle($client->testingStack(), $service, 'sha256:old');
    successfulBuild($service, 'sha256:new');
    $operator = actorWithRole('operator');

    $this->actingAs($operator)->post("/clients/{$client->slug}/pin");
    $this->actingAs($operator)->post('/versions/update-testing', ['client_ids' => [$client->id]])
        ->assertSessionHas('error', fn ($m) => str_contains($m, 'pinned'));
    expect(Release::count())->toBe(0);

    $this->actingAs($operator)->post("/clients/{$client->slug}/pin");
    $this->actingAs($operator)->post('/versions/update-testing', ['client_ids' => [$client->id]])
        ->assertSessionHas('success');
    expect(Release::sole()->stack_id)->toBe($client->testingStack()->id);
});

test('the versions board shows what each tier runs and how far behind it is', function () {
    $client = twoTierClient();
    $service = Service::factory()->create(['slug' => 'hiring', 'subdomain' => 'hiring', 'name' => 'Hiring']);
    $running = successfulBuild($service, 'sha256:one');
    $running->update(['number' => 1, 'built_at' => now()->subDays(2)]);
    foreach ([2, 3] as $n) {
        successfulBuild($service, "sha256:n{$n}")->update(['number' => $n, 'built_at' => now()->subDays(2)->addHours($n)]);
    }
    entitle($client->testingStack(), $service, 'sha256:one');

    $this->actingAs(actorWithRole('viewer'))->get('/versions')->assertOk()->assertInertia(fn ($page) => $page
        ->component('versions/index')
        ->where("clients.0.cells.{$service->id}.testing.build.number", 1)
        ->where("clients.0.cells.{$service->id}.testing.behind", 2)
        ->where("clients.0.cells.{$service->id}.live", null)
    );
});

test('a rollback starts at once', function () {
    Bus::fake();
    $client = twoTierClient();
    $service = Service::factory()->create(['slug' => 'hiring', 'subdomain' => 'hiring']);
    $previous = Release::create(['stack_id' => $client->liveStack()->id, 'kind' => Release::KIND_NORMAL, 'status' => 'succeeded']);
    ReleaseItem::create(['release_id' => $previous->id, 'service_id' => $service->id, 'from_digest' => 'sha256:good', 'to_digest' => 'sha256:bad', 'status' => 'succeeded']);

    $this->actingAs(actorWithRole('operator'))->post("/releases/{$previous->id}/rollback");

    $rollback = Release::where('kind', Release::KIND_ROLLBACK)->sole();
    expect($rollback->task_id)->not->toBeNull();
    Bus::assertDispatched(ReleaseJob::class, fn ($job) => $job->releaseId === $rollback->id);
});

test('viewers can look at the board and plans but change nothing', function () {
    $client = twoTierClient();

    $this->actingAs(actorWithRole('viewer'))->get("/stacks/{$client->testingStack()->id}/plan")->assertOk();
    $this->actingAs(actorWithRole('viewer'))->post("/stacks/{$client->testingStack()->id}/releases", ['mode' => 'update'])->assertForbidden();
    $this->actingAs(actorWithRole('viewer'))->post('/versions/update-testing', ['client_ids' => [$client->id]])->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| Editions
|--------------------------------------------------------------------------
*/

test('sync merges the template into an edition, one way', function () {
    Setting::put(Setting::GITHUB_TOKEN, 'github_pat_x');
    Http::fake(['api.github.com/repos/RnD-Experts-Team/LC_PIZZA_DATA/merges' => Http::response(['sha' => 'abc'], 201)]);
    Service::factory()->create(['slug' => 'data', 'repo_full_name' => 'RnD-Experts-Team/LC_PIZZA_DATA', 'default_branch' => 'testing']);

    $this->actingAs(actorWithRole('operator'))
        ->post('/services/data/editions/franchisee/sync')
        ->assertSessionHas('success');

    Http::assertSent(fn ($request) => $request['base'] === 'edition/franchisee' && $request['head'] === 'testing');
});

test('a conflicting sync opens a pull request against the edition', function () {
    Setting::put(Setting::GITHUB_TOKEN, 'github_pat_x');
    Http::fake([
        'api.github.com/repos/RnD-Experts-Team/LC_PIZZA_DATA/merges' => Http::response(['message' => 'Merge conflict'], 409),
        'api.github.com/repos/RnD-Experts-Team/LC_PIZZA_DATA/pulls' => Http::response(['html_url' => 'https://github.com/pr/7'], 201),
    ]);
    Service::factory()->create(['slug' => 'data', 'repo_full_name' => 'RnD-Experts-Team/LC_PIZZA_DATA', 'default_branch' => 'testing']);

    $this->actingAs(actorWithRole('operator'))
        ->post('/services/data/editions/franchisee/sync')
        ->assertSessionHas('error', fn ($m) => str_contains($m, 'https://github.com/pr/7'));

    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/pulls') && $request['base'] === 'edition/franchisee' && $request['head'] === 'testing');
});
