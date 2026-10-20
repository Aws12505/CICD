import { Link, usePage } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';
import { Breadcrumbs } from '@/components/breadcrumbs';
import { SidebarTrigger } from '@/components/ui/sidebar';
import type { BreadcrumbItem as BreadcrumbItemType } from '@/types';
import type { GuideStep } from '@/types/guide';

/**
 * Breadcrumbs on the left; on the right, the one thing to do next, so the
 * guidance follows you off the dashboard.
 */
export function AppSidebarHeader({
    breadcrumbs = [],
}: {
    breadcrumbs?: BreadcrumbItemType[];
}) {
    const page = usePage<{ nextStep: GuideStep | null }>();
    const nextStep = page.props.nextStep;
    const onDashboard = page.url.startsWith('/dashboard');

    return (
        <header className="flex h-16 shrink-0 items-center justify-between gap-4 border-b border-sidebar-border/60 bg-background/80 px-4 backdrop-blur transition-[width,height] ease-linear group-has-data-[collapsible=icon]/sidebar-wrapper:h-12 md:px-6">
            <div className="flex min-w-0 items-center gap-2">
                <SidebarTrigger className="-ml-1" />
                <Breadcrumbs breadcrumbs={breadcrumbs} />
            </div>
            {nextStep && !onDashboard && (
                <Link
                    href={nextStep.action ? '/dashboard' : nextStep.href}
                    className="hidden min-h-9 items-center gap-2 rounded-full border border-primary/30 bg-primary/8 px-3 text-sm text-primary transition-colors hover:bg-primary/15 md:inline-flex"
                >
                    <span className="text-xs font-medium tracking-wide text-primary/70 uppercase">
                        Next
                    </span>
                    <span className="max-w-[28rem] truncate">
                        {nextStep.scope && nextStep.scope !== 'Setup'
                            ? `${nextStep.scope}: `
                            : ''}
                        {nextStep.title}
                    </span>
                    <ArrowRight className="size-4 shrink-0" />
                </Link>
            )}
        </header>
    );
}
