import { router } from '@inertiajs/react';
import { AlertTriangle, FileText, RotateCw } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import { Sparkline } from '@/components/sparkline';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

export type Sample = {
    recorded_at: string;
    cpu: number;
    mem: number;
    mem_used: number;
    mem_total: number;
    disk: number;
    disk_used: number;
    disk_total: number;
    load: [number, number, number];
    uptime_seconds: number;
    containers_total: number;
    containers_down: number;
};

type Container = {
    name: string;
    state: string;
    status: string;
    image: string;
    running: boolean;
    cpu: number;
    mem_bytes: number;
    mem_percent: number;
    build: string | null;
};

type Alert = { level: 'critical' | 'warning'; message: string };

type Live = {
    status: string;
    sample: Sample | null;
    containers: Container[];
    alerts: Alert[];
    certificates: { domain: string; expires_at: string }[];
};

type Point = {
    t: string;
    cpu: number;
    mem: number;
    disk: number;
    load: number;
    rx: number;
    tx: number;
};

export const bytes = (n: number) => {
    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    let i = 0;

    while (n >= 1024 && i < units.length - 1) {
        n /= 1024;
        i++;
    }

    return `${n.toFixed(i === 0 ? 0 : 1)} ${units[i]}`;
};

const uptime = (s: number) => {
    const d = Math.floor(s / 86400);
    const h = Math.floor((s % 86400) / 3600);

    return d > 0 ? `${d}d ${h}h` : `${h}h ${Math.floor((s % 3600) / 60)}m`;
};

export function Gauge({
    label,
    percent,
    detail,
    alertAt,
}: {
    label: string;
    percent: number;
    detail: string;
    alertAt: number;
}) {
    const hot = percent >= alertAt;

    return (
        <div className="rounded-xl border border-sidebar-border/60 bg-background/60 p-4">
            <div className="flex items-baseline justify-between">
                <span className="text-xs text-muted-foreground">{label}</span>
                <span
                    className={`font-mono text-lg ${hot ? 'text-destructive' : ''}`}
                >
                    {percent.toFixed(0)}%
                </span>
            </div>
            <div className="mt-2 h-1.5 overflow-hidden rounded-full bg-muted">
                <div
                    className={`h-full rounded-full ${hot ? 'bg-destructive' : 'bg-primary'}`}
                    style={{ width: `${Math.min(100, percent)}%` }}
                />
            </div>
            <div className="mt-2 font-mono text-xs text-muted-foreground">
                {detail}
            </div>
        </div>
    );
}

/**
 * Live server health: refreshed every five seconds while the page is visible,
 * with history charts, containers, their logs, and restart.
 */
