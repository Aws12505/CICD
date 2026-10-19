<?php

namespace App\Services\Stack;

use App\Contracts\Target;
use App\Models\Stack;
use App\Services\Remote\CommandResult;
use App\Services\Remote\CommandRunner;
use App\Support\Shell;
use RuntimeException;

/**
 * Writes a stack's rendered files onto its server.
 */
class StackSync
{
    public function __construct(
        private readonly StackRenderer $renderer,
        private readonly CommandRunner $runner,
    ) {}

    /**
     * Push every rendered file, plus the directories the containers bind to.
     *
     * Returns the last failing result, or null when everything landed.
     */
    public function push(Stack $stack, ?Target $target = null): ?CommandResult
    {
        $target ??= $stack->server->target();
        $root = $this->renderer->rootPath($stack);

        $result = $this->runner->run($target, $this->prepareScript($stack, $root), timeout: 120);

        if ($result->failed()) {
            return $result;
        }

        foreach ($this->renderer->files($stack) as $path => $contents) {
            $result = $this->runner->run($target, $this->writeScript($root.'/'.$path, $contents), timeout: 60);

            if ($result->failed()) {
                return $result;
            }
        }

        return null;
    }

    /**
     * Link the rendered vhosts into nginx and reload it.
     *
     * Reload rather than restart: a restart drops in-flight requests on every
     * other site on the box, including the other tier.
     */
    public function reloadNginx(Stack $stack, ?Target $target = null): CommandResult
    {
        $target ??= $stack->server->target();
        $root = $this->renderer->rootPath($stack);

        // Each domain has a TLS vhost (.conf) and a plain one (.http). The TLS
        // one cannot load before its certificate exists, so the plain one
        // stands in until then.
        $script = <<<SH
        set -e
        for conf in {$root}/nginx/*.conf; do
          [ -e "\$conf" ] || continue
          domain="\$(basename "\$conf" .conf)"
          if [ -f "/etc/letsencrypt/live/\$domain/fullchain.pem" ]; then
            ln -sfn "\$conf" "/etc/nginx/sites-enabled/\$domain.conf"
          else
            ln -sfn "{$root}/nginx/\$domain.http" "/etc/nginx/sites-enabled/\$domain.conf"
          fi
        done
        # Refuse to reload a broken config: nginx keeps serving the old one,
        # but only if we never told it to load the new one.
        nginx -t
        nginx -s reload
        SH;

        return $this->runner->run($target, $script, timeout: 60);
    }

    /**
     * Directories the compose file binds into containers. Created before the
     * containers start, or Docker creates them itself as root-owned.
     */
    private function prepareScript(Stack $stack, string $root): string
    {
        $dirs = [$root, $root.'/env', $root.'/nginx', $root.'/storage'];

        foreach ($this->renderer->files($stack) as $path => $ignored) {
            if (str_starts_with($path, 'env/')) {
                $dirs[] = $root.'/storage/'.basename($path, '.env');
            }
        }

        $quoted = implode(' ', array_map(fn (string $d) => Shell::quote($d), array_unique($dirs)));

        return "set -e\nmkdir -p {$quoted}\nchmod 700 ".Shell::quote($root.'/env');
    }

    /**
     * Base64 rather than a heredoc: the contents are arbitrary, and a value
     * containing the terminator would truncate the file silently.
     */
    private function writeScript(string $path, string $contents): string
    {
        $encoded = base64_encode($contents);
        $quotedPath = Shell::quote($path);

        if (strlen($encoded) > 100_000) {
            throw new RuntimeException("Rendered file [{$path}] is too large to push in one command.");
        }

        return implode("\n", [
            'set -e',
            'mkdir -p '.Shell::quote(dirname($path)),
            // Written to a temporary file then moved, so a half-written file
            // is never visible to a container that is starting.
            "printf '%s' '{$encoded}' | base64 -d > {$quotedPath}.tmp",
            // Env files hold database passwords and service tokens.
            str_contains($path, '/env/')
                ? "chmod 600 {$quotedPath}.tmp"
                : "chmod 644 {$quotedPath}.tmp",
            "mv {$quotedPath}.tmp {$quotedPath}",
        ]);
    }
}
