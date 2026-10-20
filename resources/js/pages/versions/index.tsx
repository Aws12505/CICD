import { Head, Link, router } from '@inertiajs/react';
import { ArrowUpRight, Pin, PinOff, Rocket, TestTube2 } from 'lucide-react';
import { useState } from 'react';
import { PageHeader } from '@/components/page-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { useAbilities } from '@/hooks/use-abilities';

type BuildSummary = {
    id: number;
    number: number | null;
    label: string;
    sha: string;
    edition: string | null;
    migration_kind: string;
};

type Cell = {
    enabled: boolean;
    edition: string | null;
    state: string;
    deployed: boolean;
    build: BuildSummary | null;
    newest: BuildSummary | null;
    behind: number | null;
    drifted: boolean;
} | null;

type ClientRow = {
    id: number;
    name: string;
    slug: string;
    status: string;
    pinned: boolean;
    stacks: {
        id: number;
        tier: 'testing' | 'live';
        status: string;
        server_ready: boolean;
    }[];
    cells: Record<number, { testing: Cell; live: Cell }>;
};

type Props = {
    services: { id: number; name: string; slug: string }[];
    clients: ClientRow[];
};

function Version({ tier, cell }: { tier: 'T' | 'L'; cell: Cell }) {
    if (cell === null) {
        return <div className="text-xs text-muted-foreground/60">{tier} —</div>;
    }

    const behind = cell.behind ?? 0;

    return (
        <div className="flex items-center gap-1 text-xs whitespace-nowrap">
            <span className="text-muted-foreground">{tier}</span>
            <span className="font-mono">
                {cell.build
                    ? `#${cell.build.number ?? '?'}`
                    : cell.deployed
                      ? 'unknown'
                      : 'not deployed'}
            </span>
            {behind > 0 && (
                <Badge
                    variant="outline"
                    className="border-amber-500/50 px-1 py-0 text-[10px] text-amber-700 dark:text-amber-400"
                >
                    {behind} behind
                </Badge>
            )}
            {cell.drifted && (
                <Badge variant="destructive" className="px-1 py-0 text-[10px]">
                    drift
                </Badge>
            )}
        </div>
    );
}

