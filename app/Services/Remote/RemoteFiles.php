<?php

namespace App\Services\Remote;

use App\Support\Shell;
use RuntimeException;

/**
 * Builds the command that writes a file on a remote machine.
 */
final class RemoteFiles
{
    /**
     * Base64 rather than a heredoc: the contents are arbitrary, and a line
     * matching the terminator would truncate the file with no error. Written
     * to a temporary name and moved, so nothing ever reads half a file.
     */
    public static function write(string $path, string $contents, string $mode = '644'): string
    {
        $encoded = base64_encode($contents);

        if (strlen($encoded) > 100_000) {
            throw new RuntimeException("File [{$path}] is too large to write in one command.");
        }

        $quoted = Shell::quote($path);

        return implode("\n", [
            'set -e',
            'mkdir -p '.Shell::quote(dirname($path)),
            "printf '%s' '{$encoded}' | base64 -d > {$quoted}.tmp",
            'chmod '.$mode." {$quoted}.tmp",
            "mv {$quoted}.tmp {$quoted}",
        ]);
    }

    /** Write only if nothing is there yet — for state the server then owns. */
    public static function writeIfMissing(string $path, string $contents, string $mode = '644'): string
    {
        return 'if [ ! -e '.Shell::quote($path)." ]; then\n".self::write($path, $contents, $mode)."\nfi";
    }

    /**
     * Run a script whose text is shipped base64-encoded, so it needs no
     * quoting at all on the way through.
     */
    public static function runScript(string $script): string
    {
        return "printf '%s' '".base64_encode($script)."' | base64 -d | bash";
    }
}
