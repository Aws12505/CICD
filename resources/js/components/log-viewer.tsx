import { useEffect, useRef, useState } from 'react';
import { cn } from '@/lib/utils';

interface LogViewerProps {
    /**
     * The private channel to listen on. Defaults to a pipeline run's channel
     * when only `runId` is given, which is how every older page uses it.
     */
    channel?: string;
    /** The broadcast event carrying `{ line }`. */
    event?: string;
    runId?: number;
    initialLog: string;
    live: boolean;
    className?: string;
}

export function LogViewer({
    channel,
    event = '.PipelineLogLine',
    runId,
    initialLog,
    live,
    className,
}: LogViewerProps) {
    const [log, setLog] = useState(initialLog);
    const [syncedInitial, setSyncedInitial] = useState(initialLog);
    const containerRef = useRef<HTMLPreElement>(null);
    const stickToBottom = useRef(true);

    const channelName = channel ?? `pipeline.run.${runId}`;

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

        const subscription = window.Echo.private(channelName);
        subscription.listen(event, (e: { line: string }) => {
            setLog((prev) => prev + e.line);
        });

        return () => {
            // Stop listening for this event only. Leaving the whole channel
            // would also tear down a status listener another component on the
            // same page has on it.
            subscription.stopListening(event);
        };
    }, [channelName, event, live]);

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

        stickToBottom.current =
            el.scrollHeight - el.scrollTop - el.clientHeight < 40;
    };

    return (
        <pre
            ref={containerRef}
            onScroll={handleScroll}
            className={cn(
                'h-[60vh] overflow-auto rounded-lg bg-zinc-950 p-4 font-mono text-xs leading-relaxed whitespace-pre-wrap text-zinc-200 ring-1 ring-zinc-800',
                className,
            )}
        >
            {log || <span className="text-zinc-500">Waiting for output…</span>}
        </pre>
    );
}
