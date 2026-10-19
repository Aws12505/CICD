<?php

use App\Jobs\RestoreBackupJob;
use App\Jobs\RunBackupJob;
use App\Models\Backup;
use App\Models\BackupRestore;
use App\Models\Client;
use App\Models\Server;
use App\Models\Service;
use App\Models\Stack;
use App\Models\StackService;
use App\Models\Task;
use App\Services\Backup\BackupService;
use App\Services\Backup\RestoreService;
use App\Services\Remote\CommandResult;
use App\Services\Stack\StackRenderer;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    config(['deploy.root' => '/srv', 'backup.mysql_container' => 'mysql']);
});

/** ACME live running Hiring, with one good database backup. */
function restorable(array $tables = []): Backup
{
    $client = Client::factory()->create(['slug' => 'acme']);
    $stack = Stack::factory()->create([
        'client_id' => $client->id,
        'server_id' => Server::factory()->verified()->create()->id,
        'tier' => Stack::TIER_LIVE,
        'compose_project' => 'acme_live',
    ]);
    $service = Service::factory()->create(['slug' => 'hiring', 'database_name' => 'hiringpizza']);
    StackService::create(['stack_id' => $stack->id, 'service_id' => $service->id, 'enabled' => true]);

    return Backup::create([
        'stack_id' => $stack->id,
        'service_id' => $service->id,
        'kind' => Backup::KIND_DATABASE,
        'status' => 'success',
        'triggered_by' => 'schedule',
        'path' => '/var/backups/control-plane/acme/live/db/hiring/2026-10-18_020000-hiringpizza.sql.gz',
        'tables' => $tables ?: null,
        'finished_at' => now()->subDay(),
    ]);
}

function restoreService(RecordingRunner $runner): RestoreService
{
    return new RestoreService($runner, new BackupService($runner, app(StackRenderer::class)), app(StackRenderer::class));
}

function restoreRecord(Backup $backup): BackupRestore
{
    return BackupRestore::create(['backup_id' => $backup->id, 'stack_id' => $backup->stack_id, 'reason' => 'Rows deleted by mistake', 'status' => 'running']);
}

test('restoring is admin-only and needs the stack name typed out', function () {
    Bus::fake();
    $backup = restorable();

    $this->actingAs(actorWithRole('operator'))
        ->post("/backups/{$backup->id}/restore", ['confirm' => 'acme_live', 'reason' => 'Rows deleted by mistake'])
        ->assertForbidden();

    $this->actingAs(actorWithRole('admin'))
        ->post("/backups/{$backup->id}/restore", ['confirm' => 'acme', 'reason' => 'Rows deleted by mistake'])
        ->assertSessionHasErrors('confirm');

    Bus::assertNothingDispatched();

    $this->actingAs(actorWithRole('admin'))
        ->post("/backups/{$backup->id}/restore", ['confirm' => 'acme_live', 'reason' => 'Rows deleted by mistake'])
        ->assertRedirect();

    expect(BackupRestore::sole())->reason->toBe('Rows deleted by mistake')->status->toBe('running');
    Bus::assertDispatched(RestoreBackupJob::class);
});

test('a restore takes a safety backup first, then streams the dump into that database', function () {
    $backup = restorable();
    $runner = new RecordingRunner;
    $task = Task::begin(Task::TYPE_RESTORE, 'Restore', $backup);
    $restore = restoreRecord($backup);

    expect(restoreService($runner)->restore($restore, $task))->toBeTrue();

    $safety = collect($runner->commands)->search(fn ($c) => str_contains($c, 'mysqldump'));
    $load = collect($runner->commands)->search(fn ($c) => str_contains($c, 'gunzip -c'));

    expect($safety)->toBeLessThan($load);
    expect($runner->commands[$load])
        ->toContain('set -o pipefail')
        ->toContain("'/var/backups/control-plane/acme/live/db/hiring/2026-10-18_020000-hiringpizza.sql.gz'")
        ->toContain('docker exec -i')
        ->toContain('hiringpizza');
    expect(Backup::where('triggered_by', 'manual')->count())->toBe(1);
    expect($restore->fresh()->status)->toBe('success');
});

test('a failed safety backup means nothing is restored', function () {
    $backup = restorable();
    $runner = new RecordingRunner(fn ($c) => str_contains($c, 'mysqldump') ? new CommandResult(1, '', 'disk full', 1) : null);
    $restore = restoreRecord($backup);

    restoreService($runner)->restore($restore, Task::begin(Task::TYPE_RESTORE, 'Restore', $backup));

    expect(collect($runner->commands)->filter(fn ($c) => str_contains($c, 'gunzip -c')))->toBeEmpty();
    expect($restore->fresh())->status->toBe('failed')->error->toContain('safety backup');
});

test('a damaged backup file is refused before anything is touched', function () {
    $backup = restorable();
    $runner = new RecordingRunner(fn ($c) => str_starts_with($c, 'gzip -t') ? new CommandResult(1, '', 'unexpected end of file', 1) : null);
    $restore = restoreRecord($backup);

    restoreService($runner)->restore($restore, Task::begin(Task::TYPE_RESTORE, 'Restore', $backup));

    expect(collect($runner->commands)->filter(fn ($c) => str_contains($c, 'mysqldump') || str_contains($c, 'gunzip -c')))->toBeEmpty();
    expect($restore->fresh()->error)->toContain('missing or damaged');
});

test('a pre-release table dump only puts back its own tables', function () {
    $backup = restorable(['goals', 'goal_metrics']);
    $runner = new RecordingRunner;

    restoreService($runner)->restore(restoreRecord($backup), Task::begin(Task::TYPE_RESTORE, 'Restore', $backup));

    // The safety dump covers the same tables, not the whole database.
    $safety = collect($runner->commands)->first(fn ($c) => str_contains($c, 'mysqldump'));
    expect($safety)->toContain('hiringpizza-tables.sql.gz')->toContain('goals')->toContain('goal_metrics');
});

test('back up now queues a backup that streams to a task', function () {
    Bus::fake();
    $backup = restorable();

    $this->actingAs(actorWithRole('operator'))
        ->post("/stacks/{$backup->stack_id}/backups", ['kind' => 'database'])
        ->assertRedirect('/tasks/'.Task::sole()->id);

    Bus::assertDispatched(RunBackupJob::class, fn ($job) => $job->kind === 'database' && $job->stackId === $backup->stack_id && $job->taskId !== null);
});
