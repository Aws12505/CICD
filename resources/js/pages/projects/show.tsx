import { Head, Link } from '@inertiajs/react';
import { GitBranch, Pencil, Plus, Settings2 } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';

interface Project {
    id: number;
    name: string;
    repo_name: string;
    description: string | null;
}

interface PipelineRow {
    id: number;
    branch: string;
    enabled: boolean;
    steps_count: number;
    runs_count: number;
}

export default function ProjectShow({
    project,
    pipelines,
}: {
    project: Project;
    pipelines: PipelineRow[];
}) {
    return (
        <>
            <Head title={project.name} />
            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <div className="flex items-start justify-between">
                    <div>
                        <h1 className="text-2xl font-bold">{project.name}</h1>
                        <p className="font-mono text-sm text-muted-foreground">
                            {project.repo_name}
                        </p>
                        {project.description && (
                            <p className="mt-2 max-w-2xl text-sm text-muted-foreground">
                                {project.description}
                            </p>
                        )}
                    </div>
                    <Button asChild variant="outline">
                        <Link href={`/projects/${project.id}/edit`}>
                            <Settings2 className="size-4" /> Settings
                        </Link>
                    </Button>
                </div>

                <Card className="p-0">
                    <div className="flex items-center justify-between border-b border-sidebar-border/70 p-4 dark:border-sidebar-border">
                        <h2 className="font-semibold">Pipelines (branches)</h2>
                        <Button asChild size="sm">
                            <Link href={`/projects/${project.id}/pipelines/create`}>
                                <Plus className="size-4" /> Add pipeline
                            </Link>
                        </Button>
                    </div>

                    {pipelines.length === 0 ? (
                        <p className="p-6 text-sm text-muted-foreground">
                            No pipelines yet. Add one per branch you want to auto-deploy.
                        </p>
                    ) : (
                        <div className="divide-y divide-sidebar-border/70 dark:divide-sidebar-border">
                            {pipelines.map((p) => (
                                <div
                                    key={p.id}
                                    className="flex items-center justify-between p-4"
                                >
                                    <div className="flex items-center gap-3">
                                        <GitBranch className="size-4 text-muted-foreground" />
                                        <div>
                                            <div className="flex items-center gap-2 font-medium">
                                                {p.branch}
                                                {!p.enabled && (
                                                    <span className="rounded bg-muted px-1.5 py-0.5 text-[10px] text-muted-foreground">
                                                        disabled
                                                    </span>
                                                )}
                                            </div>
                                            <div className="text-xs text-muted-foreground">
                                                {p.steps_count} steps · {p.runs_count} runs
                                            </div>
                                        </div>
                                    </div>
                                    <Button asChild variant="ghost" size="sm">
                                        <Link href={`/pipelines/${p.id}/edit`}>
                                            <Pencil className="size-4" /> Edit
                                        </Link>
                                    </Button>
                                </div>
                            ))}
                        </div>
                    )}
                </Card>
            </div>
        </>
    );
}

ProjectShow.layout = {
    breadcrumbs: [{ title: 'Projects', href: '/projects' }],
};
