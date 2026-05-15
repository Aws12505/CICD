import { Head, router } from '@inertiajs/react';
import { GitCommit, RotateCw, User } from 'lucide-react';
import { useEffect, useState } from 'react';
import { LogViewer } from '@/components/log-viewer';
import { RunStatusBadge } from '@/components/run-status-badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';

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
return '—';
}

    return ms < 1000 ? `${ms}ms` : `${Math.round(ms / 1000)}s`;
}

export default function RunShow({ run, steps, log }: Props) {
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
                <div className="flex items-start justify-between">
                    <div>
                        <div className="flex items-center gap-3">
                            <h1 className="text-2xl font-bold">Run #{run.id}</h1>
                            <RunStatusBadge status={liveStatus as never} />
                        </div>
                        <p className="mt-1 text-sm text-muted-foreground">
                            {run.project_name} ·{' '}
                            <span className="font-mono">{run.branch}</span>
                        </p>
                    </div>
                    <Button
                        variant="outline"
                        onClick={() => router.post(`/runs/${run.id}/rerun`)}
                    >
                        <RotateCw className="size-4" /> Rerun
                    </Button>
                </div>

                <div className="grid gap-4 sm:grid-cols-4">
                    <Card className="p-4">
                        <div className="flex items-center gap-2 text-xs text-muted-foreground">
                            <GitCommit className="size-3" /> Commit
                        </div>
                        <div className="mt-1 truncate font-mono text-sm">
                            {run.commit_sha?.slice(0, 10) ?? '—'}
                        </div>
                    </Card>
                    <Card className="p-4">
                        <div className="flex items-center gap-2 text-xs text-muted-foreground">
                            <User className="size-3" /> Pusher
                        </div>
                        <div className="mt-1 truncate text-sm">{run.pusher ?? '—'}</div>
                    </Card>
                    <Card className="p-4">
                        <div className="text-xs text-muted-foreground">Trigger</div>
                        <div className="mt-1 text-sm">{run.triggered_by}</div>
                    </Card>
                    <Card className="p-4">
                        <div className="text-xs text-muted-foreground">Duration</div>
                        <div className="mt-1 text-sm">{fmtDuration(run.duration_ms)}</div>
                    </Card>
                </div>

                {run.commit_message && (
                    <Card className="p-4">
                        <div className="text-xs text-muted-foreground">Commit message</div>
                        <p className="mt-1 text-sm whitespace-pre-wrap">
                            {run.commit_message}
                        </p>
                    </Card>
                )}

                <Card className="p-0">
                    <div className="border-b border-sidebar-border/70 p-4 dark:border-sidebar-border">
                        <h2 className="font-semibold">Steps</h2>
                    </div>
                    <ul className="divide-y divide-sidebar-border/70 dark:divide-sidebar-border">
                        {liveSteps.length === 0 && (
                            <li className="p-4 text-sm text-muted-foreground">
                                Steps will appear when the run starts.
                            </li>
                        )}
                        {liveSteps.map((step) => (
                            <li
                                key={step.id ?? step.step_index}
                                className="flex items-center justify-between p-4"
                            >
                                <div className="min-w-0">
                                    <div className="font-medium">{step.name}</div>
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
                                    <RunStatusBadge status={step.status as never} />
                                </div>
                            </li>
                        ))}
                    </ul>
                </Card>

                <div>
                    <h2 className="mb-3 font-semibold">
                        Logs{' '}
                        {isLive && (
                            <span className="ml-2 text-xs font-normal text-blue-500">
                                ● live
                            </span>
                        )}
                    </h2>
                    <LogViewer runId={run.id} initialLog={log} live={isLive} />
                </div>
            </div>
        </>
    );
}

RunShow.layout = {
    breadcrumbs: [{ title: 'Runs', href: '/runs' }],
};
