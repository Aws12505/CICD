import { Head, Link } from '@inertiajs/react';
import { Boxes, Plus } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';
import { PlaceholderPattern } from '@/components/ui/placeholder-pattern';

interface Project {
    id: number;
    name: string;
    repo_name: string;
    repo_full_name: string | null;
    description: string | null;
    pipelines_count: number;
}

export default function ProjectsIndex({ projects }: { projects: Project[] }) {
    const totalPipelines = projects.reduce(
        (sum, project) => sum + project.pipelines_count,
        0,
    );

    return (
        <>
            <Head title="Projects" />
            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <Card className="relative overflow-hidden border border-sidebar-border/60 bg-card/80">
                    <PlaceholderPattern className="absolute inset-0 text-muted/25" />
                    <CardContent className="relative flex flex-col gap-4 p-6 lg:flex-row lg:items-center lg:justify-between">
                        <div>
                            <p className="text-xs tracking-[0.2em] text-muted-foreground uppercase">
                                Repositories
                            </p>
                            <h1 className="font-display text-3xl font-semibold">
                                Projects
                            </h1>
                            <p className="mt-2 text-sm text-muted-foreground">
                                Each project maps to one GitHub repository and
                                groups branch-based pipelines.
                            </p>
                            <div className="mt-3 flex flex-wrap gap-2">
                                <Badge variant="secondary">
                                    {projects.length} projects
                                </Badge>
                                <Badge variant="secondary">
                                    {totalPipelines} pipelines
                                </Badge>
                            </div>
                        </div>
                        <Button asChild>
                            <Link href="/projects/create">
                                <Plus className="size-4" /> New project
                            </Link>
                        </Button>
                    </CardContent>
                </Card>

                {projects.length === 0 ? (
                    <Card className="flex flex-col items-center gap-3 p-12 text-center">
                        <Boxes className="size-10 text-muted-foreground" />
                        <p className="text-sm text-muted-foreground">
                            No projects yet. Create your first one to start
                            receiving webhooks.
                        </p>
                        <Button asChild>
                            <Link href="/projects/create">
                                <Plus className="size-4" /> New project
                            </Link>
                        </Button>
                    </Card>
                ) : (
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        {projects.map((p) => (
                            <Link key={p.id} href={`/projects/${p.id}`}>
                                <Card className="group h-full border border-sidebar-border/60 bg-card/80 p-5 transition-all hover:-translate-y-0.5 hover:border-sidebar-border hover:shadow-md">
                                    <div className="flex items-center justify-between">
                                        <div className="font-semibold">
                                            {p.name}
                                        </div>
                                        <Badge variant="secondary">
                                            {p.pipelines_count} pipelines
                                        </Badge>
                                    </div>
                                    <div className="mt-1 font-mono text-xs text-muted-foreground">
                                        {p.repo_full_name ?? p.repo_name}
                                    </div>
                                    {p.description && (
                                        <p className="mt-2 line-clamp-2 text-sm text-muted-foreground">
                                            {p.description}
                                        </p>
                                    )}
                                    <div className="mt-4 text-xs text-muted-foreground">
                                        Click to manage pipelines and secrets.
                                    </div>
                                </Card>
                            </Link>
                        ))}
                    </div>
                )}
            </div>
        </>
    );
}

ProjectsIndex.layout = {
    breadcrumbs: [{ title: 'Projects', href: '/projects' }],
};
