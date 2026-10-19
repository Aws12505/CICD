<?php

namespace App\Services\Release;

use App\Models\Build;
use App\Models\Client;
use App\Models\Service;
use App\Models\Stack;
use App\Models\StackService;

/**
 * What every client runs, per service and tier (decision 19).
 *
 * The control plane is the source of truth for this; `main` is only a
 * high-water mark. Each cell shows the build a stack runs and how many newer
 * builds exist for that client's edition of the service.
 */
class VersionsBoard
{
    public function __construct(private readonly ReleasePlanner $planner) {}

    /**
     * @return array{services: list<array<string, mixed>>, clients: list<array<string, mixed>>}
     */
    public function data(): array
    {
        $services = Service::query()->orderByDesc('identity_provider')->orderBy('position')->orderBy('name')->get();

        $clients = Client::query()
            ->with(['stacks.services.service', 'stacks.server'])
            ->orderBy('name')
            ->get();

        return [
            'services' => $services->map(fn (Service $s) => [
                'id' => $s->id,
                'name' => $s->name,
                'slug' => $s->slug,
            ])->all(),
            'clients' => $clients->map(fn (Client $client) => $this->client($client, $services))->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function cell(StackService $entitlement): array
    {
        $current = $this->planner->buildByDigest($entitlement->current_digest);
        $candidates = $this->planner->candidates($entitlement, 100);
        $newest = $candidates->first();

        $behind = $current === null
            ? ($entitlement->current_digest ? null : $candidates->unique('number')->count())
            : $candidates
                ->filter(fn (Build $b) => ($b->built_at ?? $b->created_at) > ($current->built_at ?? $current->created_at))
                ->unique('number')
                ->count();

        return [
            'enabled' => $entitlement->enabled,
            'edition' => $entitlement->edition,
            'state' => $entitlement->state,
            'deployed' => filled($entitlement->current_digest),
            'build' => $current ? $this->buildSummary($current) : null,
            'newest' => $newest ? $this->buildSummary($newest) : null,
            'behind' => $behind,
            'drifted' => $entitlement->hasDrifted(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function buildSummary(Build $build): array
    {
        return [
            'id' => $build->id,
            'number' => $build->number,
            'label' => $build->label(),
            'sha' => $build->shortSha(),
            'edition' => $build->edition,
            'migration_kind' => $build->migration_kind,
            'built_at' => $build->built_at?->toIso8601String(),
            'changelog' => $build->changelog ?? [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function client(Client $client, $services): array
    {
        $stacks = $client->stacks->keyBy('tier');
        $cells = [];

        foreach ($services as $service) {
            foreach ([Stack::TIER_TESTING, Stack::TIER_LIVE] as $tier) {
                $entitlement = $stacks->get($tier)?->services->firstWhere('service_id', $service->id);

                $cells[$service->id][$tier] = $entitlement && $entitlement->enabled
                    ? $this->cell($entitlement)
                    : null;
            }
        }

        return [
            'id' => $client->id,
            'name' => $client->name,
            'slug' => $client->slug,
            'status' => $client->status,
            'pinned' => (bool) $stacks->get(Stack::TIER_LIVE)?->pinned,
            'stacks' => $stacks->map(fn (Stack $s) => [
                'id' => $s->id,
                'tier' => $s->tier,
                'status' => $s->status,
                'server_ready' => (bool) $s->server?->isProvisioned(),
            ])->values()->all(),
            'cells' => $cells,
        ];
    }
}
