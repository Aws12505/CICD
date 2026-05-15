import { Head, router } from '@inertiajs/react';
import { useEffect } from 'react';
import { RunStatusBadge } from '@/components/run-status-badge';
import { Badge } from '@/components/ui/badge';
import { Card, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';

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
    duration_ms: number | null;
    created_at: string | null;
}

function fmtDuration(ms: number | null) {
    if (!ms) {
        return '-';
    }

    return ms < 1000 ? `${ms}ms` : `${Math.round(ms / 1000)}s`;
}

export default function RunsIndex({ runs }: { runs: Run[] }) {
    const counts = runs.reduce(
        (acc, run) => {
            acc.total += 1;
            acc[run.status] = (acc[run.status] ?? 0) + 1;
            return acc;
        },
        { total: 0 } as Record<string, number>,
    );
    const active = (counts.queued ?? 0) + (counts.running ?? 0);

    useEffect(() => {
        const channel = window.Echo.private('pipeline.runs');
        channel.listen('.PipelineRunStatusChanged', () => {
            router.reload({ only: ['runs'] });
        });

        return () => {
            window.Echo.leave('pipeline.runs');
        };
    }, []);

    return (
        <>
            <Head title="Runs" />
            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <div className="flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <h1 className="font-display text-3xl font-semibold">
                            Pipeline runs
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            Monitor every deployment event across projects and
                            branches.
                        </p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <Badge variant="secondary">{counts.total} total</Badge>
                        <Badge variant="secondary">{active} active</Badge>
                        <Badge variant="secondary">
                            {counts.success ?? 0} success
                        </Badge>
                        <Badge variant="secondary">
                            {counts.failed ?? 0} failed
                        </Badge>
                    </div>
                </div>

                <Card>
                    <CardHeader className="border-b border-sidebar-border/70">
                        <CardTitle>Latest activity</CardTitle>
                    </CardHeader>
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>#</TableHead>
                                <TableHead>Project</TableHead>
                                <TableHead>Branch</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead>Commit</TableHead>
                                <TableHead>Trigger</TableHead>
                                <TableHead>Duration</TableHead>
                                <TableHead>When</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {runs.length === 0 && (
                                <TableRow>
                                    <TableCell
                                        colSpan={8}
                                        className="py-10 text-center text-muted-foreground"
                                    >
                                        No runs yet.
                                    </TableCell>
                                </TableRow>
                            )}
                            {runs.map((run) => (
                                <TableRow
                                    key={run.id}
                                    className="cursor-pointer"
                                    onClick={() =>
                                        router.visit(`/runs/${run.id}`)
                                    }
                                >
                                    <TableCell className="text-muted-foreground">
                                        {run.id}
                                    </TableCell>
                                    <TableCell className="font-medium">
                                        {run.project_name}
                                    </TableCell>
                                    <TableCell className="font-mono text-xs">
                                        {run.branch}
                                    </TableCell>
                                    <TableCell>
                                        <RunStatusBadge
                                            status={run.status as never}
                                        />
                                    </TableCell>
                                    <TableCell
                                        className="max-w-[220px] truncate text-xs text-muted-foreground"
                                        title={run.commit_message ?? ''}
                                    >
                                        {run.commit_sha?.slice(0, 7) ?? '-'}{' '}
                                        {run.commit_message ?? ''}
                                    </TableCell>
                                    <TableCell className="text-xs text-muted-foreground">
                                        {run.triggered_by}
                                    </TableCell>
                                    <TableCell className="text-xs text-muted-foreground">
                                        {fmtDuration(run.duration_ms)}
                                    </TableCell>
                                    <TableCell className="text-xs text-muted-foreground">
                                        {run.created_at
                                            ? new Date(
                                                  run.created_at,
                                              ).toLocaleString()
                                            : '-'}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </Card>
            </div>
        </>
    );
}

RunsIndex.layout = {
    breadcrumbs: [{ title: 'Runs', href: '/runs' }],
};
