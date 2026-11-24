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

const soonPill =
    'ml-auto shrink-0 rounded-full bg-neutral-100 px-1.5 py-px text-[10px] leading-4 font-medium text-neutral-500 group-data-[collapsible=icon]:hidden dark:bg-neutral-500/20 dark:text-neutral-400';

// aria-disabled in the kit adds pointer-events-none and a 50% fade; a Soon row
// keeps pointer events (for the tooltip) and has no hover or press highlight.
const soonRow =
    'cursor-default text-muted-foreground hover:bg-transparent hover:text-muted-foreground active:bg-transparent active:text-muted-foreground aria-disabled:pointer-events-auto aria-disabled:opacity-100';

function SoonItem({ item }: { item: NavItem }) {
    return (
        <SidebarMenuItem>
            <SidebarMenuButton
                asChild
                tooltip={{ children: `${item.title} - Coming soon` }}
                className={soonRow}
            >
                <span aria-disabled="true" title="Coming soon">
                    {item.icon && <item.icon />}
                    <span>{item.title}</span>
                    <span className={soonPill}>Soon</span>
                </span>
            </SidebarMenuButton>
        </SidebarMenuItem>
    );
}

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
                            item.soon && !item.children ? (
                                <SoonItem key={item.title} item={item} />
                            ) : item.children ? (
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
                        {children.map((child) =>
                            child.soon ? (
                                <SidebarMenuSubItem key={child.title}>
                                    <SidebarMenuSubButton
                                        asChild
                                        className={`${soonRow} text-muted-foreground!`}
                                    >
                                        <span
                                            aria-disabled="true"
                                            title="Coming soon"
                                        >
                                            <span>{child.title}</span>
                                            <span className={soonPill}>
                                                Soon
                                            </span>
                                        </span>
                                    </SidebarMenuSubButton>
                                </SidebarMenuSubItem>
                            ) : (
                                <SidebarMenuSubItem key={child.title}>
                                    <SidebarMenuSubButton
                                        asChild
                                        isActive={isActive(child)}
                                    >
                                        <Link
                                            href={child.href ?? '#'}
                                            prefetch
                                            aria-current={
                                                isActive(child)
                                                    ? 'page'
                                                    : undefined
                                            }
                                        >
                                            <span>{child.title}</span>
                                        </Link>
                                    </SidebarMenuSubButton>
                                </SidebarMenuSubItem>
                            ),
                        )}
                    </SidebarMenuSub>
                </CollapsibleContent>
            </SidebarMenuItem>
        </Collapsible>
    );
}
