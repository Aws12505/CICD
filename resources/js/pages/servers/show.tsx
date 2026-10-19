import { Head, Link, router } from '@inertiajs/react';
import {
    CheckCircle2,
    Circle,
    Copy,
    KeyRound,
    Pencil,
    PlugZap,
    RefreshCw,
    SquareTerminal,
    Wrench,
} from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { ServerMonitor } from '@/components/server-monitor';
import { ServerStatusBadge } from '@/components/server-status-badge';
import { TASK_STATUS_VARIANT } from '@/components/task-panel';
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

function Step({
    n,
    title,
    done,
    children,
}: {
    n: number;
    title: string;
    done: boolean;
    children: ReactNode;
}) {
    return (
        <div className="flex gap-4">
            <div className="pt-0.5">
                {done ? (
                    <CheckCircle2 className="size-5 text-emerald-600" />
                ) : (
                    <Circle className="size-5 text-muted-foreground" />
                )}
            </div>
            <div className="flex min-w-0 flex-1 flex-col gap-2">
                <div className="font-medium">
                    {n}. {title}
                </div>
                {children}
            </div>
        </div>
    );
}

function CopyLine({ text }: { text: string }) {
    const [copied, setCopied] = useState(false);

    return (
        <div className="flex items-start gap-2">
            <pre className="min-w-0 flex-1 overflow-x-auto rounded-md border border-sidebar-border/70 bg-muted/40 p-3 font-mono text-xs break-all whitespace-pre-wrap">
                {text}
            </pre>
            <Button
                variant="outline"
                size="sm"
                onClick={() => {
                    void navigator.clipboard.writeText(text).then(() => {
                        setCopied(true);
                        setTimeout(() => setCopied(false), 1500);
                    });
                }}
            >
                <Copy className="size-4" /> {copied ? 'Copied' : 'Copy'}
            </Button>
        </div>
    );
}

