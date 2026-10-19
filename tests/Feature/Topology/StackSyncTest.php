<?php

use App\Contracts\Target;
use App\Models\Service;
use App\Models\Stack;
use App\Models\StackService;
use App\Services\Remote\CommandResult;
use App\Services\Remote\CommandRunner;
use App\Services\Stack\StackRenderer;
use App\Services\Stack\StackSync;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function syncableStack(): Stack
{
    config([
        'deploy.ports.base' => 40000,
        'deploy.ports.block_size' => 100,
        'deploy.root' => '/srv',
    ]);

    $stack = renderableStack();

    StackService::create([
        'stack_id' => $stack->id,
        'service_id' => Service::factory()->create(['slug' => 'hiring', 'subdomain' => 'hiring'])->id,
        'enabled' => true,
    ]);

    return $stack;
}

test('files are pushed base64 encoded rather than through a heredoc', function () {
    $runner = new RecordingRunner;
    $sync = new StackSync(app(StackRenderer::class), $runner);

    $sync->push(syncableStack(), new NullTarget);

    $all = implode("\n", $runner->commands);

    // A rendered file can contain anything, including a line matching a
    // heredoc terminator, which would truncate the file with no error.
    expect($all)->toContain('base64 -d');
    expect($all)->not->toContain('<<');
});

test('env files are written 0600 and their directory 0700', function () {
    $runner = new RecordingRunner;
    $sync = new StackSync(app(StackRenderer::class), $runner);

    $sync->push(syncableStack(), new NullTarget);

    $all = implode("\n", $runner->commands);

    // They hold database passwords and service tokens in plaintext, because
    // the container has to read them.
    expect($all)->toContain("chmod 700 '/srv/acme_live/env'");
    expect($all)->toMatch('/chmod 600 .*hiring\.env/');
});

test('a file is written to a temporary name and moved into place', function () {
    $runner = new RecordingRunner;
    $sync = new StackSync(app(StackRenderer::class), $runner);

    $sync->push(syncableStack(), new NullTarget);

    $all = implode("\n", $runner->commands);

    // A container starting mid-write must never see half a compose file.
    expect($all)->toContain('.tmp');
    expect($all)->toMatch('/mv .*\.tmp /');
});

test('storage directories are created before containers could create them as root', function () {
    $runner = new RecordingRunner;
    $sync = new StackSync(app(StackRenderer::class), $runner);

    $sync->push(syncableStack(), new NullTarget);

    expect($runner->commands[0])->toContain('/srv/acme_live/storage/hiring');
});

test('nginx is validated before it is reloaded, and reloaded rather than restarted', function () {
    $runner = new RecordingRunner;
    $sync = new StackSync(app(StackRenderer::class), $runner);

    $sync->reloadNginx(syncableStack(), new NullTarget);

    $script = $runner->commands[0];

    // `nginx -t` first: a broken config that is never loaded leaves every
    // other site on the box, including the other tier, still serving.
    expect($script)->toContain('nginx -t');
    expect($script)->toContain('nginx -s reload');
    expect($script)->not->toContain('restart');
});

test('a failing push stops rather than carrying on to the next file', function () {
    $runner = new class extends CommandRunner
    {
        public int $calls = 0;

        public function run(Target $target, string $command, ?string $cwd = null, array $env = [], int $timeout = 60, ?Closure $onOutput = null): CommandResult
        {
            $this->calls++;

            return new CommandResult(1, '', 'disk full', 1);
        }
    };

    $sync = new StackSync(app(StackRenderer::class), $runner);

    $result = $sync->push(syncableStack(), new NullTarget);

    expect($result?->failed())->toBeTrue();
    // Half a stack on disk is worse than none of it.
    expect($runner->calls)->toBe(1);
});
