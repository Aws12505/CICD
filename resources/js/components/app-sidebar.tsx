import { Link } from '@inertiajs/react';
import {
    Activity,
    BookOpen,
    Boxes,
    HeartPulse,
    Building2,
    History,
    Layers,
    LayoutGrid,
    ListChecks,
    Package,
    Rocket,
    Server,
    Settings2,
    Table2,
} from 'lucide-react';
import AppLogo from '@/components/app-logo';
import { NavFooter } from '@/components/nav-footer';
import { NavMain } from '@/components/nav-main';
import type { NavGroup } from '@/components/nav-main';
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

export function AppSidebar() {
    // Servers and Settings hold keys to client machines, so even the nav
    // entries are admin-only; routes/web.php is what enforces it.
    const { destroy: isAdmin } = useAbilities();

    const groups: NavGroup[] = [
        {
            label: 'Start here',
            items: [
                { title: 'Dashboard', href: dashboard(), icon: LayoutGrid },
                { title: 'Guide', href: '/guide', icon: BookOpen },
            ],
        },
        {
            label: 'Set up once',
            items: [
                ...(isAdmin
                    ? [
                          {
                              title: 'Settings',
                              href: '/control-plane/settings',
                              icon: Settings2,
                          },
                      ]
                    : []),
                { title: 'Services', href: '/services', icon: Layers },
                ...(isAdmin
                    ? [{ title: 'Servers', href: '/servers', icon: Server }]
                    : []),
            ],
        },
        {
            label: 'Clients',
            items: [
                { title: 'Clients', href: '/clients', icon: Building2 },
                { title: 'Versions', href: '/versions', icon: Table2 },
            ],
        },
        {
            label: 'Ship',
            items: [
                { title: 'Builds', href: '/builds', icon: Package },
                { title: 'Releases', href: '/releases', icon: Rocket },
            ],
        },
        {
            label: 'Watch',
            items: [
                { title: 'Operations', href: '/operations', icon: Activity },
                ...(isAdmin
                    ? [
                          {
                              title: 'System health',
                              href: '/control-plane/system',
                              icon: HeartPulse,
                          },
                      ]
                    : []),
                { title: 'Activity', href: '/tasks', icon: History },
            ],
        },
        {
            label: 'Old pipelines',
            items: [
                { title: 'Projects', href: '/projects', icon: Boxes },
                { title: 'Runs', href: '/runs', icon: ListChecks },
            ],
        },
    ];

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
                <NavMain groups={groups} />
            </SidebarContent>

            <SidebarFooter>
                <NavFooter items={[]} className="mt-auto" />
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
