<?php

namespace App\Services\Stack;

use App\Models\ClientSetting;
use App\Models\Service;
use App\Models\ServiceEnvDefinition;
use App\Models\Stack;
use App\Models\StackEnvValue;
use App\Models\StackService;
use Illuminate\Support\Collection;

/**
 * Works out the full environment one service gets on one stack.
 *
 * Most values are derived here rather than typed by anyone: database names
 * and credentials, sibling service URLs, NATS and Redis settings, and every
 * secret nobody should have to invent (APP_KEY, service tokens). Asking a
 * human to keep those consistent across twelve services and two tiers is how
 * the current estate ended up with three spellings of the auth verify path.
 *
 * Layers, lowest first; a later one wins:
 *
 *   1. the service's definition (derived, generated or its default)
 *   2. the client, every service      (client_settings, service_id null)
 *   3. the client, this service
 *   4. the stack, every service       (stack_env_values, service_id null)
 *   5. the stack, this service
 */
class EnvResolver
{
    /** The only verify route pizzasys has. Services' own defaults are wrong. */
    public const AUTH_VERIFY_PATH = '/api/v1/auth/token-verify';

    /**
     * @return array<string, string>
     */
    public function resolve(StackService $entitlement): array
    {
        $service = $entitlement->service;
        $stack = $entitlement->stack;

        $resolved = [];

        foreach ($service->envDefinitions as $definition) {
            $value = $this->valueFor($definition, $stack, $service);

            if ($value !== null) {
                $resolved[$definition->key] = $value;
            }
        }

        foreach ($this->overridesFor($stack, $service) as $key => $value) {
            $resolved[$key] = $value;
        }

        return $resolved;
    }

    /**
     * Where each resolved value came from, for the environment screen.
     *
     * @return array<string, string> key => definition|client|stack
     */
    public function origins(StackService $entitlement): array
    {
        $origins = [];

        foreach ($entitlement->service->envDefinitions as $definition) {
            $origins[$definition->key] = 'definition';
        }

        foreach ($this->clientRows($entitlement->stack, $entitlement->service) as $row) {
            $origins[$row->key] = 'client';
        }

        foreach ($this->stackRows($entitlement->stack, $entitlement->service) as $row) {
            $origins[$row->key] = 'stack';
        }

        return $origins;
    }

    /**
     * Required keys this stack cannot supply.
     *
     * This is the check that runs before anything swaps. Returning a key here
     * stops the deploy: the alternative is a container that boots, falls back
     * to a hardcoded default, and quietly talks to the wrong backend.
     *
     * @return list<string>
     */
    public function missingRequired(StackService $entitlement): array
    {
        $resolved = $this->resolve($entitlement);

        return $entitlement->service->envDefinitions
            ->filter(fn (ServiceEnvDefinition $d) => $d->required)
            ->filter(fn (ServiceEnvDefinition $d) => blank($resolved[$d->key] ?? null))
            ->pluck('key')
            ->values()
            ->all();
    }

    /**
     * Build arguments have to be baked in, so a change to one of these means
     * a rebuild rather than a restart.
     *
     * @return array<string, string>
     */
    public function buildArgs(StackService $entitlement): array
    {
        $resolved = $this->resolve($entitlement);

        return $entitlement->service->envDefinitions
            ->filter(fn (ServiceEnvDefinition $d) => $d->is_build_arg)
            ->mapWithKeys(fn (ServiceEnvDefinition $d) => [$d->key => $resolved[$d->key] ?? ''])
            ->all();
    }

    /**
     * The token a service presents to pizzasys. The same value is registered
     * on pizzasys's side as that service's client, so both ends come from
     * this one call.
     */
    public function serviceToken(Stack $stack, Service $service): string
    {
        return $stack->secret('service_token:'.$service->slug, 64);
    }

    /**
     * Every value worth masking in a log for this stack: generated secrets
     * and anything typed in as secret.
     *
     * @return list<string>
     */
    public function secretsFor(Stack $stack): array
    {
        $typed = [
            ...$this->clientRows($stack)->where('is_secret', true)->pluck('value'),
            ...$this->stackRows($stack)->where('is_secret', true)->pluck('value'),
        ];

        return array_values(array_filter([
            ...$stack->fresh()->allSecrets(),
            ...$stack->server?->allSecrets() ?? [],
            ...$typed,
        ]));
    }

    private function valueFor(ServiceEnvDefinition $definition, Stack $stack, Service $service): ?string
    {
        return match ($definition->source) {
            ServiceEnvDefinition::SOURCE_STACK => $this->fromStack($definition, $stack, $service),
            ServiceEnvDefinition::SOURCE_SERVICE_URL => $this->fromSiblingService($definition, $stack),
            ServiceEnvDefinition::SOURCE_GENERATED => $this->generated($definition, $stack, $service),
            default => $definition->default_value,
        };
    }

