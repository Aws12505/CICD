import { Head, router } from '@inertiajs/react';
import { GitCommit, RotateCw, User } from 'lucide-react';
import { useEffect, useState } from 'react';
import { LogViewer } from '@/components/log-viewer';
import { RunStatusBadge } from '@/components/run-status-badge';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { useAbilities } from '@/hooks/use-abilities';

interface Run {
    id: number;
    project_name: string;
    repo_name: string;
    branch: string;
    status: string;
    commit_sha: string | null;
    commit_message: string | null;
    pusher: string | null;
    triggered_by: string;
    started_at: string | null;
    finished_at: string | null;
    duration_ms: number | null;
}

interface Step {
    id: number;
    step_index: number;
    name: string;
    command: string;
    status: string;
    exit_code: number | null;
    duration_ms: number | null;
}

interface Props {
    run: Run;
    steps: Step[];
    log: string;
}

function fmtDuration(ms: number | null) {
    if (!ms) {
        return '-';
    }

    return ms < 1000 ? `${ms}ms` : `${Math.round(ms / 1000)}s`;
}

export default function RunShow({ run, steps, log }: Props) {
    const { manage: canManage } = useAbilities();

    const [liveStatus, setLiveStatus] = useState(run.status);
    const [liveSteps, setLiveSteps] = useState(steps);
    const [syncedRun, setSyncedRun] = useState(run.id);
    const [syncedStatus, setSyncedStatus] = useState(run.status);

    // Re-sync from server props when Inertia swaps in fresh data
    // (different run, or a reload after completion) — without an effect.
    if (syncedRun !== run.id || syncedStatus !== run.status) {
        setSyncedRun(run.id);
        setSyncedStatus(run.status);
        setLiveStatus(run.status);
        setLiveSteps(steps);
    }

    const isLive = !['success', 'failed', 'cancelled'].includes(liveStatus);

    useEffect(() => {
        const channel = window.Echo.private(`pipeline.run.${run.id}`);
        channel.listen(
            '.PipelineRunStatusChanged',
            (e: { run: { status: string }; steps: Step[] }) => {
                setLiveStatus(e.run.status);
                setLiveSteps(e.steps);

                if (['success', 'failed', 'cancelled'].includes(e.run.status)) {
                    router.reload({ only: ['run', 'steps', 'log'] });
                }
            },
        );

        return () => {
            window.Echo.leave(`pipeline.run.${run.id}`);
        };
    }, [run.id]);

    return (
        <>
            <Head title={`Run #${run.id}`} />
            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <div className="flex items-center gap-3">
                            <h1 className="font-display text-3xl font-semibold">
                                Run #{run.id}
                            </h1>
                            <RunStatusBadge status={liveStatus as never} />
                        </div>
                        <p className="mt-1 text-sm text-muted-foreground">
                            {run.project_name} ·{' '}
                            <span className="font-mono">{run.branch}</span>
                        </p>
                        <div className="mt-2 flex flex-wrap gap-2">
                            <Badge variant="secondary">
                                {run.triggered_by}
                            </Badge>
                            {isLive && <Badge variant="secondary">live</Badge>}
                        </div>
                    </div>
                    {canManage && (
                        <Button
                            variant="outline"
                            onClick={() => router.post(`/runs/${run.id}/rerun`)}
                        >
                            <RotateCw className="size-4" /> Rerun
                        </Button>
                    )}
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>Run summary</CardTitle>
                    </CardHeader>
                    <CardContent className="grid gap-4 sm:grid-cols-4">
                        <div className="rounded-xl border border-sidebar-border/60 bg-background/60 p-4">
                            <div className="flex items-center gap-2 text-xs text-muted-foreground">
                                <GitCommit className="size-3" /> Commit
                            </div>
                            <div className="mt-1 truncate font-mono text-sm">
                                {run.commit_sha?.slice(0, 10) ?? '-'}
                            </div>
                        </div>
                        <div className="rounded-xl border border-sidebar-border/60 bg-background/60 p-4">
                            <div className="flex items-center gap-2 text-xs text-muted-foreground">
                                <User className="size-3" /> Pusher
                            </div>
                            <div className="mt-1 truncate text-sm">
                                {run.pusher ?? '-'}
                            </div>
                        </div>
                        <div className="rounded-xl border border-sidebar-border/60 bg-background/60 p-4">
                            <div className="text-xs text-muted-foreground">
                                Trigger
                            </div>
                            <div className="mt-1 text-sm">
                                {run.triggered_by}
                            </div>
                        </div>
                        <div className="rounded-xl border border-sidebar-border/60 bg-background/60 p-4">
                            <div className="text-xs text-muted-foreground">
                                Duration
                            </div>
                            <div className="mt-1 text-sm">
                                {fmtDuration(run.duration_ms)}
                            </div>
                        </div>
                    </CardContent>
                </Card>

                {run.commit_message && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Commit message</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="text-sm whitespace-pre-wrap">
                                {run.commit_message}
                            </p>
                        </CardContent>
                    </Card>
                )}

                <Card>
                    <CardHeader className="border-b border-sidebar-border/70">
                        <CardTitle>Steps</CardTitle>
                    </CardHeader>
                    <ul className="divide-y divide-sidebar-border/70 dark:divide-sidebar-border">
                        {liveSteps.length === 0 && (
                            <li className="p-4 text-sm text-muted-foreground">
                                Steps will appear when the run starts.
                            </li>
                        )}
                        {liveSteps.map((step) => (
                            <li
                                key={step.id ?? step.step_index}
                                className="flex flex-wrap items-center justify-between gap-3 p-4"
                            >
                                <div className="min-w-0">
                                    <div className="font-medium">
                                        {step.name}
                                    </div>
                                    {step.command && (
                                        <code className="text-xs text-muted-foreground">
                                            {step.command}
                                        </code>
                                    )}
                                </div>
                                <div className="flex items-center gap-3">
                                    {step.exit_code !== null && (
                                        <span className="text-xs text-muted-foreground">
                                            exit {step.exit_code}
                                        </span>
                                    )}
                                    <span className="text-xs text-muted-foreground">
                                        {fmtDuration(step.duration_ms)}
                                    </span>
                                    <RunStatusBadge
                                        status={step.status as never}
                                    />
                                </div>
                            </li>
                        ))}
                    </ul>
                </Card>

                <div>
                    <div className="mb-3 flex items-center gap-2">
                        <h2 className="font-semibold">Logs</h2>
                        {isLive && <Badge variant="secondary">live</Badge>}
                    </div>
                    <LogViewer runId={run.id} initialLog={log} live={isLive} />
                </div>
            </div>
        </>
    );
}

RunShow.layout = {
    breadcrumbs: [{ title: 'Runs', href: '/runs' }],
};
