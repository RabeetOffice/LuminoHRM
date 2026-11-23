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
import { filterNavigation, navigation } from '@/config/navigation';
import { dashboard } from '@/routes';

export function AppSidebar() {
    const [query, setQuery] = useState('');
    const { permissions } = usePage().props.auth;

    const groups = useMemo(
        () => filterNavigation(navigation, permissions, query),
        [permissions, query],
    );

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href={dashboard()} prefetch>
                                <BrandLogo
                                    // The uploaded logo replaces the whole lockup. In the icon rail
                                    // only its left square shows.
                                    className="h-8 max-w-44 object-contain object-left group-data-[collapsible=icon]:w-8 group-data-[collapsible=icon]:object-cover"
                                    fallback={
                                        <>
                                            <div className="flex aspect-square size-8 items-center justify-center rounded-md bg-sidebar-primary text-sm font-bold text-sidebar-primary-foreground">
                                                L
                                            </div>
                                            <span className="truncate text-base font-semibold tracking-tight">
                                                Lumino HRM
                                            </span>
                                        </>
                                    }
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
