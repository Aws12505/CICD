<?php

namespace App\Services\Stack;

use App\Models\Stack;
use App\Models\StackService;
use RuntimeException;

/**
 * Hands out host ports.
 *
 * Every stack gets a contiguous block, and every enabled service gets one
 * port inside its own stack's block. Blocks rather than a global pool so the
 * ports on a client's machine stay readable: everything on one stack sits in
 * the same hundred.
 *
 * This replaces the hand-picked numbers in the current estate (49137, 40151,
 * 40666), which nothing recorded and nothing prevented colliding.
 */
class PortAllocator
{
    /**
     * Reserve the next free block. Blocks are never reused while a stack
     * holds one, so a deleted stack's block is only recovered when its row
     * goes with it.
     */
    public function allocateBlock(): int
    {
        $base = (int) config('deploy.ports.base');
        $size = (int) config('deploy.ports.block_size');
        $max = (int) config('deploy.ports.max');

        $taken = Stack::query()->pluck('port_range_start')->all();

        for ($start = $base; $start + $size <= $max; $start += $size) {
            if (! in_array($start, $taken, true)) {
                return $start;
            }
        }

        throw new RuntimeException(
            "No free port block between {$base} and {$max}. Widen deploy.ports.max or reclaim a stack."
        );
    }

    /**
     * Give this service a port inside its stack's block, or return the one it
     * already holds. Stable across deploys, because nginx and the compose
     * file both point at it.
     */
    public function assign(StackService $entitlement): int
    {
        if ($entitlement->host_port !== null) {
            return $entitlement->host_port;
        }

        $stack = $entitlement->stack;
        $size = (int) config('deploy.ports.block_size');

        $used = StackService::query()
            ->where('stack_id', $stack->id)
            ->whereNotNull('host_port')
            ->pluck('host_port')
            ->all();

        for ($offset = 0; $offset < $size; $offset++) {
            $candidate = $stack->port_range_start + $offset;

            if (! in_array($candidate, $used, true)) {
                $entitlement->forceFill(['host_port' => $candidate])->save();

                return $candidate;
            }
        }

        throw new RuntimeException(
            "Stack [{$stack->compose_project}] has no free port left in its block of {$size}."
        );
    }
}
