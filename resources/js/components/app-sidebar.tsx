import { Link, usePage } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import { BrandLogo } from '@/components/hrm/brand-logo';
import { SidebarSearch } from '@/components/hrm/sidebar-search';
import { NavMain } from '@/components/nav-main';
import {
    Sidebar,
    SidebarContent,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { filterNavigation, navigation, withBadge } from '@/config/navigation';
import { dashboard } from '@/routes';

export function AppSidebar() {
    const [query, setQuery] = useState('');
    const { auth, updateFlag } = usePage().props;
    const permissions = auth.permissions;
    const updateWaiting = updateFlag?.available === true;

    const groups = useMemo(
        () =>
            filterNavigation(
                withBadge(
                    navigation,
                    'Updates',
                    updateWaiting ? 'New' : undefined,
                ),
                permissions,
                query,
            ),
        [permissions, query, updateWaiting],
    );

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href={dashboard()} prefetch>
                                <BrandLogo
                                    iconRail
                                    className="h-9 max-w-44 object-left"
                                />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
                <SidebarSearch value={query} onChange={setQuery} />
            </SidebarHeader>

            <SidebarContent>
                <NavMain groups={groups} expandAll={query.trim() !== ''} />
            </SidebarContent>
        </Sidebar>
    );
}
