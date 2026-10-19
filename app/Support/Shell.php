<?php

namespace App\Support;

/**
 * Quoting for commands that a POSIX shell will read.
 *
 * Every command the control plane builds is read by bash — either locally
 * (LocalTarget runs `/bin/bash -lc`) or on a client server over SSH. So the
 * quoting has to be POSIX quoting, regardless of where PHP happens to run.
 *
 * escapeshellarg() is not that: it quotes for the platform PHP runs on, and on
 * Windows it emits double quotes, inside which bash still expands `$(...)`. A
 * value containing `$(rm -rf /)` would run.
 */
final class Shell
{
    /**
     * Single-quote a value. Nothing inside single quotes is expanded; the only
     * character needing care is the single quote itself, which is closed,
     * escaped and reopened.
     */
    public static function quote(string|int $value): string
    {
        return "'".str_replace("'", "'\\''", (string) $value)."'";
    }

    /**
     * Quote and join an argv into one command string.
     *
     * @param  list<string|int>  $argv
     */
    public static function join(array $argv): string
    {
        return implode(' ', array_map(self::quote(...), $argv));
    }
}
