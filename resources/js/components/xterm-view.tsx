import { FitAddon } from '@xterm/addon-fit';
import { Terminal } from '@xterm/xterm';
import '@xterm/xterm/css/xterm.css';
import { useEffect, useRef } from 'react';

/**
 * An xterm.js terminal that fills its box. The caller gets the Terminal once
 * it is mounted and is told whenever its size changes.
 */
export function XtermView({
    onReady,
    onResize,
    className = 'h-[70vh]',
}: {
    onReady: (term: Terminal) => (() => void) | void;
    onResize?: (cols: number, rows: number) => void;
    className?: string;
}) {
    const box = useRef<HTMLDivElement>(null);
    const ready = useRef(onReady);
    const resized = useRef(onResize);

    useEffect(() => {
        ready.current = onReady;
        resized.current = onResize;
    });

    useEffect(() => {
        if (!box.current) {
            return;
        }

        const term = new Terminal({
            cursorBlink: true,
            fontFamily:
                'ui-monospace, SFMono-Regular, Menlo, Consolas, monospace',
            fontSize: 13,
            scrollback: 10000,
            theme: { background: '#0b0f14' },
        });
        const fit = new FitAddon();
        term.loadAddon(fit);
        term.open(box.current);
        fit.fit();

        const cleanup = ready.current(term);
        const observer = new ResizeObserver(() => {
            fit.fit();
            resized.current?.(term.cols, term.rows);
        });
        observer.observe(box.current);

        return () => {
            observer.disconnect();
            cleanup?.();
            term.dispose();
        };
    }, []);

    return (
        <div
            ref={box}
            className={`overflow-hidden rounded-lg bg-[#0b0f14] p-2 ${className}`}
        />
    );
}
