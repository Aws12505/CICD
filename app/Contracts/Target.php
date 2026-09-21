<?php

namespace App\Contracts;

/**
 * Where a pipeline step's command actually runs.
 *
 * The executor has always built its argv inline and handed it to proc_open.
 * Wrapping that one decision is what lets the same runner drive a command on
 * this box or on a client's server, with log streaming, timeouts and exit
 * codes unchanged.
 *
 * Implementations must be safe to reuse across the steps of one run, and must
 * release anything they allocated when cleanup() is called.
 */
interface Target
{
    /**
     * Turn a step into something proc_open can run.
     *
     * @param  array<string, string|int>  $env
     */
    public function invoke(string $command, ?string $cwd, array $env): Invocation;

    /** Short human label for the logs, e.g. "local" or "deploy@10.0.0.4". */
    public function label(): string;

    /** Release temporary resources. Always called, including after a failure. */
    public function cleanup(): void;
}
