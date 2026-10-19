<?php

namespace App\Services\Provision;

use App\Contracts\Target;
use App\Models\Service;
use App\Models\Stack;
use App\Models\StackService;
use App\Services\Remote\CommandRunner;
use App\Support\NatsTopology;
use App\Support\Shell;
use Closure;
use Illuminate\Support\Collection;

/**
 * Gives one stack its own corner of the shared MySQL, Redis and NATS.
 *
 * Run when a server is set up and again before every release, so a service
 * enabled since then gets its database, grant and streams. Every statement is
 * idempotent; running it twice changes nothing.
 *
 * Isolation between the two tiers on one server is by credentials, not by
 * separate instances (decision 8):
 *
 *  - MySQL: a user per stack, granted only that stack's databases;
 *  - Redis: a user per stack, restricted to keys under its own prefix, and
 *    denied FLUSHALL and FLUSHDB — Redis ACLs cannot restrict a database index,
 *    so without the key pattern a testing user could SELECT the live index;
 *  - NATS: the testing tier uses the *_TESTING_* stream twins the services
 *    already switch to when DEV_MODE=1.
 */
class StackProvisioner
{
    public const NATS_BOX_IMAGE = 'natsio/nats-box:0.14.5';

    public function __construct(private readonly CommandRunner $runner) {}

    /**
     * @param  Closure(string):void  $log
     * @return string|null the reason it failed, or null
     */
    public function prepare(Stack $stack, Target $target, Closure $log): ?string
    {
        $stack->loadMissing('server', 'client');
        $services = $this->enabledServices($stack);

        // The stack's compose project doubles as its MySQL and Redis user,
        // and is interpolated into SQL and ACL rules below.
        if (! preg_match('/^[a-z0-9][a-z0-9_-]{0,31}$/', $stack->dataUser())) {
            return "The stack name \"{$stack->dataUser()}\" cannot be a database user: use up to 32 lowercase letters, digits, - and _.";
        }

        $steps = [
            'MySQL user and databases' => fn () => $this->mysql($stack, $services),
            'Redis user' => fn () => $this->redis($stack),
            'NATS streams and consumers' => fn () => $this->nats($stack, $services),
        ];

        foreach ($steps as $label => $build) {
            $log("\n--- {$stack->label()}: {$label}\n");

            $result = $this->runner->run($target, $build(), timeout: 300, onOutput: $log);

            if ($result->failed()) {
                return "{$label}: ".$result->failureReason();
            }
        }

        return null;
    }

    /** Every database a stack needs, tier-suffixed. */
    public function databases(Stack $stack, ?Collection $services = null): array
    {
        $services ??= $this->enabledServices($stack);
        $databases = [];

        foreach ($services as $service) {
            foreach ($this->databaseNames($service) as $name) {
                $databases[] = $name.($stack->isLive() ? '' : '_testing');
            }
        }

        return array_values(array_unique($databases));
    }

    /**
     * @param  Collection<int, Service>  $services
     */
    public function mysql(Stack $stack, Collection $services): string
    {
        $user = $stack->dataUser();
        $password = $stack->secret('mysql_password');

        $sql = [
            "CREATE USER IF NOT EXISTS '{$user}'@'%' IDENTIFIED BY '{$password}';",
            // Re-asserted each time, so the stored password is always the one
            // MySQL has.
            "ALTER USER '{$user}'@'%' IDENTIFIED BY '{$password}';",
        ];

        foreach ($this->databases($stack, $services) as $database) {
            $sql[] = "CREATE DATABASE IF NOT EXISTS `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;";
            $sql[] = "GRANT ALL PRIVILEGES ON `{$database}`.* TO '{$user}'@'%';";
        }

        $sql[] = 'FLUSH PRIVILEGES;';

        // SQL goes in on stdin and the root password stays inside the
        // container's environment, so neither appears on a command line.
        return "printf '%s' '".base64_encode(implode("\n", $sql)."\n")."' | base64 -d"
            .' | docker exec -i mysql sh -c '.Shell::quote('exec mysql -uroot -p"$MYSQL_ROOT_PASSWORD"');
    }

    public function redis(Stack $stack): string
    {
        $user = $stack->dataUser();
        $prefix = $stack->redisPrefix();
        $password = $stack->secret('redis_password');

        $acl = "ACL SETUSER {$user} reset on >{$password} ~{$prefix}* &* +@all"
            .' -flushall -flushdb -config -shutdown -debug -monitor -acl -save -bgsave -bgrewriteaof -replicaof -slaveof -failover -module';

        $commands = $acl."\nACL SAVE\n";

        return "printf '%s' '".base64_encode($commands)."' | base64 -d"
            .' | docker exec -i redis sh -c '.Shell::quote('REDISCLI_AUTH="$REDIS_ROOT_PASSWORD" exec redis-cli --user default');
    }

    /**
     * @param  Collection<int, Service>  $services
     */
    public function nats(Stack $stack, Collection $services): string
    {
        $plan = NatsTopology::plan($services, $stack->tier);

        $lines = ['set -e'];

        foreach ($plan['streams'] as $stream => $subject) {
            $lines[] = sprintf(
                'nats stream info %1$s >/dev/null 2>&1 && echo "stream %1$s exists" || { nats stream add %1$s --subjects %2$s --storage file --retention limits --discard old --max-msgs=-1 --max-bytes=-1 --max-age=0 --max-msg-size=-1 --dupe-window 2m --replicas 1 --defaults >/dev/null && echo "created stream %1$s"; }',
                $stream,
                Shell::quote($subject),
            );
        }

        foreach ($plan['consumers'] as $consumer) {
            // --deliver all, always. Stores, users and employees reach the
            // other services only as replayed events: a consumer created with
            // deliver-new leaves their tables empty, with no error anywhere.
            $lines[] = sprintf(
                'nats consumer info %1$s %2$s >/dev/null 2>&1 && echo "consumer %2$s exists" || { nats consumer add %1$s %2$s --pull --deliver all --ack explicit --filter %3$s --max-deliver=-1 --replay instant --defaults >/dev/null && echo "created consumer %2$s"; }',
                $consumer['stream'],
                $consumer['durable'],
                Shell::quote($plan['streams'][$consumer['stream']]),
            );
        }

        if (count($lines) === 1) {
            return 'echo "no NATS streams needed"';
        }

        $script = implode("\n", $lines)."\n";

        return 'docker run --rm -i --network pizza_shared'
            .' -e NATS_URL='.Shell::quote('nats://'.$stack->server->secret('nats_token').'@nats:4222')
            .' '.self::NATS_BOX_IMAGE
            .' sh -c '.Shell::quote("printf '%s' '".base64_encode($script)."' | base64 -d | sh");
    }

    /**
     * @return Collection<int, Service>
     */
    private function enabledServices(Stack $stack): Collection
    {
        return StackService::query()
            ->with('service')
            ->where('stack_id', $stack->id)
            ->where('enabled', true)
            ->get()
            ->map(fn (StackService $s) => $s->service)
            ->values();
    }

    /** @return list<string> */
    private function databaseNames(Service $service): array
    {
        return array_values(array_filter([
            $service->database_name,
            ...($service->extra_databases ?? []),
        ]));
    }
}
