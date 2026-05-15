import { cn } from '@/lib/utils';

type Status = 'queued' | 'running' | 'success' | 'failed' | 'cancelled' | 'pending' | 'skipped' | null | undefined;

const STYLES: Record<string, string> = {
    queued: 'bg-amber-500/15 text-amber-600 dark:text-amber-400 ring-amber-500/30',
    pending: 'bg-zinc-500/15 text-zinc-600 dark:text-zinc-400 ring-zinc-500/30',
    running: 'bg-blue-500/15 text-blue-600 dark:text-blue-400 ring-blue-500/30 animate-pulse',
    success: 'bg-emerald-500/15 text-emerald-600 dark:text-emerald-400 ring-emerald-500/30',
    failed: 'bg-red-500/15 text-red-600 dark:text-red-400 ring-red-500/30',
    cancelled: 'bg-zinc-500/15 text-zinc-600 dark:text-zinc-400 ring-zinc-500/30',
    skipped: 'bg-zinc-500/15 text-zinc-500 ring-zinc-500/20',
};

export function RunStatusBadge({ status }: { status: Status }) {
    const key = status ?? 'pending';
    const label = key.charAt(0).toUpperCase() + key.slice(1);

    return (
        <span
            className={cn(
                'inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-semibold ring-1 ring-inset',
                STYLES[key] ?? STYLES.pending,
            )}
        >
            <span className="size-1.5 rounded-full bg-current" />
            {label}
        </span>
    );
}
