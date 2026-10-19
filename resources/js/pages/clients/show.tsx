import { Head, Link, router } from '@inertiajs/react';
import { AlertTriangle, Eye, SlidersHorizontal } from 'lucide-react';
import { useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Switch } from '@/components/ui/switch';
import { useAbilities } from '@/hooks/use-abilities';
import type {
    ClientRow,
    Entitlement,
    ServiceRow,
    StackDetail,
} from '@/types/topology';

type Props = {
    client: Omit<ClientRow, 'stacks'> & {
        notes: string | null;
        admin_email: string | null;
    };
    services: Pick<ServiceRow, 'id' | 'name' | 'slug' | 'subdomain'>[];
    stacks: StackDetail[];
};

function EntitlementRow({
    service,
    entitlement,
    stackId,
    canManage,
}: {
    service: Props['services'][number];
    entitlement: Entitlement | undefined;
    stackId: number;
    canManage: boolean;
}) {
    const enabled = entitlement?.enabled ?? false;
    const missing = entitlement?.missing_env ?? [];

    const toggle = (next: boolean) => {
        router.post(
            `/stacks/${stackId}/entitlements`,
            { service_id: service.id, enabled: next, edition: null },
            { preserveScroll: true },
        );
    };

    return (
        <div className="flex flex-wrap items-center justify-between gap-3 p-4">
            <div className="min-w-0">
                <div className="flex items-center gap-2 font-medium">
                    {service.name}
                    {entitlement?.edition && (
                        <Badge variant="secondary">
                            edition: {entitlement.edition}
                        </Badge>
                    )}
                </div>
                <div className="truncate font-mono text-xs text-muted-foreground">
                    {enabled
                        ? `${entitlement?.domain} · port ${entitlement?.host_port ?? '—'}`
                        : 'not deployed'}
                </div>
                {missing.length > 0 && (
                    <div className="mt-1 flex items-center gap-1 text-xs text-destructive">
                        <AlertTriangle className="size-3" />
                        Missing: {missing.join(', ')}
                    </div>
                )}
            </div>
            <div className="flex items-center gap-3">
                {enabled && (
                    <Badge variant="secondary">{entitlement?.state}</Badge>
                )}
                <Switch
                    checked={enabled}
                    disabled={!canManage}
                    onCheckedChange={toggle}
                    aria-label={`${enabled ? 'Disable' : 'Enable'} ${service.name}`}
                />
            </div>
        </div>
    );
}

function FirstAdmin({ slug, email }: { slug: string; email: string }) {
    const [passwords, setPasswords] = useState<Record<
        string,
        string | null
    > | null>(null);

    const reveal = () => {
        void fetch(`/clients/${slug}/admin-password`, {
            headers: { Accept: 'application/json' },
        })
            .then((r) => r.json())
            .then(setPasswords);
    };

    return (
        <Card>
            <CardHeader>
                <CardTitle>First admin</CardTitle>
            </CardHeader>
            <CardContent className="grid gap-2 text-sm">
                <p>
                    <span className="font-mono">{email}</span>, created on each
                    tier's first deploy.
                </p>
                {passwords ? (
                    Object.entries(passwords).map(([tier, password]) => (
                        <p key={tier} className="font-mono text-xs">
                            {tier}: {password ?? 'not created yet'}
                        </p>
                    ))
                ) : (
                    <div>
                        <Button variant="outline" size="sm" onClick={reveal}>
                            <Eye className="size-4" /> Show passwords
                        </Button>
                    </div>
                )}
            </CardContent>
        </Card>
    );
}

export default function ClientShow({ client, services, stacks }: Props) {
    const { manage: canManage, destroy: isAdmin } = useAbilities();

    return (
        <>
            <Head title={client.name} />
            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <div className="flex items-center gap-3">
                            <h1 className="font-display text-3xl font-semibold">
                                {client.name}
                            </h1>
                            {client.status !== 'active' && (
                                <Badge variant="destructive">
                                    {client.status}
                                </Badge>
                            )}
                        </div>
                        <p className="mt-1 font-mono text-sm text-muted-foreground">
                            {client.domain}
                        </p>
                    </div>
                    {canManage && (
                        <Button asChild variant="outline">
                            <Link href={`/clients/${client.slug}/env`}>
                                <SlidersHorizontal className="size-4" />{' '}
                                Environment
                            </Link>
                        </Button>
                    )}
                </div>

                <p className="text-sm text-muted-foreground">
                    Turning a service on records intent. Containers, databases
                    and certificates appear on the next release, not on save.
                </p>

                {stacks.map((stack) => (
                    <Card key={stack.id}>
                        <CardHeader className="border-b border-sidebar-border/70">
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <CardTitle className="capitalize">
                                    {stack.tier}
                                </CardTitle>
                                <div className="flex flex-wrap items-center gap-2">
                                    <Badge variant="secondary">
                                        {stack.server ?? 'no server'}
                                    </Badge>
                                    <Badge variant="secondary">
                                        ports from {stack.port_range_start}
                                    </Badge>
                                    <Badge variant="secondary">
                                        {stack.status}
                                    </Badge>
                                    <Link
                                        href={`/stacks/${stack.id}/plan`}
                                        className="text-sm text-primary hover:underline"
                                    >
                                        {stack.tier === 'live'
                                            ? 'Promote'
                                            : 'Update'}
                                    </Link>
                                    <Link
                                        href={`/stacks/${stack.id}/backups`}
                                        className="text-sm text-primary hover:underline"
                                    >
                                        Backups
                                    </Link>
                                </div>
                            </div>
                        </CardHeader>
                        <CardContent className="p-0">
                            <div className="divide-y divide-sidebar-border/70 dark:divide-sidebar-border">
                                {services.map((service) => (
                                    <EntitlementRow
                                        key={service.id}
                                        service={service}
                                        stackId={stack.id}
                                        canManage={canManage}
                                        entitlement={stack.entitlements.find(
                                            (e) => e.service_id === service.id,
                                        )}
                                    />
                                ))}
                                {services.length === 0 && (
                                    <p className="p-4 text-sm text-muted-foreground">
                                        No services defined yet.
                                    </p>
                                )}
                            </div>
                        </CardContent>
                    </Card>
                ))}

                {isAdmin && client.admin_email && (
                    <FirstAdmin slug={client.slug} email={client.admin_email} />
                )}

                {client.notes && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Notes</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="text-sm whitespace-pre-wrap">
                                {client.notes}
                            </p>
                        </CardContent>
                    </Card>
                )}
            </div>
        </>
    );
}

ClientShow.layout = {
    breadcrumbs: [{ title: 'Clients', href: '/clients' }],
};
