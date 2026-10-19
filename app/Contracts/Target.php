<?php

namespace App\Contracts;

/**
 * Where a command actually runs.
 *
 * The executor has always built its argv inline and handed it to proc_open.
 * Wrapping that one decision is what lets the same runner drive a command on
 * this box or on a client's server, with log streaming, timeouts and exit
 * codes unchanged.
 *
 * Implementations must be safe to reuse across many commands, and must
 * release anything they allocated when cleanup() is called.
 */
interface Target
{
    /**
     * Turn a command into something proc_open can run.
     *
     * @param  array<string, string|int>  $env
     */
    public function invoke(string $command, ?string $cwd, array $env): Invocation;

    /**
     * The argv that, followed by one command string, runs it on this target.
     * For composing pipelines that span machines, e.g. streaming a remote
     * dump into a local gzip.
     *
     * @return list<string>
     */
    public function prefix(): array;

    /** Short human label for the logs, e.g. "local" or "deploy@10.0.0.4". */
    public function label(): string;

    /** Release temporary resources. Always called, including after a failure. */
    public function cleanup(): void;
}
