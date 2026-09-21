<?php

namespace App\Services\Release;

use App\Models\Build;
use App\Models\Release;
use App\Models\ReleaseItem;
use App\Models\Stack;
use App\Models\StackService;
use App\Models\User;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Builds the four kinds of release.
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
     * Copy exactly what testing is running onto live.
     *
     * No rebuild. The digests are read from the testing stack, so what passed
     * testing is bit-for-bit what goes live — that guarantee is the reason
     * promotion exists as its own kind rather than just another deploy.
     */
    public function promote(Stack $testing, ?User $actor = null): Release
    {
        if ($testing->isLive()) {
            throw new RuntimeException('Promotion runs from a testing stack, not a live one.');
        }

        $live = $testing->client->stacks->firstWhere('tier', Stack::TIER_LIVE);

        if ($live === null) {
            throw new RuntimeException('This client has no live stack to promote into.');
        }

        $release = Release::create([
            'stack_id' => $live->id,
            'kind' => Release::KIND_PROMOTION,
            'created_by' => $actor?->id,
        ]);

        $position = 0;

        foreach ($this->entitlements($testing) as $source) {
            if (blank($source->current_digest)) {
                continue;
            }

            $target = StackService::query()
                ->where('stack_id', $live->id)
                ->where('service_id', $source->service_id)
                ->where('enabled', true)
                ->first();

            if ($target === null || $target->current_digest === $source->current_digest) {
                continue;
            }

            ReleaseItem::create([
                'release_id' => $release->id,
                'service_id' => $source->service_id,
                // The build that produced the digest testing is running, so
                // the migration classification travels with it.
                'build_id' => Build::where('digest', $source->current_digest)->value('id'),
                'to_digest' => $source->current_digest,
                'position' => $position++,
            ]);
        }

        return $release->load('items.build', 'items.service');
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
            ReleaseItem::create([
                'release_id' => $release->id,
                'service_id' => $item->service_id,
                'build_id' => $item->build_id,
                'to_digest' => $item->to_digest,
                'position' => $position,
            ]);
        }

        return $release->load('items.build', 'items.service');
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
            ->sortBy(fn (StackService $s) => [$s->service->position, $s->service->slug])
            ->values();
    }

    private function latestBuildFor(StackService $entitlement): ?Build
    {
        return Build::query()
            ->where('service_id', $entitlement->service_id)
            ->where('edition', $entitlement->edition)
            ->where('status', 'success')
            // A per-stack build belongs to exactly one stack; a shared one to
            // none, and is used by every stack on that edition.
            ->where(fn ($q) => $q->whereNull('stack_id')->orWhere('stack_id', $entitlement->stack_id))
            ->latest('built_at')
            ->first();
    }
}
