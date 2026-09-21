<?php

namespace App\Support;

/**
 * The control plane's roles, in one place.
 *
 * Coarse on purpose — this is about blast radius, not fine-grained capability.
 * Anything destructive or irreversible belongs to ADMIN.
 */
final class Roles
{
    public const ADMIN = 'admin';

    public const OPERATOR = 'operator';

    public const VIEWER = 'viewer';

    /** Every role that implies legitimate access to the control plane. */
    public const ALL = [self::ADMIN, self::OPERATOR, self::VIEWER];
}
