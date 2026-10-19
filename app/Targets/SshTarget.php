<?php

namespace App\Targets;

use App\Contracts\Invocation;
use App\Contracts\Target;
use App\Support\Shell;
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
 *    which callers run even when a step throws.
 */
final class SshTarget implements Target
{
    private ?string $keyPath = null;

    private ?string $knownHostsPath = null;

    /**
     * @param  string  $privateKey  OpenSSH/PEM contents, already decrypted
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
            argv: [...$this->prefix(), $this->remoteScript($command, $cwd, $env)],
            // Both are the local ssh client's, not the remote shell's.
            cwd: null,
            env: null,
        );
    }

    public function prefix(): array
    {
        return [...$this->sshArgv(), $this->address()];
    }

    /**
     * `ssh` with this server's key and pinned host key, but no destination.
     *
     * What rsync's `-e` wants. Anything that reaches the server by some route
     * other than invoke() — rsync, a streamed dump — must use this rather than
     * a bare `ssh`, or it silently falls back to whatever key and known_hosts
     * the worker happens to have.
     *
     * @return list<string>
     */
    public function sshArgv(): array
    {
        return [
            'ssh',
            '-i', $this->keyPath(),
            '-p', (string) $this->port,
            '-o', 'BatchMode=yes',
            '-o', 'IdentitiesOnly=yes',
            '-o', 'ConnectTimeout='.$this->connectTimeout,
            ...$this->hostKeyOptions(),
        ];
    }

    public function address(): string
    {
        return $this->user.'@'.$this->host;
    }

    public function label(): string
    {
        return $this->address();
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
            $lines[] = 'cd '.Shell::quote($cwd).' || exit 1';
        }

        foreach ($env as $key => $value) {
            // A key that is not a valid shell identifier would produce a line
            // the remote shell reads as a command. Skip it rather than inject.
            if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', (string) $key)) {
                continue;
            }

            $lines[] = 'export '.$key.'='.Shell::quote((string) $value);
        }

        $lines[] = $command;

        return implode("\n", $lines);
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
        return $this->keyPath ??= $this->writeTempFile('key-', rtrim($this->privateKey)."\n");
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
