import { usePage } from '@inertiajs/react';
import type { Abilities, Auth } from '@/types';

const NONE: Abilities = { manage: false, destroy: false };

/**
 * What the signed-in user may do, shared from HandleInertiaRequests.
 *
 * Use this to hide controls that would only 403. It is not a security
 * boundary — the routes enforce access on the server, whatever the UI shows.
 */
export function useAbilities(): Abilities {
    const auth = usePage().props.auth as Auth | undefined;

    return auth?.can ?? NONE;
}
