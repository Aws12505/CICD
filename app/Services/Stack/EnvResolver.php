<?php

namespace App\Services\Stack;

use App\Models\Service;
use App\Models\ServiceEnvDefinition;
use App\Models\Stack;
use App\Models\StackEnvValue;
use App\Models\StackService;

/**
 * Works out the full environment one service gets on one stack.
 *
 * Four of the five sources are derived here rather than typed by anyone —
 * database names, sibling service URLs and NATS stream names are all a
 * function of the stack, and asking a human to keep them consistent across
 * twelve services and two tiers is how the current estate ended up with three
 * different spellings of the auth verify path.
 */
class EnvResolver
{
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

        // Stack-wide values apply to every service, and an explicit
        // service-scoped value wins over both the stack-wide one and anything
        // derived above.
        foreach ($this->overridesFor($stack, $service) as $key => $value) {
            $resolved[$key] = $value;
        }

        return $resolved;
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

    private function valueFor(ServiceEnvDefinition $definition, Stack $stack, Service $service): ?string
    {
        return match ($definition->source) {
            ServiceEnvDefinition::SOURCE_STACK => $this->fromStack($definition, $stack, $service),
            ServiceEnvDefinition::SOURCE_SERVICE_URL => $this->fromSiblingService($definition, $stack),
            default => $definition->default_value,
        };
    }

    /**
     * Derived from the stack itself. `default_value` names which property.
     */
    private function fromStack(ServiceEnvDefinition $definition, Stack $stack, Service $service): ?string
    {
        return match ($definition->default_value) {
            'database' => $stack->databaseFor($service),
            'redis_index' => (string) $stack->redisDatabase(),
            'domain' => $stack->domainFor($service),
            'app_url' => 'https://'.$stack->domainFor($service),
            'compose_project' => $stack->compose_project,
            'tier' => $stack->tier,
            // The existing services select their NATS streams with this.
            'dev_mode' => $stack->isLive() ? '0' : '1',
            'storage_path' => rtrim(config('deploy.root'), '/')."/{$stack->compose_project}/storage/{$service->slug}",
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

        $target = Service::query()->where('slug', $slug)->first();

        if ($target === null) {
            return null;
        }

        return 'https://'.$stack->domainFor($target).$path;
    }

    /**
     * @return array<string, string>
     */
    private function overridesFor(Stack $stack, Service $service): array
    {
        return StackEnvValue::query()
            ->where('stack_id', $stack->id)
            ->where(fn ($q) => $q->whereNull('service_id')->orWhere('service_id', $service->id))
            // Stack-wide rows first so service-scoped ones overwrite them.
            ->orderByRaw('service_id is null desc')
            ->get()
            ->mapWithKeys(fn (StackEnvValue $row) => [$row->key => (string) $row->value])
            ->all();
    }
}
