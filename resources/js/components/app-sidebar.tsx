import { Link } from '@inertiajs/react';
import { LayoutGrid, ListChecks, Boxes, Server } from 'lucide-react';
import AppLogo from '@/components/app-logo';
import { NavFooter } from '@/components/nav-footer';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useAbilities } from '@/hooks/use-abilities';
import { dashboard } from '@/routes';
import type { NavItem } from '@/types';

const mainNavItems: NavItem[] = [
    {
        title: 'Dashboard',
        href: dashboard(),
        icon: LayoutGrid,
    },
    {
        title: 'Projects',
        href: '/projects',
        icon: Boxes,
    },
    {
        title: 'Runs',
        href: '/runs',
        icon: ListChecks,
    },
    {
        title: 'Clients',
        href: '/clients',
        icon: Building2,
    },
    {
        title: 'Services',
        href: '/services',
        icon: Layers,
    },
];

// Servers hold keys that grant root on client machines, so even the nav
// entry is admin-only — see routes/web.php, which is what enforces it.
const adminNavItems: NavItem[] = [
    {
        title: 'Servers',
        href: '/servers',
        icon: Server,
    },
];

const footerNavItems: NavItem[] = [];

export function AppSidebar() {
    const { destroy: isAdmin } = useAbilities();

    const navItems = isAdmin
        ? [...mainNavItems, ...adminNavItems]
        : mainNavItems;

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href={dashboard()} prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={navItems} />
            </SidebarContent>

            <SidebarFooter>
                <NavFooter items={footerNavItems} className="mt-auto" />
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
