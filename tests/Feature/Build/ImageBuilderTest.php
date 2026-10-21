<?php

use App\Jobs\BuildJob;
use App\Models\Build;
use App\Models\Release;
use App\Models\Service;
use App\Models\ServiceEnvDefinition;
use App\Models\Setting;
use App\Models\Stack;
use App\Models\StackService;
use App\Models\Task;
use App\Services\Build\BuildFiles;
use App\Services\Build\ImageBuilder;
use App\Services\Build\MigrationClassifier;
use App\Services\Build\PhpBase;
use App\Services\Remote\CommandResult;
use App\Services\Stack\EnvResolver;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['deploy.registry' => 'ghcr.io/rnd-experts-team']);
    $this->seed(RoleSeeder::class);
});

const HEAD = 'a1b2c3d4e5f6a7b8c9d0a1b2c3d4e5f6a7b8c9d0';

/**
 * Answers each command from a canned map (first matching needle wins) and
 * records it. The digest and the branch head have sensible defaults.
 */
function scriptedRunner(array $responses = []): RecordingRunner
{
    $responses += [
        'rev-parse' => new CommandResult(0, HEAD."\n", '', 1),
        'docker inspect' => new CommandResult(0, 'ghcr.io/rnd-experts-team/hiring@sha256:deadbeef', '', 1),
    ];

    return new RecordingRunner(function (string $command) use ($responses) {
        foreach ($responses as $needle => $response) {
            if (str_contains($command, $needle)) {
                return $response;
            }
        }

        return null;
    });
}

function builderWith(RecordingRunner $runner): ImageBuilder
{
    return new ImageBuilder($runner, new MigrationClassifier, app(EnvResolver::class), app(BuildFiles::class));
}

function runBuild(ImageBuilder $builder, Service $service, string $branch = 'testing', ?string $sha = HEAD, ?Stack $stack = null): Build
{
    $build = $builder->create($service, $branch, $sha, $stack);

    return $builder->run($build, $build->task);
}

/*
|--------------------------------------------------------------------------
| Tags and numbers
|--------------------------------------------------------------------------
*/

test('a tag carries the commit, never the client', function () {
    $service = Service::factory()->create(['slug' => 'hiring']);

    expect(builderWith(scriptedRunner())->tagFor($service, 'a1b2c3d4e5f6a7b8'))->toBe('a1b2c3d');
});

test('an edition tag names the operating model', function () {
    $service = Service::factory()->create(['slug' => 'data']);

    expect(builderWith(scriptedRunner())->tagFor($service, 'a1b2c3d4e5f6', 'franchisee'))
        ->toBe('a1b2c3d-franchisee');
});

test('a per-stack build is distinguished by the stack id, not the client name', function () {
    $stack = Stack::factory()->create(['compose_project' => 'acme_live']);
    $service = Service::factory()->perStackBuild()->create(['slug' => 'dashboard']);

    $tag = builderWith(scriptedRunner())->tagFor($service, 'a1b2c3d4e5f6', null, $stack);

    // The governing rule: no image ever carries a client's name.
    expect($tag)->toBe('a1b2c3d-s'.$stack->id);
    expect($tag)->not->toContain('acme');
});

test('builds are numbered per service and per edition', function () {
    $service = Service::factory()->create(['slug' => 'hiring', 'name' => 'Hiring']);
    $builder = builderWith(scriptedRunner());

    $first = runBuild($builder, $service, sha: str_repeat('1', 40));
    $second = runBuild($builder, $service, sha: str_repeat('2', 40));
    $edition = runBuild($builder, $service, 'edition/two-step-approval', str_repeat('3', 40));

    expect([$first->number, $second->number, $edition->number])->toBe([1, 2, 1]);
    expect($second->label())->toBe('Hiring #2 (2222222)');
    expect($edition->label())->toBe('Hiring/two-step-approval #1 (3333333)');
    expect($edition->image_tag)->toBe('3333333-two-step-approval');
});

/*
|--------------------------------------------------------------------------
| Fetch, classify, changelog
|--------------------------------------------------------------------------
*/

