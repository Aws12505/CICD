import { Head, Link } from '@inertiajs/react';
import type { Terminal } from '@xterm/xterm';
import { Play } from 'lucide-react';
import { useCallback, useState } from 'react';
import { bytes } from '@/components/server-monitor';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { XtermView } from '@/components/xterm-view';

type Session = {
    id: number;
    user: string | null;
    container: string | null;
    started_at: string | null;
    ended_at: string | null;
    end_reason: string | null;
    client_ip: string | null;
    recording: boolean;
    recording_bytes: number;
    truncated: boolean;
};

type Props = {
    server: { id: number; name: string };
    sessions: Session[];
};

/** Plays an asciicast v2 transcript into a terminal. */
function Replay({ serverId, session }: { serverId: number; session: Session }) {
    const [speed, setSpeed] = useState(4);
    const [run, setRun] = useState(0);

    const play = useCallback(
        (term: Terminal) => {
            const timers: number[] = [];

            fetch(
                `/servers/${serverId}/terminal/sessions/${session.id}/recording`,
            )
                .then((r) => r.text())
                .then((text) => {
                    const [header, ...lines] = text.trim().split('\n');
                    const meta = JSON.parse(header) as {
                        width: number;
                        height: number;
                    };
                    term.resize(meta.width, meta.height);

                    lines.forEach((line) => {
                        const [at, kind, data] = JSON.parse(line) as [
                            number,
                            string,
                            string,
                        ];

                        if (kind !== 'o') {
                            return;
                        }

                        timers.push(
                            window.setTimeout(
                                () => term.write(data),
                                speed === 0 ? 0 : (at * 1000) / speed,
                            ),
                        );
                    });
                });

            return () => timers.forEach((t) => window.clearTimeout(t));
        },
        [serverId, session.id, speed],
    );

    return (
        <div className="grid gap-2">
            <div className="flex items-center gap-2 text-sm">
                Speed
                {[1, 4, 16, 0].map((s) => (
                    <Button
                        key={s}
                        size="sm"
                        variant={speed === s ? 'default' : 'outline'}
                        onClick={() => {
                            setSpeed(s);
                            setRun((r) => r + 1);
                        }}
                    >
                        {s === 0 ? 'instant' : `${s}×`}
                    </Button>
                ))}
            </div>
            <XtermView key={`${session.id}-${run}`} onReady={play} />
        </div>
    );
}

export default function TerminalSessions({ server, sessions }: Props) {
    const [open, setOpen] = useState<Session | null>(null);

    return (
        <>
            <Head title={`Terminal recordings · ${server.name}`} />
            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <h1 className="font-display text-3xl font-semibold">
                        Terminal recordings · {server.name}
                    </h1>
                    <Button asChild>
                        <Link href={`/servers/${server.id}/terminal`}>
                            Open a terminal
                        </Link>
                    </Button>
                </div>

                {open && (
                    <Card>
                        <CardHeader>
                            <CardTitle>
                                Session #{open.id} · {open.user ?? 'unknown'}
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <Replay serverId={server.id} session={open} />
                        </CardContent>
                    </Card>
                )}

                <Card>
                    <CardContent className="p-0">
                        <div className="divide-y divide-sidebar-border/70 dark:divide-sidebar-border">
                            {sessions.length === 0 && (
                                <p className="p-4 text-sm text-muted-foreground">
                                    No sessions yet.
                                </p>
                            )}
                            {sessions.map((s) => (
                                <div
                                    key={s.id}
                                    className="flex flex-wrap items-center justify-between gap-3 p-4"
                                >
                                    <div className="grid gap-1">
                                        <div className="flex flex-wrap items-center gap-2 text-sm">
                                            <span className="font-medium">
                                                {s.user ?? 'unknown'}
                                            </span>
                                            {s.container && (
                                                <Badge variant="outline">
                                                    {s.container}
                                                </Badge>
                                            )}
                                            {!s.ended_at && s.started_at && (
                                                <Badge>live</Badge>
                                            )}
                                            {!s.started_at && (
                                                <Badge variant="outline">
                                                    never connected
                                                </Badge>
                                            )}
                                        </div>
                                        <div className="font-mono text-xs text-muted-foreground">
                                            {s.started_at
                                                ? new Date(
                                                      s.started_at,
                                                  ).toLocaleString()
                                                : '—'}
                                            {s.ended_at &&
                                                ` → ${new Date(s.ended_at).toLocaleTimeString()}`}
                                            {s.end_reason &&
                                                ` · ${s.end_reason}`}
                                            {s.client_ip &&
                                                ` · from ${s.client_ip}`}
                                        </div>
                                    </div>
                                    {s.recording && (
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            onClick={() => setOpen(s)}
                                        >
                                            <Play className="size-4" /> Replay (
                                            {bytes(s.recording_bytes)}
                                            {s.truncated ? ', truncated' : ''})
                                        </Button>
                                    )}
                                </div>
                            ))}
                        </div>
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

TerminalSessions.layout = {
    breadcrumbs: [{ title: 'Servers', href: '/servers' }],
};
