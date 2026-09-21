<?php

namespace App\Broadcasting;

use App\Models\PipelineRun;
use App\Models\User;
use App\Support\Roles;

/**
 * Authorizes the per-run log stream.
 *
 * A channel class rather than a closure so the rule can be unit tested. The
 * `null` broadcaster used in tests short-circuits `/broadcasting/auth` and
 * returns true without ever evaluating a channel closure, which makes closure
 * authorization effectively untestable — and this rule is worth testing.
 */
class PipelineRunChannel
{
    public function join(User $user, int $runId): bool
    {
        return $user->hasAnyRole(Roles::ALL)
            && PipelineRun::whereKey($runId)->exists();
    }
}
