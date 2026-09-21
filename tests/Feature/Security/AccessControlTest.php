<?php

use App\Broadcasting\PipelineRunChannel;
use App\Broadcasting\PipelineRunsChannel;
use App\Models\PipelineRun;
use App\Models\Project;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Fortify\Features;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

/*
|--------------------------------------------------------------------------
| Registration
|--------------------------------------------------------------------------
*/

test('registration is disabled', function () {
    expect(Features::enabled(Features::registration()))->toBeFalse();

    // Anyone who can sign in can run shell as root on every managed server,
    // so there must be no self-service path to an account.
    $this->get('/register')->assertNotFound();
    $this->post('/register', [
        'name' => 'Intruder',
        'email' => 'intruder@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertNotFound();
});

/*
|--------------------------------------------------------------------------
| Role gates
|--------------------------------------------------------------------------
*/

test('a viewer can read but cannot change anything', function () {
    $project = aProject();
    $this->actingAs(actorWithRole('viewer'));

    $this->get('/dashboard')->assertOk();
    $this->get('/projects')->assertOk();
    $this->get("/projects/{$project->id}")->assertOk();
    $this->get('/runs')->assertOk();

    $this->get('/projects/create')->assertForbidden();
    $this->post('/projects', [])->assertForbidden();
    $this->get("/projects/{$project->id}/edit")->assertForbidden();
    $this->delete("/projects/{$project->id}")->assertForbidden();
});

test('an operator can change what runs but cannot delete', function () {
    $project = aProject();
    $this->actingAs(actorWithRole('operator'));

    $this->get('/projects/create')->assertOk();
    $this->get("/projects/{$project->id}/edit")->assertOk();
    $this->get("/projects/{$project->id}/pipelines/create")->assertOk();

    $this->delete("/projects/{$project->id}")->assertForbidden();
});

test('an admin can delete', function () {
    $project = aProject();
    $this->actingAs(actorWithRole('admin'));

    $this->delete("/projects/{$project->id}")->assertRedirect('/projects');
    $this->assertDatabaseMissing('projects', ['id' => $project->id]);
});

test('the UI is told what the user may do, so it can hide dead controls', function () {
    // Presentation only — the routes above are what actually enforce this.
    // Without it a viewer sees a "New project" button that 403s.
    $this->actingAs(actorWithRole('viewer'))
        ->get('/projects')
        ->assertInertia(fn (Assert $page) => $page
            ->where('auth.roles', ['viewer'])
            ->where('auth.can.manage', false)
            ->where('auth.can.destroy', false)
        );

    $this->actingAs(actorWithRole('operator'))
        ->get('/projects')
        ->assertInertia(fn (Assert $page) => $page
            ->where('auth.can.manage', true)
            ->where('auth.can.destroy', false)
        );

    $this->actingAs(actorWithRole('admin'))
        ->get('/projects')
        ->assertInertia(fn (Assert $page) => $page
            ->where('auth.can.manage', true)
            ->where('auth.can.destroy', true)
        );
});

test('a user with no role at all cannot reach anything privileged', function () {
    $project = aProject();
    $this->actingAs(User::factory()->create());

    $this->get('/projects/create')->assertForbidden();
    $this->delete("/projects/{$project->id}")->assertForbidden();
});

test('rerunning a pipeline is an operator action, not a read', function () {
    $project = aProject();
    $pipeline = $project->pipelines()->create([
        'branch' => 'testing',
        'steps' => [['id' => 'a', 'name' => 'noop', 'command' => 'true']],
        'enabled' => true,
    ]);
    $run = PipelineRun::create([
        'pipeline_id' => $pipeline->id,
        'status' => 'success',
        'log_path' => 'logs/pipeline-runs/test.log',
    ]);

    // A rerun executes arbitrary shell as root on the host.
    $this->actingAs(actorWithRole('viewer'));
    $this->post("/runs/{$run->id}/rerun")->assertForbidden();

    $this->actingAs(actorWithRole('operator'));
    $this->post("/runs/{$run->id}/rerun")->assertRedirect();
});

/*
|--------------------------------------------------------------------------
| The create/{wildcard} ordering trap
|--------------------------------------------------------------------------
*/

test('projects/create is not swallowed by the project wildcard route', function () {
    aProject();
    $this->actingAs(actorWithRole('operator'));

    // Declared after `projects/{project}`, this would bind "create" as an id
    // and 404. It is a route-ordering bug that only shows up at runtime.
    $this->get('/projects/create')->assertOk();
});

/*
|--------------------------------------------------------------------------
| Broadcast channels
|--------------------------------------------------------------------------
*/

test('log streams are not readable by a user with no role', function () {
    $project = aProject();
    $pipeline = $project->pipelines()->create([
        'branch' => 'testing',
        'steps' => [],
        'enabled' => true,
    ]);
    $run = PipelineRun::create([
        'pipeline_id' => $pipeline->id,
        'status' => 'running',
        'log_path' => 'logs/pipeline-runs/test.log',
    ]);

    $roleless = User::factory()->create();
    $viewer = actorWithRole('viewer');

    // Called directly rather than through /broadcasting/auth: the `null`
    // broadcaster used in tests returns true without evaluating the rule,
    // so an HTTP-level assertion here would pass no matter what we wrote.
    $perRun = new PipelineRunChannel;

    // Deploy logs carry env values and hostnames; membership is the gate.
    expect($perRun->join($roleless, $run->id))->toBeFalse();
    expect($perRun->join($viewer, $run->id))->toBeTrue();

    // A run that does not exist is never subscribable, whatever the role.
    expect($perRun->join($viewer, 999999))->toBeFalse();

    // Same for the global activity feed.
    expect((new PipelineRunsChannel)->join($roleless))->toBeFalse();
    expect((new PipelineRunsChannel)->join($viewer))->toBeTrue();
});
