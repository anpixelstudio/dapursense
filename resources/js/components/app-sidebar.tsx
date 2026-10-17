import { Link, usePage } from '@inertiajs/react';
import {
    Activity,
    Bell,
    Building2,
    Camera,
    FileText,
    LayoutGrid,
    MonitorPlay,
    RadioTower,
    ServerCog,
    ShieldAlert,
    SlidersHorizontal,
    Sparkles,
    Users,
    Video,
} from 'lucide-react';
import AppLogo from '@/components/app-logo';
import { TenantSwitcher } from '@/components/kitchen/tenant-switcher';
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
import { useTenant } from '@/lib/kitchen';
import { dashboard, monitor } from '@/routes';
import activities from '@/routes/activities';
import assistant from '@/routes/assistant';
import events from '@/routes/events';
import locations from '@/routes/locations';
import members from '@/routes/members';
import notifications from '@/routes/notifications';
import reports from '@/routes/reports';
import rules from '@/routes/rules';
import sources from '@/routes/sources';
import system from '@/routes/system';
import uptime from '@/routes/uptime';
import videos from '@/routes/videos';
import type { NavItem } from '@/types';

export function AppSidebar() {
    const { app, unreadNotifications } = usePage().props;
    const { tenant, can } = useTenant();

    const operations: NavItem[] = tenant
        ? [
              { title: 'Dashboard', href: dashboard(), icon: LayoutGrid },
              { title: 'Monitor CCTV', href: monitor(), icon: MonitorPlay },
              {
                  title: 'Device Uptime',
                  href: uptime.index(),
                  icon: RadioTower,
              },
              { title: 'Video & Analysis', href: videos.index(), icon: Video },
              { title: 'Anomalies', href: events.index(), icon: ShieldAlert },
              { title: 'Activities', href: activities.index(), icon: Activity },
              { title: 'Reports', href: reports.index(), icon: FileText },
              {
                  title: 'Assistant',
                  href: assistant.index(),
                  icon: Sparkles,
              },
              {
                  title:
                      unreadNotifications > 0
                          ? `Notifications (${unreadNotifications})`
                          : 'Notifications',
                  href: notifications.index(),
                  icon: Bell,
              },
          ]
        : [];

    const settings: NavItem[] = tenant
        ? [
              { title: 'Locations', href: locations.index(), icon: Building2 },
              { title: 'Video Sources', href: sources.index(), icon: Camera },
              {
                  title: 'SOP Rules & Models',
                  href: rules.index(),
                  icon: SlidersHorizontal,
              },
              { title: 'Members', href: members.index(), icon: Users },
              ...(can('manage_organization') || app.isPlatformAdmin
                  ? [{ title: 'System', href: system.index(), icon: ServerCog }]
                  : []),
          ]
        : [];

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
                <TenantSwitcher />
            </SidebarHeader>

            <SidebarContent>
                <NavMain label="Operations" items={operations} />
                <NavMain label="Settings" items={settings} />
            </SidebarContent>

            <SidebarFooter>
                <p className="px-2 text-xs text-muted-foreground group-data-[collapsible=icon]:hidden">
                    v{app.version}
                </p>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
