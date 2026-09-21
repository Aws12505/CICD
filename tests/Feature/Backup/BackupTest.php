<?php

use App\Models\Backup;
use App\Models\Client;
use App\Models\Server;
use App\Models\Service;
use App\Models\Stack;
use App\Models\StackService;
use App\Services\Backup\BackupService;
use App\Services\Remote\CommandResult;
use App\Services\Remote\CommandRunner;
use App\Services\Stack\StackRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'backup.root' => '/var/backups/control-plane',
        'backup.mysql_container' => 'mysql',
        'backup.retention.database_days' => 14,
        'backup.retention.files_days' => 56,
        'backup.stale_after_hours' => 36,
        'deploy.root' => '/srv',
    ]);
});

function backupStack(): Stack
{
    $client = Client::factory()->create(['slug' => 'acme', 'domain' => 'acme.cloud']);

    return Stack::factory()->create([
        'client_id' => $client->id,
        'server_id' => Server::factory()->verified()->create([
            'hostname' => 'acme.example.com', 'ssh_user' => 'deploy', 'ssh_port' => 22,
        ])->id,
        'tier' => Stack::TIER_LIVE,
        'compose_project' => 'acme_live',
        'status' => 'active',
    ]);
}

function backupService(array $failOn = []): array
{
    $runner = new class($failOn) extends CommandRunner
    {
        public array $commands = [];

        public function __construct(private array $failOn) {}

        public function run($target, string $command, ?string $cwd = null, array $env = [], int $timeout = 60, ?Closure $onOutput = null): CommandResult
        {
            $this->commands[] = $command;

            foreach ($this->failOn as $needle) {
                if (str_contains($command, $needle)) {
                    return new CommandResult(1, '', 'boom', 1);
                }
            }

            return new CommandResult(0, '1024', '', 1);
        }
    };

    return [new BackupService($runner, app(StackRenderer::class)), $runner];
}

/*
|--------------------------------------------------------------------------
| Databases
|--------------------------------------------------------------------------
*/

test('a database dump uses pipefail, or a truncated dump looks like a success', function () {
    $stack = backupStack();
    StackService::create([
        'stack_id' => $stack->id,
        'service_id' => Service::factory()->create(['slug' => 'hiring', 'database_name' => 'hiringpizza'])->id,
        'enabled' => true,
    ]);

    [$backups, $runner] = backupService();
    $backups->databases($stack);

    $dump = collect($runner->commands)->first(fn ($c) => str_contains($c, 'mysqldump'));

    // The old command piped mysqldump into gzip without this, so the exit
    // status was gzip's: a dump that died halfway exited 0 and left a
    // truncated file that passed every check and restored to half a database.
    expect($dump)->toContain('set -o pipefail');
});

test('a dump is verified and moved into place, never left half-written', function () {
    $stack = backupStack();
    StackService::create([
        'stack_id' => $stack->id,
        'service_id' => Service::factory()->create(['slug' => 'hiring', 'database_name' => 'hiringpizza'])->id,
        'enabled' => true,
    ]);

    [$backups, $runner] = backupService();
    $backups->databases($stack);

    $dump = collect($runner->commands)->first(fn ($c) => str_contains($c, 'mysqldump'));

    expect($dump)->toContain('gzip -t');
    expect($dump)->toContain('.part');
    expect($dump)->toMatch('/mv .*\.part /');
});

test('the dump is streamed back rather than written on the client first', function () {
    $stack = backupStack();
    StackService::create([
        'stack_id' => $stack->id,
        'service_id' => Service::factory()->create(['slug' => 'hiring', 'database_name' => 'hiringpizza'])->id,
        'enabled' => true,
    ]);

    [$backups, $runner] = backupService();
    $backups->databases($stack);

    $dump = collect($runner->commands)->first(fn ($c) => str_contains($c, 'mysqldump'));

    // No temporary file on the client's disk, which matters most on the
    // server that is already short of space — the one about to need a backup.
    expect($dump)->toContain('ssh');
    expect($dump)->toContain('| gzip -9 >');
});

test('services with no database of their own are skipped', function () {
    $stack = backupStack();

    StackService::create([
        'stack_id' => $stack->id,
        'service_id' => Service::factory()->create(['slug' => 'hiring', 'database_name' => 'hiringpizza'])->id,
        'enabled' => true,
    ]);
    StackService::create([
        'stack_id' => $stack->id,
        'service_id' => Service::factory()->perStackBuild()->create(['slug' => 'dashboard'])->id,
        'enabled' => true,
    ]);

    [$backups] = backupService();
    $results = $backups->databases($stack);

    expect($results)->toHaveCount(1);
});

