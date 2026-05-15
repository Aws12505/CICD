import { Head, Link } from '@inertiajs/react';
import { Activity, CheckCircle2, GitBranch, XCircle } from 'lucide-react';
import { RunStatusBadge } from '@/components/run-status-badge';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { PlaceholderPattern } from '@/components/ui/placeholder-pattern';
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
    pipelines: {
        id: number;
        branch: string;
        enabled: boolean;
        last_status: string | null;
    }[];
}

interface Props {
    recentRuns: RecentRun[];
    projects: ProjectCard[];
    stats: {
        total_runs: number;
        running: number;
        success: number;
        failed: number;
    };
}

function fmtDuration(ms: number | null) {
    if (!ms) {
        return '—';
    }

    return ms < 1000 ? `${ms}ms` : `${Math.round(ms / 1000)}s`;
}

export default function Dashboard({ recentRuns, projects, stats }: Props) {
    const totalPipelines = projects.reduce(
        (sum, project) => sum + project.pipelines.length,
        0,
    );
    const cards = [
        {
            label: 'Total runs',
            value: stats.total_runs,
            icon: Activity,
            tone: 'text-foreground',
        },
        {
            label: 'Active',
            value: stats.running,
            icon: Activity,
            tone: 'text-sky-500',
        },
        {
            label: 'Succeeded',
            value: stats.success,
            icon: CheckCircle2,
            tone: 'text-emerald-500',
        },
        {
            label: 'Failed',
            value: stats.failed,
            icon: XCircle,
            tone: 'text-red-500',
        },
    ];

    return (
        <>
            <Head title="Dashboard" />
            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <Card className="relative overflow-hidden border border-sidebar-border/60 bg-card/80">
                    <PlaceholderPattern className="absolute inset-0 text-muted/30" />
                    <CardContent className="relative flex flex-col gap-6 p-6 lg:flex-row lg:items-end lg:justify-between">
                        <div className="space-y-4">
                            <div className="flex flex-wrap items-center gap-2 text-xs tracking-[0.2em] text-muted-foreground uppercase">
                                CI control center
                                <Badge variant="secondary">
                                    {stats.running} active
                                </Badge>
                                <Badge variant="secondary">
                                    {projects.length} projects
                                </Badge>
                                <Badge variant="secondary">
                                    {totalPipelines} pipelines
                                </Badge>
                            </div>
                            <div>
                                <h1 className="font-display text-3xl font-semibold sm:text-4xl">
                                    Deployment health, clear and fast.
                                </h1>
                                <p className="mt-2 max-w-xl text-sm text-muted-foreground">
                                    Track pipeline activity, spot failures
                                    quickly, and keep your releases flowing
                                    across every branch.
                                </p>
                            </div>
                            <div className="flex flex-wrap gap-2">
                                <Button asChild>
                                    <Link href="/projects/create">
                                        New project
                                    </Link>
                                </Button>
                                <Button variant="outline" asChild>
                                    <Link href="/runs">View runs</Link>
                                </Button>
                            </div>
                        </div>
                        <div className="grid w-full gap-3 sm:grid-cols-2 lg:w-[360px]">
                            {cards.map((c) => (
                                <Card
                                    key={c.label}
                                    className="border border-sidebar-border/70 bg-background/70 px-4 py-3 shadow-sm"
                                >
                                    <div className="flex items-center justify-between">
                                        <span className="text-xs text-muted-foreground">
                                            {c.label}
                                        </span>
                                        <c.icon
                                            className={`size-4 ${c.tone}`}
                                        />
                                    </div>
                                    <div className="mt-2 text-2xl font-semibold">
                                        {c.value}
                                    </div>
                                </Card>
                            ))}
                        </div>
                    </CardContent>
                </Card>

                <div className="grid gap-6 lg:grid-cols-3">
                    <Card className="lg:col-span-2">
                        <CardHeader className="border-b border-sidebar-border/70">
                            <div className="flex items-center justify-between">
                                <CardTitle>Recent runs</CardTitle>
                                <Button variant="ghost" size="sm" asChild>
                                    <Link href="/runs">All runs</Link>
                                </Button>
                            </div>
                        </CardHeader>
                        <div className="divide-y divide-sidebar-border/70 dark:divide-sidebar-border">
                            {recentRuns.length === 0 && (
                                <p className="p-6 text-sm text-muted-foreground">
                                    No runs yet. Push to a configured repo to
                                    trigger one.
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
                                            <span className="font-medium">
                                                {run.project_name}
                                            </span>
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
                                        <RunStatusBadge
                                            status={run.status as never}
                                        />
                                    </div>
                                </Link>
                            ))}
                        </div>
                    </Card>

                    <Card>
                        <CardHeader className="border-b border-sidebar-border/70">
                            <div className="flex items-center justify-between">
                                <CardTitle>Projects</CardTitle>
                                <Button variant="ghost" size="sm" asChild>
                                    <Link href="/projects">All projects</Link>
                                </Button>
                            </div>
                        </CardHeader>
                        <div className="divide-y divide-sidebar-border/70 dark:divide-sidebar-border">
                            {projects.length === 0 && (
                                <Link
                                    href="/projects/create"
                                    className="block p-6 text-sm text-muted-foreground hover:bg-muted/50"
                                >
                                    No projects yet - create one.
                                </Link>
                            )}
                            {projects.map((p) => (
                                <Link
                                    key={p.id}
                                    href={`/projects/${p.id}`}
                                    className="block p-4 hover:bg-muted/50"
                                >
                                    <div className="font-medium">{p.name}</div>
                                    <div className="text-xs text-muted-foreground">
                                        {p.repo_name}
                                    </div>
                                    <div className="mt-2 flex flex-wrap gap-1.5">
                                        {p.pipelines.map((pl) => (
                                            <span
                                                key={pl.id}
                                                className="flex items-center gap-1 rounded bg-muted px-1.5 py-0.5 text-[10px]"
                                            >
                                                {pl.branch}
                                                <RunStatusBadge
                                                    status={
                                                        pl.last_status as never
                                                    }
                                                />
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
