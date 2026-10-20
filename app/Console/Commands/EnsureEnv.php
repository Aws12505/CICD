<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Fills in the values the control plane can generate for itself and nobody
 * should have to type: the Reverb app id, key and secret.
 *
 * `reverb:install` is meant to do this but is easy to skip or run somewhere
 * that does not write the server's .env, which leaves the browser connecting
 * to `/app/` with no key and every live log silently dead. Run at container
 * boot; only ever fills a value that is empty, so it never rotates one.
 */
class EnsureEnv extends Command
{
    protected $signature = 'control-plane:ensure-env {--env-file= : Path to the .env to fill (default: the app\'s)}';

    protected $description = 'Generate missing Reverb credentials in .env';

    public function handle(): int
    {
        $path = $this->option('env-file') ?: base_path('.env');

        if (! is_file($path) || ! is_writable($path)) {
            $this->warn("Cannot write {$path}; set the REVERB_* values by hand.");

            return self::SUCCESS;
        }

        $lines = preg_split('/\r?\n/', (string) file_get_contents($path));
        $generated = [];

        $wanted = [
            'REVERB_APP_ID' => fn () => (string) random_int(100000, 999999),
            'REVERB_APP_KEY' => fn () => bin2hex(random_bytes(10)),
            'REVERB_APP_SECRET' => fn () => bin2hex(random_bytes(20)),
        ];

        foreach ($wanted as $key => $make) {
            $found = false;

            foreach ($lines as $i => $line) {
                if (! preg_match('/^'.$key.'=(.*)$/', $line, $m)) {
                    continue;
                }

                $found = true;

                if (trim($m[1], " \t\"'") === '') {
                    $lines[$i] = $key.'='.($generated[$key] = $make());
                }

                break;
            }

            if (! $found) {
                $lines[] = $key.'='.($generated[$key] = $make());
            }
        }

        if ($generated === []) {
            $this->info('reverb-credentials: present');

            return self::SUCCESS;
        }

        file_put_contents($path, rtrim(implode("\n", $lines), "\n")."\n", LOCK_EX);

        // Names only: the values are secrets and this goes to the container log.
        $this->info('reverb-credentials: generated ('.implode(', ', array_keys($generated)).')');

        return self::SUCCESS;
    }
}
