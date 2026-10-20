<?php

namespace App\Services\Guide;

use App\Models\Build;
use App\Models\Client;
use App\Models\Server;
use App\Models\Service;
use App\Models\Setting;
use App\Models\Stack;
use App\Models\StackService;
use App\Services\Stack\EnvResolver;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Works out, from the actual state, what to do next and where.
 *
 * The dashboard shows the first open step with one button; a client's page
 * shows that client's steps. Every step has a title, a sentence of why, and
 * the page that does it.
 */
class SetupGuide
{
    public const TODO = 'todo';

    public const DONE = 'done';

    public const BLOCKED = 'blocked';

    /** The header's "Next" chip is cached under this; see forget(). */
    public const CACHE_KEY = 'guide:next';

    /** Call after anything that moves a step. */
    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    public function __construct(private readonly EnvResolver $env) {}

    /**
     * Setting the control plane up, once.
     *
     * @return list<array<string, mixed>>
     */
    public function setup(): array
    {
        $tokens = Setting::has(Setting::GITHUB_TOKEN) && Setting::has(Setting::REGISTRY_USERNAME)
            && Setting::has(Setting::REGISTRY_PUSH_TOKEN) && Setting::has(Setting::REGISTRY_PULL_TOKEN);
        $webhook = Setting::has(Setting::GITHUB_WEBHOOK_SECRET);
        $services = Service::count();
        $servers = Server::query()->get();
        $provisioned = $servers->contains(fn (Server $s) => $s->isProvisioned());

        return [
            $this->step(
                'tokens',
                'Connect GitHub and the image registry',
                'The control plane fetches the repositories with a GitHub token and pushes images with a registry token. Client servers pull with a read-only one.',
                $tokens ? self::DONE : self::TODO,
                '/control-plane/settings',
                'Open Settings',
            ),
            $this->step(
                'webhook',
                'Let GitHub tell the control plane about merges',
                'Set a webhook secret, then add the webhook URL shown in Settings to each service repository. A merge then builds an image on its own.',
                $webhook ? self::DONE : self::TODO,
                '/control-plane/settings',
                'Open Settings',
            ),
            $this->step(
                'services',
                'Add the services',
                $services > 0
                    ? "{$services} services are in the catalog."
                    : 'One click adds the eleven services with their settings, streams and worker processes as they are in the repositories today.',
                $services > 0 ? self::DONE : self::TODO,
                '/services',
                $services > 0 ? 'Open Services' : 'Add the standard services',
                $services > 0 ? null : 'seed-services',
            ),
            $this->step(
                'server',
                'Add a server and set it up',
                $servers->isEmpty()
                    ? 'A client\'s VPS. The control plane makes a key for it; you paste one line into the VPS console.'
                    : ($provisioned ? 'At least one server is set up.' : 'A server is added but not set up yet. Open it and follow its three steps.'),
                $provisioned ? self::DONE : self::TODO,
                $servers->isEmpty() ? '/servers/create' : '/servers/'.$servers->first(fn (Server $s) => ! $s->isProvisioned())?->id,
                $servers->isEmpty() ? 'Add a server' : 'Open the server',
            ),
            $this->step(
                'client',
                'Add a client',
                'Name, domain and server. Both a testing and a live stack are created.',
                Client::count() > 0 ? self::DONE : self::TODO,
                '/clients/create',
                'Add a client',
            ),
        ];
    }

