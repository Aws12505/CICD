<?php

use App\Models\Service;
use App\Models\Setting;
use App\Services\Build\ImageBuilder;
use App\Services\Build\MigrationClassifier;
use App\Services\GitHub\GitHub;
use App\Services\Remote\CommandResult;
use App\Services\Stack\EnvResolver;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(RoleSeeder::class));

/*
|--------------------------------------------------------------------------
| Each way GitHub can say no, in words of what to change
|--------------------------------------------------------------------------
*/

test('a readable repository and branch passes', function () {
    Setting::put(Setting::GITHUB_TOKEN, 'github_pat_x');
    Http::fake([
        'api.github.com/repos/RnD-Experts-Team/pizzasys' => Http::response(['full_name' => 'x'], 200),
        'api.github.com/repos/RnD-Experts-Team/pizzasys/branches/events_testing' => Http::response(['name' => 'events_testing'], 200),
    ]);

    expect(app(GitHub::class)->checkRead('RnD-Experts-Team/pizzasys', 'events_testing'))
        ->ok->toBeTrue()
        ->message->toContain('pizzasys@events_testing');
});

test('a token granted to the wrong owner or repository is explained', function () {
    Setting::put(Setting::GITHUB_TOKEN, 'github_pat_x');
    Http::fake(['api.github.com/*' => Http::response(['message' => 'Not Found'], 404)]);

    $verdict = app(GitHub::class)->checkRead('RnD-Experts-Team/pizzasys', 'main');

    expect($verdict)->ok->toBeFalse()->problem->toBe('no-access');
    expect($verdict['message'])->toContain('Resource owner must be the organisation')->toContain('Repository access');
});

test('a visible repository with an unreadable branch points at the branch or Contents permission', function () {
    Setting::put(Setting::GITHUB_TOKEN, 'github_pat_x');
    Http::fake([
        'api.github.com/repos/RnD-Experts-Team/pizzasys' => Http::response([], 200),
        'api.github.com/repos/RnD-Experts-Team/pizzasys/branches/testing' => Http::response([], 404),
    ]);

    $verdict = app(GitHub::class)->checkRead('RnD-Experts-Team/pizzasys', 'testing');

    expect($verdict)->problem->toBe('branch');
    expect($verdict['message'])->toContain('branch testing')->toContain('Contents');
});

test('a rejected token is told apart from a missing grant', function () {
    Setting::put(Setting::GITHUB_TOKEN, 'github_pat_x');
    Http::fake(['api.github.com/*' => Http::response([], 401)]);

    expect(app(GitHub::class)->checkRead('a/b', 'main'))->problem->toBe('bad-token');
});

test('SAML single sign-on is named, with where to authorize the token', function () {
    Setting::put(Setting::GITHUB_TOKEN, 'github_pat_x');
    Http::fake(['api.github.com/*' => Http::response(['message' => 'Resource protected by organization SAML enforcement'], 403, ['X-GitHub-SSO' => 'required; url=https://github.com/orgs/x/sso'])]);

    $sso = app(GitHub::class)->checkRead('a/b', 'main');

    expect($sso)->problem->toBe('forbidden');
    expect($sso['message'])->toContain('single sign-on')->toContain('Authorize');
});

/*' => Http::response([], 401)]);
    expect(app(GitHub::class)->checkRead('a/b', 'main'))->problem->toBe('bad-token');

    Http::fake(['api.github.com/*' => Http::response(['message' => 'Resource protected by organization SAML enforcement'], 403, ['X-GitHub-SSO' => 'required; url=https://github.com/orgs/x/sso'])]);
    $sso = app(GitHub::class)->checkRead('a/b', 'main');
    expect($sso)->problem->toBe('forbidden');
    expect($sso['message'])->toContain('single sign-on')->toContain('Authorize');
});

/*
|--------------------------------------------------------------------------
| The Settings button
|--------------------------------------------------------------------------
*/

test('the check runs against every service with its own build branch', function () {
    Setting::put(Setting::GITHUB_TOKEN, 'github_pat_x');
    Service::factory()->create(['name' => 'Hiring', 'repo_full_name' => 'RnD-Experts-Team/HiringPizza', 'default_branch' => 'testing']);
    Service::factory()->create(['name' => 'Auth', 'repo_full_name' => 'RnD-Experts-Team/pizzasys', 'default_branch' => 'events_testing']);
    Http::fake([
        'api.github.com/repos/RnD-Experts-Team/HiringPizza' => Http::response([], 200),
        'api.github.com/repos/RnD-Experts-Team/HiringPizza/branches/testing' => Http::response([], 200),
        'api.github.com/repos/RnD-Experts-Team/pizzasys' => Http::response([], 404),
    ]);

    $response = $this->actingAs(actorWithRole('admin'))->postJson('/control-plane/settings/test-github')->assertOk();

    $results = collect($response->json('results'))->keyBy('service');
    expect($response->json('configured'))->toBeTrue();
    expect($results['Hiring'])->ok->toBeTrue();
    expect($results['Auth'])->ok->toBeFalse()->branch->toBe('events_testing');
});

test('without a saved token the button says so instead of calling GitHub', function () {
    Http::fake();
    Service::factory()->create();

    $this->actingAs(actorWithRole('admin'))->postJson('/control-plane/settings/test-github')->assertOk()->assertJson(['configured' => false]);

    Http::assertNothingSent();
});

test('only admins can run it', function () {
    $this->actingAs(actorWithRole('operator'))->postJson('/control-plane/settings/test-github')->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| A refused clone during a build
|--------------------------------------------------------------------------
*/

function refusedClone(): RecordingRunner
{
    return new RecordingRunner(fn (string $command) => str_contains($command, 'git clone')
        ? new CommandResult(128, '', "Cloning into '/x'...\nfatal: could not read Username for 'https://github.com': terminal prompts disabled", 1)
        : null);
}

test('a refused clone tells you to run the GitHub test, in the task log and on the build', function () {
    Setting::put(Setting::GITHUB_TOKEN, 'github_pat_x');
    $runner = refusedClone();
    $builder = new ImageBuilder($runner, new MigrationClassifier, app(EnvResolver::class));
    $build = $builder->create(Service::factory()->create(['repo_full_name' => 'RnD-Experts-Team/pizzasys']), 'testing', null);

    $build = $builder->run($build, $build->task);

    expect($build->status)->toBe('failed');
    expect($build->error)->toContain('Test GitHub access')->toContain('RnD-Experts-Team/pizzasys')->toContain('terminal prompts disabled');
    expect($build->task->contents())->toContain('Test GitHub access');
});

test('without any token the message says to add one', function () {
    $runner = refusedClone();
    $builder = new ImageBuilder($runner, new MigrationClassifier, app(EnvResolver::class));
    $build = $builder->create(Service::factory()->create(), 'testing', null);

    expect($builder->run($build, $build->task)->error)->toContain('No GitHub token is saved');
});

test('git is never allowed to wait on a prompt, with or without a token', function () {
    foreach ([null, 'github_pat_x'] as $token) {
        Setting::put(Setting::GITHUB_TOKEN, $token);
        $runner = new RecordingRunner;
        $builder = new ImageBuilder($runner, new MigrationClassifier, app(EnvResolver::class));
        $build = $builder->create(Service::factory()->create(), 'testing', null);

        $builder->run($build, $build->task);

        expect(collect($runner->envs)->first(fn ($env) => isset($env['GIT_TERMINAL_PROMPT'])))->toMatchArray(['GIT_TERMINAL_PROMPT' => '0']);
    }
});
