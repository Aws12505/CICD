<?php

use App\Models\Service;
use App\Models\Setting;
use App\Services\Build\ImageBuilder;
use App\Services\Build\MigrationClassifier;
use App\Services\GitHub\AccessCheck;
use App\Services\GitHub\GitHub;
use App\Services\Remote\CommandResult;
use App\Services\Remote\CommandRunner;
use App\Services\Stack\EnvResolver;
use App\Support\TokenInfo;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(RoleSeeder::class));

const FATAL = "fatal: could not read Username for 'https://github.com': terminal prompts disabled";

/** A runner where `git ls-remote` gives $lsRemote, and everything else succeeds. */
function gitReturning(CommandResult $lsRemote): RecordingRunner
{
    return new RecordingRunner(fn (string $command) => str_contains($command, 'ls-remote') ? $lsRemote : null);
}

function accessCheckWith(RecordingRunner $runner): AccessCheck
{
    return new AccessCheck(app(GitHub::class), $runner);
}

/*
|--------------------------------------------------------------------------
| The API says why
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

/*
|--------------------------------------------------------------------------
| Git is the truth, because git is what a build runs
|--------------------------------------------------------------------------
*/

test('if git can fetch it, it passes, whatever the API says', function () {
    Setting::put(Setting::GITHUB_TOKEN, 'github_pat_x');
    Http::fake();
    $service = Service::factory()->create(['repo_full_name' => 'RnD-Experts-Team/pizzasys', 'default_branch' => 'events_testing']);
    $runner = gitReturning(new CommandResult(0, "abc\trefs/heads/events_testing\n", '', 1));

    $result = accessCheckWith($runner)->service($service);

    expect($result)->ok->toBeTrue();
    // The probe is the very command a build runs, authenticated the same way.
    expect($runner->transcript())->toContain("git ls-remote --exit-code 'https://github.com/RnD-Experts-Team/pizzasys.git' 'refs/heads/events_testing'");
    expect($runner->envs[0])->toMatchArray(['GIT_TERMINAL_PROMPT' => '0'])->toHaveKey('GIT_CONFIG_VALUE_0');
    Http::assertNothingSent();
});

test('a git failure is explained by the API, with what git said', function () {
    Setting::put(Setting::GITHUB_TOKEN, 'github_pat_x');
    Http::fake(['api.github.com/*' => Http::response([], 404)]);
    $service = Service::factory()->create(['repo_full_name' => 'RnD-Experts-Team/pizzasys']);

    $result = accessCheckWith(gitReturning(new CommandResult(128, '', FATAL, 1)))->service($service);

    expect($result)->ok->toBeFalse();
    expect($result['message'])->toContain('Resource owner must be the organisation')->toContain('terminal prompts disabled');
});

test('a missing branch on a readable repository is said plainly', function () {
    Setting::put(Setting::GITHUB_TOKEN, 'github_pat_x');
    Http::fake();
    $service = Service::factory()->create(['default_branch' => 'events_testing']);

    $result = accessCheckWith(gitReturning(new CommandResult(2, '', '', 1)))->service($service);

    expect($result)->ok->toBeFalse();
    expect($result['message'])->toContain('readable but the branch events_testing does not exist');
});

test('when the API is fine but git is not, the connection is blamed, not the token', function () {
    Setting::put(Setting::GITHUB_TOKEN, 'github_pat_x');
    Http::fake(['api.github.com/*' => Http::response([], 200)]);
    $service = Service::factory()->create();

    $result = accessCheckWith(gitReturning(new CommandResult(128, '', 'fatal: unable to access: Could not resolve host: github.com', 1)))->service($service);

    expect($result['message'])->toContain('Could not resolve host')->toContain('connection from this server');
});

test('the token never appears in what is reported', function () {
    Setting::put(Setting::GITHUB_TOKEN, 'github_pat_SECRETVALUE');
    Http::fake(['api.github.com/*' => Http::response([], 404)]);
    $service = Service::factory()->create();

    $result = accessCheckWith(gitReturning(new CommandResult(128, '', 'fatal: unable to access github_pat_SECRETVALUE', 1)))->service($service);

    expect(json_encode($result))->not->toContain('SECRETVALUE');
});

/*
|--------------------------------------------------------------------------
| The Settings button and the command
|--------------------------------------------------------------------------
*/

