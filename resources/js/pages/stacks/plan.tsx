import { Head, Link, router, useForm } from '@inertiajs/react';
import { AlertTriangle, Hammer, Play, Siren } from 'lucide-react';
import { useMemo } from 'react';
import type { FormEventHandler } from 'react';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';

type BuildSummary = {
    id: number;
    number: number | null;
    label: string;
    sha: string;
    edition: string | null;
    migration_kind: 'none' | 'additive' | 'destructive';
    built_at: string | null;
    changelog: { sha: string; author: string; subject: string }[];
};

type UpdateRow = {
    service: { id: number; name: string };
    edition: string | null;
    current: BuildSummary | null;
    deployed: boolean;
    candidates: BuildSummary[];
    missing_env: string[];
};

type PromoteRow = {
    service: { id: number; name: string };
    testing: BuildSummary | null;
    live: BuildSummary | null;
    target: BuildSummary | null;
    status: 'same' | 'ready' | 'needs_build' | 'not_enabled';
};

type Props = {
    stack: {
        id: number;
        tier: string;
        label: string;
        client: { name: string; slug: string };
        server_ready: boolean;
        pinned: boolean;
    };
    mode: 'update' | 'promote' | 'emergency';
    rows: UpdateRow[] | PromoteRow[];
};

function MigrationBadge({ kind }: { kind: BuildSummary['migration_kind'] }) {
    if (kind === 'none') {
        return null;
    }

    return (
        <Badge variant={kind === 'destructive' ? 'destructive' : 'secondary'}>
            {kind} migration
        </Badge>
    );
}

/** Commits between what runs and what is chosen, newest first. */
function commitsBetween(row: UpdateRow, chosenId: number) {
    const chosen = row.candidates.findIndex((b) => b.id === chosenId);
    const current = row.current
        ? row.candidates.findIndex((b) => b.id === row.current!.id)
        : row.candidates.length;

    if (chosen === -1 || chosen >= current) {
        return [];
    }

    return row.candidates
        .slice(chosen, current === -1 ? row.candidates.length : current)
        .flatMap((b) => b.changelog);
}

