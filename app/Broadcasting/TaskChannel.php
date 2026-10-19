<?php

namespace App\Broadcasting;

use App\Models\Task;
use App\Models\User;
use App\Support\Roles;

/**
 * Task logs carry the same kind of output as deploy logs — hostnames, command
 * output, errors — so the same rule applies: a role, and a task that exists.
 * Secrets are masked before they are written, not here.
 */
class TaskChannel
{
    public function join(User $user, int $taskId): bool
    {
        return $user->hasAnyRole(Roles::ALL)
            && Task::whereKey($taskId)->exists();
    }
}
