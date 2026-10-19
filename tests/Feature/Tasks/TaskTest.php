<?php

use App\Broadcasting\TaskChannel;
use App\Models\Task;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(RoleSeeder::class));

test('a task writes its log to a file the page can read back', function () {
    $task = Task::begin(Task::TYPE_PROVISION, 'Set up server');
    $task->start();
    $task->log("installing docker\n");
    $task->succeed('Server ready.');

    expect($task->fresh()->status)->toBe('succeeded');
    expect($task->contents())
        ->toContain('installing docker')
        ->toContain('Server ready.');
});

test('masked secrets never reach the log, however a command echoes them', function () {
    $task = Task::begin(Task::TYPE_PROVISION, 'Set up server');
    $task->mask('s3cret-root-password');

    $task->log("mysql: using password s3cret-root-password\n");

    expect($task->contents())
        ->not->toContain('s3cret-root-password')
        ->toContain('******');
});

test('very short values are not masked, or every "a" in the log would vanish', function () {
    $task = Task::begin(Task::TYPE_PROVISION, 'x');
    $task->mask('ab');
    $task->log("table ab created\n");

    expect($task->contents())->toContain('table ab created');
});

test('a failed task keeps its reason', function () {
    $task = Task::begin(Task::TYPE_BUILD, 'Build hiring');
    $task->start();
    $task->fail('docker build: no space left on device');

    expect($task->fresh()->status)->toBe('failed');
    expect($task->fresh()->error)->toContain('no space left');
});

test('the log stream is open to roles only, and only for a task that exists', function () {
    $task = Task::begin(Task::TYPE_RELEASE, 'Deploy');

    $channel = new TaskChannel;

    expect($channel->join(User::factory()->create(), $task->id))->toBeFalse();
    expect($channel->join(actorWithRole('viewer'), $task->id))->toBeTrue();
    expect($channel->join(actorWithRole('viewer'), 999999))->toBeFalse();
});

test('the task page shows the log so far', function () {
    $task = Task::begin(Task::TYPE_RELEASE, 'Deploy ACME testing');
    $task->log("pulling hiring\n");

    $this->actingAs(actorWithRole('viewer'))
        ->get("/tasks/{$task->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->component('tasks/show')
            ->where('task.title', 'Deploy ACME testing')
            ->where('log', "pulling hiring\n")
        );
});
