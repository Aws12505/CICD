<?php

namespace App\Models\Concerns;

use Illuminate\Support\Str;

/**
 * Secrets a model mints for itself, once, and keeps.
 *
 * Stored in an encrypted `secrets` column. A value is generated the first
 * time it is asked for and never again, so re-running a server setup or
 * re-rendering a stack never rotates a password out from under a running
 * database.
 */
trait HasGeneratedSecrets
{
    public function secret(string $key, int $length = 40): string
    {
        $secrets = $this->secrets ?? [];

        if (! filled($secrets[$key] ?? null)) {
            // Letters and digits only: these end up in .env files, YAML,
            // connection strings and shell commands, and none of them should
            // need escaping.
            $secrets[$key] = Str::random($length);
            $this->forceFill(['secrets' => $secrets])->save();
        }

        return $secrets[$key];
    }

    /** @return list<string> every secret minted so far, for log masking. */
    public function allSecrets(): array
    {
        return array_values(array_filter($this->secrets ?? []));
    }
}
