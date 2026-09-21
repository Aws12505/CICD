<?php

namespace App\Contracts;

/**
 * Exactly what gets handed to proc_open.
 *
 * Local and remote execution differ in more than the argv: running over SSH
 * cannot use proc_open's cwd or env arguments, because those apply to the
 * local ssh client rather than the remote shell. Both therefore have to be
 * folded into the remote command itself. Carrying all three together keeps
 * that difference inside the Target instead of leaking into the executor.
 */
final class Invocation
{
    /**
     * @param  list<string>  $argv
     * @param  array<string, string|int>|null  $env  null inherits the parent environment
     */
    public function __construct(
        public readonly array $argv,
        public readonly ?string $cwd = null,
        public readonly ?array $env = null,
    ) {}
}