export default function VersionsIndex({ services, clients }: Props) {
    const { manage: canManage } = useAbilities();
    const [selected, setSelected] = useState<number[]>([]);

    const toggle = (id: number, on: boolean) =>
        setSelected((s) => (on ? [...s, id] : s.filter((x) => x !== id)));

    const many = (url: string, verb: string) => {
        if (
            confirm(
                `${verb} ${selected.length} client(s)? Pinned clients and anything needing a maintenance window are skipped.`,
            )
        ) {
            router.post(url, { client_ids: selected });
        }
    };

    const stackOf = (client: ClientRow, tier: 'testing' | 'live') =>
        client.stacks.find((s) => s.tier === tier);

    return (
        <>
            <Head title="Versions" />
            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <PageHeader
                        eyebrow="Clients"
                        title="Versions"
                        description='What every client runs. T is testing, L is live; "behind" counts newer builds for that client. Each client moves on its own, by a click, never automatically.'
                    />
                    {canManage && selected.length > 0 && (
                        <div className="flex flex-wrap gap-2">
                            <Button
                                variant="outline"
                                onClick={() =>
                                    many(
                                        '/versions/update-testing',
                                        'Update testing for',
                                    )
                                }
                            >
                                <TestTube2 className="size-4" /> Update testing
                                ({selected.length})
                            </Button>
                            <Button
                                onClick={() =>
                                    many('/versions/promote', 'Promote')
                                }
                            >
                                <Rocket className="size-4" /> Promote to live (
                                {selected.length})
                            </Button>
                        </div>
                    )}
                </div>

                <Card>
                    <CardContent className="overflow-x-auto p-0">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-b border-sidebar-border/70 text-left">
                                    <th className="sticky left-0 z-10 bg-card p-3 font-medium">
                                        Client
                                    </th>
                                    {services.map((s) => (
                                        <th
                                            key={s.id}
                                            className="p-3 font-medium whitespace-nowrap"
                                        >
                                            {s.name}
                                        </th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody>
                                {clients.length === 0 && (
                                    <tr>
                                        <td
                                            colSpan={services.length + 1}
                                            className="p-4 text-muted-foreground"
                                        >
                                            No clients yet.
                                        </td>
                                    </tr>
                                )}
                                {clients.map((client) => {
                                    const testing = stackOf(client, 'testing');
                                    const live = stackOf(client, 'live');

                                    return (
                                        <tr
                                            key={client.id}
                                            className="border-b border-sidebar-border/50 align-top last:border-0"
                                        >
                                            <td className="sticky left-0 z-10 bg-card p-3">
                                                <div className="flex items-start gap-2">
                                                    {canManage && (
                                                        <Checkbox
                                                            className="mt-0.5"
                                                            checked={selected.includes(
                                                                client.id,
                                                            )}
                                                            onCheckedChange={(
                                                                v,
                                                            ) =>
                                                                toggle(
                                                                    client.id,
                                                                    v === true,
                                                                )
                                                            }
                                                        />
                                                    )}
                                                    <div className="grid gap-1">
                                                        <div className="flex items-center gap-1 font-medium">
                                                            <Link
                                                                href={`/clients/${client.slug}`}
                                                                className="hover:underline"
                                                            >
                                                                {client.name}
                                                            </Link>
                                                            {client.pinned && (
                                                                <Pin className="size-3 text-muted-foreground" />
                                                            )}
                                                        </div>
                                                        {canManage && (
                                                            <div className="flex flex-wrap gap-x-3 gap-y-1 text-xs">
                                                                {testing && (
                                                                    <Link
                                                                        href={`/stacks/${testing.id}/plan`}
                                                                        className="inline-flex items-center gap-0.5 text-primary hover:underline"
                                                                    >
                                                                        Update
                                                                        testing
                                                                        <ArrowUpRight className="size-3" />
                                                                    </Link>
                                                                )}
                                                                {live && (
                                                                    <Link
                                                                        href={`/stacks/${live.id}/plan`}
                                                                        className="inline-flex items-center gap-0.5 text-primary hover:underline"
                                                                    >
                                                                        Promote
                                                                        <ArrowUpRight className="size-3" />
                                                                    </Link>
                                                                )}
                                                                <button
                                                                    type="button"
                                                                    className="inline-flex items-center gap-0.5 text-muted-foreground hover:underline"
                                                                    onClick={() =>
                                                                        router.post(
                                                                            `/clients/${client.slug}/pin`,
                                                                            {},
                                                                            {
                                                                                preserveScroll: true,
                                                                            },
                                                                        )
                                                                    }
                                                                >
                                                                    {client.pinned ? (
                                                                        <>
                                                                            <PinOff className="size-3" />{' '}
                                                                            Unpin
                                                                        </>
                                                                    ) : (
                                                                        <>
                                                                            <Pin className="size-3" />{' '}
                                                                            Pin
                                                                        </>
                                                                    )}
                                                                </button>
                                                            </div>
                                                        )}
                                                    </div>
                                                </div>
                                            </td>
                                            {services.map((s) => {
                                                const cell = client.cells[s.id];
                                                const edition =
                                                    cell?.testing?.edition ??
                                                    cell?.live?.edition;

                                                return (
                                                    <td
                                                        key={s.id}
                                                        className="p-3"
                                                    >
                                                        <div className="grid gap-0.5">
                                                            <Version
                                                                tier="T"
                                                                cell={
                                                                    cell?.testing ??
                                                                    null
                                                                }
                                                            />
                                                            <Version
                                                                tier="L"
                                                                cell={
                                                                    cell?.live ??
                                                                    null
                                                                }
                                                            />
                                                            {edition && (
                                                                <span className="text-[10px] text-muted-foreground">
                                                                    {edition}
                                                                </span>
                                                            )}
                                                        </div>
                                                    </td>
                                                );
                                            })}
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

VersionsIndex.layout = {
    breadcrumbs: [{ title: 'Versions', href: '/versions' }],
};