export function ServerMonitor({ serverId }: { serverId: number }) {
    const [live, setLive] = useState<Live | null>(null);
    const [failed, setFailed] = useState(false);
    const [range, setRange] = useState<'1h' | '24h' | '7d'>('24h');
    const [points, setPoints] = useState<Point[]>([]);
    const [logs, setLogs] = useState<{ name: string; text: string } | null>(
        null,
    );

    const refresh = useCallback(() => {
        if (document.visibilityState !== 'visible') {
            return;
        }

        fetch(`/servers/${serverId}/metrics/live`, {
            headers: { Accept: 'application/json' },
        })
            .then((r) => (r.ok ? r.json() : Promise.reject(r)))
            .then((data: Live) => {
                setLive(data);
                setFailed(false);
            })
            .catch(() => setFailed(true));
    }, [serverId]);

    useEffect(() => {
        refresh();
        const timer = window.setInterval(refresh, 5000);

        return () => window.clearInterval(timer);
    }, [refresh]);

    useEffect(() => {
        fetch(`/servers/${serverId}/metrics?range=${range}`, {
            headers: { Accept: 'application/json' },
        })
            .then((r) => r.json())
            .then((data: { points: Point[] }) => setPoints(data.points));
    }, [serverId, range]);

    const openLogs = (name: string) => {
        setLogs({ name, text: 'Loading…' });
        fetch(`/servers/${serverId}/containers/${name}/logs?tail=400`, {
            headers: { Accept: 'application/json' },
        })
            .then((r) => r.json())
            .then((data: { log: string }) =>
                setLogs({ name, text: data.log || '(empty)' }),
            );
    };

    const sample = live?.sample;

    return (
        <div className="flex flex-col gap-6">
            {live && live.alerts.length > 0 && (
                <Card className="border-destructive/40 bg-destructive/5">
                    <CardContent className="grid gap-1 p-4">
                        {live.alerts.map((a) => (
                            <div
                                key={a.message}
                                className={`flex items-center gap-2 text-sm ${a.level === 'critical' ? 'text-destructive' : 'text-amber-700 dark:text-amber-400'}`}
                            >
                                <AlertTriangle className="size-4 shrink-0" />
                                {a.message}
                            </div>
                        ))}
                    </CardContent>
                </Card>
            )}

            <Card>
                <CardHeader className="flex-row items-center justify-between space-y-0">
                    <CardTitle>Now</CardTitle>
                    <span className="text-xs text-muted-foreground">
                        {failed
                            ? 'Could not reach the server'
                            : sample
                              ? `updated ${new Date(sample.recorded_at).toLocaleTimeString()} · up ${uptime(sample.uptime_seconds)}`
                              : 'Sampling…'}
                    </span>
                </CardHeader>
                <CardContent className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    {sample ? (
                        <>
                            <Gauge
                                label="CPU"
                                percent={sample.cpu}
                                alertAt={90}
                                detail={`load ${sample.load.map((l) => l.toFixed(2)).join(' ')}`}
                            />
                            <Gauge
                                label="Memory"
                                percent={sample.mem}
                                alertAt={90}
                                detail={`${bytes(sample.mem_used)} of ${bytes(sample.mem_total)}`}
                            />
                            <Gauge
                                label="Disk /"
                                percent={sample.disk}
                                alertAt={85}
                                detail={`${bytes(sample.disk_used)} of ${bytes(sample.disk_total)}`}
                            />
                            <Gauge
                                label="Containers"
                                percent={
                                    sample.containers_total
                                        ? (100 *
                                              (sample.containers_total -
                                                  sample.containers_down)) /
                                          sample.containers_total
                                        : 0
                                }
                                alertAt={101}
                                detail={`${sample.containers_total - sample.containers_down} of ${sample.containers_total} running`}
                            />
                        </>
                    ) : (
                        <p className="text-sm text-muted-foreground">
                            {failed
                                ? 'No answer over SSH.'
                                : 'Taking the first sample…'}
                        </p>
                    )}
                </CardContent>
            </Card>

            <Card>
                <CardHeader className="flex-row items-center justify-between space-y-0">
                    <CardTitle>History</CardTitle>
                    <div className="flex gap-1">
                        {(['1h', '24h', '7d'] as const).map((r) => (
                            <Button
                                key={r}
                                size="sm"
                                variant={range === r ? 'default' : 'outline'}
                                onClick={() => setRange(r)}
                            >
                                {r}
                            </Button>
                        ))}
                    </div>
                </CardHeader>
                <CardContent className="grid gap-6 sm:grid-cols-2">
                    {(
                        [
                            ['CPU %', points.map((p) => p.cpu), 100],
                            ['Memory %', points.map((p) => p.mem), 100],
                            ['Disk %', points.map((p) => p.disk), 100],
                            [
                                'Load (1 min)',
                                points.map((p) => p.load),
                                undefined,
                            ],
                            [
                                'Network in (B/s)',
                                points.map((p) => p.rx),
                                undefined,
                            ],
                            [
                                'Network out (B/s)',
                                points.map((p) => p.tx),
                                undefined,
                            ],
                        ] as [string, number[], number | undefined][]
                    ).map(([label, values, max]) => (
                        <div key={label}>
                            <div className="mb-1 flex justify-between text-xs text-muted-foreground">
                                <span>{label}</span>
                                <span className="font-mono">
                                    {values.length
                                        ? label.includes('B/s')
                                            ? `${bytes(values[values.length - 1])}/s`
                                            : values[values.length - 1].toFixed(
                                                  1,
                                              )
                                        : ''}
                                </span>
                            </div>
                            <Sparkline values={values} max={max} />
                        </div>
                    ))}
                </CardContent>
            </Card>

            <Card>
                <CardHeader className="border-b border-sidebar-border/70">
                    <CardTitle>Containers</CardTitle>
                </CardHeader>
                <CardContent className="overflow-x-auto p-0">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b border-sidebar-border/70 text-left text-xs text-muted-foreground">
                                <th className="p-3 font-normal">Name</th>
                                <th className="p-3 font-normal">Running</th>
                                <th className="p-3 font-normal">CPU</th>
                                <th className="p-3 font-normal">Memory</th>
                                <th className="p-3" />
                            </tr>
                        </thead>
                        <tbody>
                            {(live?.containers ?? []).map((c) => (
                                <tr
                                    key={c.name}
                                    className="border-b border-sidebar-border/40 last:border-0"
                                >
                                    <td className="p-3">
                                        <div className="font-mono text-xs">
                                            {c.name}
                                        </div>
                                        <div className="text-xs text-muted-foreground">
                                            {c.build ?? c.image.split('@')[0]}
                                        </div>
                                    </td>
                                    <td className="p-3">
                                        <Badge
                                            variant={
                                                c.running
                                                    ? 'secondary'
                                                    : 'destructive'
                                            }
                                        >
                                            {c.status}
                                        </Badge>
                                    </td>
                                    <td className="p-3 font-mono text-xs">
                                        {c.cpu.toFixed(1)}%
                                    </td>
                                    <td className="p-3 font-mono text-xs">
                                        {bytes(c.mem_bytes)}
                                    </td>
                                    <td className="p-3">
                                        <div className="flex justify-end gap-1">
                                            <Button
                                                size="sm"
                                                variant="ghost"
                                                onClick={() => openLogs(c.name)}
                                            >
                                                <FileText className="size-4" />{' '}
                                                Logs
                                            </Button>
                                            <Button
                                                size="sm"
                                                variant="ghost"
                                                onClick={() => {
                                                    if (
                                                        confirm(
                                                            `Restart ${c.name}?`,
                                                        )
                                                    ) {
                                                        router.post(
                                                            `/servers/${serverId}/containers/${c.name}/restart`,
                                                        );
                                                    }
                                                }}
                                            >
                                                <RotateCw className="size-4" />{' '}
                                                Restart
                                            </Button>
                                        </div>
                                    </td>
                                </tr>
                            ))}
                            {live && live.containers.length === 0 && (
                                <tr>
                                    <td
                                        colSpan={5}
                                        className="p-4 text-muted-foreground"
                                    >
                                        No containers.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </CardContent>
            </Card>

            <Dialog
                open={logs !== null}
                onOpenChange={(o) => !o && setLogs(null)}
            >
                <DialogContent className="max-w-5xl">
                    <DialogHeader>
                        <DialogTitle className="font-mono text-sm">
                            {logs?.name}
                        </DialogTitle>
                    </DialogHeader>
                    <pre className="max-h-[70vh] overflow-auto rounded-md bg-muted/50 p-3 font-mono text-xs whitespace-pre-wrap">
                        {logs?.text}
                    </pre>
                    {logs && (
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={() => openLogs(logs.name)}
                        >
                            Refresh
                        </Button>
                    )}
                </DialogContent>
            </Dialog>
        </div>
    );
}
