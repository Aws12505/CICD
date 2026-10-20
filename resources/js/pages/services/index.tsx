import { Head, Link, router } from '@inertiajs/react';
import { Layers, Plus, Sparkles } from 'lucide-react';
import { PageHeader } from '@/components/page-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { useAbilities } from '@/hooks/use-abilities';
import type { ServiceRow } from '@/types/topology';

export default function ServicesIndex({
    services,
}: {
    services: ServiceRow[];
}) {
    const { manage: canManage } = useAbilities();

    return (
        <>
            <Head title="Services" />
            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <PageHeader
                    eyebrow="Set up once"
                    title="Services"
                    description='One entry per service: its repository, the settings it needs, its worker processes and the commands a deploy runs. Clients pick from this list. "Add the standard services" fills it from the repositories; run it again any time to pick up new keys without losing your edits.'
                    meta={
                        <Badge variant="secondary">
                            {services.length} services
                        </Badge>
                    }
                    actions={
                        canManage && (
                            <>
                                <Button
                                    variant={
                                        services.length === 0
                                            ? 'default'
                                            : 'outline'
                                    }
                                    onClick={() =>
                                        router.post('/services/seed')
                                    }
                                >
                                    <Sparkles className="size-4" />{' '}
                                    {services.length === 0
                                        ? 'Add the standard services'
                                        : 'Refresh the standard services'}
                                </Button>
                                <Button asChild variant="outline">
                                    <Link href="/services/create">
                                        <Plus className="size-4" /> Add a
                                        service
                                    </Link>
                                </Button>
                            </>
                        )
                    }
                />

                {services.length === 0 ? (
                    <Card className="flex flex-col items-center gap-3 p-12 text-center">
                        <Layers className="size-10 text-muted-foreground" />
                        <p className="text-sm text-muted-foreground">
                            No services defined yet.
                        </p>
                    </Card>
                ) : (
                    <Card>
                        <div className="divide-y divide-sidebar-border/70 dark:divide-sidebar-border">
                            {services.map((service) => (
                                <Link
                                    key={service.id}
                                    href={`/services/${service.slug}`}
                                    className="flex flex-wrap items-center justify-between gap-3 p-4 transition-colors hover:bg-muted/40"
                                >
                                    <div>
                                        <div className="flex items-center gap-2 font-medium">
                                            {service.name}
                                            {service.per_stack_build && (
                                                <Badge variant="secondary">
                                                    built per stack
                                                </Badge>
                                            )}
                                        </div>
                                        <div className="font-mono text-xs text-muted-foreground">
                                            {service.subdomain} ·{' '}
                                            {service.build_recipe}
                                        </div>
                                    </div>
                                    <div className="flex items-center gap-2">
                                        <Badge variant="secondary">
                                            {service.env_definitions_count} env
                                            keys
                                        </Badge>
                                        <Badge variant="secondary">
                                            {service.enabled_count} stacks
                                        </Badge>
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

ServicesIndex.layout = {
    breadcrumbs: [{ title: 'Services', href: '/services' }],
};
