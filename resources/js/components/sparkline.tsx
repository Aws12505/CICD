/**
 * A dependency-free line chart for metric history. Values are plotted
 * against a fixed maximum (100 for percentages) or their own peak.
 */
export function Sparkline({
    values,
    max,
    height = 48,
    className = 'text-primary',
}: {
    values: number[];
    max?: number;
    height?: number;
    className?: string;
}) {
    const width = 300;

    if (values.length < 2) {
        return (
            <div
                style={{ height }}
                className="flex items-center text-xs text-muted-foreground"
            >
                Not enough data yet
            </div>
        );
    }

    const top = max ?? Math.max(...values, 1);
    const step = width / (values.length - 1);
    const y = (v: number) =>
        height - (Math.min(v, top) / top) * (height - 2) - 1;
    const line = values
        .map((v, i) => `${(i * step).toFixed(1)},${y(v).toFixed(1)}`)
        .join(' ');

    return (
        <svg
            viewBox={`0 0 ${width} ${height}`}
            preserveAspectRatio="none"
            className={`w-full ${className}`}
            style={{ height }}
            role="img"
        >
            <polygon
                points={`0,${height} ${line} ${width},${height}`}
                className="fill-current opacity-10"
            />
            <polyline
                points={line}
                fill="none"
                className="stroke-current"
                strokeWidth={1.5}
                vectorEffect="non-scaling-stroke"
            />
        </svg>
    );
}
