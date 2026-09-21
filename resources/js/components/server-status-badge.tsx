import { Badge } from '@/components/ui/badge';
import type { ServerStatus } from '@/types/server';

const LABELS: Record<ServerStatus, string> = {
    pending: 'never checked',
    online: 'online',
    unreachable: 'unreachable',
};

const VARIANTS: Record<
    ServerStatus,
    'default' | 'secondary' | 'destructive' | 'outline'
> = {
    pending: 'secondary',
    online: 'default',
    unreachable: 'destructive',
};

export function ServerStatusBadge({ status }: { status: ServerStatus }) {
    return <Badge variant={VARIANTS[status]}>{LABELS[status]}</Badge>;
}
