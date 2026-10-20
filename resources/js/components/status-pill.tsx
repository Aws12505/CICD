import {
    AlertTriangle,
    CheckCircle2,
    Circle,
    CircleDashed,
    Loader2,
    Lock,
    XCircle,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { cn } from '@/lib/utils';

export type Tone =
    | 'success'
    | 'info'
    | 'warning'
    | 'danger'
    | 'neutral'
    | 'running'
    | 'blocked';

const TONE: Record<Tone, { icon: LucideIcon; className: string }> = {
    success: {
        icon: CheckCircle2,
        className: 'bg-success/12 text-success border-success/30',
    },
    info: {
        icon: Circle,
        className: 'bg-info/12 text-info border-info/30',
    },
    warning: {
        icon: AlertTriangle,
        className: 'bg-warning/15 text-warning border-warning/35',
    },
    danger: {
        icon: XCircle,
        className: 'bg-destructive/12 text-destructive border-destructive/30',
    },
    running: {
        icon: Loader2,
        className: 'bg-info/12 text-info border-info/30 [&>svg]:animate-spin',
    },
    blocked: {
        icon: Lock,
        className: 'bg-muted text-muted-foreground border-border',
    },
    neutral: {
        icon: CircleDashed,
        className: 'bg-muted text-muted-foreground border-border',
    },
};

/** Words for statuses the backend uses, so the same status reads the same everywhere. */
export const STATUS_TONE: Record<string, Tone> = {
    succeeded: 'success',
    success: 'success',
    online: 'success',
    active: 'success',
    deployed: 'success',
    done: 'success',
    running: 'running',
    queued: 'info',
    scheduled: 'info',
    draft: 'neutral',
    pending: 'neutral',
    todo: 'info',
    blocked: 'blocked',
    failed: 'danger',
    unreachable: 'danger',
    cancelled: 'neutral',
    suspended: 'warning',
};

/**
 * A status with an icon and a word, never colour alone.
 */
export function StatusPill({
    tone,
    status,
    children,
    className,
}: {
    tone?: Tone;
    /** A backend status word; its tone is looked up. */
    status?: string;
    children?: React.ReactNode;
    className?: string;
}) {
    const resolved: Tone = tone ?? STATUS_TONE[status ?? ''] ?? 'neutral';
    const { icon: Icon, className: toneClass } = TONE[resolved];

    return (
        <span
            className={cn(
                'inline-flex items-center gap-1.5 rounded-full border px-2.5 py-0.5 text-xs font-medium whitespace-nowrap',
                toneClass,
                className,
            )}
        >
            <Icon className="size-3.5 shrink-0" aria-hidden="true" />
            {children ?? status}
        </span>
    );
}