test('build now resolves the branch head after fetching, then checks out that exact commit', function () {
    $service = Service::factory()->create(['slug' => 'hiring']);
    $runner = scriptedRunner();

    $build = runBuild(builderWith($runner), $service, sha: null);

    expect($build->commit_sha)->toBe(HEAD);
    // A branch name can move between classifying and building; the commit
    // cannot.
    expect($runner->transcript())->toContain("checkout --force '".HEAD."'");
});

test('every git call in the workspace trusts it, whoever owns the directory', function () {
    $service = Service::factory()->create(['slug' => 'hiring']);
    $runner = scriptedRunner();

    runBuild(builderWith($runner), $service, sha: null);

    // The web container and the queue worker can run as different users, and a
    // clone made before an update keeps its old owner. Without this git stops
    // with "detected dubious ownership" and the build never starts.
    $git = collect(explode('
', $runner->transcript()))->filter(fn ($l) => str_contains($l, 'git ') && str_contains($l, ' -C '));

    expect($git)->not->toBeEmpty();
    expect($git->reject(fn ($l) => str_contains($l, "-c 'safe.directory=*' -C")))->toBeEmpty();
});

test('an API-only service builds without its front end', function () {
    $api = Service::factory()->create(['slug' => 'qa', 'build_assets' => false]);
    $web = Service::factory()->create(['slug' => 'hiring']);

    $runner = scriptedRunner();
    runBuild(builderWith($runner), $api, sha: null);
    // A front end left in an API's repository can only fail the build.
    expect($runner->transcript())->toContain("--build-arg 'BUILD_ASSETS=0'");

    $runner = scriptedRunner();
    runBuild(builderWith($runner), $web, sha: null);
    expect($runner->transcript())->toContain("--build-arg 'BUILD_ASSETS=1'");
});

test('a Laravel service builds on the shared PHP base and does not rebuild it when it exists', function () {
    $runner = scriptedRunner();
    runBuild(builderWith($runner), Service::factory()->create(['slug' => 'hiring']), sha: null);

    $base = PhpBase::reference();
    expect($base)->toStartWith('ghcr.io/rnd-experts-team/php-base:')->not->toContain('latest');

    // The service starts FROM the base, which is an immutable tag: nothing to pull.
    expect($runner->transcript())
        ->toContain("--build-arg 'PHP_BASE={$base}'")
        ->not->toContain('docker build --pull')
        ->not->toContain('.control-plane/base.Dockerfile');
});

test('the first Laravel build makes the PHP base once, before the service build', function () {
    $runner = scriptedRunner(['docker manifest inspect' => new CommandResult(1, '', 'manifest unknown', 1)]);
    $build = runBuild(builderWith($runner), Service::factory()->create(['slug' => 'hiring']), sha: null);

    $log = $runner->transcript();
    $base = PhpBase::reference();

    expect($build->status)->toBe('success');
    expect($log)->toContain('docker build --pull -f')->toContain("-t '{$base}'")->toContain("docker push '{$base}'");
    expect(strpos($log, '.control-plane/base.Dockerfile'))->toBeLessThan(strpos($log, "--build-arg 'PHP_BASE="));
});

test('a failed base build fails the build at that step', function () {
    $runner = scriptedRunner([
        'docker manifest inspect' => new CommandResult(1, '', 'manifest unknown', 1),
        '.control-plane/base.Dockerfile' => new CommandResult(1, '', 'pecl: 504', 1),
    ]);

    $build = runBuild(builderWith($runner), Service::factory()->create(['slug' => 'hiring']), sha: null);

    expect($build->status)->toBe('failed');
    expect($build->error)->toContain('base:');
    expect($runner->transcript())->not->toContain("--build-arg 'PHP_BASE=");
});

test('the PHP base tag follows its Dockerfile, so editing it makes a new base', function () {
    expect(PhpBase::reference())->toBe(
        'ghcr.io/rnd-experts-team/php-base:'.substr(sha1(PhpBase::contents()), 0, 12),
    );
    // Extensions compile here, once, not in every service's Dockerfile.
    expect(PhpBase::contents())->toContain('docker-php-ext-install');
    expect(app(BuildFiles::class)->defaultContents(Service::factory()->make(['build_recipe' => Service::RECIPE_LARAVEL_API]), BuildFiles::DOCKERFILE))
        ->toContain('FROM ${PHP_BASE}')->not->toContain('docker-php-ext-install');
});

test('the Next.js recipe does not use the PHP base and still refreshes its image', function () {
    $runner = scriptedRunner();
    runBuild(builderWith($runner), Service::factory()->perStackBuild()->create(['slug' => 'dashboard', 'build_recipe' => Service::RECIPE_NEXTJS]), sha: null, stack: Stack::factory()->create());

    expect($runner->transcript())->toContain('docker build --pull')->not->toContain('PHP_BASE')->not->toContain('manifest inspect');
});

test('a branch that does not exist fails the build', function () {
    $service = Service::factory()->create();

    $build = runBuild(builderWith(scriptedRunner(['rev-parse' => new CommandResult(128, '', 'unknown revision', 1)])), $service, sha: null);

    expect($build->status)->toBe('failed');
    expect($build->error)->toContain('does not exist');
});

test('a build records what its migrations will do before anyone deploys it', function () {
    $service = Service::factory()->create(['slug' => 'hiring']);
    $runner = scriptedRunner([
        'diff --name-only' => new CommandResult(0, "database/migrations/2026_10_01_drop.php\n", '', 1),
        ' show ' => new CommandResult(0, "<?php Schema::table('goals', fn (\$t) => \$t->dropColumn('band'));", '', 1),
    ]);

    $build = runBuild(builderWith($runner), $service);

    expect($build->migration_kind)->toBe(MigrationClassifier::KIND_DESTRUCTIVE);
    expect($build->affected_tables)->toBe(['goals']);
    expect($build->needsWindow())->toBeTrue();
});

test('a first build only classifies its own commit, not the whole history', function () {
    $runner = scriptedRunner();

    runBuild(builderWith($runner), Service::factory()->create());

    expect($runner->transcript())->toContain("diff --name-only --diff-filter=AM '".HEAD."^!'");
});

test('a later build compares against the last successful build, and lists the commits between', function () {
    $service = Service::factory()->create();
    Build::create([
        'service_id' => $service->id,
        'branch' => 'testing',
        'commit_sha' => 'older000000',
        'image_tag' => 'older00',
        'status' => 'success',
        'number' => 7,
        'built_at' => now()->subDay(),
    ]);
    $runner = scriptedRunner([
        ' log ' => new CommandResult(0, "a1b2c3d\tSam\tAdd the shift export\nb2c3d4e\tAlex\tFix the rota total\n", '', 1),
    ]);

    $build = runBuild(builderWith($runner), $service);

    expect($runner->transcript())->toContain("'older000000'..'".HEAD."'");
    expect($build->number)->toBe(8);
    expect($build->changelog)->toBe([
        ['sha' => 'a1b2c3d', 'author' => 'Sam', 'subject' => 'Add the shift export'],
        ['sha' => 'b2c3d4e', 'author' => 'Alex', 'subject' => 'Fix the rota total'],
    ]);
});

/*
|--------------------------------------------------------------------------
| Context, credentials, push
|--------------------------------------------------------------------------
*/

test('a repository without a Dockerfile builds with the control plane recipe', function () {
    $runner = scriptedRunner();

    runBuild(builderWith($runner), Service::factory()->create());

    expect($runner->transcript())
        ->toContain('/.control-plane/Dockerfile')
        ->toContain('FROM ${PHP_BASE}')
        ->toContain("-f '".storage_path('app/private/workspaces'));
});

test('a repository Dockerfile overrides the recipe', function () {
    $runner = scriptedRunner();

    $data = Service::factory()->create(['slug' => 'data']);
    $data->files()->create(['name' => 'Dockerfile', 'source' => 'repo', 'repo_path' => 'docker/Dockerfile.prod']);

    runBuild(builderWith($runner), $data);

    expect($runner->transcript())
        ->not->toContain('FROM ${PHP_BASE}')
        ->toContain('/workspaces/data/docker/Dockerfile.prod');
});

test('a per-stack build bakes that stack values into the image, and only those', function () {
    $stack = Stack::factory()->create();
    $dashboard = Service::factory()->perStackBuild()->create(['slug' => 'dashboard']);
    ServiceEnvDefinition::create(['service_id' => $dashboard->id, 'key' => 'NEXT_PUBLIC_BRAND', 'source' => 'literal', 'default_value' => 'Acme Pizza', 'required' => true, 'is_build_arg' => true]);
    StackService::create(['stack_id' => $stack->id, 'service_id' => $dashboard->id, 'enabled' => true]);
    $runner = scriptedRunner();

    $build = runBuild(builderWith($runner), $dashboard, stack: $stack);

    expect($runner->transcript())
        ->toContain('.env.production.local')
        ->toContain('NEXT_PUBLIC_BRAND=Acme Pizza')
        ->toContain('FROM node:22');
    expect($build->image_tag)->toEndWith('-s'.$stack->id);
});

test('tokens reach git and docker through the environment and never the log', function () {
    Setting::put(Setting::GITHUB_TOKEN, 'github_pat_SECRET123');
    Setting::put(Setting::REGISTRY_USERNAME, 'rnd-bot');
    Setting::put(Setting::REGISTRY_PUSH_TOKEN, 'ghp_PUSHSECRET');
    $runner = scriptedRunner();

    $build = runBuild(builderWith($runner), Service::factory()->create());

    expect($runner->transcript())->not->toContain('SECRET');
    $envs = collect($runner->envs)->filter()->values();
    expect($envs->firstWhere('GIT_CONFIG_KEY_0', 'http.https://github.com/.extraheader'))->not->toBeNull();
    expect($envs->firstWhere('REGISTRY_TOKEN', 'ghp_PUSHSECRET'))->not->toBeNull();
    expect($build->task->contents())->not->toContain('SECRET');
});

test('a successful build records the digest, which is what gets deployed', function () {
    $build = runBuild(builderWith(scriptedRunner()), Service::factory()->create(['slug' => 'hiring']));

    expect($build->status)->toBe('success');
    expect($build->digest)->toBe('sha256:deadbeef');
    expect($build->reference())->toBe('ghcr.io/rnd-experts-team/hiring@sha256:deadbeef');
    expect($build->task->fresh()->status)->toBe('succeeded');
});

test('a failed build records which step failed and never gets a digest', function () {
    $build = runBuild(builderWith(scriptedRunner(['docker build' => new CommandResult(1, '', 'no space left on device', 1)])), Service::factory()->create());

    expect($build->status)->toBe('failed');
    expect($build->error)->toContain('build:')->toContain('no space left');
    expect($build->digest)->toBeNull();
    expect($build->task->fresh()->status)->toBe('failed');
});

test('a push that yields no digest is a failure, not a silent success', function () {
    $build = runBuild(builderWith(scriptedRunner(['docker inspect' => new CommandResult(1, '', 'no such image', 1)])), Service::factory()->create());

    expect($build->status)->toBe('failed');
    expect($build->error)->toContain('no digest');
});

/*
|--------------------------------------------------------------------------
| Webhook
|--------------------------------------------------------------------------
*/

function push(string $repository, string $branch, string $secret = 'hook-secret', array $extra = []): TestResponse
{
    $body = json_encode(array_merge([
        'ref' => 'refs/heads/'.$branch,
        'after' => HEAD,
        'deleted' => false,
        'repository' => ['full_name' => $repository],
    ], $extra));

    return test()->call('POST', '/webhook/build', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_GITHUB_EVENT' => 'push',
        'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, $secret),
    ], $body);
}

test('a push to the default branch builds the template, and deploys nothing', function () {
    Bus::fake();
    Setting::put(Setting::GITHUB_WEBHOOK_SECRET, 'hook-secret');
    $service = Service::factory()->create(['repo_full_name' => 'RnD-Experts-Team/HiringPizza', 'default_branch' => 'testing']);

    push('rnd-experts-team/hiringpizza', 'testing')->assertStatus(202);

    $build = Build::sole();
    expect($build)->service_id->toBe($service->id)->edition->toBeNull()->commit_sha->toBe(HEAD)->trigger->toBe('webhook');
    Bus::assertDispatched(BuildJob::class);
    expect(Release::count())->toBe(0);
});

test('a push to an edition branch builds that edition; other branches are ignored', function () {
    Bus::fake();
    Setting::put(Setting::GITHUB_WEBHOOK_SECRET, 'hook-secret');
    Service::factory()->create(['repo_full_name' => 'RnD-Experts-Team/LC_PIZZA_DATA']);

    push('RnD-Experts-Team/LC_PIZZA_DATA', 'edition/franchisee')->assertStatus(202);
    push('RnD-Experts-Team/LC_PIZZA_DATA', 'feature/new-report')->assertStatus(202);

    expect(Build::sole()->edition)->toBe('franchisee');
});

test('a per-stack service builds once per client running that edition', function () {
    Bus::fake();
    Setting::put(Setting::GITHUB_WEBHOOK_SECRET, 'hook-secret');
    $dashboard = Service::factory()->perStackBuild()->create(['repo_full_name' => 'RnD-Experts-Team/b-dashboard-pizza']);
    [$a, $b, $c] = Stack::factory()->count(3)->create();
    StackService::create(['stack_id' => $a->id, 'service_id' => $dashboard->id, 'enabled' => true]);
    StackService::create(['stack_id' => $b->id, 'service_id' => $dashboard->id, 'enabled' => true]);
    StackService::create(['stack_id' => $c->id, 'service_id' => $dashboard->id, 'enabled' => true, 'edition' => 'franchisee']);

    push('RnD-Experts-Team/b-dashboard-pizza', 'testing');

    expect(Build::pluck('stack_id')->sort()->values()->all())->toBe([$a->id, $b->id]);
});

test('a bad signature is rejected exactly like an unknown repository', function () {
    Bus::fake();
    Setting::put(Setting::GITHUB_WEBHOOK_SECRET, 'hook-secret');
    Service::factory()->create(['repo_full_name' => 'RnD-Experts-Team/HiringPizza']);

    $forged = push('RnD-Experts-Team/HiringPizza', 'testing', 'wrong-secret');

    $forged->assertNotFound();
    expect(Build::count())->toBe(0);
});

test('no webhook secret configured means no webhook builds at all', function () {
    Bus::fake();
    Service::factory()->create(['repo_full_name' => 'RnD-Experts-Team/HiringPizza']);

    push('RnD-Experts-Team/HiringPizza', 'testing', '')->assertNotFound();
});

/*
|--------------------------------------------------------------------------
| Build now
|--------------------------------------------------------------------------
*/

test('build now queues a build of the branch head and opens its log', function () {
    Bus::fake();
    $service = Service::factory()->create(['default_branch' => 'testing']);

    $response = $this->actingAs(actorWithRole('operator'))->post('/builds', ['service_id' => $service->id, 'branch' => 'testing']);

    $build = Build::sole();
    $response->assertRedirect('/tasks/'.$build->task_id);
    expect($build->commit_sha)->toBeNull();
    expect(Task::find($build->task_id)->type)->toBe(Task::TYPE_BUILD);
    Bus::assertDispatched(BuildJob::class, fn ($job) => $job->buildId === $build->id);
});

test('build now refuses branches that are never deployed', function () {
    Bus::fake();
    $service = Service::factory()->create(['default_branch' => 'testing']);

    $this->actingAs(actorWithRole('operator'))
        ->post('/builds', ['service_id' => $service->id, 'branch' => 'feature/x'])
        ->assertSessionHasErrors('branch');

    Bus::assertNothingDispatched();
});

test('viewers can see builds but not start one', function () {
    $service = Service::factory()->create();

    $this->actingAs(actorWithRole('viewer'))->get('/builds')->assertOk();
    $this->actingAs(actorWithRole('viewer'))->post('/builds', ['service_id' => $service->id, 'branch' => 'testing'])->assertForbidden();
});
