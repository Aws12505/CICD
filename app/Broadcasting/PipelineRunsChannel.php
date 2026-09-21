<?php

namespace App\Broadcasting;

use App\Models\User;
use App\Support\Roles;

/**
 * Authorizes the global run-activity feed, which announces every run's status
 * across the whole install.
 */
class PipelineRunsChannel
{
    public function join(User $user): bool
    {
        return $user->hasAnyRole(Roles::ALL);
    }
}
