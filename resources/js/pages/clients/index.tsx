import { Head, Link } from '@inertiajs/react';
import { Building2, Plus } from 'lucide-react';
import { PageHeader } from '@/components/page-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { useAbilities } from '@/hooks/use-abilities';
import type { ClientRow } from '@/types/topology';

export default function ClientsIndex({ clients }: { clients: ClientRow[] }) {
    const { manage: canManage } = useAbilities();

    return (
        <>
            <Head title="Clients" />
            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <PageHeader
                    eyebrow="Clients"
                    title="Clients"
                    description="One entry per client, with a testing and a live stack. What each one runs is a setting here, never a copy of the code."
                    meta={
                        <Badge variant="secondary">
                            {clients.length} clients
                        </Badge>
                    }
                    actions={
                        canManage && (
                            <Button asChild>
                                <Link href="/clients/create">
                                    <Plus className="size-4" /> Add client
                                </Link>
                            </Button>
                        )
                    }
                />

                {clients.length === 0 ? (
                    <Card className="flex flex-col items-center gap-3 p-12 text-center">
                        <Building2 className="size-10 text-muted-foreground" />
                        <p className="text-sm text-muted-foreground">
                            No clients yet. A client is a company with its own
                            server, domain and choice of services.
                        </p>
                        {canManage && (
                            <Button asChild>
                                <Link href="/clients/create">
                                    <Plus className="size-4" /> Add the first
                                    client
                                </Link>
                            </Button>
                        )}
                    </Card>
                ) : (
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        {clients.map((client) => (
                            <Link
                                key={client.id}
                                href={`/clients/${client.slug}`}
                            >
                                <Card className="group h-full border border-sidebar-border/60 bg-card/80 p-5 transition-all hover:-translate-y-0.5 hover:shadow-md">
                                    <div className="flex items-start justify-between gap-2">
                                        <div className="font-semibold">
                                            {client.name}
                                        </div>
                                        {client.status !== 'active' && (
                                            <Badge variant="destructive">
                                                {client.status}
                                            </Badge>
                                        )}
                                    </div>
                                    <div className="mt-1 font-mono text-xs text-muted-foreground">
                                        {client.domain}
                                    </div>
                                    <div className="mt-4 flex flex-wrap gap-2">
                                        {client.stacks.map((stack) => (
                                            <Badge
                                                key={stack.tier}
                                                variant="secondary"
                                            >
                                                {stack.tier} ·{' '}
                                                {stack.server ?? 'no server'}
                                            </Badge>
                                        ))}
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

ClientsIndex.layout = {
    breadcrumbs: [{ title: 'Clients', href: '/clients' }],
};
