import { Link } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    SidebarMenuSub,
    SidebarMenuSubButton,
    SidebarMenuSubItem,
} from '@/components/ui/sidebar';
import { activeHref } from '@/config/navigation';
import type { NavGroup, NavItem } from '@/config/navigation';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { toUrl } from '@/lib/utils';

type Props = {
    groups: NavGroup[];
    /** Expand every parent (used while a search query is active). */
    expandAll?: boolean;
};

export function NavMain({ groups, expandAll = false }: Props) {
    const { currentUrl } = useCurrentUrl();
    const active = activeHref(currentUrl);
    const isActive = (item: NavItem) =>
        item.href !== undefined && toUrl(item.href) === active;

    if (groups.length === 0) {
        return (
            <p className="px-4 py-3 text-sm text-muted-foreground">
                No menu items match
            </p>
        );
    }

    return (
        <>
            {groups.map((group) => (
                <SidebarGroup key={group.label} className="px-2 py-1">
                    <SidebarGroupLabel className="text-[11px] font-semibold tracking-wider uppercase">
                        {group.label}
                    </SidebarGroupLabel>
                    <SidebarMenu>
                        {group.items.map((item) =>
                            item.children ? (
                                <ParentItem
                                    key={item.title}
                                    item={item}
                                    isActive={isActive}
                                    forceOpen={expandAll}
                                />
                            ) : (
                                <SidebarMenuItem key={item.title}>
                                    <SidebarMenuButton
                                        asChild
                                        isActive={isActive(item)}
                                        tooltip={{ children: item.title }}
                                    >
                                        <Link
                                            href={item.href ?? '#'}
                                            prefetch
                                            aria-current={
                                                isActive(item)
                                                    ? 'page'
                                                    : undefined
                                            }
                                        >
                                            {item.icon && <item.icon />}
                                            <span>{item.title}</span>
                                        </Link>
                                    </SidebarMenuButton>
                                </SidebarMenuItem>
                            ),
                        )}
                    </SidebarMenu>
                </SidebarGroup>
            ))}
        </>
    );
}

function ParentItem({
    item,
    isActive,
    forceOpen,
}: {
    item: NavItem;
    isActive: (item: NavItem) => boolean;
    forceOpen: boolean;
}) {
    const children = item.children ?? [];
    const hasActiveChild = children.some(isActive);

    // The key resets the open state when the active child or search mode changes.
    return (
        <Collapsible
            key={`${hasActiveChild}-${forceOpen}`}
            defaultOpen={hasActiveChild || forceOpen}
            className="group/collapsible"
            asChild
        >
            <SidebarMenuItem>
                <CollapsibleTrigger asChild>
                    <SidebarMenuButton tooltip={{ children: item.title }}>
                        {item.icon && <item.icon />}
                        <span>{item.title}</span>
                        <ChevronRight className="ml-auto transition-transform group-data-[state=open]/collapsible:rotate-90" />
                    </SidebarMenuButton>
                </CollapsibleTrigger>
                <CollapsibleContent>
                    <SidebarMenuSub>
                        {children.map((child) => (
                            <SidebarMenuSubItem key={child.title}>
                                <SidebarMenuSubButton
                                    asChild
                                    isActive={isActive(child)}
                                >
                                    <Link
                                        href={child.href ?? '#'}
                                        prefetch
                                        aria-current={
                                            isActive(child) ? 'page' : undefined
                                        }
                                    >
                                        <span>{child.title}</span>
                                    </Link>
                                </SidebarMenuSubButton>
                            </SidebarMenuSubItem>
                        ))}
                    </SidebarMenuSub>
                </CollapsibleContent>
            </SidebarMenuItem>
        </Collapsible>
    );
}