export default function ServerShow({
    server,
    actions,
}: {
    server: ServerDetail;
    actions: { key: string; label: string }[];
}) {
    const online = server.status === 'online';
    const provision = server.last_provision;
    const provisioning =
        provision?.status === 'queued' || provision?.status === 'running';

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
                            {server.provisioned && (
                                <Badge variant="secondary">set up</Badge>
                            )}
                        </div>
                        <p className="mt-1 font-mono text-sm text-muted-foreground">
                            {server.ssh_user}@{server.hostname}:
                            {server.ssh_port}
                        </p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        {server.verified && online && (
                            <Button asChild>
                                <Link href={`/servers/${server.id}/terminal`}>
                                    <SquareTerminal className="size-4" />{' '}
                                    Terminal
                                </Link>
                            </Button>
                        )}
                        <Button asChild variant="outline">
                            <Link href={`/servers/${server.id}/edit`}>
                                <Pencil className="size-4" /> Edit
                            </Link>
                        </Button>
                    </div>
                </div>

                {server.last_error && !online && (
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
                    <CardHeader className="border-b border-sidebar-border/70">
                        <CardTitle>Get this server ready</CardTitle>
                    </CardHeader>
                    <CardContent className="flex flex-col gap-6 pt-6">
                        <Step
                            n={1}
                            title="Let the control plane in"
                            done={online}
                        >
                            <p className="text-sm text-muted-foreground">
                                Open the server's console at your VPS provider,
                                log in as root and paste this line. It adds the
                                control plane's public key, nothing else.
                            </p>
                            {server.authorize_command ? (
                                <CopyLine text={server.authorize_command} />
                            ) : (
                                <p className="text-sm text-muted-foreground">
                                    This server has no generated key. Generate
                                    one below.
                                </p>
                            )}
                        </Step>

                        <Step n={2} title="Verify" done={online}>
                            <p className="text-sm text-muted-foreground">
                                Pins the server's host key, logs in with the new
                                key and checks what is installed.
                            </p>
                            <div>
                                <Button
                                    variant={online ? 'outline' : 'default'}
                                    onClick={() =>
                                        router.post(
                                            `/servers/${server.id}/check`,
                                        )
                                    }
                                >
                                    <PlugZap className="size-4" /> Verify
                                </Button>
                            </div>
                            {server.capabilities && (
                                <div className="flex flex-wrap gap-2">
                                    {Object.entries(server.capabilities).map(
                                        ([name, present]) => (
                                            <Badge
                                                key={name}
                                                variant={
                                                    present
                                                        ? 'secondary'
                                                        : 'outline'
                                                }
                                            >
                                                {name}: {present ? 'yes' : 'no'}
                                            </Badge>
                                        ),
                                    )}
                                </div>
                            )}
                        </Step>

                        <Step
                            n={3}
                            title="Set up server"
                            done={server.provisioned}
                        >
                            <p className="text-sm text-muted-foreground">
                                Installs Docker, nginx and certbot, locks SSH to
                                the control plane, and starts the shared MySQL,
                                Redis and NATS with a user and streams for every
                                stack on this server. Safe to run again at any
                                time; a second run changes nothing that is
                                already right.
                            </p>
                            <div className="flex flex-wrap items-center gap-3">
                                <Button
                                    disabled={!online && !provisioning}
                                    variant={
                                        server.provisioned
                                            ? 'outline'
                                            : 'default'
                                    }
                                    onClick={() =>
                                        router.post(
                                            `/servers/${server.id}/provision`,
                                        )
                                    }
                                >
                                    <Wrench className="size-4" />{' '}
                                    {provisioning
                                        ? 'Open the running setup'
                                        : server.provisioned
                                          ? 'Run setup again'
                                          : 'Set up server'}
                                </Button>
                                {provision && (
                                    <Link
                                        href={`/tasks/${provision.id}`}
                                        className="flex items-center gap-2 text-sm underline-offset-4 hover:underline"
                                    >
                                        Last run
                                        <Badge
                                            variant={
                                                TASK_STATUS_VARIANT[
                                                    provision.status
                                                ]
                                            }
                                        >
                                            {provision.status}
                                        </Badge>
                                        {provision.finished_at &&
                                            new Date(
                                                provision.finished_at,
                                            ).toLocaleString()}
                                    </Link>
                                )}
                            </div>
                        </Step>
                    </CardContent>
                </Card>

                {server.verified && online && (
                    <>
                        <ServerMonitor serverId={server.id} />
                        <Card>
                            <CardHeader>
                                <CardTitle>Quick actions</CardTitle>
                            </CardHeader>
                            <CardContent className="flex flex-wrap gap-2">
                                {actions.map((action) => (
                                    <Button
                                        key={action.key}
                                        variant="outline"
                                        size="sm"
                                        onClick={() => {
                                            if (confirm(`${action.label}?`)) {
                                                router.post(
                                                    `/servers/${server.id}/actions/${action.key}`,
                                                );
                                            }
                                        }}
                                    >
                                        {action.label}
                                    </Button>
                                ))}
                            </CardContent>
                        </Card>
                    </>
                )}

                {server.stacks && server.stacks.length > 0 && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Stacks on this server</CardTitle>
                        </CardHeader>
                        <CardContent className="flex flex-wrap gap-2">
                            {server.stacks.map((stack) => (
                                <Badge key={stack.id} variant="outline">
                                    {stack.label} · {stack.status}
                                </Badge>
                            ))}
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
                            <CardTitle>Keys</CardTitle>
                            {server.verified ? (
                                <Badge variant="secondary">
                                    host key pinned
                                </Badge>
                            ) : (
                                <Badge variant="destructive">
                                    host key not pinned
                                </Badge>
                            )}
                        </div>
                    </CardHeader>
                    <CardContent className="grid gap-4 pt-6">
                        <div className="grid gap-1">
                            <p className="text-xs text-muted-foreground">
                                Server's host key
                            </p>
                            <p className="font-mono text-sm break-all">
                                {server.host_key_fingerprint ??
                                    'Not recorded yet.'}
                            </p>
                            <p className="text-xs text-muted-foreground">
                                Compare this with what the server reports
                                (`ssh-keygen -lf
                                /etc/ssh/ssh_host_ed25519_key.pub`). A rebuilt
                                server fails to connect until the key is read
                                again.
                            </p>
                        </div>
                        {server.public_key && (
                            <div className="grid gap-1">
                                <p className="text-xs text-muted-foreground">
                                    Control plane's public key for this server
                                </p>
                                <p className="font-mono text-xs break-all">
                                    {server.public_key}
                                </p>
                            </div>
                        )}
                        <div className="flex flex-wrap gap-2">
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
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() => {
                                    if (
                                        confirm(
                                            'Generate a new key? The control plane cannot reach this server again until the new command is pasted on it.',
                                        )
                                    ) {
                                        router.post(
                                            `/servers/${server.id}/regenerate-key`,
                                        );
                                    }
                                }}
                            >
                                <RefreshCw className="size-4" /> New key
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
