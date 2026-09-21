<?php

use App\Models\Build;
use App\Models\Service;
use App\Models\Stack;
use App\Services\Build\ImageBuilder;
use App\Services\Build\MigrationClassifier;
use App\Services\Remote\CommandResult;
use App\Services\Remote\CommandRunner;
use App\Services\Stack\EnvResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** Answers each command from a canned map, and records what it was asked. */
function scriptedRunner(array $responses = []): CommandRunner
{
    return new class($responses) extends CommandRunner
    {
        /** @var list<string> */
        public array $commands = [];

        public function __construct(private array $responses) {}

        public function run($target, string $command, ?string $cwd = null, array $env = [], int $timeout = 60, ?Closure $onOutput = null): CommandResult
        {
            $this->commands[] = $command;

            foreach ($this->responses as $needle => $response) {
                if (str_contains($command, $needle)) {
                    return $response;
                }
            }

            return new CommandResult(0, '', '', 1);
        }
    };
}

function builderWith(CommandRunner $runner): ImageBuilder
{
    return new ImageBuilder($runner, new MigrationClassifier, app(EnvResolver::class));
}

beforeEach(fn () => config(['deploy.registry' => 'ghcr.io/rnd-experts-team']));

/*
|--------------------------------------------------------------------------
| Tags
|--------------------------------------------------------------------------
*/

test('a tag carries the commit, never the client', function () {
    $service = Service::factory()->create(['slug' => 'hiring']);
    $builder = builderWith(scriptedRunner());

    expect($builder->tagFor($service, 'a1b2c3d4e5f6a7b8'))->toBe('a1b2c3d');
});

test('an edition tag names the operating model', function () {
    $service = Service::factory()->create(['slug' => 'data']);
    $builder = builderWith(scriptedRunner());

    expect($builder->tagFor($service, 'a1b2c3d4e5f6', 'franchisee'))
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

/*
|--------------------------------------------------------------------------
| Classification at build time
|--------------------------------------------------------------------------
*/

test('a build records what its migrations will do before anyone deploys it', function () {
    $service = Service::factory()->create(['slug' => 'hiring']);

    $runner = scriptedRunner([
        'diff --name-only' => new CommandResult(0, "database/migrations/2026_10_01_drop.php\n", '', 1),
        'git -C' => new CommandResult(0, '', '', 1),
    ]);
    // The `show` of the file content also matches 'git -C', so respond to it
    // by ordering: the more specific needle is checked first above.
    $runner = scriptedRunner([
        'diff --name-only' => new CommandResult(0, "database/migrations/2026_10_01_drop.php\n", '', 1),
        'show' => new CommandResult(0, "<?php Schema::table('goals', fn (\$t) => \$t->dropColumn('band'));", '', 1),
    ]);

    $build = builderWith($runner)->prepare($service, 'testing', 'a1b2c3d4e5f6');

    // Discovered now, not at six o'clock on a Friday.
    expect($build->migration_kind)->toBe(MigrationClassifier::KIND_DESTRUCTIVE);
    expect($build->affected_tables)->toBe(['goals']);
    expect($build->needsWindow())->toBeTrue();
});

test('a first build only classifies its own commit, not the whole history', function () {
    $service = Service::factory()->create();
    $runner = scriptedRunner();

    builderWith($runner)->prepare($service, 'testing', 'a1b2c3d4e5f6');

    $diff = collect($runner->commands)->first(fn ($c) => str_contains($c, 'diff --name-only'));

    // Without this, the first build of an existing service would classify
    // every migration ever written as destructive and demand a window.
    expect($diff)->toContain("'a1b2c3d4e5f6'^!");
});

test('a later build compares against the last successful build, not the parent commit', function () {
    $service = Service::factory()->create();

    Build::create([
        'service_id' => $service->id,
        'branch' => 'testing',
        'commit_sha' => 'older000000',
        'image_tag' => 'older00',
        'status' => 'success',
        'built_at' => now()->subDay(),
    ]);

    $runner = scriptedRunner();
    builderWith($runner)->prepare($service, 'testing', 'newer000000');

    $diff = collect($runner->commands)->first(fn ($c) => str_contains($c, 'diff --name-only'));

    // A release covering several merges must still see every migration it
    // is about to run.
    expect($diff)->toContain("'older000000'..'newer000000'");
});

/*
|--------------------------------------------------------------------------
| Building
|--------------------------------------------------------------------------
*/

test('the build checks out the exact commit, not a branch', function () {
    $service = Service::factory()->create(['slug' => 'hiring']);
    $runner = scriptedRunner();
    $builder = builderWith($runner);

    $build = $builder->prepare($service, 'testing', 'a1b2c3d4e5f6');
    $runner->commands = [];
    $builder->build($build);

    $fetch = collect($runner->commands)->first(fn ($c) => str_contains($c, 'checkout'));

    // A branch name can move between classifying the migrations and building
    // the image, which would ship different bytes than were classified.
    expect($fetch)->toContain("checkout --force 'a1b2c3d4e5f6'");
});

test('a successful build records the digest, which is what gets deployed', function () {
    $service = Service::factory()->create(['slug' => 'hiring']);

    $runner = scriptedRunner([
        'docker inspect' => new CommandResult(0, 'ghcr.io/rnd-experts-team/hiring@sha256:deadbeef', '', 1),
    ]);

    $builder = builderWith($runner);
    $build = $builder->build($builder->prepare($service, 'testing', 'a1b2c3d4e5f6'));

    expect($build->status)->toBe('success');
    expect($build->digest)->toBe('sha256:deadbeef');
    expect($build->reference())->toBe('ghcr.io/rnd-experts-team/hiring@sha256:deadbeef');
});

test('a failed build records which step failed and never gets a digest', function () {
    $service = Service::factory()->create();

    $runner = scriptedRunner([
        'docker build' => new CommandResult(1, '', 'no space left on device', 1),
    ]);

    $builder = builderWith($runner);
    $build = $builder->build($builder->prepare($service, 'testing', 'a1b2c3d4e5f6'));

    expect($build->status)->toBe('failed');
    expect($build->error)->toContain('build:');
    expect($build->error)->toContain('no space left');
    // Nothing can be deployed from this, which is the point.
    expect($build->digest)->toBeNull();
});

test('a push that yields no digest is a failure, not a silent success', function () {
    $service = Service::factory()->create();

    $runner = scriptedRunner([
        'docker inspect' => new CommandResult(1, '', 'no such image', 1),
    ]);

    $builder = builderWith($runner);
    $build = $builder->build($builder->prepare($service, 'testing', 'a1b2c3d4e5f6'));

    // Without a digest there is nothing to pin, so "deployed the same image"
    // could not be verified later.
    expect($build->status)->toBe('failed');
    expect($build->error)->toContain('no digest');
});
