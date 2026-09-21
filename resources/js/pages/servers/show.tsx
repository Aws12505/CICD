import { Head, Link, router } from '@inertiajs/react';
import { KeyRound, Pencil, PlugZap } from 'lucide-react';
import { ServerStatusBadge } from '@/components/server-status-badge';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import type { ServerDetail } from '@/types/server';

function Field({ label, value }: { label: string; value: string }) {
    return (
        <div className="rounded-xl border border-sidebar-border/60 bg-background/60 p-4">
            <div className="text-xs text-muted-foreground">{label}</div>
            <div className="mt-1 truncate font-mono text-sm">{value}</div>
        </div>
    );
}

export default function ServerShow({ server }: { server: ServerDetail }) {
    return (
        <>
            <Head title={server.name} />
            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <div className="flex items-center gap-3">
                            <h1 className="font-display text-3xl font-semibold">
                                {server.name}
                            </h1>
                            <ServerStatusBadge status={server.status} />
                        </div>
                        <p className="mt-1 font-mono text-sm text-muted-foreground">
                            {server.ssh_user}@{server.hostname}:
                            {server.ssh_port}
                        </p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <Button
                            variant="outline"
                            onClick={() =>
                                router.post(`/servers/${server.id}/check`)
                            }
                        >
                            <PlugZap className="size-4" /> Test connection
                        </Button>
                        <Button asChild variant="outline">
                            <Link href={`/servers/${server.id}/edit`}>
                                <Pencil className="size-4" /> Edit
                            </Link>
                        </Button>
                    </div>
                </div>

                {server.last_error && (
                    <Card className="border-destructive/40 bg-destructive/5">
                        <CardContent className="p-4">
                            <p className="text-xs tracking-wide text-muted-foreground uppercase">
                                Last error
                            </p>
                            <p className="mt-1 font-mono text-sm break-all">
                                {server.last_error}
                            </p>
                        </CardContent>
                    </Card>
                )}

                <Card>
                    <CardHeader>
                        <CardTitle>Connection</CardTitle>
                    </CardHeader>
                    <CardContent className="grid gap-4 sm:grid-cols-3">
                        <Field
                            label="IP address"
                            value={server.ip_address ?? '—'}
                        />
                        <Field label="SSH user" value={server.ssh_user} />
                        <Field
                            label="Last seen"
                            value={
                                server.last_seen_at
                                    ? new Date(
                                          server.last_seen_at,
                                      ).toLocaleString()
                                    : 'never'
                            }
                        />
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader className="border-b border-sidebar-border/70">
                        <div className="flex items-center justify-between">
                            <CardTitle>Host key</CardTitle>
                            {server.verified ? (
                                <Badge variant="secondary">pinned</Badge>
                            ) : (
                                <Badge variant="destructive">not pinned</Badge>
                            )}
                        </div>
                    </CardHeader>
                    <CardContent className="grid gap-3 pt-6">
                        {server.verified ? (
                            <>
                                <p className="font-mono text-sm break-all">
                                    {server.host_key_fingerprint}
                                </p>
                                <p className="text-xs text-muted-foreground">
                                    Compare this against the fingerprint the
                                    machine itself reports before trusting it.
                                    Every connection is pinned to this key, so a
                                    rebuilt server will fail to connect until
                                    the key is re-read.
                                </p>
                            </>
                        ) : (
                            <p className="text-xs text-muted-foreground">
                                No host key recorded. Connections cannot be
                                pinned and will accept whatever answers.
                            </p>
                        )}
                        <div>
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() =>
                                    router.post(`/servers/${server.id}/rescan`)
                                }
                            >
                                <KeyRound className="size-4" />{' '}
                                {server.verified
                                    ? 'Re-read host key'
                                    : 'Read host key'}
                            </Button>
                        </div>
                    </CardContent>
                </Card>

                {server.notes && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Notes</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="text-sm whitespace-pre-wrap">
                                {server.notes}
                            </p>
                        </CardContent>
                    </Card>
                )}
            </div>
        </>
    );
}

ServerShow.layout = {
    breadcrumbs: [{ title: 'Servers', href: '/servers' }],
};
