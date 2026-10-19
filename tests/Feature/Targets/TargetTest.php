<?php

use App\Targets\LocalTarget;
use App\Targets\SshTarget;

/*
|--------------------------------------------------------------------------
| LocalTarget — must behave exactly as the executor always did
|--------------------------------------------------------------------------
*/

test('the local target runs the command through bash, unchanged', function () {
    $invocation = (new LocalTarget)->invoke('echo hi', __DIR__, ['FOO' => 'bar']);

    expect($invocation->argv)->toBe(['/bin/bash', '-lc', 'echo hi']);
    expect($invocation->cwd)->toBe(__DIR__);
    expect($invocation->env)->toHaveKey('FOO', 'bar');
});

test('the local target drops a working directory that does not exist', function () {
    // Preserved from the original implementation: the step then runs in PHP's
    // own working directory rather than failing.
    $invocation = (new LocalTarget)->invoke('echo hi', '/no/such/directory', []);

    expect($invocation->cwd)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| SshTarget
|--------------------------------------------------------------------------
*/

test('the ssh target never passes cwd or env to proc_open', function () {
    $target = new SshTarget('10.0.0.4', 'deploy', 22, 'PRIVATE KEY', 'host ssh-ed25519 AAAA');

    $invocation = $target->invoke('whoami', '/srv/app', ['FOO' => 'bar']);

    // These would configure the local ssh client, not the remote shell.
    expect($invocation->cwd)->toBeNull();
    expect($invocation->env)->toBeNull();

    $target->cleanup();
});

test('the ssh target folds the working directory and environment into the remote script', function () {
    $target = new SshTarget('10.0.0.4', 'deploy', 22, 'PRIVATE KEY', 'host ssh-ed25519 AAAA');

    $argv = $target->invoke('whoami', '/srv/app', ['FOO' => 'bar'])->argv;
    $script = end($argv);

    expect($script)->toBe("cd '/srv/app' || exit 1\nexport FOO='bar'\nwhoami");

    $target->cleanup();
});

test('a working directory that is missing on the remote host fails the step', function () {
    $target = new SshTarget('10.0.0.4', 'deploy');

    $argv = $target->invoke('whoami', '/srv/app', [])->argv;
    $script = end($argv);

    // Unlike the local target: silently running a deploy in the wrong
    // directory on a client's production server is not worth preserving.
    expect($script)->toContain('|| exit 1');

    $target->cleanup();
});

test('quoting is POSIX regardless of the platform the control plane runs on', function () {
    // escapeshellarg() would emit double quotes on Windows, and the remote
    // shell expands $(...) inside those. The remote is always POSIX, so the
    // quoting must be too.
    $target = new SshTarget('10.0.0.4', 'deploy');

    $argv = $target->invoke('run', null, ['EVIL' => '$(touch /tmp/pwned)'])->argv;
    $script = end($argv);

    expect($script)->toContain("export EVIL='\$(touch /tmp/pwned)'");
    expect($script)->not->toContain('"');

    $target->cleanup();
});

test('environment values are escaped, and unusable keys are dropped not injected', function () {
    $target = new SshTarget('10.0.0.4', 'deploy');

    $argv = $target->invoke('run', null, [
        'GOOD' => "it's; rm -rf /",
        'BAD KEY' => 'whatever',
        'ALSO-BAD' => 'whatever',
    ])->argv;
    $script = end($argv);

    expect($script)->toContain("export GOOD='it'\\''s; rm -rf /'");
    expect($script)->not->toContain('BAD KEY');
    expect($script)->not->toContain('ALSO-BAD');

    $target->cleanup();
});

test('a known host key is pinned', function () {
    $target = new SshTarget('10.0.0.4', 'deploy', 2222, 'PRIVATE KEY', 'host ssh-ed25519 AAAA');

    $argv = $target->invoke('whoami', null, [])->argv;

    expect($argv)->toContain('StrictHostKeyChecking=yes');
    expect($argv)->toContain('BatchMode=yes');
    expect($argv)->toContain('2222');
    expect($argv)->toContain('deploy@10.0.0.4');

    $target->cleanup();
});

test('only a target with no pinned key will accept an unknown host', function () {
    $target = new SshTarget('10.0.0.4', 'deploy');

    expect($target->invoke('whoami', null, [])->argv)
        ->toContain('StrictHostKeyChecking=accept-new');

    $target->cleanup();
});

/*
|--------------------------------------------------------------------------
| Key custody
|--------------------------------------------------------------------------
*/

test('the private key is written locked down and removed afterwards', function () {
    $target = new SshTarget('10.0.0.4', 'deploy', 22, 'THE PRIVATE KEY');

    $argv = $target->invoke('whoami', null, [])->argv;
    $keyPath = $argv[array_search('-i', $argv, true) + 1];

    expect(is_file($keyPath))->toBeTrue();
    // With a trailing newline: OpenSSH rejects a private key file without one
    // as "invalid format".
    expect(file_get_contents($keyPath))->toBe("THE PRIVATE KEY\n");

    // 0600 on any platform that reports modes meaningfully.
    if (DIRECTORY_SEPARATOR === '/') {
        expect(substr(sprintf('%o', fileperms($keyPath)), -3))->toBe('600');
    }

    $target->cleanup();

    // A key left behind on the control plane is a key someone can steal.
    expect(is_file($keyPath))->toBeFalse();
});

test('cleanup is safe to call twice', function () {
    $target = new SshTarget('10.0.0.4', 'deploy', 22, 'KEY');
    $target->invoke('whoami', null, []);

    $target->cleanup();
    $target->cleanup();
})->throwsNoExceptions();
