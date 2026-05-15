import * as React from 'react';
import { SidebarInset } from '@/components/ui/sidebar';
import { cn } from '@/lib/utils';
import type { AppVariant } from '@/types';

type Props = React.ComponentProps<'main'> & {
    variant?: AppVariant;
};

export function AppContent({
    variant = 'sidebar',
    children,
    className,
    ...props
}: Props) {
    const backgroundLayers = (
        <>
            <div className="pointer-events-none absolute inset-0 -z-10 bg-[radial-gradient(1200px_600px_at_0%_-10%,rgba(56,189,248,0.16),transparent_60%)]" />
            <div className="pointer-events-none absolute inset-0 -z-10 bg-[radial-gradient(900px_500px_at_100%_-20%,rgba(251,191,36,0.14),transparent_55%)]" />
            <div className="pointer-events-none absolute inset-0 -z-10 bg-[linear-gradient(to_bottom,rgba(15,23,42,0.04),transparent_30%)] dark:bg-[linear-gradient(to_bottom,rgba(2,6,23,0.5),transparent_35%)]" />
        </>
    );

    if (variant === 'sidebar') {
        return (
            <SidebarInset
                className={cn('overflow-hidden', className)}
                {...props}
            >
                <div className="relative flex min-h-svh flex-1 flex-col">
                    {backgroundLayers}
                    <div className="relative flex min-h-svh flex-1 flex-col">
                        {children}
                    </div>
                </div>
            </SidebarInset>
        );
    }

    return (
        <main
            className={cn(
                'relative mx-auto flex h-full w-full max-w-7xl flex-1 flex-col gap-4 rounded-xl',
                className,
            )}
            {...props}
        >
            {backgroundLayers}
            <div className="relative flex min-h-svh flex-1 flex-col">
                {children}
            </div>
        </main>
    );
}
