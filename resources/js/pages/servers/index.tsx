import { Head, Link } from '@inertiajs/react';
import { Plus, Server as ServerIcon } from 'lucide-react';
import { ServerStatusBadge } from '@/components/server-status-badge';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { PlaceholderPattern } from '@/components/ui/placeholder-pattern';
import type { ServerSummary } from '@/types/server';

export default function ServersIndex({
    servers,
}: {
    servers: ServerSummary[];
}) {
    const unverified = servers.filter((s) => !s.verified).length;

    return (
        <>
            <Head title="Servers" />
            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <Card className="relative overflow-hidden border border-sidebar-border/60 bg-card/80">
                    <PlaceholderPattern className="absolute inset-0 text-muted/25" />
                    <CardContent className="relative flex flex-col gap-4 p-6 lg:flex-row lg:items-center lg:justify-between">
                        <div>
                            <p className="text-xs tracking-[0.2em] text-muted-foreground uppercase">
                                Infrastructure
                            </p>
                            <h1 className="font-display text-3xl font-semibold">
                                Servers
                            </h1>
                            <p className="mt-2 max-w-2xl text-sm text-muted-foreground">
                                Machines this control plane can reach over SSH.
                                Each one holds a key that grants root, so keys
                                are encrypted at rest and host keys are pinned.
                            </p>
                            <div className="mt-3 flex flex-wrap gap-2">
                                <Badge variant="secondary">
                                    {servers.length} servers
                                </Badge>
                                {unverified > 0 && (
                                    <Badge variant="destructive">
                                        {unverified} unverified
                                    </Badge>
                                )}
                            </div>
                        </div>
                        <Button asChild>
                            <Link href="/servers/create">
                                <Plus className="size-4" /> Add server
                            </Link>
                        </Button>
                    </CardContent>
                </Card>

                {servers.length === 0 ? (
                    <Card className="flex flex-col items-center gap-3 p-12 text-center">
                        <ServerIcon className="size-10 text-muted-foreground" />
                        <p className="text-sm text-muted-foreground">
                            No servers yet. Add one to start deploying to it.
                        </p>
                        <Button asChild>
                            <Link href="/servers/create">
                                <Plus className="size-4" /> Add server
                            </Link>
                        </Button>
                    </Card>
                ) : (
                    <Card>
                        <div className="divide-y divide-sidebar-border/70 dark:divide-sidebar-border">
                            {servers.map((server) => (
                                <Link
                                    key={server.id}
                                    href={`/servers/${server.id}`}
                                    className="flex flex-wrap items-center justify-between gap-3 p-4 transition-colors hover:bg-muted/40"
                                >
                                    <div className="flex items-center gap-3">
                                        <ServerIcon className="size-4 text-muted-foreground" />
                                        <div>
                                            <div className="font-medium">
                                                {server.name}
                                            </div>
                                            <div className="font-mono text-xs text-muted-foreground">
                                                {server.ssh_user}@
                                                {server.hostname}:
                                                {server.ssh_port}
                                            </div>
                                        </div>
                                    </div>
                                    <div className="flex items-center gap-2">
                                        {!server.verified && (
                                            <Badge variant="destructive">
                                                host key not pinned
                                            </Badge>
                                        )}
                                        <ServerStatusBadge
                                            status={server.status}
                                        />
                                    </div>
                                </Link>
                            ))}
                        </div>
                    </Card>
                )}
            </div>
        </>
    );
}

ServersIndex.layout = {
    breadcrumbs: [{ title: 'Servers', href: '/servers' }],
};
