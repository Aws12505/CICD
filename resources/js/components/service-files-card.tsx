import { Link } from '@inertiajs/react';
import { FileCode2 } from 'lucide-react';
import { StatusPill } from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';

export type ServiceFileRow = {
    name: string;
    description: string;
    template: boolean;
    buildFile: boolean;
    source: 'default' | 'custom' | 'repo';
    repo_path: string | null;
};

/**
 * How this service is built and run: one line per file, where it comes
 * from, and a way in to change it.
 */
export function ServiceFilesCard({
    slug,
    files,
}: {
    slug: string;
    files: ServiceFileRow[];
}) {
    return (
        <Card>
            <CardHeader className="border-b border-border">
                <CardTitle>Build &amp; run files</CardTitle>
                <p className="text-sm text-muted-foreground">
                    How the image is built and how its containers run. Every
                    file has a default that works; change one here when this
                    service needs something different, or point a build file
                    at one in the repository.
                </p>
            </CardHeader>
            <CardContent className="p-0">
                <div className="divide-y divide-border">
                    {files.map((file) => (
                        <div
                            key={file.name}
                            className="flex flex-wrap items-center justify-between gap-3 p-4"
                        >
                            <div className="min-w-0">
                                <div className="flex flex-wrap items-center gap-2">
                                    <span className="font-mono text-sm">
                                        {file.name}
                                    </span>
                                    {file.source === 'default' && (
                                        <StatusPill tone="neutral">
                                            default
                                        </StatusPill>
                                    )}
                                    {file.source === 'custom' && (
                                        <StatusPill tone="info">
                                            customised
                                        </StatusPill>
                                    )}
                                    {file.source === 'repo' && (
                                        <StatusPill tone="info">
                                            from repository: {file.repo_path}
                                        </StatusPill>
                                    )}
                                </div>
                                <p className="mt-0.5 text-sm text-muted-foreground">
                                    {file.description}
                                </p>
                            </div>
                            <Button asChild size="sm" variant="outline">
                                <Link href={`/services/${slug}/files/${file.name}`}>
                                    <FileCode2 className="size-4" />{' '}
                                    {file.source === 'default' ? 'View' : 'Edit'}
                                </Link>
                            </Button>
                        </div>
                    ))}
                </div>
            </CardContent>
        </Card>
    );
}
