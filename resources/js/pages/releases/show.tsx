import { Head, router } from '@inertiajs/react';
import { AlertTriangle, Play, Undo2 } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { useAbilities } from '@/hooks/use-abilities';

type Item = {
    id: number;
    service: string;
    slug: string;
    from_digest: string | null;
    to_digest: string | null;
    status: string;
    error: string | null;
    migration_kind: string | null;
    migration_reasons: string[];
    revertable: boolean;
};

type ReleaseDetail = {
    id: number;
    kind: string;
    status: string;
    client: string;
    tier: string;
    reason: string | null;
    created_by: string | null;
    needs_window: boolean;
    scheduled_for: string | null;
    window_started_at: string | null;
    window_ended_at: string | null;
    affected_tables: string[];
};

const shorten = (digest: string | null) =>
    digest ? digest.replace('sha256:', '').slice(0, 12) : '—';

export default function ReleaseShow({
    release,
    items,
}: {
    release: ReleaseDetail;
    items: Item[];
}) {
    const { manage: canManage } = useAbilities();
    const finished = ['succeeded', 'failed'].includes(release.status);
    const blockedOnWindow = release.needs_window && !release.scheduled_for;

    return (
        <>
            <Head title={`Release #${release.id}`} />
            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <div className="flex flex-wrap items-center gap-3">
                            <h1 className="font-display text-3xl font-semibold">
                                Release #{release.id}
                            </h1>
                            <Badge
                                variant={
                                    release.status === 'failed'
                                        ? 'destructive'
                                        : 'secondary'
                                }
                            >
                                {release.status}
                            </Badge>
                            {release.kind !== 'normal' && (
                                <Badge
                                    variant={
                                        release.kind === 'emergency'
                                            ? 'destructive'
                                            : 'secondary'
                                    }
                                >
                                    {release.kind}
                                </Badge>
                            )}
                        </div>
                        <p className="mt-1 text-sm text-muted-foreground">
                            {release.client} · {release.tier}
                            {release.created_by &&
                                ` · by ${release.created_by}`}
                        </p>
                    </div>
                    {canManage && (
                        <div className="flex flex-wrap gap-2">
                            {!finished && (
                                <Button
                                    disabled={blockedOnWindow}
                                    onClick={() =>
                                        router.post(
                                            `/releases/${release.id}/run`,
                                        )
                                    }
                                >
                                    <Play className="size-4" /> Run release
                                </Button>
                            )}
                            {release.status === 'succeeded' && (
                                <Button
                                    variant="outline"
                                    onClick={() =>
                                        router.post(
                                            `/releases/${release.id}/rollback`,
                                        )
                                    }
                                >
                                    <Undo2 className="size-4" /> Plan rollback
                                </Button>
                            )}
                        </div>
                    )}
                </div>

                {release.kind === 'emergency' && release.reason && (
                    <Card className="border-destructive/40 bg-destructive/5">
                        <CardContent className="p-4">
                            <p className="text-xs tracking-wide text-muted-foreground uppercase">
                                Emergency — permanently recorded
                            </p>
                            <p className="mt-1 text-sm">{release.reason}</p>
                            <p className="mt-2 text-xs text-muted-foreground">
                                This skipped testing. The same deploy is queued
                                onto testing automatically so the two tiers do
                                not diverge.
                            </p>
                        </CardContent>
                    </Card>
                )}

                {blockedOnWindow && (
                    <Card className="border-amber-500/40 bg-amber-500/10">
                        <CardContent className="flex gap-3 p-4">
                            <AlertTriangle className="size-5 shrink-0" />
                            <div>
                                <p className="text-sm font-medium">
                                    This release drops or rewrites data.
                                </p>
                                <p className="mt-1 text-sm text-muted-foreground">
                                    It cannot run until a window has been agreed
                                    with the client. Only these tables are
                                    dumped first:{' '}
                                    <span className="font-mono">
                                        {release.affected_tables.join(', ') ||
                                            'none recorded'}
                                    </span>
                                    .
                                </p>
                            </div>
                        </CardContent>
                    </Card>
                )}

                <Card>
                    <CardHeader className="border-b border-sidebar-border/70">
                        <CardTitle>Services</CardTitle>
                    </CardHeader>
                    <CardContent className="p-0">
                        <div className="divide-y divide-sidebar-border/70 dark:divide-sidebar-border">
                            {items.map((item) => (
                                <div key={item.id} className="p-4">
                                    <div className="flex flex-wrap items-center justify-between gap-3">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <span className="font-medium">
                                                {item.service}
                                            </span>
                                            {item.migration_kind ===
                                                'destructive' && (
                                                <Badge variant="destructive">
                                                    destructive migration
                                                </Badge>
                                            )}
                                            {item.revertable && (
                                                <Badge variant="outline">
                                                    revertable
                                                </Badge>
                                            )}
                                        </div>
                                        <Badge
                                            variant={
                                                item.status === 'failed'
                                                    ? 'destructive'
                                                    : 'secondary'
                                            }
                                        >
                                            {item.status}
                                        </Badge>
                                    </div>
                                    <div className="mt-1 font-mono text-xs text-muted-foreground">
                                        {shorten(item.from_digest)} →{' '}
                                        {shorten(item.to_digest)}
                                    </div>
                                    {item.error && (
                                        <p className="mt-2 font-mono text-xs text-destructive">
                                            {item.error}
                                        </p>
                                    )}
                                    {item.migration_reasons.length > 0 && (
                                        <ul className="mt-2 text-xs text-muted-foreground">
                                            {item.migration_reasons.map(
                                                (reason) => (
                                                    <li key={reason}>
                                                        {reason}
                                                    </li>
                                                ),
                                            )}
                                        </ul>
                                    )}
                                </div>
                            ))}
                        </div>
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

ReleaseShow.layout = {
    breadcrumbs: [{ title: 'Releases', href: '/releases' }],
};
