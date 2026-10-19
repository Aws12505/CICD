import { Head, Link } from '@inertiajs/react';
import type { Terminal } from '@xterm/xterm';
import { History } from 'lucide-react';
import { useCallback, useRef, useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { XtermView } from '@/components/xterm-view';

type Props = {
    server: { id: number; name: string; hostname: string };
    container: string | null;
    enabled: boolean;
};

const csrf = () =>
    decodeURIComponent(
        document.cookie
            .split('; ')
            .find((c) => c.startsWith('XSRF-TOKEN='))
            ?.split('=')[1] ?? '',
    );

export default function ServerTerminal({ server, container, enabled }: Props) {
    const socket = useRef<WebSocket | null>(null);
    const [state, setState] = useState<'connecting' | 'open' | 'closed'>(
        'connecting',
    );
    const [attempt, setAttempt] = useState(0);

    const connect = useCallback(
        (term: Terminal) => {
            let closed = false;

            term.writeln(
                `\x1b[90mConnecting to ${container ? `${container} on ` : ''}${server.name}… This session is recorded.\x1b[0m`,
            );

            fetch(`/servers/${server.id}/terminal`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': csrf(),
                },
                body: JSON.stringify({ container }),
            })
                .then(async (r) => {
                    if (!r.ok) {
                        throw new Error(
                            (await r.json().catch(() => null))?.message ??
                                `HTTP ${r.status}`,
                        );
                    }

                    return r.json();
                })
                .then((issued: { token: string; url: string }) => {
                    if (closed) {
                        return;
                    }

                    const base = issued.url.startsWith('/')
                        ? `${location.protocol === 'https:' ? 'wss' : 'ws'}://${location.host}${issued.url}`
                        : issued.url;
                    const ws = new WebSocket(
                        `${base}?token=${encodeURIComponent(issued.token)}`,
                    );
                    socket.current = ws;

                    ws.onopen = () => {
                        setState('open');
                        ws.send(
                            JSON.stringify({
                                type: 'resize',
                                cols: term.cols,
                                rows: term.rows,
                            }),
                        );
                        term.focus();
                    };
                    ws.onmessage = (e) => term.write(String(e.data));
                    ws.onclose = (e) => {
                        setState('closed');
                        term.writeln(
                            `\r\n\x1b[90mDisconnected${e.reason ? `: ${e.reason}` : ''}.\x1b[0m`,
                        );
                    };
                })
                .catch((error: Error) => {
                    setState('closed');
                    term.writeln(
                        `\r\n\x1b[31mCould not open a terminal: ${error.message}\x1b[0m`,
                    );
                });

            const input = term.onData((data) => {
                if (socket.current?.readyState === WebSocket.OPEN) {
                    socket.current.send(JSON.stringify({ type: 'data', data }));
                }
            });

            return () => {
                closed = true;
                input.dispose();
                socket.current?.close();
            };
        },
        [server.id, server.name, container],
    );

    const resize = useCallback((cols: number, rows: number) => {
        if (socket.current?.readyState === WebSocket.OPEN) {
            socket.current.send(JSON.stringify({ type: 'resize', cols, rows }));
        }
    }, []);

    return (
        <>
            <Head title={`Terminal · ${server.name}`} />
            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div className="flex items-center gap-3">
                        <h1 className="font-display text-2xl font-semibold">
                            {container ?? server.name}
                        </h1>
                        <Badge
                            variant={
                                state === 'open'
                                    ? 'secondary'
                                    : state === 'closed'
                                      ? 'destructive'
                                      : 'outline'
                            }
                        >
                            {state}
                        </Badge>
                        <span className="font-mono text-xs text-muted-foreground">
                            {container ? `on ${server.name}` : server.hostname}
                        </span>
                    </div>
                    <div className="flex gap-2">
                        {state === 'closed' && (
                            <Button
                                variant="outline"
                                onClick={() => {
                                    setState('connecting');
                                    setAttempt((a) => a + 1);
                                }}
                            >
                                Reconnect
                            </Button>
                        )}
                        <Button asChild variant="outline">
                            <Link
                                href={`/servers/${server.id}/terminal/sessions`}
                            >
                                <History className="size-4" /> Recordings
                            </Link>
                        </Button>
                    </div>
                </div>

                {enabled ? (
                    <XtermView
                        key={attempt}
                        onReady={connect}
                        onResize={resize}
                    />
                ) : (
                    <Card>
                        <CardContent className="p-4 text-sm text-muted-foreground">
                            The terminal gateway is not configured. Set
                            TERMINAL_GATEWAY_SECRET in the control plane's .env
                            and start the terminal-gateway service.
                        </CardContent>
                    </Card>
                )}
            </div>
        </>
    );
}

ServerTerminal.layout = {
    breadcrumbs: [{ title: 'Servers', href: '/servers' }],
};