    /**
     * Derived from the stack itself. `default_value` names which property.
     */
    private function fromStack(ServiceEnvDefinition $definition, Stack $stack, Service $service): ?string
    {
        $property = (string) $definition->default_value;

        // "database:hiring_analytics" — one of a service's extra databases,
        // tier-suffixed the same way as the main one.
        if (str_starts_with($property, 'database:')) {
            return substr($property, 9).($stack->isLive() ? '' : '_testing');
        }

        // "container:reverb" — the name of one of this service's process
        // containers, for the service to reach it on the Docker network.
        if (str_starts_with($property, 'container:')) {
            return $stack->compose_project.'_'.$service->slug.'_'.substr($property, 10);
        }

        return match ($property) {
            'database' => $stack->databaseFor($service),
            'domain' => $stack->domainFor($service),
            'app_url' => 'https://'.$stack->domainFor($service),
            'compose_project' => $stack->compose_project,
            'tier' => $stack->tier,
            'app_env' => $stack->isLive() ? 'production' : 'staging',
            // The existing services select their NATS streams with this.
            'dev_mode' => $stack->isLive() ? '0' : '1',
            'storage_path' => rtrim(config('deploy.root'), '/')."/{$stack->compose_project}/storage/{$service->slug}",

            // The shared MySQL, through this stack's own user.
            'db_host' => 'mysql',
            'db_port' => '3306',
            'db_username' => $stack->dataUser(),
            'db_password' => $stack->secret('mysql_password'),

            // The shared Redis, confined by ACL to this stack's prefix.
            'redis_host' => 'redis',
            'redis_port' => '6379',
            'redis_index' => (string) $stack->redisDatabase(),
            'redis_username' => $stack->dataUser(),
            'redis_password' => $stack->secret('redis_password'),
            'redis_prefix' => $stack->redisPrefix(),

            // The shared NATS. The tier is chosen by the stream names
            // (dev_mode), not by a separate server.
            'nats_host' => 'nats',
            'nats_port' => '4222',
            'nats_token' => $stack->server->secret('nats_token'),
            'nats_url' => 'nats://'.$stack->server->secret('nats_token').'@nats:4222',

            // pizzasys, the identity provider every service checks tokens with.
            'auth_service_name' => $service->auth_service_name,
            'auth_verify_path' => self::AUTH_VERIFY_PATH,
            default => null,
        };
    }

    /**
     * Minted once per stack and kept. `default_value` names the shape:
     *
     *   app_key               Laravel APP_KEY, per service
     *   random | random:64    letters and digits, per service and key
     *   hex:32                hex characters, per service and key
     *   service_token         this service's token for pizzasys
     *   service_token:hiring  another service's token, e.g. on pizzasys
     *   shared:reverb_key     one value shared by every service that names it
     */
    private function generated(ServiceEnvDefinition $definition, Stack $stack, Service $service): ?string
    {
        [$kind, $argument] = array_pad(explode(':', (string) $definition->default_value, 2), 2, null);
        $own = "generated:{$service->slug}:{$definition->key}";

        return match ($kind) {
            'app_key' => $stack->secretUsing($own, fn () => 'base64:'.base64_encode(random_bytes(32))),
            'random', '' => $stack->secret($own, (int) ($argument ?: 40)),
            'hex' => $stack->secretUsing($own, fn () => bin2hex(random_bytes(intdiv((int) ($argument ?: 32), 2)))),
            'service_token' => $this->serviceToken(
                $stack,
                $argument ? (Service::query()->where('slug', $argument)->first() ?? $service) : $service,
            ),
            'shared' => $argument ? $stack->secret('shared:'.$argument) : null,
            default => null,
        };
    }

    /**
     * Another service's public URL on this same stack. `default_value` holds
     * the target service's slug, optionally with a path: "auth:/api/v1".
     */
    private function fromSiblingService(ServiceEnvDefinition $definition, Stack $stack): ?string
    {
        [$slug, $path] = array_pad(explode(':', (string) $definition->default_value, 2), 2, '');

        // "notifications@host" — the bare hostname, for websocket clients.
        $hostOnly = str_ends_with($slug, '@host');
        $slug = $hostOnly ? substr($slug, 0, -5) : $slug;

        $target = Service::query()->where('slug', $slug)->first();

        if ($target === null) {
            return null;
        }

        return $hostOnly ? $stack->domainFor($target) : 'https://'.$stack->domainFor($target).$path;
    }

    /**
     * @return array<string, string>
     */
    private function overridesFor(Stack $stack, Service $service): array
    {
        $values = [];

        foreach ([...$this->clientRows($stack, $service), ...$this->stackRows($stack, $service)] as $row) {
            $values[$row->key] = (string) $row->value;
        }

        return $values;
    }

    /**
     * Client rows for this stack's client, every-service rows first so a
     * service's own row overwrites them.
     *
     * @return Collection<int, ClientSetting>
     */
    private function clientRows(Stack $stack, ?Service $service = null)
    {
        return ClientSetting::query()
            ->where('client_id', $stack->client_id)
            ->when($service, fn ($q) => $q->where(fn ($q) => $q->whereNull('service_id')->orWhere('service_id', $service->id)))
            ->orderByRaw('service_id is null desc')
            ->get();
    }

    /**
     * @return Collection<int, StackEnvValue>
     */
    private function stackRows(Stack $stack, ?Service $service = null)
    {
        return StackEnvValue::query()
            ->where('stack_id', $stack->id)
            ->when($service, fn ($q) => $q->where(fn ($q) => $q->whereNull('service_id')->orWhere('service_id', $service->id)))
            ->orderByRaw('service_id is null desc')
            ->get();
    }

    /** Stack properties that are credentials, and must never be displayed. */
    private const SECRET_STACK_PROPERTIES = ['db_password', 'redis_password', 'nats_token', 'nats_url'];

    /** Whether a definition's value is a credential, wherever it comes from. */
    public static function isSecretDefinition(ServiceEnvDefinition $definition): bool
    {
        return $definition->isSecret()
            || ($definition->source === ServiceEnvDefinition::SOURCE_STACK
                && in_array($definition->default_value, self::SECRET_STACK_PROPERTIES, true));
    }
}
