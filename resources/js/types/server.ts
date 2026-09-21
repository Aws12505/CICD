export type ServerStatus = 'pending' | 'online' | 'unreachable';

export type ServerSummary = {
    id: number;
    name: string;
    hostname: string;
    ip_address: string | null;
    ssh_user: string;
    ssh_port: number;
    status: ServerStatus;
    /** Whether a host key has been captured and is being pinned. */
    verified: boolean;
    /** Safe to display and to compare out of band. The key itself never leaves the server. */
    host_key_fingerprint: string | null;
    last_seen_at: string | null;
};

export type ServerDetail = ServerSummary & {
    notes: string | null;
    last_error?: string | null;
};
