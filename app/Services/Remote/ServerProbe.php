<?php

namespace App\Services\Remote;

use App\Models\Server;
use App\Support\Shell;
use App\Targets\LocalTarget;

/**
 * Establishes and verifies that the control plane can reach a server.
 */
class ServerProbe
{
    public function __construct(private readonly CommandRunner $runner) {}

    /**
     * Record the server's host key so every later connection can pin it.
     *
     * ssh-keyscan runs on the control plane, not on the target — the whole
     * point is to learn the key before trusting a connection to it.
     *
     * This is trust-on-first-use and carries the usual caveat: if the very
     * first scan is intercepted, the wrong key gets pinned. Check the
     * fingerprint out of band for anything that matters.
     */
    public function captureHostKey(Server $server): CommandResult
    {
        $result = $this->runner->run(
            target: new LocalTarget,
            command: sprintf('ssh-keyscan -T 10 -p %d %s', $server->ssh_port, Shell::quote($server->hostname)),
            timeout: 20,
        );

        $line = collect(explode("\n", $result->stdout))
            ->map(fn (string $l) => trim($l))
            // ssh-keyscan writes its progress to stdout as # comments.
            ->first(fn (string $l) => $l !== '' && ! str_starts_with($l, '#'));

        if ($result->failed() || $line === null) {
            $server->markUnreachable($result->failureReason() ?? 'ssh-keyscan returned no host key.');

            return $result;
        }

        $server->forceFill(['host_key' => $line])->save();

        return $result;
    }

    /**
     * Confirm the server answers, and record the outcome on the row.
     *
     * Deliberately trivial — this proves the key, the host key and the
     * network path, which is everything that can be wrong before a deploy
     * has any chance of working.
     */
    public function check(Server $server): CommandResult
    {
        $result = $this->runner->run(
            target: $server->target(),
            command: 'echo "$(id -un)@$(hostname) $(uname -sr)"',
            timeout: 30,
        );

        $result->successful()
            ? $server->markOnline()
            : $server->markUnreachable($result->failureReason());

        return $result;
    }

    /**
     * What a server needs before it can host a stack.
     *
     * @return array<string, bool>
     */
    public function capabilities(Server $server): array
    {
        $probes = [
            'docker' => 'docker version --format "{{.Server.Version}}"',
            'compose' => 'docker compose version --short',
            'nginx' => 'command -v nginx',
            'rsync' => 'command -v rsync',
        ];

        $target = $server->target();
        $found = [];

        foreach ($probes as $name => $command) {
            $found[$name] = $this->runner->run($target, $command, timeout: 20)->successful();
        }

        $target->cleanup();

        return $found;
    }
}