export default function StackPlan({ stack, mode, rows }: Props) {
    const update = mode !== 'promote';
    const updateRows = rows as UpdateRow[];
    const promoteRows = rows as PromoteRow[];

    const initialSelection = useMemo(() => {
        const selection: Record<string, number> = {};

        if (update) {
            updateRows.forEach((r) => {
                const newest = r.candidates[0];

                if (
                    newest &&
                    newest.id !== r.current?.id &&
                    mode === 'update'
                ) {
                    selection[r.service.id] = newest.id;
                }
            });
        }

        return selection;
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const form = useForm({
        mode,
        selection: initialSelection,
        service_ids: update
            ? []
            : promoteRows
                  .filter((r) => r.status === 'ready')
                  .map((r) => r.service.id),
        reason: '',
        scheduled_for: '',
    });

    const destructive = update
        ? updateRows.some((r) => {
              const id = form.data.selection[r.service.id];
              const build = r.candidates.find((b) => b.id === id);

              return build?.migration_kind === 'destructive';
          })
        : promoteRows.some(
              (r) =>
                  form.data.service_ids.includes(r.service.id) &&
                  r.target?.migration_kind === 'destructive',
          );

    const count = update
        ? Object.keys(form.data.selection).length
        : form.data.service_ids.length;

    const needsBuild =
        !update && promoteRows.some((r) => r.status === 'needs_build');

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        form.post(`/stacks/${stack.id}/releases`);
    };

    const title =
        mode === 'update'
            ? `Update ${stack.client.name} testing`
            : mode === 'promote'
              ? `Promote ${stack.client.name} to live`
              : `Emergency release to ${stack.client.name} live`;

    return (
        <>
            <Head title={title} />
            <form
                onSubmit={submit}
                className="flex h-full flex-1 flex-col gap-6 p-4"
            >
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h1 className="font-display text-3xl font-semibold">
                            {title}
                        </h1>
                        <p className="mt-2 max-w-3xl text-sm text-muted-foreground">
                            {mode === 'update' &&
                                'The newest build of each service for this client. Untick a service to hold it back, or pick an older build.'}
                            {mode === 'promote' &&
                                'Exactly what testing runs, bit for bit. Untick a service to leave live where it is.'}
                            {mode === 'emergency' &&
                                'Straight to live, skipping testing. Permanently marked, and testing is planned to catch up afterwards.'}
                        </p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        {mode === 'promote' && (
                            <Button asChild variant="outline">
                                <Link
                                    href={`/stacks/${stack.id}/plan?emergency=1`}
                                >
                                    <Siren className="size-4" /> Emergency
                                </Link>
                            </Button>
                        )}
                        {mode === 'emergency' && (
                            <Button asChild variant="outline">
                                <Link href={`/stacks/${stack.id}/plan`}>
                                    Back to promote
                                </Link>
                            </Button>
                        )}
                    </div>
                </div>

                {!stack.server_ready && (
                    <Card className="border-destructive/40 bg-destructive/5">
                        <CardContent className="flex items-center gap-2 p-4 text-sm">
                            <AlertTriangle className="size-4" />
                            The server has not been set up yet. Run "Set up
                            server" on it first.
                        </CardContent>
                    </Card>
                )}

                {needsBuild && (
                    <Card className="border-amber-500/40 bg-amber-500/5">
                        <CardContent className="flex flex-wrap items-center justify-between gap-3 p-4 text-sm">
                            <span>
                                A service built per client needs the same commit
                                built with live's settings before it can be
                                promoted.
                            </span>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() =>
                                    router.post(
                                        `/stacks/${stack.id}/build-for-live`,
                                    )
                                }
                            >
                                <Hammer className="size-4" /> Build for live
                            </Button>
                        </CardContent>
                    </Card>
                )}

                <Card>
                    <CardContent className="p-0">
                        <div className="divide-y divide-sidebar-border/70 dark:divide-sidebar-border">
                            {rows.length === 0 && (
                                <p className="p-4 text-sm text-muted-foreground">
                                    {update
                                        ? 'No services are enabled on this stack.'
                                        : 'Testing has nothing deployed yet.'}
                                </p>
                            )}

                            {update &&
                                updateRows.map((row) => {
                                    const chosen =
                                        form.data.selection[row.service.id];
                                    const included = chosen !== undefined;
                                    const build = row.candidates.find(
                                        (b) => b.id === chosen,
                                    );
                                    const commits = included
                                        ? commitsBetween(row, chosen)
                                        : [];

                                    return (
                                        <div
                                            key={row.service.id}
                                            className="grid gap-3 p-4"
                                        >
                                            <div className="flex flex-wrap items-center justify-between gap-3">
                                                <label className="flex items-center gap-3">
                                                    <Checkbox
                                                        checked={included}
                                                        disabled={
                                                            row.candidates
                                                                .length === 0
                                                        }
                                                        onCheckedChange={(
                                                            v,
                                                        ) => {
                                                            const next = {
                                                                ...form.data
                                                                    .selection,
                                                            };

                                                            if (v === true) {
                                                                next[
                                                                    row.service.id
                                                                ] =
                                                                    row.candidates[0].id;
                                                            } else {
                                                                delete next[
                                                                    row.service
                                                                        .id
                                                                ];
                                                            }

                                                            form.setData(
                                                                'selection',
                                                                next,
                                                            );
                                                        }}
                                                    />
                                                    <span className="font-medium">
                                                        {row.service.name}
                                                    </span>
                                                    {row.edition && (
                                                        <Badge variant="outline">
                                                            {row.edition}
                                                        </Badge>
                                                    )}
                                                </label>
                                                <div className="flex flex-wrap items-center gap-2 text-sm">
                                                    <span className="text-muted-foreground">
                                                        now{' '}
                                                        {row.current
                                                            ? `#${row.current.number}`
                                                            : row.deployed
                                                              ? 'unknown'
                                                              : 'not deployed'}
                                                    </span>
                                                    <span>→</span>
                                                    <Select
                                                        disabled={
                                                            row.candidates
                                                                .length === 0
                                                        }
                                                        value={
                                                            included
                                                                ? String(chosen)
                                                                : ''
                                                        }
                                                        onValueChange={(v) =>
                                                            form.setData(
                                                                'selection',
                                                                {
                                                                    ...form.data
                                                                        .selection,
                                                                    [row.service
                                                                        .id]:
                                                                        Number(
                                                                            v,
                                                                        ),
                                                                },
                                                            )
                                                        }
                                                    >
                                                        <SelectTrigger className="w-56">
                                                            <SelectValue
                                                                placeholder={
                                                                    row
                                                                        .candidates
                                                                        .length
                                                                        ? 'Hold back'
                                                                        : 'No builds yet'
                                                                }
                                                            />
                                                        </SelectTrigger>
                                                        <SelectContent>
                                                            {row.candidates.map(
                                                                (b) => (
                                                                    <SelectItem
                                                                        key={
                                                                            b.id
                                                                        }
                                                                        value={String(
                                                                            b.id,
                                                                        )}
                                                                    >
                                                                        #
                                                                        {
                                                                            b.number
                                                                        }{' '}
                                                                        ·{' '}
                                                                        {b.sha}
                                                                        {b.id ===
                                                                        row
                                                                            .current
                                                                            ?.id
                                                                            ? ' (running)'
                                                                            : ''}
                                                                    </SelectItem>
                                                                ),
                                                            )}
                                                        </SelectContent>
                                                    </Select>
                                                    {build && (
                                                        <MigrationBadge
                                                            kind={
                                                                build.migration_kind
                                                            }
                                                        />
                                                    )}
                                                </div>
                                            </div>
                                            {row.missing_env.length > 0 && (
                                                <p className="flex items-center gap-1 text-xs text-destructive">
                                                    <AlertTriangle className="size-3" />
                                                    Missing settings:{' '}
                                                    {row.missing_env.join(', ')}
                                                    . The release will stop
                                                    before anything changes.
                                                </p>
                                            )}
                                            {commits.length > 0 && (
                                                <div className="grid gap-0.5 pl-7">
                                                    {commits
                                                        .slice(0, 12)
                                                        .map((c) => (
                                                            <div
                                                                key={c.sha}
                                                                className="font-mono text-xs"
                                                            >
                                                                <span className="text-muted-foreground">
                                                                    {c.sha}
                                                                </span>{' '}
                                                                {c.subject}
                                                            </div>
                                                        ))}
                                                    {commits.length > 12 && (
                                                        <div className="text-xs text-muted-foreground">
                                                            and{' '}
                                                            {commits.length -
                                                                12}{' '}
                                                            more
                                                        </div>
                                                    )}
                                                </div>
                                            )}
                                        </div>
                                    );
                                })}

                            {!update &&
                                promoteRows.map((row) => {
                                    const ready = row.status === 'ready';
                                    const included =
                                        form.data.service_ids.includes(
                                            row.service.id,
                                        );

                                    return (
                                        <div
                                            key={row.service.id}
                                            className="flex flex-wrap items-center justify-between gap-3 p-4"
                                        >
                                            <label className="flex items-center gap-3">
                                                <Checkbox
                                                    checked={included}
                                                    disabled={!ready}
                                                    onCheckedChange={(v) =>
                                                        form.setData(
                                                            'service_ids',
                                                            v === true
                                                                ? [
                                                                      ...form
                                                                          .data
                                                                          .service_ids,
                                                                      row
                                                                          .service
                                                                          .id,
                                                                  ]
                                                                : form.data.service_ids.filter(
                                                                      (x) =>
                                                                          x !==
                                                                          row
                                                                              .service
                                                                              .id,
                                                                  ),
                                                        )
                                                    }
                                                />
                                                <span className="font-medium">
                                                    {row.service.name}
                                                </span>
                                            </label>
                                            <div className="flex flex-wrap items-center gap-2 text-sm">
                                                <span className="text-muted-foreground">
                                                    live{' '}
                                                    {row.live
                                                        ? `#${row.live.number}`
                                                        : '—'}
                                                </span>
                                                <span>→</span>
                                                <span className="font-mono">
                                                    {row.testing
                                                        ? `#${row.testing.number} · ${row.testing.sha}`
                                                        : 'unknown'}
                                                </span>
                                                {row.status === 'same' && (
                                                    <Badge variant="secondary">
                                                        already live
                                                    </Badge>
                                                )}
                                                {row.status ===
                                                    'needs_build' && (
                                                    <Badge variant="outline">
                                                        needs a build for live
                                                    </Badge>
                                                )}
                                                {row.status ===
                                                    'not_enabled' && (
                                                    <Badge variant="outline">
                                                        not enabled on live
                                                    </Badge>
                                                )}
                                                {row.target && ready && (
                                                    <MigrationBadge
                                                        kind={
                                                            row.target
                                                                .migration_kind
                                                        }
                                                    />
                                                )}
                                            </div>
                                        </div>
                                    );
                                })}
                        </div>
                    </CardContent>
                </Card>

                {(mode === 'emergency' || destructive) && (
                    <Card>
                        <CardHeader>
                            <CardTitle>
                                {mode === 'emergency'
                                    ? 'Why this cannot wait'
                                    : 'Maintenance window'}
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="grid gap-4">
                            {mode === 'emergency' && (
                                <div className="grid gap-2">
                                    <Textarea
                                        rows={3}
                                        value={form.data.reason}
                                        onChange={(e) =>
                                            form.setData(
                                                'reason',
                                                e.target.value,
                                            )
                                        }
                                        placeholder="What is broken for the client, and why testing is being skipped"
                                    />
                                    <InputError message={form.errors.reason} />
                                </div>
                            )}
                            {destructive && (
                                <div className="grid gap-2">
                                    <Label htmlFor="scheduled_for">
                                        Agreed start time
                                    </Label>
                                    <Input
                                        id="scheduled_for"
                                        type="datetime-local"
                                        className="w-64"
                                        value={form.data.scheduled_for}
                                        onChange={(e) =>
                                            form.setData(
                                                'scheduled_for',
                                                e.target.value,
                                            )
                                        }
                                    />
                                    <p className="text-xs text-muted-foreground">
                                        A migration drops or rewrites data. At
                                        this time a maintenance page goes up,
                                        the affected tables are backed up, and
                                        the release runs on its own.
                                    </p>
                                    <InputError
                                        message={form.errors.scheduled_for}
                                    />
                                </div>
                            )}
                        </CardContent>
                    </Card>
                )}

                <div className="flex items-center gap-3">
                    <Button
                        type="submit"
                        disabled={
                            form.processing ||
                            count === 0 ||
                            !stack.server_ready
                        }
                        variant={
                            mode === 'emergency' ? 'destructive' : 'default'
                        }
                    >
                        <Play className="size-4" />
                        {destructive ? 'Schedule' : 'Run'} ({count})
                    </Button>
                    {stack.pinned && (
                        <span className="text-xs text-muted-foreground">
                            Pinned: skipped by multi-client updates, but you can
                            still update it here.
                        </span>
                    )}
                </div>
            </form>
        </>
    );
}

StackPlan.layout = {
    breadcrumbs: [{ title: 'Versions', href: '/versions' }],
};
