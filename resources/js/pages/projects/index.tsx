import { Head, Link } from '@inertiajs/react';
import { Boxes, Plus } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';

interface Project {
    id: number;
    name: string;
    repo_name: string;
    description: string | null;
    pipelines_count: number;
}

export default function ProjectsIndex({ projects }: { projects: Project[] }) {
    return (
        <>
            <Head title="Projects" />
            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-2xl font-bold">Projects</h1>
                        <p className="text-sm text-muted-foreground">
                            Each project maps to one GitHub repository.
                        </p>
                    </div>
                    <Button asChild>
                        <Link href="/projects/create">
                            <Plus className="size-4" /> New project
                        </Link>
                    </Button>
                </div>

                {projects.length === 0 ? (
                    <Card className="flex flex-col items-center gap-3 p-12 text-center">
                        <Boxes className="size-10 text-muted-foreground" />
                        <p className="text-sm text-muted-foreground">
                            No projects yet. Create your first one to start receiving webhooks.
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
                                <Card className="h-full p-5 transition-colors hover:bg-muted/50">
                                    <div className="font-semibold">{p.name}</div>
                                    <div className="font-mono text-xs text-muted-foreground">
                                        {p.repo_name}
                                    </div>
                                    {p.description && (
                                        <p className="mt-2 line-clamp-2 text-sm text-muted-foreground">
                                            {p.description}
                                        </p>
                                    )}
                                    <div className="mt-4 text-xs text-muted-foreground">
                                        {p.pipelines_count} pipeline
                                        {p.pipelines_count === 1 ? '' : 's'}
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
