import { Head, Link } from '@inertiajs/react';
import { Plus, Server as ServerIcon } from 'lucide-react';
import { PageHeader } from '@/components/page-header';
import { ServerStatusBadge } from '@/components/server-status-badge';
import { StatusPill } from '@/components/status-pill';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
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
                <PageHeader
                    eyebrow="Set up once"
                    title="Servers"
                    description="Each client's machine. The control plane makes a key for it, you paste one line into the VPS console, then it installs everything itself."
                    meta={
                        <>
                            <Badge variant="secondary">
                                {servers.length} servers
                            </Badge>
                            {unverified > 0 && (
                                <StatusPill tone="warning">
                                    {unverified} not verified yet
                                </StatusPill>
                            )}
                        </>
                    }
                    actions={
                        <Button asChild>
                            <Link href="/servers/create">
                                <Plus className="size-4" /> Add server
                            </Link>
                        </Button>
                    }
                />

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
