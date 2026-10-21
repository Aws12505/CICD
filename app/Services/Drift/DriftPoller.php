<?php

namespace App\Services\Drift;

use App\Contracts\Target;
use App\Models\DriftObservation;
use App\Models\Stack;
use App\Models\StackService;
use App\Models\User;
use App\Services\Remote\CommandRunner;
use App\Support\Shell;

/**
 * Asks each server what it is actually running and compares it with what we
 * think it should be.
 *
 * This is the one piece of the design doing by hand what a cluster would do
 * for us. It is five minutes of polling, and it is the only part of
 * Kubernetes we actually needed.
 */
class DriftPoller
{
    public function __construct(private readonly CommandRunner $runner) {}

    /**
     * One SSH connection per stack, not one per service.
     *
     * @return list<DriftObservation>
     */
    public function poll(Stack $stack, ?Target $target = null): array
    {
        $entitlements = StackService::query()
            ->with('service')
            ->where('stack_id', $stack->id)
            ->where('enabled', true)
            ->get();

        if ($entitlements->isEmpty()) {
            return [];
        }

        $ownTarget = $target === null;
        $target ??= $stack->server->target();

        try {
            $running = $this->runningDigests($stack, $target);
        } finally {
            if ($ownTarget) {
                $target->cleanup();
            }
        }

        // An unreachable server is not drift. Reporting every service as
        // changed because SSH timed out would bury the real thing.
        if ($running === null) {
            $stack->server->markUnreachable('Drift poll could not reach the server.');

            return [];
        }

        $stack->server->markOnline();

        $observations = [];

        foreach ($entitlements as $entitlement) {
            $expected = $entitlement->desired_digest;

            if (blank($expected)) {
                // Never deployed. Nothing to compare against yet.
                continue;
            }

            // The web container and every process container run the same
            // image; the first one that differs (or is missing) is the drift.
            $observed = $running[$entitlement->containerName()] ?? null;

            foreach ($entitlement->service->processList() as $process) {
                $name = $entitlement->processContainerName($process['name']);

                if (($running[$name] ?? null) !== $expected) {
                    $observed = $running[$name] ?? null;

                    break;
                }
            }

            if ($observed === $expected) {
                if ($entitlement->state === 'drifted') {
                    $entitlement->update(['state' => 'deployed', 'current_digest' => $observed]);
                }

                continue;
            }

            $entitlement->update(['state' => 'drifted', 'current_digest' => $observed]);

            $observations[] = $this->record($entitlement, $expected, $observed);
        }

        return $observations;
    }

    /**
     * Reassert what the control plane says should be running.
     */
    public function converge(DriftObservation $observation, ?User $actor = null, string $reason = ''): void
    {
        $entitlement = $this->entitlementFor($observation);

        $entitlement?->update([
            'desired_digest' => $observation->expected_digest,
            'state' => 'pending',
        ]);

        $observation->update([
            'resolution' => 'converge',
            'resolution_reason' => $reason,
            'resolved_by' => $actor?->id,
            'resolved_at' => now(),
        ]);
    }

    /**
     * Accept what is running as the new intent.
     *
     * This matters more than it looks. A system that only ever says "you were
     * wrong" gets worked around; one that lets you say the server was right,
     * and records why, gets used. The reason is required.
     */
    public function adopt(DriftObservation $observation, User $actor, string $reason): void
    {
        $entitlement = $this->entitlementFor($observation);

        $entitlement?->update([
            'desired_digest' => $observation->observed_digest,
            'current_digest' => $observation->observed_digest,
            'state' => 'deployed',
        ]);

        $observation->update([
            'resolution' => 'adopt',
            'resolution_reason' => $reason,
            'resolved_by' => $actor->id,
            'resolved_at' => now(),
        ]);
    }

    /**
     * Container name => the digest it was started from, for everything in
     * this compose project.
     *
     * `Config.Image` is the exact reference compose put in the create request
     * (`repo@sha256:…` from our file), so its digest part is what we pinned.
     * `.Image` is the local image ID, a different hash entirely: comparing
     * that with the registry digest would report every deploy as drift. A
     * container someone started by hand with a tag has no `@` and is
     * reported as drift, which is right.
     *
     * @return array<string, string|null>|null null when the server is unreachable
     */
    private function runningDigests(Stack $stack, Target $target): ?array
    {
        $result = $this->runner->run(
            $target,
            'docker ps --all --filter '.Shell::quote('label=com.docker.compose.project='.$stack->compose_project)
                .' --format '.Shell::quote('{{.Names}}')
                .' | xargs -r docker inspect --format '.Shell::quote('{{.Name}} {{.Config.Image}} {{.Image}}'),
            timeout: 60,
        );

        if ($result->failed()) {
            return null;
        }

        $digests = [];

        foreach (explode("\n", $result->stdout) as $line) {
            $parts = preg_split('/\s+/', trim($line));

            if (count($parts) < 2) {
                continue;
            }

            $reference = $parts[1];
            $digests[ltrim($parts[0], '/')] = str_contains($reference, '@')
                ? substr($reference, strpos($reference, '@') + 1)
                : $reference;
        }

        return $digests;
    }

    private function record(StackService $entitlement, ?string $expected, ?string $observed): DriftObservation
    {
        // One open observation per service: re-recording the same difference
        // every five minutes would turn a single problem into a wall of rows.
        $existing = DriftObservation::query()
            ->where('stack_id', $entitlement->stack_id)
            ->where('service_id', $entitlement->service_id)
            ->whereNull('resolution')
            ->first();

        if ($existing !== null) {
            $existing->update([
                'expected_digest' => $expected,
                'observed_digest' => $observed,
                'observed_at' => now(),
            ]);

            return $existing;
        }

        return DriftObservation::create([
            'stack_id' => $entitlement->stack_id,
            'service_id' => $entitlement->service_id,
            'expected_digest' => $expected,
            'observed_digest' => $observed,
            'observed_at' => now(),
        ]);
    }

    private function entitlementFor(DriftObservation $observation): ?StackService
    {
        return StackService::query()
            ->where('stack_id', $observation->stack_id)
            ->where('service_id', $observation->service_id)
            ->first();
    }
}
