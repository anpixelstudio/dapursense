import { router, usePage } from '@inertiajs/react';
import { Building2, Check, ChevronsUpDown } from 'lucide-react';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { ROLE_LABELS } from '@/lib/kitchen';
import { switchMethod } from '@/routes/tenants';

export function TenantSwitcher() {
    const { tenant, memberships } = usePage().props;

    if (!tenant) {
        return null;
    }

    return (
        <SidebarMenu>
            <SidebarMenuItem>
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <SidebarMenuButton
                            size="lg"
                            className="data-[state=open]:bg-sidebar-accent"
                        >
                            <div className="flex aspect-square size-8 items-center justify-center rounded-md bg-sidebar-primary text-sidebar-primary-foreground">
                                <Building2 className="size-4" />
                            </div>
                            <div className="grid flex-1 text-left text-sm leading-tight">
                                <span className="truncate font-medium">
                                    {tenant.name}
                                </span>
                                <span className="truncate text-xs text-muted-foreground">
                                    {ROLE_LABELS[tenant.role]}
                                </span>
                            </div>
                            <ChevronsUpDown className="ml-auto size-4" />
                        </SidebarMenuButton>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent className="min-w-56" align="start">
                        <DropdownMenuLabel>Organization</DropdownMenuLabel>
                        {memberships.map((m) => (
                            <DropdownMenuItem
                                key={m.tenant_id}
                                onSelect={() =>
                                    m.tenant_id !== tenant.id &&
                                    router.post(switchMethod().url, {
                                        tenant_id: m.tenant_id,
                                    })
                                }
                            >
                                <span className="flex-1 truncate">
                                    {m.name}
                                </span>
                                <span className="text-xs text-muted-foreground">
                                    {ROLE_LABELS[m.role]}
                                </span>
                                {m.tenant_id === tenant.id && (
                                    <Check className="size-4" />
                                )}
                            </DropdownMenuItem>
                        ))}
                    </DropdownMenuContent>
                </DropdownMenu>
            </SidebarMenuItem>
        </SidebarMenu>
    );
}
