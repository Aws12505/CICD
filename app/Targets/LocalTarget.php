<?php

namespace App\Targets;

use App\Contracts\Invocation;
use App\Contracts\Target;

/**
 * Runs the command on this machine, exactly as the executor always has.
 *
 * Every pipeline that existed before remote execution keeps this behaviour.
 */
final class LocalTarget implements Target
{
    public function invoke(string $command, ?string $cwd, array $env): Invocation
    {
        return new Invocation(
            argv: ['/bin/bash', '-lc', $command],
            // A cwd that does not exist silently becomes null, which runs the
            // step in PHP's working directory. Preserved from the original
            // implementation rather than quietly changed here.
            cwd: $cwd !== null && is_dir($cwd) ? $cwd : null,
            env: array_merge(getenv() ?: [], $env),
        );
    }

    public function prefix(): array
    {
        return ['/bin/bash', '-c'];
    }

    public function label(): string
    {
        return 'local';
    }

    public function cleanup(): void
    {
        // Nothing to release.
    }
}
