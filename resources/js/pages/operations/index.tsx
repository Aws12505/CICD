import { Head, router } from '@inertiajs/react';
import { AlertTriangle, Check, HardDriveDownload } from 'lucide-react';
import { useState } from 'react';
import { PageHeader } from '@/components/page-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { useAbilities } from '@/hooks/use-abilities';

type Drift = {
    id: number;
    client: string;
    tier: string;
    service: string;
    expected_digest: string | null;
    observed_digest: string | null;
    missing: boolean;
    observed_at: string | null;
};

type BackupRow = {
    id: number;
    client: string;
    tier: string | null;
    service: string | null;
    kind: string;
    status: string;
    triggered_by: string;
    size_bytes: number | null;
    tables: string[] | null;
    error: string | null;
    finished_at: string | null;
};

const shorten = (d: string | null) =>
    d ? d.replace('sha256:', '').slice(0, 12) : 'nothing';

const size = (bytes: number | null) =>
    bytes === null ? '—' : `${(bytes / 1024 / 1024).toFixed(1)} MB`;

function DriftCard({ drift, isAdmin }: { drift: Drift; isAdmin: boolean }) {
    const [reason, setReason] = useState('');

    return (
        <div className="flex flex-col gap-3 p-4">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <div>
                    <div className="font-medium">
                        {drift.client} · {drift.tier} · {drift.service}
                    </div>
                    <div className="mt-1 font-mono text-xs text-muted-foreground">
                        expected {shorten(drift.expected_digest)} · running{' '}
                        {shorten(drift.observed_digest)}
                    </div>
                </div>
                {drift.missing && (
                    <Badge variant="destructive">not running at all</Badge>
                )}
            </div>

            <div className="flex flex-wrap items-center gap-2">
                <Button
                    size="sm"
                    variant="outline"
                    onClick={() =>
                        router.post(
                            `/drift/${drift.id}/converge`,
                            { reason },
                            { preserveScroll: true },
                        )
                    }
                >
                    <Check className="size-4" /> Converge
                </Button>
                {isAdmin && (
                    <>
                        <Input
                            value={reason}
                            onChange={(e) => setReason(e.target.value)}
                            placeholder="Why keep what is running?"
                            className="h-9 max-w-md"
                        />
                        <Button
                            size="sm"
                            variant="secondary"
                            disabled={reason.trim().length < 5}
                            onClick={() =>
                                router.post(
                                    `/drift/${drift.id}/adopt`,
                                    { reason },
                                    { preserveScroll: true },
                                )
                            }
                        >
                            Adopt
                        </Button>
                    </>
                )}
            </div>
            <p className="text-xs text-muted-foreground">
                Converge puts back what the control plane intended. Adopt
                accepts what is running as the new truth — sometimes the fix
                made in the field was the right one.
            </p>
        </div>
    );
}

export default function OperationsIndex({
    drift,
    backups,
    stale,
}: {
    drift: Drift[];
    backups: BackupRow[];
    stale: { id: number; label: string }[];
}) {
    const { destroy: isAdmin } = useAbilities();

    return (
        <>
            <Head title="Operations" />
            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <PageHeader
                    eyebrow="Watch"
                    title="Operations"
                    description="Whether every server is really running what the control plane last deployed, and whether backups are fresh."
                />

                {stale.length > 0 && (
                    <Card className="border-amber-500/40 bg-amber-500/10">
                        <CardContent className="flex gap-3 p-4">
                            <AlertTriangle className="size-5 shrink-0" />
                            <div>
                                <p className="text-sm font-medium">
                                    {stale.length} stack
                                    {stale.length === 1 ? '' : 's'} without a
                                    recent successful backup
                                </p>
                                <p className="mt-1 text-sm text-muted-foreground">
                                    {stale.map((s) => s.label).join(', ')}.
                                    Silent failure is the normal way a backup
                                    system fails.
                                </p>
                            </div>
                        </CardContent>
                    </Card>
                )}

                <Card>
                    <CardHeader className="border-b border-sidebar-border/70">
                        <div className="flex items-center justify-between">
                            <CardTitle>Drift</CardTitle>
                            <Badge variant="secondary">
                                {drift.length} open
                            </Badge>
                        </div>
                    </CardHeader>
                    <CardContent className="p-0">
                        {drift.length === 0 ? (
                            <p className="p-4 text-sm text-muted-foreground">
                                Every server is running what we asked for.
                            </p>
                        ) : (
                            <div className="divide-y divide-sidebar-border/70 dark:divide-sidebar-border">
                                {drift.map((d) => (
                                    <DriftCard
                                        key={d.id}
                                        drift={d}
                                        isAdmin={isAdmin}
                                    />
                                ))}
                            </div>
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader className="border-b border-sidebar-border/70">
                        <CardTitle>Recent backups</CardTitle>
                    </CardHeader>
                    <CardContent className="p-0">
                        {backups.length === 0 ? (
                            <p className="flex items-center gap-2 p-4 text-sm text-muted-foreground">
                                <HardDriveDownload className="size-4" /> No
                                backups recorded yet.
                            </p>
                        ) : (
                            <div className="divide-y divide-sidebar-border/70 dark:divide-sidebar-border">
                                {backups.map((backup) => (
                                    <div
                                        key={backup.id}
                                        className="flex flex-wrap items-center justify-between gap-3 p-4"
                                    >
                                        <div className="min-w-0">
                                            <div className="flex flex-wrap items-center gap-2 font-medium">
                                                {backup.client}
                                                {backup.tier &&
                                                    ` · ${backup.tier}`}
                                                {backup.service &&
                                                    ` · ${backup.service}`}
                                                <Badge variant="secondary">
                                                    {backup.kind}
                                                </Badge>
                                                {backup.triggered_by ===
                                                    'pre_release' && (
                                                    <Badge variant="outline">
                                                        before a migration
                                                    </Badge>
                                                )}
                                            </div>
                                            {backup.tables && (
                                                <div className="mt-1 font-mono text-xs text-muted-foreground">
                                                    tables:{' '}
                                                    {backup.tables.join(', ')}
                                                </div>
                                            )}
                                            {backup.error && (
                                                <div className="mt-1 font-mono text-xs text-destructive">
                                                    {backup.error}
                                                </div>
                                            )}
                                        </div>
                                        <div className="flex items-center gap-2">
                                            <span className="text-xs text-muted-foreground">
                                                {size(backup.size_bytes)}
                                            </span>
                                            <Badge
                                                variant={
                                                    backup.status === 'failed'
                                                        ? 'destructive'
                                                        : 'secondary'
                                                }
                                            >
                                                {backup.status}
                                            </Badge>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

OperationsIndex.layout = {
    breadcrumbs: [{ title: 'Operations', href: '/operations' }],
};
