import { Head, router } from '@inertiajs/react';
import { useEffect } from 'react';
import { RunStatusBadge } from '@/components/run-status-badge';
import { Card } from '@/components/ui/card';
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
return '—';
}

    return ms < 1000 ? `${ms}ms` : `${Math.round(ms / 1000)}s`;
}

export default function RunsIndex({ runs }: { runs: Run[] }) {
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
                <h1 className="text-2xl font-bold">Pipeline runs</h1>

                <Card className="p-0">
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
                                    onClick={() => router.visit(`/runs/${run.id}`)}
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
                                        <RunStatusBadge status={run.status as never} />
                                    </TableCell>
                                    <TableCell
                                        className="max-w-[220px] truncate text-xs text-muted-foreground"
                                        title={run.commit_message ?? ''}
                                    >
                                        {run.commit_sha?.slice(0, 7) ?? '—'}{' '}
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
                                            ? new Date(run.created_at).toLocaleString()
                                            : '—'}
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
