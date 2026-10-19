<?php

namespace App\Services\Release;

use App\Models\Build;
use App\Models\Release;
use App\Models\ReleaseItem;
use App\Models\Service;
use App\Models\Stack;
use App\Models\StackService;
use App\Models\User;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Builds the kinds of release.
 *
 * They differ only in where the target digests come from, which is why one
 * model covers all of them.
 */
class ReleasePlanner
{
    /**
     * Deploy the newest successful build of each enabled service.
     */
    public function normal(Stack $stack, ?User $actor = null, ?Collection $services = null): Release
    {
        $release = Release::create([
            'stack_id' => $stack->id,
            'kind' => Release::KIND_NORMAL,
            'created_by' => $actor?->id,
        ]);

        $position = 0;

        foreach ($this->entitlements($stack, $services) as $entitlement) {
            $build = $this->latestBuildFor($entitlement);

            if ($build === null || $build->digest === $entitlement->current_digest) {
                // Nothing new to ship for this service.
                continue;
            }

            ReleaseItem::create([
                'release_id' => $release->id,
                'service_id' => $entitlement->service_id,
                'build_id' => $build->id,
                'to_digest' => $build->digest,
                'position' => $position++,
            ]);
        }

        return $release->load('items.build', 'items.service');
    }

    /**
     * A release of chosen builds: service id => build id. Used by "Update
     * testing", where each service can be held back or set to an older
     * build, and by an emergency straight to live.
     *
     * @param  array<int, int>  $selection
     */
    public function chosen(Stack $stack, array $selection, string $kind = Release::KIND_NORMAL, ?User $actor = null, ?string $reason = null): Release
    {
        if ($kind === Release::KIND_EMERGENCY && blank(trim((string) $reason))) {
            throw new RuntimeException('An emergency release needs a typed reason.');
        }

        $entitlements = $this->entitlements($stack)->keyBy('service_id');
        $items = [];

        foreach ($selection as $serviceId => $buildId) {
            $entitlement = $entitlements->get((int) $serviceId);

            if ($entitlement === null) {
                throw new RuntimeException('A selected service is not enabled on this stack.');
            }

            $build = $this->candidates($entitlement, 200)->firstWhere('id', (int) $buildId);

            if ($build === null) {
                throw new RuntimeException("That build cannot run {$entitlement->service->name} on this stack.");
            }

            if ($build->digest !== $entitlement->current_digest) {
                $items[] = [$entitlement, $build];
            }
        }

        $release = Release::create([
            'stack_id' => $stack->id,
            'kind' => $kind,
            'reason' => filled($reason) ? trim($reason) : null,
            'created_by' => $actor?->id,
        ]);

        foreach ($items as $position => [$entitlement, $build]) {
            ReleaseItem::create([
                'release_id' => $release->id,
                'service_id' => $entitlement->service_id,
                'build_id' => $build->id,
                'to_digest' => $build->digest,
                'position' => $position,
            ]);
        }

        return $release->load('items.build', 'items.service');
    }

    /**
     * Copy exactly what testing is running onto live.
     *
     * No rebuild. The digests are read from the testing stack, so what passed
     * testing is bit-for-bit what goes live — that guarantee is the reason
     * promotion exists as its own kind rather than just another deploy.
     *
     * The exception is a per-stack service (the dashboard), whose build-time
     * values differ by tier: it promotes the same commit, built for live.
     *
     * @param  list<int>|null  $serviceIds  only these services; null for all
     */
    public function promote(Stack $testing, ?User $actor = null, ?array $serviceIds = null): Release
    {
        $rows = collect($this->promotionRows($testing))
            ->filter(fn (array $row) => $row['status'] === 'ready')
            ->filter(fn (array $row) => $serviceIds === null || in_array($row['service']->id, $serviceIds, true))
            ->values();

        $release = Release::create([
            'stack_id' => $this->liveOf($testing)->id,
            'kind' => Release::KIND_PROMOTION,
            'created_by' => $actor?->id,
        ]);

        foreach ($rows as $position => $row) {
            ReleaseItem::create([
                'release_id' => $release->id,
                'service_id' => $row['service']->id,
                // The build that produced the digest, so the migration
                // classification travels with it.
                'build_id' => $row['target_build']?->id,
                'to_digest' => $row['target_digest'],
                'position' => $position,
            ]);
        }

        return $release->load('items.build', 'items.service');
    }

