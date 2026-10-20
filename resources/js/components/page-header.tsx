import type { ReactNode } from 'react';

/**
 * Every page opens the same way: what this page is, one sentence on what it
 * is for, and the page's one primary action on the right.
 */
export function PageHeader({
    eyebrow,
    title,
    description,
    actions,
    meta,
}: {
    eyebrow?: string;
    title: ReactNode;
    description?: ReactNode;
    actions?: ReactNode;
    /** Small facts under the title: badges, counts, a hostname. */
    meta?: ReactNode;
}) {
    return (
        <header className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div className="min-w-0">
                {eyebrow && (
                    <p className="mb-1 text-xs font-medium tracking-[0.18em] text-muted-foreground uppercase">
                        {eyebrow}
                    </p>
                )}
                <h1 className="font-display text-3xl font-semibold text-balance">
                    {title}
                </h1>
                {description && (
                    <p className="mt-2 max-w-2xl text-base leading-relaxed text-muted-foreground">
                        {description}
                    </p>
                )}
                {meta && (
                    <div className="mt-3 flex flex-wrap items-center gap-2">
                        {meta}
                    </div>
                )}
            </div>
            {actions && (
                <div className="flex shrink-0 flex-wrap gap-2 lg:justify-end">
                    {actions}
                </div>
            )}
        </header>
    );
}
