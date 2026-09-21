export type User = {
    id: number;
    name: string;
    email: string;
    avatar?: string;
    email_verified_at: string | null;
    two_factor_enabled?: boolean;
    created_at: string;
    updated_at: string;
    [key: string]: unknown;
};

export type Role = 'admin' | 'operator' | 'viewer';

/**
 * What the signed-in user is allowed to do, shared on every page.
 *
 * Presentation only — hiding a control the user cannot use. The routes do the
 * actual enforcing, so never treat these as security.
 */
export type Abilities = {
    /** Create and edit projects and pipelines, and trigger runs. */
    manage: boolean;
    /** Delete projects and pipelines. */
    destroy: boolean;
};

export type Auth = {
    user: User;
    roles: Role[];
    can: Abilities;
};

export type TwoFactorSetupData = {
    svg: string;
    url: string;
};

export type TwoFactorSecretKey = {
    secretKey: string;
};
