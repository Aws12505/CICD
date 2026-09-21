<?php

namespace App\Targets;

use App\Contracts\Invocation;
use App\Contracts\Target;
use RuntimeException;

/**
 * Runs the command on another machine over SSH.
 *
 * Two things cannot work the way they do locally:
 *
 *  - proc_open's `cwd` and `env` arguments apply to the local ssh client, not
 *    to the remote shell, so both are folded into the remote script instead.
 *  - The private key has to exist as a file for the duration of the run. It is
 *    written with 0600 into a private directory and removed by cleanup(),
 *    which the executor calls even when a step throws.
 */
final class SshTarget implements Target
{
    private ?string $keyPath = null;

    private ?string $knownHostsPath = null;

    /**
     * @param  string  $privateKey  PEM contents, already decrypted
     * @param  string|null  $hostKey  the server's known_hosts line. Null disables
     *                                pinning and is only legitimate for the very
     *                                first connection, when the key is captured.
     */
    public function __construct(
        private readonly string $host,
        private readonly string $user,
        private readonly int $port = 22,
        private readonly string $privateKey = '',
        private readonly ?string $hostKey = null,
        private readonly int $connectTimeout = 10,
    ) {}

    public function invoke(string $command, ?string $cwd, array $env): Invocation
    {
        return new Invocation(
            argv: [
                'ssh',
                '-i', $this->keyPath(),
                '-p', (string) $this->port,
                '-o', 'BatchMode=yes',
                '-o', 'ConnectTimeout='.$this->connectTimeout,
                ...$this->hostKeyOptions(),
                $this->user.'@'.$this->host,
                $this->remoteScript($command, $cwd, $env),
            ],
            // Both are the local ssh client's, not the remote shell's.
            cwd: null,
            env: null,
        );
    }

    public function label(): string
    {
        return $this->user.'@'.$this->host;
    }

    public function cleanup(): void
    {
        foreach ([$this->keyPath, $this->knownHostsPath] as $path) {
            if ($path !== null && is_file($path)) {
                @unlink($path);
            }
        }

        $this->keyPath = null;
        $this->knownHostsPath = null;
    }

    /**
     * Unlike the local target, a missing working directory is fatal here.
     * Silently running a deploy in the wrong directory on someone else's
     * production server is not a behaviour worth preserving for consistency.
     *
     * @param  array<string, string|int>  $env
     */
    private function remoteScript(string $command, ?string $cwd, array $env): string
    {
        $lines = [];

        if ($cwd !== null && $cwd !== '') {
            $lines[] = 'cd '.$this->quote($cwd).' || exit 1';
        }

        foreach ($env as $key => $value) {
            // A key that is not a valid shell identifier would produce a line
            // the remote shell reads as a command. Skip it rather than inject.
            if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', (string) $key)) {
                continue;
            }

            $lines[] = 'export '.$key.'='.$this->quote((string) $value);
        }

        $lines[] = $command;

        return implode("\n", $lines);
    }

    /**
     * POSIX single-quoting, done here rather than with escapeshellarg().
     *
     * escapeshellarg() quotes for the *local* platform: on Windows it emits
     * double quotes. The remote shell is always POSIX regardless of where the
     * control plane runs, and inside double quotes it would still expand `$`,
     * so a value containing $(...) would execute on the client's server.
     *
     * Single quotes suppress every expansion; the only character needing care
     * is the single quote itself, which is closed, escaped and reopened.
     */
    private function quote(string $value): string
    {
        return "'".str_replace("'", "'\\''", $value)."'";
    }

    /** @return list<string> */
    private function hostKeyOptions(): array
    {
        if ($this->hostKey === null || $this->hostKey === '') {
            // No pinned key: accept and record on first sight. Only the
            // host-key capture flow should ever construct a target this way.
            return ['-o', 'StrictHostKeyChecking=accept-new'];
        }

        return [
            '-o', 'StrictHostKeyChecking=yes',
            '-o', 'UserKnownHostsFile='.$this->knownHostsPath(),
        ];
    }

    private function keyPath(): string
    {
        return $this->keyPath ??= $this->writeTempFile('key-', $this->privateKey);
    }

    private function knownHostsPath(): string
    {
        return $this->knownHostsPath ??= $this->writeTempFile('known-hosts-', rtrim((string) $this->hostKey)."\n");
    }

    private function writeTempFile(string $prefix, string $contents): string
    {
        $directory = storage_path('app/private/ssh');

        if (! is_dir($directory) && ! @mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException("Unable to create SSH scratch directory [{$directory}].");
        }

        @chmod($directory, 0700);

        $path = $directory.'/'.$prefix.bin2hex(random_bytes(8));

        // Create empty and lock down the mode before any secret is written,
        // so there is no window where it is world-readable.
        if (file_put_contents($path, '') === false) {
            throw new RuntimeException("Unable to create SSH scratch file [{$path}].");
        }

        @chmod($path, 0600);

        if (file_put_contents($path, $contents) === false) {
            @unlink($path);

            throw new RuntimeException("Unable to write SSH scratch file [{$path}].");
        }

        return $path;
    }

    public function __destruct()
    {
        $this->cleanup();
    }
}
