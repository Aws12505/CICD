<?php

namespace App\Services\System;

use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Is the control plane itself healthy: the queue that runs every long action,
 * the scheduler that samples metrics and backs up, and Reverb, which streams
 * every log. Each check says what is wrong and the exact way to fix it,
 * because "queued forever" and "websocket failed" explain nothing.
 */
class SystemCheck
{
    public const OK = 'ok';

    public const WARN = 'warn';

    public const FAIL = 'fail';

    /** Written every minute by the scheduler; read here. */
    public const SCHEDULER_KEY = 'scheduler:heartbeat';

    /** A job waiting longer than this means no worker is taking jobs. */
    public const QUEUE_STALE_SECONDS = 90;

    public const SCHEDULER_STALE_SECONDS = 300;

    /**
     * Everything that needs no network: cheap enough for every dashboard load.
     *
     * @return list<array{key: string, title: string, status: string, detail: string, fix: ?string}>
     */
    public function quick(): array
    {
        return [
            $this->queue(),
            $this->scheduler(),
            $this->reverbCredentials(),
            $this->reverbHost(),
        ];
    }

    /**
     * The quick checks plus a real event published to Reverb, which proves the
     * credentials, the host, nginx routing of /apps and the Reverb process at
     * once.
     *
     * @return list<array{key: string, title: string, status: string, detail: string, fix: ?string}>
     */
    public function full(): array
    {
        return [...$this->quick(), $this->reverbPublish()];
    }

    /** @param  list<array{status: string}>  $checks */
    public static function problems(array $checks): array
    {
        return array_values(array_filter($checks, fn (array $c) => $c['status'] !== self::OK));
    }

    /** @return array{key: string, title: string, status: string, detail: string, fix: ?string} */
    public function queue(): array
    {
        $now = time();
        $waiting = DB::table('jobs')->whereNull('reserved_at')->where('available_at', '<=', $now);
        $count = (clone $waiting)->count();
        $oldest = $count > 0 ? (int) (clone $waiting)->min('available_at') : null;
        $failed = DB::table('failed_jobs')->where('failed_at', '>=', now()->subDay())->count();

        if ($oldest !== null && $now - $oldest > self::QUEUE_STALE_SECONDS) {
            return $this->check('queue', 'Queue worker', self::FAIL,
                "{$count} job(s) have been waiting for ".$this->age($now - $oldest).'. Nothing is taking jobs, so server setups, builds and releases stay "queued".',
                'docker exec CICD_Worker supervisorctl status'."\n".'docker exec CICD_Worker tail -n 40 /var/log/supervisor/worker.log');
        }

        if ($failed > 0) {
            return $this->check('queue', 'Queue worker', self::WARN,
                "{$failed} job(s) failed in the last day. The worker is running, but something it ran crashed.",
                'docker exec CICD php artisan queue:failed');
        }

        return $this->check('queue', 'Queue worker', self::OK, $count > 0 ? "{$count} job(s) waiting, being picked up." : 'Taking jobs; none waiting.');
    }

    /** @return array{key: string, title: string, status: string, detail: string, fix: ?string} */
    public function scheduler(): array
    {
        $beat = Cache::get(self::SCHEDULER_KEY);

        if ($beat === null) {
            return $this->check('scheduler', 'Scheduler', self::WARN,
                'No heartbeat yet. Normal for the first minute after a start; if it stays like this, the scheduler is not running, and so no server metrics or backups are being taken.',
                'docker exec CICD_Worker supervisorctl status laravel-scheduler');
        }

        $age = time() - (int) $beat;

        return $age > self::SCHEDULER_STALE_SECONDS
            ? $this->check('scheduler', 'Scheduler', self::FAIL,
                'Last ran '.$this->age($age).' ago. Metrics, backups and drift checks have stopped.',
                'docker exec CICD_Worker supervisorctl restart laravel-scheduler')
            : $this->check('scheduler', 'Scheduler', self::OK, 'Ran '.$this->age($age).' ago.');
    }

    /** @return array{key: string, title: string, status: string, detail: string, fix: ?string} */
    public function reverbCredentials(): array
    {
        $connection = config('broadcasting.connections.reverb', []);
        $missing = collect(['app_id' => 'REVERB_APP_ID', 'key' => 'REVERB_APP_KEY', 'secret' => 'REVERB_APP_SECRET'])
            ->filter(fn (string $env, string $key) => blank($connection[$key] ?? null))
            ->values();

        return $missing->isNotEmpty()
            ? $this->check('reverb-credentials', 'Live logs: credentials', self::FAIL,
                'Not set in .env: '.$missing->implode(', ').'. The browser then connects to /app/ with no key and every live log stays empty.',
                'docker compose up -d --build'."\n".'# the boot script generates them, rebuilds the frontend and restarts everything')
            : $this->check('reverb-credentials', 'Live logs: credentials', self::OK, 'App id, key and secret are set.');
    }

    /** @return array{key: string, title: string, status: string, detail: string, fix: ?string} */
    public function reverbHost(): array
    {
        $host = (string) config('broadcasting.connections.reverb.options.host');
        $app = (string) parse_url((string) config('app.url'), PHP_URL_HOST);

        if (blank($host)) {
            return $this->check('reverb-host', 'Live logs: address', self::FAIL, 'REVERB_HOST is empty.', 'Set REVERB_HOST to the address people open the control plane at, then rebuild the frontend.');
        }

        return $app !== '' && $host !== $app
            ? $this->check('reverb-host', 'Live logs: address', self::WARN,
                "REVERB_HOST is {$host} but the app is served at {$app}. Browsers connect to one and the server publishes to the other.",
                "# in .env set both to the same name:\nREVERB_HOST=\"{$app}\"\n# then: docker exec CICD sh -c 'npm run build' && docker compose restart")
            : $this->check('reverb-host', 'Live logs: address', self::OK, "Browsers and the server both use {$host}.");
    }

    /** @return array{key: string, title: string, status: string, detail: string, fix: ?string} */
    public function reverbPublish(): array
    {
        $error = $this->publish();

        return $error === null
            ? $this->check('reverb-publish', 'Live logs: end to end', self::OK, 'An event was published to Reverb and accepted.')
            : $this->check('reverb-publish', 'Live logs: end to end', self::FAIL,
                'Publishing to Reverb failed: '.$error,
                "docker exec CICD_Worker supervisorctl status laravel-reverb\n"
                ."docker exec CICD_Worker tail -n 40 /var/log/supervisor/reverb.log\n"
                .'# and in the nginx site, /app AND /apps must proxy to 127.0.0.1:42070 (see nginx/control-plane.conf.example)');
    }

    /** The error from publishing a test event, or null when it worked. */
    protected function publish(): ?string
    {
        try {
            config(['broadcasting.connections.reverb.client_options.timeout' => 5]);
            Broadcast::purge('reverb');
            Broadcast::connection('reverb')->broadcast(['system-check'], 'system.ping', ['at' => time()]);

            return null;
        } catch (Throwable $e) {
            return trim(strtok($e->getMessage(), "\n") ?: $e::class);
        }
    }

    /** @return array{key: string, title: string, status: string, detail: string, fix: ?string} */
    private function check(string $key, string $title, string $status, string $detail, ?string $fix = null): array
    {
        return compact('key', 'title', 'status', 'detail', 'fix');
    }

    private function age(int $seconds): string
    {
        return match (true) {
            $seconds < 120 => "{$seconds} seconds",
            $seconds < 7200 => intdiv($seconds, 60).' minutes',
            default => intdiv($seconds, 3600).' hours',
        };
    }
}
