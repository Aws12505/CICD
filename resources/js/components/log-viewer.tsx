import { useEffect, useRef, useState } from 'react';
import { cn } from '@/lib/utils';

interface LogViewerProps {
    runId: number;
    initialLog: string;
    live: boolean;
    className?: string;
}

export function LogViewer({ runId, initialLog, live, className }: LogViewerProps) {
    const [log, setLog] = useState(initialLog);
    const [syncedInitial, setSyncedInitial] = useState(initialLog);
    const containerRef = useRef<HTMLPreElement>(null);
    const stickToBottom = useRef(true);

    // Reset buffer when the server sends a fresh log (e.g. after reload)
    // without using an effect.
    if (syncedInitial !== initialLog) {
        setSyncedInitial(initialLog);
        setLog(initialLog);
    }

    useEffect(() => {
        if (!live) {
return;
}

        const channel = window.Echo.private(`pipeline.run.${runId}`);
        channel.listen('.PipelineLogLine', (e: { line: string }) => {
            setLog((prev) => prev + e.line);
        });

        return () => {
            window.Echo.leave(`pipeline.run.${runId}`);
        };
    }, [runId, live]);

    useEffect(() => {
        const el = containerRef.current;

        if (el && stickToBottom.current) {
            el.scrollTop = el.scrollHeight;
        }
    }, [log]);

    const handleScroll = () => {
        const el = containerRef.current;

        if (!el) {
return;
}

        stickToBottom.current = el.scrollHeight - el.scrollTop - el.clientHeight < 40;
    };

    return (
        <pre
            ref={containerRef}
            onScroll={handleScroll}
            className={cn(
                'h-[60vh] overflow-auto rounded-lg bg-zinc-950 p-4 font-mono text-xs leading-relaxed text-zinc-200 ring-1 ring-zinc-800',
                className,
            )}
        >
            {log || <span className="text-zinc-500">Waiting for output…</span>}
        </pre>
    );
}
