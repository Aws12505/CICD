<?php

use App\Models\Backup;
use App\Models\Release;
use App\Models\ReleaseItem;
use App\Models\Service;
use App\Models\Stack;
use App\Models\StackService;
use App\Services\Backup\BackupService;
use App\Services\Stack\StackRenderer;
use App\Support\Shell;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'deploy.ports.base' => 40000,
        'deploy.ports.block_size' => 100,
        'deploy.root' => '/srv',
        'deploy.health.attempts' => 1,
        'deploy.health.interval_seconds' => 0,
        'backup.root' => '/var/backups/control-plane',
        'backup.mysql_container' => 'mysql',
    ]);
});

/*
|--------------------------------------------------------------------------
| Quoting
|--------------------------------------------------------------------------
*/

test('quoting is POSIX single quotes on every platform', function () {
    // escapeshellarg() emits double quotes on Windows, inside which bash
    // still expands $(...).
    expect(Shell::quote('$(touch /tmp/pwned)'))->toBe("'\$(touch /tmp/pwned)'");
    expect(Shell::quote("it's"))->toBe("'it'\\''s'");
    expect(Shell::join(['ssh', 'a b']))->toBe("'ssh' 'a b'");
});

test('no code path builds a shell command with escapeshellarg', function () {
    $offenders = collect(File::allFiles(app_path()))
        ->filter(fn ($file) => str_contains($file->getContents(), 'escapeshellarg('))
        ->reject(fn ($file) => str_ends_with($file->getPathname(), 'Shell.php'))
        ->map(fn ($file) => $file->getRelativePathname())
        ->values()
        ->all();

    expect($offenders)->toBe([]);
});

/*
|--------------------------------------------------------------------------
| Backups reach the server through its own key
|--------------------------------------------------------------------------
*/

test('a file backup uses the server key and pinned host key, not whatever ssh the worker has', function () {
    $stack = backupStack();
    [$backups, $runner] = backupService();

    // No target passed: the service must build one from the server row.
    $backups->files($stack);

    $rsync = collect($runner->commands)->first(fn ($c) => str_contains($c, 'rsync'));

    expect($rsync)->toContain("'-i'");
    expect($rsync)->toContain('StrictHostKeyChecking=yes');
    expect($rsync)->toContain('UserKnownHostsFile=');
    expect($rsync)->toContain('deploy@acme.example.com:');
});

test('a database dump reaches the server through its own key too', function () {
    $stack = backupStack();
    StackService::create([
        'stack_id' => $stack->id,
        'service_id' => Service::factory()->create(['slug' => 'hiring', 'database_name' => 'hiringpizza'])->id,
        'enabled' => true,
    ]);

    [$backups, $runner] = backupService();
    $backups->databases($stack);

    $dump = collect($runner->commands)->first(fn ($c) => str_contains($c, 'mysqldump'));

    expect($dump)->toContain("'-i'")->toContain('UserKnownHostsFile=');
    // The root password stays inside the MySQL container's own environment
    // and never appears on a command line on either machine.
    expect($dump)->toContain('$MYSQL_ROOT_PASSWORD');
});

test('the temporary key files are gone once a backup finishes', function () {
    $stack = backupStack();
    [$backups] = backupService();

    $before = glob(storage_path('app/private/ssh/key-*')) ?: [];
    $backups->files($stack);
    $after = glob(storage_path('app/private/ssh/key-*')) ?: [];

    expect(count($after))->toBe(count($before));
});

/*
|--------------------------------------------------------------------------
| Releases
|--------------------------------------------------------------------------
*/

test('a failed pre-migration backup stops the release before anything is migrated', function () {
    $client = twoTierClient();
    $live = $client->liveStack();
    $service = Service::factory()->create(['slug' => 'hiring', 'subdomain' => 'hiring', 'database_name' => 'hiringpizza']);
    entitle($live, $service, 'sha256:old');
    $build = successfulBuild($service, 'sha256:new', 'destructive', ['goals']);

    $release = Release::create(['stack_id' => $live->id, 'kind' => Release::KIND_NORMAL, 'scheduled_for' => now()]);
    ReleaseItem::create([
        'release_id' => $release->id, 'service_id' => $service->id,
        'build_id' => $build->id, 'to_digest' => 'sha256:new',
    ]);

    $commands = fakeRunner(failOn: ['mysqldump']);
    $result = runnerWith($commands)->run($release->fresh(), new NullTarget);

    expect($result->status)->toBe('failed');

    // The old runner ignored this and went on to drop columns with nothing
    // to restore from.
    expect(implode("\n", $commands->commands))->not->toContain('artisan migrate');
    expect(Backup::first()->status)->toBe('failed');
    expect(Backup::first()->release_id)->toBe($release->id);

    // And the maintenance window is still closed on the way out.
    expect($result->fresh()->window_ended_at)->not->toBeNull();
});

test('a stack becomes active after its first successful release, and not before', function () {
    $client = twoTierClient();
    $testing = $client->testingStack();
    $testing->update(['status' => 'pending']);

    $service = Service::factory()->create(['slug' => 'hiring', 'subdomain' => 'hiring']);
    entitle($testing, $service);

    $release = Release::create(['stack_id' => $testing->id, 'kind' => Release::KIND_NORMAL]);
    ReleaseItem::create(['release_id' => $release->id, 'service_id' => $service->id, 'to_digest' => 'sha256:new']);

    // Failing first: still pending, so no backups or drift polls start
    // against a stack that has nothing on it.
    runnerWith(fakeRunner(failOn: ['curl']))->run($release->fresh(), new NullTarget);
    expect($testing->fresh()->status)->toBe('pending');

    $retry = Release::create(['stack_id' => $testing->id, 'kind' => Release::KIND_NORMAL]);
    ReleaseItem::create(['release_id' => $retry->id, 'service_id' => $service->id, 'to_digest' => 'sha256:new']);

    runnerWith(fakeRunner())->run($retry->fresh(), new NullTarget);
    expect($testing->fresh()->status)->toBe('active');
});

test('the renderer and the backup store agree on where a stack lives', function () {
    $stack = backupStack();

    expect(app(StackRenderer::class)->rootPath($stack))->toBe('/srv/acme_live');
    expect(app(BackupService::class)->stackRoot($stack))->toBe('/var/backups/control-plane/acme/live');
});
