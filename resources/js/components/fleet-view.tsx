import { Link } from '@inertiajs/react';
import { AlertTriangle } from 'lucide-react';
import { Gauge } from '@/components/server-monitor';
import type { Sample } from '@/components/server-monitor';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';

export type FleetServer = {
    id: number;
    name: string;
    hostname: string;
    status: string;
    provisioned: boolean;
    last_seen_at: string | null;
    sample: Sample | null;
    stacks: string[];
    alerts: { level: 'critical' | 'warning'; message: string }[];
};

function Dot({ server }: { server: FleetServer }) {
    const critical = server.alerts.some((a) => a.level === 'critical');
    const warning = server.alerts.length > 0;
    const color = critical
        ? 'bg-destructive'
        : warning
          ? 'bg-amber-500'
          : 'bg-emerald-500';

    return <span className={`inline-block size-2.5 rounded-full ${color}`} />;
}

/** Every server, health first. Red is something to act on now. */
export function FleetView({ servers }: { servers: FleetServer[] }) {
    if (servers.length === 0) {
        return null;
    }

    const sorted = [...servers].sort(
        (a, b) => b.alerts.length - a.alerts.length,
    );

    return (
        <Card>
            <CardHeader>
                <CardTitle>Fleet</CardTitle>
            </CardHeader>
            <CardContent className="grid gap-4 lg:grid-cols-2">
                {sorted.map((server) => (
                    <Link
                        key={server.id}
                        href={`/servers/${server.id}`}
                        className="grid gap-3 rounded-xl border border-sidebar-border/60 p-4 transition hover:bg-muted/40"
                    >
                        <div className="flex items-center justify-between gap-2">
                            <div className="flex items-center gap-2 font-medium">
                                <Dot server={server} />
                                {server.name}
                            </div>
                            <span className="truncate font-mono text-xs text-muted-foreground">
                                {server.stacks.join(' · ') || server.hostname}
                            </span>
                        </div>
                        {server.sample ? (
                            <div className="grid grid-cols-3 gap-2">
                                <Gauge
                                    label="CPU"
                                    percent={server.sample.cpu}
                                    alertAt={90}
                                    detail={`load ${server.sample.load[0].toFixed(2)}`}
                                />
                                <Gauge
                                    label="Memory"
                                    percent={server.sample.mem}
                                    alertAt={90}
                                    detail=""
                                />
                                <Gauge
                                    label="Disk"
                                    percent={server.sample.disk}
                                    alertAt={85}
                                    detail=""
                                />
                            </div>
                        ) : (
                            <p className="text-xs text-muted-foreground">
                                {server.provisioned
                                    ? 'No metrics yet.'
                                    : 'Not set up yet.'}
                            </p>
                        )}
                        {server.alerts.map((a) => (
                            <div
                                key={a.message}
                                className={`flex items-center gap-2 text-xs ${a.level === 'critical' ? 'text-destructive' : 'text-amber-700 dark:text-amber-400'}`}
                            >
                                <AlertTriangle className="size-3 shrink-0" />
                                {a.message}
                            </div>
                        ))}
                    </Link>
                ))}
            </CardContent>
        </Card>
    );
}
