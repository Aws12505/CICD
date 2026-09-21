import { Head, Link } from '@inertiajs/react';
import { Rocket } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';
import { PlaceholderPattern } from '@/components/ui/placeholder-pattern';

type ReleaseRow = {
    id: number;
    kind: string;
    status: string;
    client: string;
    tier: string;
    items_count: number;
    needs_window: boolean;
    created_at: string | null;
};

const STATUS_VARIANT: Record<
    string,
    'default' | 'secondary' | 'destructive' | 'outline'
> = {
    draft: 'secondary',
    scheduled: 'outline',
    running: 'default',
    succeeded: 'default',
    failed: 'destructive',
    cancelled: 'secondary',
};

export default function ReleasesIndex({
    releases,
}: {
    releases: ReleaseRow[];
}) {
    return (
        <>
            <Head title="Releases" />
            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <Card className="relative overflow-hidden border border-sidebar-border/60 bg-card/80">
                    <PlaceholderPattern className="absolute inset-0 text-muted/25" />
                    <CardContent className="relative p-6">
                        <p className="text-xs tracking-[0.2em] text-muted-foreground uppercase">
                            Deployments
                        </p>
                        <h1 className="font-display text-3xl font-semibold">
                            Releases
                        </h1>
                        <p className="mt-2 max-w-2xl text-sm text-muted-foreground">
                            Every deploy, promotion, rollback and emergency.
                            Each item records the digest it came from, which is
                            what makes going back possible.
                        </p>
                    </CardContent>
                </Card>

                {releases.length === 0 ? (
                    <Card className="flex flex-col items-center gap-3 p-12 text-center">
                        <Rocket className="size-10 text-muted-foreground" />
                        <p className="text-sm text-muted-foreground">
                            Nothing has been released yet.
                        </p>
                    </Card>
                ) : (
                    <Card>
                        <div className="divide-y divide-sidebar-border/70 dark:divide-sidebar-border">
                            {releases.map((release) => (
                                <Link
                                    key={release.id}
                                    href={`/releases/${release.id}`}
                                    className="flex flex-wrap items-center justify-between gap-3 p-4 transition-colors hover:bg-muted/40"
                                >
                                    <div>
                                        <div className="flex flex-wrap items-center gap-2 font-medium">
                                            #{release.id} · {release.client} ·{' '}
                                            {release.tier}
                                            {release.kind !== 'normal' && (
                                                <Badge
                                                    variant={
                                                        release.kind ===
                                                        'emergency'
                                                            ? 'destructive'
                                                            : 'secondary'
                                                    }
                                                >
                                                    {release.kind}
                                                </Badge>
                                            )}
                                            {release.needs_window && (
                                                <Badge variant="outline">
                                                    needs a window
                                                </Badge>
                                            )}
                                        </div>
                                        <div className="text-xs text-muted-foreground">
                                            {release.items_count} service
                                            {release.items_count === 1
                                                ? ''
                                                : 's'}
                                        </div>
                                    </div>
                                    <Badge
                                        variant={
                                            STATUS_VARIANT[release.status] ??
                                            'secondary'
                                        }
                                    >
                                        {release.status}
                                    </Badge>
                                </Link>
                            ))}
                        </div>
                    </Card>
                )}
            </div>
        </>
    );
}

ReleasesIndex.layout = {
    breadcrumbs: [{ title: 'Releases', href: '/releases' }],
};
