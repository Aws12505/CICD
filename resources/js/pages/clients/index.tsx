import { Head, Link } from '@inertiajs/react';
import { Building2, Plus } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { PlaceholderPattern } from '@/components/ui/placeholder-pattern';
import { useAbilities } from '@/hooks/use-abilities';
import type { ClientRow } from '@/types/topology';

export default function ClientsIndex({ clients }: { clients: ClientRow[] }) {
    const { manage: canManage } = useAbilities();

    return (
        <>
            <Head title="Clients" />
            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <Card className="relative overflow-hidden border border-sidebar-border/60 bg-card/80">
                    <PlaceholderPattern className="absolute inset-0 text-muted/25" />
                    <CardContent className="relative flex flex-col gap-4 p-6 lg:flex-row lg:items-center lg:justify-between">
                        <div>
                            <p className="text-xs tracking-[0.2em] text-muted-foreground uppercase">
                                Topology
                            </p>
                            <h1 className="font-display text-3xl font-semibold">
                                Clients
                            </h1>
                            <p className="mt-2 max-w-2xl text-sm text-muted-foreground">
                                Every client gets two stacks, live and testing.
                                Which services each one runs is data here, never
                                a fork of the code.
                            </p>
                            <div className="mt-3">
                                <Badge variant="secondary">
                                    {clients.length} clients
                                </Badge>
                            </div>
                        </div>
                        {canManage && (
                            <Button asChild>
                                <Link href="/clients/create">
                                    <Plus className="size-4" /> Add client
                                </Link>
                            </Button>
                        )}
                    </CardContent>
                </Card>

                {clients.length === 0 ? (
                    <Card className="flex flex-col items-center gap-3 p-12 text-center">
                        <Building2 className="size-10 text-muted-foreground" />
                        <p className="text-sm text-muted-foreground">
                            No clients yet.
                        </p>
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