    /**
     * Getting one client live, and keeping it current.
     *
     * @return list<array<string, mixed>>
     */
    public function client(Client $client): array
    {
        $client->loadMissing('stacks.server', 'stacks.services.service');
        $testing = $client->stacks->firstWhere('tier', Stack::TIER_TESTING);
        $live = $client->stacks->firstWhere('tier', Stack::TIER_LIVE);
        $servers = $client->stacks->map(fn (Stack $s) => $s->server)->filter()->unique('id');

        $steps = [];

        $unprovisioned = $servers->first(fn (Server $s) => ! $s->isProvisioned());
        $steps[] = $this->step(
            'server',
            'Server set up',
            $unprovisioned ? "{$unprovisioned->name} has not been set up yet." : 'Docker, nginx, certbot and the shared MySQL, Redis and NATS are in place.',
            $unprovisioned ? self::TODO : self::DONE,
            $unprovisioned ? "/servers/{$unprovisioned->id}" : '/servers',
            $unprovisioned ? 'Set up the server' : 'Servers',
        );

        $enabled = $testing ? $testing->services->where('enabled', true) : collect();
        $steps[] = $this->step(
            'services',
            'Services chosen',
            $enabled->isEmpty()
                ? 'Tick the services this client gets, on the testing stack first. Auth (pizzasys) is always needed.'
                : $enabled->count().' services enabled on testing.',
            $enabled->isEmpty() ? self::TODO : self::DONE,
            "/clients/{$client->slug}",
            'Choose services',
        );

        $missing = $enabled->flatMap(fn (StackService $s) => collect($this->env->missingRequired($s->load('service.envDefinitions', 'stack.server', 'stack.client')))
            ->map(fn ($k) => $s->service->name.': '.$k));
        $steps[] = $this->step(
            'env',
            'Settings filled in',
            $enabled->isEmpty()
                ? 'Vendor credentials and anything the services need that the control plane cannot generate.'
                : ($missing->isEmpty() ? 'Every required value is set.' : 'Missing: '.$missing->take(4)->implode(', ').($missing->count() > 4 ? ', …' : '')),
            $enabled->isEmpty() ? self::BLOCKED : ($missing->isEmpty() ? self::DONE : self::TODO),
            "/clients/{$client->slug}/env",
            'Open settings',
        );

        $unbuilt = $enabled->filter(fn (StackService $s) => ! $this->hasBuild($s))->map(fn (StackService $s) => $s->service->name);
        $steps[] = $this->step(
            'builds',
            'Images built',
            $enabled->isEmpty()
                ? 'Every service needs at least one build before it can be deployed.'
                : ($unbuilt->isEmpty() ? 'Every enabled service has a build.' : 'No build yet for '.$unbuilt->implode(', ').'.'),
            $enabled->isEmpty() ? self::BLOCKED : ($unbuilt->isEmpty() ? self::DONE : self::TODO),
            '/builds',
            'Build now',
        );

        $undeployed = $enabled->filter(fn (StackService $s) => blank($s->current_digest));
        $ready = $enabled->isNotEmpty() && $missing->isEmpty() && $unbuilt->isEmpty() && $unprovisioned === null;
        $steps[] = $this->step(
            'testing',
            'Testing deployed',
            $undeployed->isEmpty() && $enabled->isNotEmpty()
                ? 'Everything enabled is running on testing.'
                : 'The first deploy creates databases, registers services with Auth, creates the first admin and issues certificates.',
            $enabled->isEmpty() ? self::BLOCKED : ($undeployed->isEmpty() ? self::DONE : ($ready ? self::TODO : self::BLOCKED)),
            $testing ? "/stacks/{$testing->id}/plan" : "/clients/{$client->slug}",
            'Deploy testing',
        );

        $liveEnabled = $live ? $live->services->where('enabled', true) : collect();
        $liveUndeployed = $liveEnabled->filter(fn (StackService $s) => blank($s->current_digest));
        $testingDone = $enabled->isNotEmpty() && $undeployed->isEmpty();
        $steps[] = $this->step(
            'live',
            'Promoted to live',
            $liveEnabled->isEmpty()
                ? 'Enable the same services on live, then promote exactly what testing runs.'
                : ($liveUndeployed->isEmpty() ? 'Live runs what testing proved.' : 'Live is waiting for its first promotion.'),
            ! $testingDone ? self::BLOCKED : ($liveEnabled->isNotEmpty() && $liveUndeployed->isEmpty() ? self::DONE : self::TODO),
            $liveEnabled->isEmpty() ? "/clients/{$client->slug}" : ($live ? "/stacks/{$live->id}/plan" : "/clients/{$client->slug}"),
            $liveEnabled->isEmpty() ? 'Enable services on live' : 'Promote to live',
        );

        return $steps;
    }

    /**
     * The one thing to do next, across everything.
     *
     * @return array<string, mixed>|null null when all is well
     */
    public function next(): ?array
    {
        foreach ($this->setup() as $step) {
            if ($step['status'] === self::TODO) {
                return $step + ['scope' => 'Setup'];
            }
        }

        foreach (Client::query()->orderBy('name')->get() as $client) {
            foreach ($this->client($client) as $step) {
                if ($step['status'] === self::TODO) {
                    return $step + ['scope' => $client->name];
                }
            }
        }

        return null;
    }

    /**
     * Each client with its progress and next step, for the dashboard.
     *
     * @return list<array<string, mixed>>
     */
    public function clients(): array
    {
        return Client::query()->orderBy('name')->get()->map(function (Client $client) {
            $steps = $this->client($client);
            $next = collect($steps)->first(fn ($s) => $s['status'] === self::TODO);

            return [
                'id' => $client->id,
                'name' => $client->name,
                'slug' => $client->slug,
                'domain' => $client->domain,
                'done' => collect($steps)->where('status', self::DONE)->count(),
                'total' => count($steps),
                'next' => $next,
                'behind' => $this->behind($client),
            ];
        })->all();
    }

    /** Services on live with a newer build than the one running. */
    private function behind(Client $client): int
    {
        $live = $client->stacks->firstWhere('tier', Stack::TIER_LIVE);

        if ($live === null) {
            return 0;
        }

        return $live->services->where('enabled', true)->filter(function (StackService $s) {
            $newest = $this->candidates($s)->first();

            return $newest !== null && $s->current_digest !== null && $newest->digest !== $s->current_digest;
        })->count();
    }

    private function hasBuild(StackService $entitlement): bool
    {
        return $this->candidates($entitlement)->isNotEmpty();
    }

    /** @return Collection<int, Build> */
    private function candidates(StackService $entitlement): Collection
    {
        return Build::query()
            ->where('service_id', $entitlement->service_id)
            ->where('edition', $entitlement->edition ?: null)
            ->where('status', 'success')
            ->whereNotNull('digest')
            ->where(fn ($q) => $q->whereNull('stack_id')->orWhere('stack_id', $entitlement->stack_id))
            ->orderByDesc('built_at')
            ->limit(1)
            ->get();
    }

    /**
     * @return array<string, mixed>
     */
    private function step(string $key, string $title, string $detail, string $status, string $href, string $cta, ?string $action = null): array
    {
        return compact('key', 'title', 'detail', 'status', 'href', 'cta', 'action');
    }
}