test('the check runs against every service with its own build branch', function () {
    Setting::put(Setting::GITHUB_TOKEN, 'github_pat_x');
    Service::factory()->create(['name' => 'Hiring', 'repo_full_name' => 'RnD-Experts-Team/HiringPizza', 'default_branch' => 'testing']);
    Service::factory()->create(['name' => 'Auth', 'repo_full_name' => 'RnD-Experts-Team/pizzasys', 'default_branch' => 'events_testing']);
    Http::fake(['api.github.com/*' => Http::response([], 404)]);
    $this->app->instance(CommandRunner::class, new RecordingRunner(fn (string $c) => str_contains($c, 'HiringPizza')
        ? null
        : new CommandResult(128, '', FATAL, 1)));

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

test('the same check runs from the server shell', function () {
    Setting::put(Setting::GITHUB_TOKEN, 'github_pat_'.str_repeat('a', 82));
    Service::factory()->create(['name' => 'Hiring', 'repo_full_name' => 'RnD-Experts-Team/HiringPizza']);
    Service::factory()->create(['name' => 'Auth', 'repo_full_name' => 'RnD-Experts-Team/pizzasys']);
    Http::fake(['api.github.com/*' => Http::response([], 404)]);
    $this->app->instance(CommandRunner::class, new RecordingRunner(fn (string $c) => str_contains($c, 'HiringPizza')
        ? null
        : new CommandResult(128, '', FATAL, 1)));

    $this->artisan('github:check')
        ->expectsOutputToContain('fine-grained personal token (github_pat_…, 93 characters)')
        ->expectsOutputToContain('1 repository(ies) cannot be fetched')
        ->assertFailed();
});

test('the command refuses to run without a token', function () {
    $this->artisan('github:check')->expectsOutputToContain('No GitHub token is saved')->assertFailed();
});

/*
|--------------------------------------------------------------------------
| Which token is saved
|--------------------------------------------------------------------------
*/

test('a saved token is described by its kind and length, never revealed', function () {
    expect(TokenInfo::describe('github_pat_'.str_repeat('x', 82)))->toBe('fine-grained personal token (github_pat_…, 93 characters)');
    expect(TokenInfo::describe('ghp_'.str_repeat('x', 36)))->toBe('classic personal token (ghp_…, 40 characters)');
    expect(TokenInfo::describe('a-webhook-secret-pasted-by-mistake'))->toContain('not a GitHub token format');
    expect(TokenInfo::describe(null))->toBeNull();
});

test('Settings shows what kind of token is saved but not the token', function () {
    Setting::put(Setting::GITHUB_TOKEN, 'github_pat_SECRETVALUE_'.str_repeat('x', 60));

    $response = $this->actingAs(actorWithRole('admin'))->get('/control-plane/settings')->assertOk();

    $response->assertInertia(fn ($page) => $page->where('fields.0.hint', fn (string $hint) => str_starts_with($hint, 'fine-grained personal token')));
    expect($response->getContent())->not->toContain('SECRETVALUE');
});

/*
|--------------------------------------------------------------------------
| A refused clone during a build
|--------------------------------------------------------------------------
*/

function refusingBuilder(): ImageBuilder
{
    $runner = new RecordingRunner(fn (string $command) => str_contains($command, 'git clone') || str_contains($command, 'ls-remote')
        ? new CommandResult(128, '', "Cloning into '/x'...\n".FATAL, 1)
        : null);
    app()->instance(CommandRunner::class, $runner);

    return new ImageBuilder($runner, new MigrationClassifier, app(EnvResolver::class));
}

test('a refused clone says why, in the task log and on the build', function () {
    Setting::put(Setting::GITHUB_TOKEN, 'github_pat_'.str_repeat('x', 82));
    Http::fake(['api.github.com/*' => Http::response([], 404)]);
    $builder = refusingBuilder();
    $build = $builder->create(Service::factory()->create(['repo_full_name' => 'RnD-Experts-Team/pizzasys']), 'testing', null);

    $build = $builder->run($build, $build->task);

    expect($build->status)->toBe('failed');
    expect($build->error)->toContain('GitHub would not let the saved token read RnD-Experts-Team/pizzasys')
        ->toContain('Resource owner must be the organisation')
        ->toContain('terminal prompts disabled');
    // Every build log opens by saying which token it used.
    expect($build->task->contents())->toContain('GitHub token: fine-grained personal token (github_pat_…, 93 characters)')
        ->toContain('Resource owner must be the organisation');
    expect($build->task->contents())->not->toContain('github_pat_'.str_repeat('x', 82));
});

test('without any token the message says to add one', function () {
    Http::fake();
    $builder = refusingBuilder();
    $build = $builder->create(Service::factory()->create(), 'testing', null);

    $build = $builder->run($build, $build->task);

    expect($build->error)->toContain('No GitHub token is saved');
    expect($build->task->contents())->toContain('GitHub token: none saved');
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