    /**
     * For each service testing runs: what live runs, what promoting would put
     * there, and whether it can.
     *
     * status: same (nothing to do), ready, needs_build (a per-stack service
     * whose commit has not been built for live yet), not_enabled (live does
     * not run it).
     *
     * @return list<array{service: Service, testing_build: ?Build, live_build: ?Build, target_build: ?Build, target_digest: ?string, status: string}>
     */
    public function promotionRows(Stack $testing): array
    {
        if ($testing->isLive()) {
            throw new RuntimeException('Promotion runs from a testing stack, not a live one.');
        }

        $live = $this->liveOf($testing);
        $rows = [];

        foreach ($this->entitlements($testing) as $source) {
            if (blank($source->current_digest)) {
                continue;
            }

            $target = StackService::query()
                ->where('stack_id', $live->id)
                ->where('service_id', $source->service_id)
                ->where('enabled', true)
                ->first();

            $testingBuild = $this->buildByDigest($source->current_digest);

            $row = [
                'service' => $source->service,
                'testing_build' => $testingBuild,
                'live_build' => $this->buildByDigest($target?->current_digest),
                'target_build' => $testingBuild,
                'target_digest' => $source->current_digest,
                'status' => 'ready',
            ];

            if ($target === null) {
                $row['status'] = 'not_enabled';
            } elseif ($source->service->per_stack_build) {
                $liveBuild = $this->sameCommitFor($source->service, $live, $testingBuild);

                $row['target_build'] = $liveBuild;
                $row['target_digest'] = $liveBuild?->digest;
                $row['status'] = $liveBuild === null ? 'needs_build' : 'ready';
            }

            if ($row['status'] === 'ready' && $target->current_digest === $row['target_digest']) {
                $row['status'] = 'same';
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Put a stack back to the digests a past release recorded.
     *
     * Restoring a database dump is never part of this. That discards
     * everything written since the migration, so it stays a separate, human
     * decision made with the release open in front of them.
     */
    public function rollback(Release $previous, ?User $actor = null): Release
    {
        $release = Release::create([
            'stack_id' => $previous->stack_id,
            'kind' => Release::KIND_ROLLBACK,
            'source_release_id' => $previous->id,
            'created_by' => $actor?->id,
        ]);

        $position = 0;

        foreach ($previous->items()->where('status', 'succeeded')->get() as $item) {
            if (blank($item->from_digest)) {
                // Nothing was recorded to go back to — usually a service that
                // had never been deployed before that release.
                continue;
            }

            ReleaseItem::create([
                'release_id' => $release->id,
                'service_id' => $item->service_id,
                'build_id' => $this->buildByDigest($item->from_digest)?->id,
                'to_digest' => $item->from_digest,
                'position' => $position++,
            ]);
        }

        return $release->load('items.build', 'items.service');
    }

    /**
     * Straight to live, skipping testing.
     *
     * Requires a typed reason and is permanently marked. It exists so that
     * the honest option at 2am is a button with an audit trail rather than
     * an SSH session nobody records.
     */
    public function emergency(Stack $live, string $reason, ?User $actor = null, ?Collection $services = null): Release
    {
        if (blank(trim($reason))) {
            throw new RuntimeException('An emergency release needs a typed reason.');
        }

        $release = $this->normal($live, $actor, $services);

        $release->update([
            'kind' => Release::KIND_EMERGENCY,
            'reason' => trim($reason),
        ]);

        return $release;
    }

    /**
     * The follow-up that keeps the tiers from diverging.
     *
     * An emergency shipped to live without passing testing, so testing is now
     * behind. This is queued automatically afterwards; skipping it is how the
     * two tiers quietly stop being comparable.
     */
    public function emergencyFollowUp(Release $emergency, ?User $actor = null): ?Release
    {
        $testing = $emergency->stack->client->stacks->firstWhere('tier', Stack::TIER_TESTING);

        if ($testing === null) {
            return null;
        }

        $release = Release::create([
            'stack_id' => $testing->id,
            'kind' => Release::KIND_NORMAL,
            'source_release_id' => $emergency->id,
            'created_by' => $actor?->id,
            'reason' => 'Catching testing up after an emergency release to live.',
        ]);

        foreach ($emergency->items as $position => $item) {
            $build = $item->build;

            // A per-stack image carries live's values; testing needs the same
            // commit built for testing, which a later update picks up.
            if ($item->service->per_stack_build) {
                $build = $this->sameCommitFor($item->service, $testing, $build);

                if ($build === null) {
                    continue;
                }
            }

            ReleaseItem::create([
                'release_id' => $release->id,
                'service_id' => $item->service_id,
                'build_id' => $build?->id,
                'to_digest' => $build?->digest ?? $item->to_digest,
                'position' => $position,
            ]);
        }

        return $release->load('items.build', 'items.service');
    }

    /**
     * Builds a stack may run for a service: successful, on the stack's
     * edition for it, shared or built for this stack. Newest first.
     *
     * @return Collection<int, Build>
     */
    public function candidates(StackService $entitlement, int $limit = 25): Collection
    {
        return Build::query()
            ->with('service')
            ->where('service_id', $entitlement->service_id)
            ->where('edition', $entitlement->edition ?: null)
            ->where('status', 'success')
            ->whereNotNull('digest')
            ->where(fn ($q) => $q->whereNull('stack_id')->orWhere('stack_id', $entitlement->stack_id))
            ->orderByDesc('built_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /**
     * A per-stack build belongs to exactly one stack; a shared one to none,
     * and is used by every stack on that edition.
     */
    public function latestBuildFor(StackService $entitlement): ?Build
    {
        return $this->candidates($entitlement, 1)->first();
    }

    public function buildByDigest(?string $digest): ?Build
    {
        return blank($digest) ? null : Build::query()->with('service')->where('digest', $digest)->latest('id')->first();
    }

    /** The same commit, built for another stack. */
    private function sameCommitFor(Service $service, Stack $stack, ?Build $build): ?Build
    {
        if ($build === null || blank($build->commit_sha)) {
            return null;
        }

        return Build::query()
            ->where('service_id', $service->id)
            ->where('stack_id', $stack->id)
            ->where('commit_sha', $build->commit_sha)
            ->where('status', 'success')
            ->latest('id')
            ->first();
    }

    private function liveOf(Stack $testing): Stack
    {
        $live = $testing->client->stacks()->where('tier', Stack::TIER_LIVE)->first();

        if ($live === null) {
            throw new RuntimeException('This client has no live stack to promote into.');
        }

        return $live;
    }

    /**
     * @return Collection<int, StackService>
     */
    private function entitlements(Stack $stack, ?Collection $services = null): Collection
    {
        return StackService::query()
            ->with('service')
            ->where('stack_id', $stack->id)
            ->where('enabled', true)
            ->when($services !== null, fn ($q) => $q->whereIn('service_id', $services->pluck('id')))
            ->get()
            // The identity provider first, then catalog order.
            ->sortBy(fn (StackService $s) => [$s->service->identity_provider ? 0 : 1, $s->service->position, $s->service->slug])
            ->values();
    }
}
