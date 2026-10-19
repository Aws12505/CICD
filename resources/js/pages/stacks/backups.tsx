import { Head, router, useForm } from '@inertiajs/react';
import { DatabaseBackup, FolderArchive, History } from 'lucide-react';
import type { FormEventHandler } from 'react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { bytes } from '@/components/server-monitor';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { useAbilities } from '@/hooks/use-abilities';

type BackupRow = {
    id: number;
    kind: 'database' | 'files';
    service: string | null;
    status: string;
    triggered_by: string;
    tables: string[];
    size_bytes: number | null;
    finished_at: string | null;
    error: string | null;
};

type RestoreRow = {
    id: number;
    backup_id: number;
    what: string;
    by: string | null;
    reason: string;
    status: string;
    error: string | null;
    created_at: string | null;
};

type Props = {
    stack: { id: number; label: string; name: string; client_slug: string };
    backups: BackupRow[];
    restores: RestoreRow[];
};

function RestoreDialog({
    stack,
    backup,
    onClose,
}: {
    stack: Props['stack'];
    backup: BackupRow;
    onClose: () => void;
}) {
    const form = useForm({ confirm: '', reason: '' });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        form.post(`/backups/${backup.id}/restore`);
    };

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Restore {stack.label}</DialogTitle>
                </DialogHeader>
                <form onSubmit={submit} className="grid gap-4">
                    <p className="text-sm text-muted-foreground">
                        {backup.kind === 'database'
                            ? backup.tables.length
                                ? `Replaces the tables ${backup.tables.join(', ')} of ${backup.service} with this backup.`
                                : `Replaces ${backup.service}'s whole database with this backup.`
                            : 'Copies the backed-up files back. Files written since stay.'}{' '}
                        Anything written since{' '}
                        {backup.finished_at
                            ? new Date(backup.finished_at).toLocaleString()
                            : 'then'}{' '}
                        is lost. A fresh backup of what is there now is taken
                        first.
                    </p>
                    <div className="grid gap-2">
                        <Label htmlFor="reason">Why</Label>
                        <Textarea
                            id="reason"
                            rows={3}
                            value={form.data.reason}
                            onChange={(e) =>
                                form.setData('reason', e.target.value)
                            }
                        />
                        <InputError message={form.errors.reason} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="confirm">
                            Type <span className="font-mono">{stack.name}</span>{' '}
                            to confirm
                        </Label>
                        <Input
                            id="confirm"
                            className="font-mono"
                            autoComplete="off"
                            value={form.data.confirm}
                            onChange={(e) =>
                                form.setData('confirm', e.target.value)
                            }
                        />
                        <InputError message={form.errors.confirm} />
                    </div>
                    <Button
                        type="submit"
                        variant="destructive"
                        disabled={
                            form.processing || form.data.confirm !== stack.name
                        }
                    >
                        Restore
                    </Button>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export default function StackBackups({ stack, backups, restores }: Props) {
    const { manage: canManage, destroy: isAdmin } = useAbilities();
    const [restoring, setRestoring] = useState<BackupRow | null>(null);

    return (
        <>
            <Head title={`Backups · ${stack.label}`} />
            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h1 className="font-display text-3xl font-semibold">
                            Backups · {stack.label}
                        </h1>
                        <p className="mt-2 max-w-3xl text-sm text-muted-foreground">
                            Databases nightly, files weekly, pulled to the
                            control plane. Restoring is admin-only and recorded.
                        </p>
                    </div>
                    {canManage && (
                        <div className="flex flex-wrap gap-2">
                            <Button
                                variant="outline"
                                onClick={() =>
                                    router.post(`/stacks/${stack.id}/backups`, {
                                        kind: 'database',
                                    })
                                }
                            >
                                <DatabaseBackup className="size-4" /> Back up
                                databases now
                            </Button>
                            <Button
                                variant="outline"
                                onClick={() =>
                                    router.post(`/stacks/${stack.id}/backups`, {
                                        kind: 'files',
                                    })
                                }
                            >
                                <FolderArchive className="size-4" /> Back up
                                files now
                            </Button>
                        </div>
                    )}
                </div>

                <Card>
                    <CardContent className="p-0">
                        <div className="divide-y divide-sidebar-border/70 dark:divide-sidebar-border">
                            {backups.length === 0 && (
                                <p className="p-4 text-sm text-muted-foreground">
                                    No backups yet.
                                </p>
                            )}
                            {backups.map((b) => (
                                <div
                                    key={b.id}
                                    className="flex flex-wrap items-center justify-between gap-3 p-4"
                                >
                                    <div className="grid gap-1">
                                        <div className="flex flex-wrap items-center gap-2 text-sm">
                                            <span className="font-medium">
                                                {b.kind === 'database'
                                                    ? (b.service ?? 'database')
                                                    : 'Files'}
                                            </span>
                                            {b.tables.length > 0 && (
                                                <Badge variant="outline">
                                                    {b.tables.join(', ')}
                                                </Badge>
                                            )}
                                            <Badge variant="secondary">
                                                {b.triggered_by}
                                            </Badge>
                                            <Badge
                                                variant={
                                                    b.status === 'success'
                                                        ? 'secondary'
                                                        : b.status === 'failed'
                                                          ? 'destructive'
                                                          : 'outline'
                                                }
                                            >
                                                {b.status}
                                            </Badge>
                                        </div>
                                        <div className="font-mono text-xs text-muted-foreground">
                                            {b.finished_at
                                                ? new Date(
                                                      b.finished_at,
                                                  ).toLocaleString()
                                                : 'in progress'}
                                            {b.size_bytes
                                                ? ` · ${bytes(b.size_bytes)}`
                                                : ''}
                                            {b.error ? ` · ${b.error}` : ''}
                                        </div>
                                    </div>
                                    {isAdmin && b.status === 'success' && (
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            onClick={() => setRestoring(b)}
                                        >
                                            <History className="size-4" />{' '}
                                            Restore
                                        </Button>
                                    )}
                                </div>
                            ))}
                        </div>
                    </CardContent>
                </Card>

                {restores.length > 0 && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Restores</CardTitle>
                        </CardHeader>
                        <CardContent className="grid gap-3">
                            {restores.map((r) => (
                                <div key={r.id} className="grid gap-1 text-sm">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <span>{r.what}</span>
                                        <Badge
                                            variant={
                                                r.status === 'failed'
                                                    ? 'destructive'
                                                    : 'secondary'
                                            }
                                        >
                                            {r.status}
                                        </Badge>
                                    </div>
                                    <div className="text-xs text-muted-foreground">
                                        {r.by ?? 'unknown'} ·{' '}
                                        {r.created_at
                                            ? new Date(
                                                  r.created_at,
                                              ).toLocaleString()
                                            : ''}{' '}
                                        · {r.reason}
                                        {r.error ? ` · ${r.error}` : ''}
                                    </div>
                                </div>
                            ))}
                        </CardContent>
                    </Card>
                )}
            </div>

            {restoring && (
                <RestoreDialog
                    stack={stack}
                    backup={restoring}
                    onClose={() => setRestoring(null)}
                />
            )}
        </>
    );
}

StackBackups.layout = {
    breadcrumbs: [{ title: 'Clients', href: '/clients' }],
};
