import { Head, Link } from '@inertiajs/react';
import { Copy, GitBranch, Pencil, Plus, Settings2 } from 'lucide-react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { PlaceholderPattern } from '@/components/ui/placeholder-pattern';

interface Project {
    id: number;
    name: string;
    repo_owner: string | null;
    repo_name: string;
    repo_full_name: string | null;
    description: string | null;
    github_secret: string;
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
    const webhookUrl =
        typeof window === 'undefined'
            ? '/webhook/github'
            : `${window.location.origin}/webhook/github`;
    const fullName = project.repo_full_name || project.repo_name;

    const copyValue = async (value: string, label: string) => {
        try {
            await navigator.clipboard.writeText(value);
            toast.success(`${label} copied`);
        } catch {
            toast.error('Copy failed');
        }
    };

    return (
        <>
            <Head title={project.name} />
            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <Card className="relative overflow-hidden border border-sidebar-border/60 bg-card/80">
                    <PlaceholderPattern className="absolute inset-0 text-muted/25" />
                    <CardContent className="relative flex flex-col gap-4 p-6 lg:flex-row lg:items-center lg:justify-between">
                        <div>
                            <p className="text-xs tracking-[0.2em] text-muted-foreground uppercase">
                                Repository
                            </p>
                            <h1 className="font-display text-3xl font-semibold">
                                {project.name}
                            </h1>
                            <p className="mt-1 font-mono text-sm text-muted-foreground">
                                {fullName}
                            </p>
                            {project.description && (
                                <p className="mt-2 max-w-2xl text-sm text-muted-foreground">
                                    {project.description}
                                </p>
                            )}
                        </div>
                        <div className="flex flex-wrap items-center gap-2">
                            <Badge variant="secondary">
                                {pipelines.length} pipelines
                            </Badge>
                            <Button asChild variant="outline">
                                <Link href={`/projects/${project.id}/edit`}>
                                    <Settings2 className="size-4" /> Settings
                                </Link>
                            </Button>
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader className="border-b border-sidebar-border/70">
                        <div className="flex items-center justify-between">
                            <CardTitle>Webhook configuration</CardTitle>
                            <Badge variant="secondary">push</Badge>
                        </div>
                    </CardHeader>
                    <CardContent className="grid gap-4">
                        <div className="grid gap-2">
                            <p className="text-xs text-muted-foreground">
                                Payload URL
                            </p>
                            <div className="flex gap-2">
                                <input
                                    value={webhookUrl}
                                    readOnly
                                    className="h-9 w-full rounded-md border border-input bg-background px-3 font-mono text-xs"
                                />
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="icon"
                                    onClick={() =>
                                        copyValue(webhookUrl, 'Webhook URL')
                                    }
                                >
                                    <Copy className="size-4" />
                                </Button>
                            </div>
                        </div>
                        <div className="grid gap-2">
                            <p className="text-xs text-muted-foreground">
                                Secret
                            </p>
                            <div className="flex gap-2">
                                <input
                                    value={project.github_secret}
                                    readOnly
                                    className="h-9 w-full rounded-md border border-input bg-background px-3 font-mono text-xs"
                                />
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="icon"
                                    onClick={() =>
                                        copyValue(
                                            project.github_secret,
                                            'Webhook secret',
                                        )
                                    }
                                >
                                    <Copy className="size-4" />
                                </Button>
                            </div>
                        </div>
                        <p className="text-xs text-muted-foreground">
                            Use the same secret for every branch pipeline in
                            this project.
                        </p>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader className="border-b border-sidebar-border/70">
                        <div className="flex items-center justify-between">
                            <CardTitle>Pipelines (branches)</CardTitle>
                            <Button asChild size="sm">
                                <Link
                                    href={`/projects/${project.id}/pipelines/create`}
                                >
                                    <Plus className="size-4" /> Add pipeline
                                </Link>
                            </Button>
                        </div>
                    </CardHeader>

                    {pipelines.length === 0 ? (
                        <p className="p-6 text-sm text-muted-foreground">
                            No pipelines yet. Add one per branch you want to
                            auto-deploy.
                        </p>
                    ) : (
                        <div className="divide-y divide-sidebar-border/70 dark:divide-sidebar-border">
                            {pipelines.map((p) => (
                                <div
                                    key={p.id}
                                    className="flex flex-wrap items-center justify-between gap-3 p-4"
                                >
                                    <div className="flex items-center gap-3">
                                        <GitBranch className="size-4 text-muted-foreground" />
                                        <div>
                                            <div className="flex items-center gap-2 font-medium">
                                                {p.branch}
                                                {!p.enabled && (
                                                    <Badge variant="secondary">
                                                        disabled
                                                    </Badge>
                                                )}
                                            </div>
                                            <div className="text-xs text-muted-foreground">
                                                {p.steps_count} steps ·{' '}
                                                {p.runs_count} runs
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
