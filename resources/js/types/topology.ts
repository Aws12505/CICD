export type Tier = 'live' | 'testing';

export type StackSummary = {
    tier: Tier;
    status: string;
    server: string | null;
};

export type ClientRow = {
    id: number;
    name: string;
    slug: string;
    domain: string;
    status: string;
    stacks: StackSummary[];
};

export type ServiceRow = {
    id: number;
    name: string;
    slug: string;
    subdomain: string;
    build_recipe: string;
    per_stack_build: boolean;
    position: number;
    env_definitions_count: number;
    enabled_count: number;
};

export type Entitlement = {
    service_id: number;
    enabled: boolean;
    edition: string | null;
    host_port: number | null;
    state: string;
    domain: string;
    /** Required env keys this stack cannot supply. A deploy stops on these. */
    missing_env: string[];
};

export type StackDetail = {
    id: number;
    tier: Tier;
    status: string;
    compose_project: string;
    port_range_start: number;
    server: string | null;
    entitlements: Entitlement[];
};

export type EnvDefinition = {
    id: number;
    key: string;
    source: string;
    required: boolean;
    is_build_arg: boolean;
    default_value: string | null;
    description: string | null;
};
