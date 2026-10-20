import { Head, Link } from '@inertiajs/react';
import { ArrowRight, BookOpen, CheckCircle2 } from 'lucide-react';
import { FleetView } from '@/components/fleet-view';
import type { FleetServer } from '@/components/fleet-view';
import { SetupSteps, StepAction } from '@/components/setup-steps';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { useAbilities } from '@/hooks/use-abilities';
import { dashboard } from '@/routes';
import type { GuideClient, GuideStep } from '@/types/guide';

const RECENT_RUNS_SHOWN = 5;

/** One sentence on where a client stands. */
function clientSummary(client: GuideClient): string {
    if (client.next) {
        return `Next: ${client.next.title.toLowerCase()} — ${client.next.detail}`;
    }

    if (client.behind > 0) {
        return 'Newer builds are waiting. Promote from Versions when the client is ready.';
    }

    return 'Up to date.';
}

interface RecentRun {
    id: number;
    project_name: string;
    branch: string;
    status: string;
    created_at: string | null;
}

interface Props {
    recentRuns: RecentRun[];
    projects: { id: number }[];
    guide: {
        next: GuideStep | null;
        setup: GuideStep[];
        clients: GuideClient[];
    };
    fleet: FleetServer[] | null;
}

export default function Dashboard({
    guide,
    fleet,
    projects,
    recentRuns,
}: Props) {
    const { manage: canManage } = useAbilities();
    const setupDone = guide.setup.every((s) => s.status === 'done');

    return (
        <>
            <Head title="Dashboard" />
            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                {/* The one thing to do next. */}
                <Card className="border-primary/30 bg-primary/5">
                    <CardContent className="flex flex-col gap-4 p-6 lg:flex-row lg:items-center lg:justify-between">
                        <div className="min-w-0">
                            <p className="text-xs tracking-[0.2em] text-muted-foreground uppercase">
                                {guide.next
                                    ? `Next step · ${guide.next.scope}`
                                    : 'All set'}
                            </p>
                            <h1 className="font-display mt-1 text-3xl font-semibold">
                                {guide.next
                                    ? guide.next.title
                                    : 'Everything is set up and current.'}
                            </h1>
                            <p className="mt-2 max-w-2xl text-sm text-muted-foreground">
                                {guide.next
                                    ? guide.next.detail
                                    : 'When a developer merges, a build appears under Builds. Update a client from Versions when you want them to have it.'}
                            </p>
                        </div>
                        <div className="flex flex-wrap gap-2">
                            {guide.next && canManage && (
                                <StepAction
                                    step={guide.next}
                                    size="lg"
                                    variant="default"
                                />
                            )}
                            {!guide.next && (
                                <Button asChild size="lg">
                                    <Link href="/versions">
                                        Open Versions{' '}
                                        <ArrowRight className="size-4" />
                                    </Link>
                                </Button>
                            )}
                            <Button asChild variant="outline" size="lg">
                                <Link href="/guide">
                                    <BookOpen className="size-4" /> How this
                                    works
                                </Link>
                            </Button>
                        </div>
                    </CardContent>
                </Card>

                {!setupDone && (
                    <Card>
                        <CardHeader className="border-b border-sidebar-border/70">
                            <CardTitle>Setting up the control plane</CardTitle>
                        </CardHeader>
                        <CardContent className="p-0">
                            <SetupSteps
                                steps={guide.setup}
                                canAct={canManage}
                            />
                        </CardContent>
                    </Card>
                )}

                <Card>
                    <CardHeader className="flex-row items-center justify-between space-y-0 border-b border-sidebar-border/70">
                        <CardTitle>Clients</CardTitle>
                        {canManage && (
                            <Button asChild size="sm" variant="outline">
                                <Link href="/clients/create">Add a client</Link>
                            </Button>
                        )}
                    </CardHeader>
                    <CardContent className="p-0">
                        {guide.clients.length === 0 ? (
                            <p className="p-4 text-sm text-muted-foreground">
                                No clients yet. Finish the setup above, then add
                                the first one.
                            </p>
                        ) : (
                            <div className="divide-y divide-sidebar-border/70 dark:divide-sidebar-border">
                                {guide.clients.map((client) => (
                                    <div
                                        key={client.id}
                                        className="flex flex-wrap items-center justify-between gap-3 p-4"
                                    >
                                        <div className="min-w-0">
                                            <div className="flex flex-wrap items-center gap-2">
                                                <Link
                                                    href={`/clients/${client.slug}`}
                                                    className="font-medium hover:underline"
                                                >
                                                    {client.name}
                                                </Link>
                                                <span className="font-mono text-xs text-muted-foreground">
                                                    {client.domain}
                                                </span>
                                                {client.done ===
                                                client.total ? (
                                                    <Badge variant="secondary">
                                                        <CheckCircle2 className="mr-1 size-3" />
                                                        live
                                                    </Badge>
                                                ) : (
                                                    <Badge variant="outline">
                                                        {client.done} of{' '}
                                                        {client.total} steps
                                                    </Badge>
                                                )}
                                                {client.behind > 0 && (
                                                    <Badge
                                                        variant="outline"
                                                        className="border-amber-500/50 text-amber-700 dark:text-amber-400"
                                                    >
                                                        {client.behind}{' '}
                                                        {client.behind === 1
                                                            ? 'service'
                                                            : 'services'}{' '}
                                                        behind on live
                                                    </Badge>
                                                )}
                                            </div>
                                            <p className="mt-1 text-sm text-muted-foreground">
                                                {clientSummary(client)}
                                            </p>
                                        </div>
                                        {canManage && client.next && (
                                            <StepAction step={client.next} />
                                        )}
                                        {canManage &&
                                            !client.next &&
                                            client.behind > 0 && (
                                                <Button asChild size="sm">
                                                    <Link href="/versions">
                                                        Open Versions{' '}
                                                        <ArrowRight className="size-4" />
                                                    </Link>
                                                </Button>
                                            )}
                                    </div>
                                ))}
                            </div>
                        )}
                    </CardContent>
                </Card>

                {fleet && <FleetView servers={fleet} />}

                {projects.length > 0 && (
                    <Card>
                        <CardHeader className="flex-row items-center justify-between space-y-0">
                            <CardTitle>Old pipelines</CardTitle>
                            <Button asChild size="sm" variant="ghost">
                                <Link href="/runs">All runs</Link>
                            </Button>
                        </CardHeader>
                        <CardContent className="grid gap-1 text-sm">
                            <p className="text-xs text-muted-foreground">
                                The previous deploy tool, kept until every
                                client is on the control plane.
                            </p>
                            {recentRuns
                                .slice(0, RECENT_RUNS_SHOWN)
                                .map((run) => (
                                    <Link
                                        key={run.id}
                                        href={`/runs/${run.id}`}
                                        className="flex items-center justify-between gap-2 hover:underline"
                                    >
                                        <span>
                                            {run.project_name} · {run.branch}
                                        </span>
                                        <Badge variant="outline">
                                            {run.status}
                                        </Badge>
                                    </Link>
                                ))}
                        </CardContent>
                    </Card>
                )}
            </div>
        </>
    );
}

Dashboard.layout = {
    breadcrumbs: [{ title: 'Dashboard', href: dashboard() }],
};