test('a failed dump is recorded as failed, with the reason', function () {
    $stack = backupStack();
    StackService::create([
        'stack_id' => $stack->id,
        'service_id' => Service::factory()->create(['slug' => 'hiring', 'database_name' => 'hiringpizza'])->id,
        'enabled' => true,
    ]);

    [$backups] = backupService(failOn: ['mysqldump']);
    $backups->databases($stack);

    $backup = Backup::first();

    expect($backup->status)->toBe('failed');
    expect($backup->error)->not->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Targeted dumps before a destructive migration
|--------------------------------------------------------------------------
*/

test('a pre-release dump covers only the named tables and records which', function () {
    $stack = backupStack();
    $entitlement = StackService::create([
        'stack_id' => $stack->id,
        'service_id' => Service::factory()->create(['slug' => 'data', 'database_name' => 'lc_data'])->id,
        'enabled' => true,
    ]);

    [$backups, $runner] = backupService();
    $backup = $backups->tables($stack->fresh(), $entitlement->fresh(), ['goals', 'goal_metrics'], new NullTarget);

    $dump = collect($runner->commands)->first(fn ($c) => str_contains($c, 'mysqldump'));

    // A full dump of the reporting database would put the window into tens of
    // minutes, and a window nobody will schedule is a window nobody takes.
    expect($dump)->toContain('lc_data goals goal_metrics');
    // Restoring without knowing what the file holds is guesswork.
    expect($backup->tables)->toBe(['goals', 'goal_metrics']);
    expect($backup->triggered_by)->toBe('pre_release');
});

/*
|--------------------------------------------------------------------------
| Files
|--------------------------------------------------------------------------
*/

test('file backups keep deleted files instead of mirroring the deletion', function () {
    $stack = backupStack();

    [$backups, $runner] = backupService();
    $backups->files($stack);

    $rsync = collect($runner->commands)->first(fn ($c) => str_contains($c, 'rsync'));

    // A plain --delete mirror has no history: a file deleted on Monday is
    // gone from Sunday's copy too. --backup-dir is what makes this a backup.
    expect($rsync)->toContain('--delete');
    expect($rsync)->toContain('--backup-dir=');
    expect($rsync)->toContain('files-deleted');
});

test('file backups are incremental', function () {
    $stack = backupStack();

    [$backups, $runner] = backupService();
    $backups->files($stack);

    $rsync = collect($runner->commands)->first(fn ($c) => str_contains($c, 'rsync'));

    // The first pull is large; every one after transfers only what changed,
    // which is what makes a weekly policy affordable.
    expect($rsync)->toContain('rsync -az');
});

/*
|--------------------------------------------------------------------------
| Retention and noticing failure
|--------------------------------------------------------------------------
*/

test('expired backups are deleted along with any interrupted .part file', function () {
    $stack = backupStack();

    Backup::create([
        'stack_id' => $stack->id, 'kind' => 'database', 'status' => 'success',
        'path' => '/var/backups/control-plane/acme/live/db/old.sql.gz',
        'retained_until' => now()->subDay(),
    ]);
    Backup::create([
        'stack_id' => $stack->id, 'kind' => 'database', 'status' => 'success',
        'path' => '/var/backups/control-plane/acme/live/db/current.sql.gz',
        'retained_until' => now()->addDay(),
    ]);

    [$backups, $runner] = backupService();
    $removed = $backups->prune();

    expect($removed)->toBe(1);
    expect(Backup::count())->toBe(1);

    // The old command filtered on *.sql.gz, so a killed dump left a .part
    // behind forever and nothing ever noticed.
    expect(implode("\n", $runner->commands))->toContain('.part');
});

test('a stack with no recent successful backup is reported as stale', function () {
    $stack = backupStack();

    Backup::create([
        'stack_id' => $stack->id, 'kind' => 'database', 'status' => 'success',
        'path' => '/x', 'finished_at' => now()->subDays(3),
    ]);

    [$backups] = backupService();

    // Silent failure is the normal way a backup system fails. Noticing is
    // most of the job.
    expect($backups->stale())->toHaveCount(1);
});

test('a stack backed up within the window is not stale', function () {
    $stack = backupStack();

    Backup::create([
        'stack_id' => $stack->id, 'kind' => 'database', 'status' => 'success',
        'path' => '/x', 'finished_at' => now()->subHours(2),
    ]);

    [$backups] = backupService();

    expect($backups->stale())->toBeEmpty();
});

test('a failed backup does not count as a recent one', function () {
    $stack = backupStack();

    Backup::create([
        'stack_id' => $stack->id, 'kind' => 'database', 'status' => 'failed',
        'path' => '/x', 'finished_at' => now(),
    ]);

    // Otherwise a stack failing every night would look perfectly healthy.
    [$backups] = backupService();

    expect($backups->stale())->toHaveCount(1);
});

/*
|--------------------------------------------------------------------------
| The control plane's own data
|--------------------------------------------------------------------------
*/

test('the control plane backs itself up, and that is a recorded risk', function () {
    config(['backup.mysql.username' => 'cicd_backup', 'backup.mysql.password' => 'secret']);

    [$backups, $runner] = backupService();
    $backup = $backups->controlPlane();

    expect($backup->kind)->toBe(Backup::KIND_CONTROL_PLANE);
    expect($backup->stack_id)->toBeNull();

    $dump = collect($runner->commands)->first(fn ($c) => str_contains($c, 'mysqldump'));
    expect($dump)->toContain('set -o pipefail');

    // Losing this machine loses every client's config, secrets and release
    // history while every client server keeps running fine. It lands on the
    // machine it protects until object storage exists.
    expect($backup->path)->toContain('_control-plane');
});
