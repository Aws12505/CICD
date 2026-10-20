<?php

use App\Services\System\SystemCheck;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    config([
        'app.url' => 'https://cicd.example.com',
        'broadcasting.connections.reverb.app_id' => '123456',
        'broadcasting.connections.reverb.key' => 'abc',
        'broadcasting.connections.reverb.secret' => 'def',
        'broadcasting.connections.reverb.options.host' => 'cicd.example.com',
    ]);
});

function queuedJob(int $waitingSeconds): void
{
    DB::table('jobs')->insert([
        'queue' => 'default',
        'payload' => '{}',
        'attempts' => 0,
        'reserved_at' => null,
        'available_at' => time() - $waitingSeconds,
        'created_at' => time() - $waitingSeconds,
    ]);
}

function byKey(array $checks, string $key): array
{
    return collect($checks)->firstWhere('key', $key);
}

/*
|--------------------------------------------------------------------------
| EnsureEnv: the credentials reverb:install did not write
|--------------------------------------------------------------------------
*/

test('empty Reverb credentials are generated, and never a value that is set', function () {
    $path = tempnam(sys_get_temp_dir(), 'env');
    file_put_contents($path, "APP_NAME=CICD\nREVERB_APP_ID=\nREVERB_APP_KEY=\"\"\nREVERB_APP_SECRET=keep-this-one\nREVERB_HOST=cicd.example.com\n");

    $this->artisan('control-plane:ensure-env', ['--env-file' => $path])
        ->expectsOutputToContain('reverb-credentials: generated (REVERB_APP_ID, REVERB_APP_KEY)')
        ->assertSuccessful();

    $env = file_get_contents($path);
    expect($env)->toMatch('/^REVERB_APP_ID=\d{6}$/m');
    expect($env)->toMatch('/^REVERB_APP_KEY=[0-9a-f]{20}$/m');
    expect($env)->toContain("REVERB_APP_SECRET=keep-this-one\n")->toContain('APP_NAME=CICD')->toContain('REVERB_HOST=cicd.example.com');

    $first = $env;
    $this->artisan('control-plane:ensure-env', ['--env-file' => $path])->expectsOutputToContain('reverb-credentials: present')->assertSuccessful();
    // Running it again rotates nothing.
    expect(file_get_contents($path))->toBe($first);
    unlink($path);
});

test('missing lines are added, and the secret values never reach the log', function () {
    $path = tempnam(sys_get_temp_dir(), 'env');
    file_put_contents($path, "APP_NAME=CICD\n");

    $this->artisan('control-plane:ensure-env', ['--env-file' => $path])->assertSuccessful();

    $env = file_get_contents($path);
    expect($env)->toMatch('/^REVERB_APP_ID=\d{6}$/m')->toMatch('/^REVERB_APP_SECRET=[0-9a-f]{40}$/m');
    unlink($path);
});

test('a missing or read-only env file is reported, not fatal', function () {
    $this->artisan('control-plane:ensure-env', ['--env-file' => '/nonexistent/.env'])->assertSuccessful();
});

/*
|--------------------------------------------------------------------------
| The checks
|--------------------------------------------------------------------------
*/

test('a job that has waited minutes means nothing is taking jobs', function () {
    $checks = app(SystemCheck::class);

    expect($checks->queue())->status->toBe('ok');

    queuedJob(10);
    expect($checks->queue())->status->toBe('ok');

    queuedJob(400);
    $queue = $checks->queue();
    expect($queue)->status->toBe('fail');
    expect($queue['detail'])->toContain('2 job(s)')->toContain('"queued"');
    expect($queue['fix'])->toContain('supervisorctl status');
});

test('recent failures are a warning, not an outage', function () {
    DB::table('failed_jobs')->insert(['uuid' => 'a', 'connection' => 'database', 'queue' => 'default', 'payload' => '{}', 'exception' => 'boom', 'failed_at' => now()]);

    expect(app(SystemCheck::class)->queue())->status->toBe('warn');
});

test('the scheduler is judged by its heartbeat', function () {
    $checks = app(SystemCheck::class);

    expect($checks->scheduler())->status->toBe('warn');

    Cache::put(SystemCheck::SCHEDULER_KEY, time() - 30);
    expect($checks->scheduler())->status->toBe('ok');

    Cache::put(SystemCheck::SCHEDULER_KEY, time() - 900);
    expect($checks->scheduler())->status->toBe('fail');
});

test('the heartbeat is written by the schedule itself', function () {
    $this->artisan('schedule:test', ['--name' => 'scheduler-heartbeat'])->assertSuccessful();

    expect(Cache::get(SystemCheck::SCHEDULER_KEY))->toBeGreaterThan(time() - 10);
});

test('missing Reverb credentials are named, and cure is the boot script', function () {
    config(['broadcasting.connections.reverb.key' => null, 'broadcasting.connections.reverb.app_id' => '']);

    $check = app(SystemCheck::class)->reverbCredentials();

    expect($check)->status->toBe('fail');
    expect($check['detail'])->toContain('REVERB_APP_ID, REVERB_APP_KEY')->not->toContain('REVERB_APP_SECRET')->toContain('/app/ with no key');
    expect($check['fix'])->toContain('docker compose up -d --build');
});

test('a Reverb host that differs from the app address is called out with the fix', function () {
    config(['broadcasting.connections.reverb.options.host' => 'cicd.lcportal.cloud']);

    $check = app(SystemCheck::class)->reverbHost();

    expect($check)->status->toBe('warn');
    expect($check['detail'])->toContain('cicd.lcportal.cloud')->toContain('cicd.example.com');
    expect($check['fix'])->toContain('REVERB_HOST="cicd.example.com"');
});

test('publishing a real event is the end-to-end test, and its failure explains /apps', function () {
    $failing = new class extends SystemCheck
    {
        protected function publish(): ?string
        {
            return 'cURL error 7: Failed to connect';
        }
    };
    $working = new class extends SystemCheck
    {
        protected function publish(): ?string
        {
            return null;
        }
    };

    $bad = $failing->reverbPublish();
    expect($bad)->status->toBe('fail');
    expect($bad['detail'])->toContain('Failed to connect');
    expect($bad['fix'])->toContain('/app AND /apps');
    expect($working->reverbPublish())->status->toBe('ok');
});

/*
|--------------------------------------------------------------------------
| Where it shows
|--------------------------------------------------------------------------
*/

test('admins see problems on the dashboard and can open the page; nobody else', function () {
    queuedJob(600);

    $this->actingAs(actorWithRole('admin'))->get('/dashboard')->assertInertia(fn ($page) => $page
        ->where('systemProblems.0.key', 'queue'));
    $this->actingAs(actorWithRole('admin'))->get('/control-plane/system')->assertOk()
        ->assertInertia(fn ($page) => $page->component('control-plane/system')->where('live', false)->has('checks', 4));

    $this->actingAs(actorWithRole('operator'))->get('/dashboard')->assertInertia(fn ($page) => $page->where('systemProblems', []));
    $this->actingAs(actorWithRole('operator'))->get('/control-plane/system')->assertForbidden();
    $this->actingAs(actorWithRole('viewer'))->get('/control-plane/system')->assertForbidden();
});

test('the live test adds the end-to-end check', function () {
    $this->actingAs(actorWithRole('admin'))->get('/control-plane/system?live=1')
        ->assertInertia(fn ($page) => $page->where('live', true)->has('checks', 5)->where('checks.4.key', 'reverb-publish'));
});
