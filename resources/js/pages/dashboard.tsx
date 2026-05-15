import { Head, Link } from '@inertiajs/react';
import { Activity, CheckCircle2, GitBranch, XCircle } from 'lucide-react';
import { RunStatusBadge } from '@/components/run-status-badge';
import { Card } from '@/components/ui/card';
import { dashboard } from '@/routes';

interface RecentRun {
    id: number;
    project_name: string;
    branch: string;
    status: string;
    commit_sha: string | null;
    commit_message: string | null;
    pusher: string | null;
    duration_ms: number | null;
    created_at: string | null;
}

interface ProjectCard {
    id: number;
    name: string;
    repo_name: string;
    pipelines: { id: number; branch: string; enabled: boolean; last_status: string | null }[];
}

interface Props {
    recentRuns: RecentRun[];
    projects: ProjectCard[];
    stats: { total_runs: number; running: number; success: number; failed: number };
}

function fmtDuration(ms: number | null) {
    if (!ms) {
return '—';
}

    return ms < 1000 ? `${ms}ms` : `${Math.round(ms / 1000)}s`;
}

export default function Dashboard({ recentRuns, projects, stats }: Props) {
    const cards = [
        { label: 'Total runs', value: stats.total_runs, icon: Activity, color: 'text-foreground' },
        { label: 'Active', value: stats.running, icon: Activity, color: 'text-blue-500' },
        { label: 'Succeeded', value: stats.success, icon: CheckCircle2, color: 'text-emerald-500' },
        { label: 'Failed', value: stats.failed, icon: XCircle, color: 'text-red-500' },
    ];

    return (
        <>
            <Head title="Dashboard" />
            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    {cards.map((c) => (
                        <Card key={c.label} className="p-5">
                            <div className="flex items-center justify-between">
                                <span className="text-sm text-muted-foreground">{c.label}</span>
                                <c.icon className={`size-4 ${c.color}`} />
                            </div>
                            <div className="mt-2 text-3xl font-bold">{c.value}</div>
                        </Card>
                    ))}
                </div>

                <div className="grid gap-6 lg:grid-cols-3">
                    <Card className="lg:col-span-2 p-0">
                        <div className="border-b border-sidebar-border/70 p-4 dark:border-sidebar-border">
                            <h2 className="font-semibold">Recent runs</h2>
                        </div>
                        <div className="divide-y divide-sidebar-border/70 dark:divide-sidebar-border">
                            {recentRuns.length === 0 && (
                                <p className="p-6 text-sm text-muted-foreground">
                                    No runs yet. Push to a configured repo to trigger one.
                                </p>
                            )}
                            {recentRuns.map((run) => (
                                <Link
                                    key={run.id}
                                    href={`/runs/${run.id}`}
                                    className="flex items-center justify-between p-4 hover:bg-muted/50"
                                >
                                    <div className="min-w-0">
                                        <div className="flex items-center gap-2">
                                            <span className="font-medium">{run.project_name}</span>
                                            <span className="flex items-center gap-1 text-xs text-muted-foreground">
                                                <GitBranch className="size-3" />
                                                {run.branch}
                                            </span>
                                        </div>
                                        <p className="truncate text-xs text-muted-foreground">
                                            {run.commit_message ?? '—'}
                                        </p>
                                    </div>
                                    <div className="flex items-center gap-4">
                                        <span className="text-xs text-muted-foreground">
                                            {fmtDuration(run.duration_ms)}
                                        </span>
                                        <RunStatusBadge status={run.status as never} />
                                    </div>
                                </Link>
                            ))}
                        </div>
                    </Card>

                    <Card className="p-0">
                        <div className="border-b border-sidebar-border/70 p-4 dark:border-sidebar-border">
                            <h2 className="font-semibold">Projects</h2>
                        </div>
                        <div className="divide-y divide-sidebar-border/70 dark:divide-sidebar-border">
                            {projects.length === 0 && (
                                <Link
                                    href="/projects/create"
                                    className="block p-6 text-sm text-muted-foreground hover:bg-muted/50"
                                >
                                    No projects yet — create one →
                                </Link>
                            )}
                            {projects.map((p) => (
                                <Link
                                    key={p.id}
                                    href={`/projects/${p.id}`}
                                    className="block p-4 hover:bg-muted/50"
                                >
                                    <div className="font-medium">{p.name}</div>
                                    <div className="text-xs text-muted-foreground">{p.repo_name}</div>
                                    <div className="mt-2 flex flex-wrap gap-1.5">
                                        {p.pipelines.map((pl) => (
                                            <span
                                                key={pl.id}
                                                className="flex items-center gap-1 rounded bg-muted px-1.5 py-0.5 text-[10px]"
                                            >
                                                {pl.branch}
                                                <RunStatusBadge status={pl.last_status as never} />
                                            </span>
                                        ))}
                                    </div>
                                </Link>
                            ))}
                        </div>
                    </Card>
                </div>
            </div>
        </>
    );
}

Dashboard.layout = {
    breadcrumbs: [{ title: 'Dashboard', href: dashboard() }],
};
