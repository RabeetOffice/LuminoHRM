import type { InertiaLinkProps } from '@inertiajs/react';
import {
    Building2,
    CalendarCheck,
    CalendarClock,
    CalendarDays,
    CalendarRange,
    Clock,
    DoorOpen,
    FileText,
    LayoutGrid,
    Laptop,
    Network,
    Package,
    ScrollText,
    Settings,
    ShieldCheck,
    Star,
    TrendingUp,
    UserCircle,
    Users,
    Wallet,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { toUrl } from '@/lib/utils';
import { dashboard } from '@/routes';
import { daily, monthly, punches } from '@/routes/attendance';
import { index as correctionsIndex } from '@/routes/attendance/corrections';
import { index as shiftsIndex } from '@/routes/attendance/shifts';
import { index as auditLogIndex } from '@/routes/audit-log';
import { index as holidaysIndex } from '@/routes/holidays';
import { index as hrmSettingsIndex } from '@/routes/hrm-settings';
import { index as leaveTypesIndex } from '@/routes/leave/types';
import { attendance as myAttendance } from '@/routes/my';
import { index as organizationIndex } from '@/routes/organization';
import { index as usersIndex } from '@/routes/users';

/**
 * One sidebar entry. `permission` lists the permission names that unlock it:
 * a user holding any one of them sees the entry. An entry without a
 * permission is visible to every signed-in user.
 *
 * `ready` defaults to true. Entries whose page does not exist yet carry their
 * final URL with `ready: false`; a later phase flips the flag when the page
 * ships. Children inherit the parent permission and may add their own.
 */
export type NavItem = {
    title: string;
    href?: NonNullable<InertiaLinkProps['href']>;
    icon?: LucideIcon;
    permission?: string | string[];
    children?: NavItem[];
    ready?: boolean;
    /**
     * Set by `filterNavigation` on every item it returns: true when the entry
     * is not built yet (or is a parent whose visible children all are), so the
     * sidebar renders it greyed out with a "Soon" badge and no link.
     */
    soon?: boolean;
};

export type NavGroup = { label: string; items: NavItem[] };

export type FilterOptions = {
    /**
     * Unready entries are kept and flagged `soon` by default. Pass `true` to
     * also keep the full structure (a parent whose children are all unready
     * keeps them instead of collapsing to one soon row), or `false` to hide
     * unready entries altogether.
     */
    includeUnready?: boolean;
};

const unready = { ready: false } as const;

export const navigation: NavGroup[] = [
    {
        label: 'Overview',
        items: [
            { title: 'Dashboard', href: dashboard(), icon: LayoutGrid },
            {
                title: 'Calendar',
                href: '/calendar',
                icon: CalendarDays,
                ...unready,
            },
        ],
    },
    {
        label: 'Workforce',
        items: [
            {
                title: 'Employees',
                href: '/employees',
                icon: Users,
                permission: ['employees.view-all', 'employees.view-team'],
            },
            {
                title: 'Organization Chart',
                href: '/organization-chart',
                icon: Network,
                permission: ['employees.view-all', 'employees.view-team'],
            },
            {
                title: 'Departments & Designations',
                href: organizationIndex(),
                icon: Building2,
                permission: 'org.manage',
            },
            {
                title: 'Attendance',
                icon: Clock,
                permission: ['attendance.view-all', 'attendance.view-team'],
                children: [
                    { title: 'Daily', href: daily() },
                    { title: 'Monthly', href: monthly() },
                    {
                        title: 'Correction Requests',
                        href: correctionsIndex(),
                        // Admin / HR see every request, a Manager their own team's (inherits the Attendance permission).
                    },
                    {
                        title: 'Shifts',
                        href: shiftsIndex(),
                        // Changing shifts is Admin / HR; a Manager never sees it.
                        permission: 'attendance.edit',
                    },
                    {
                        title: 'Raw Punches',
                        href: punches(),
                        // Every punch from every machine: Admin / HR only.
                        permission: 'attendance.view-all',
                    },
                ],
            },
            {
                title: 'Leave',
                icon: CalendarCheck,
                permission: ['leave.approve-all', 'leave.approve-team'],
                children: [
                    { title: 'Requests', href: '/leave/requests', ...unready },
                    { title: 'Balances', href: '/leave/balances', ...unready },
                    {
                        title: 'Leave Types',
                        href: leaveTypesIndex(),
                        // Part of the attendance and leave rules (Admin, HR).
                        permission: 'settings.attendance-leave',
                    },
                    {
                        title: 'Holidays',
                        href: holidaysIndex(),
                        // The holiday calendar is part of the attendance and leave rules (Admin, HR).
                        permission: 'settings.attendance-leave',
                    },
                ],
            },
        ],
    },
    {
        label: 'Growth',
        items: [
            {
                title: 'Performance',
                icon: TrendingUp,
                permission: ['performance.manage', 'performance.review-team'],
                children: [
                    { title: 'KPIs', href: '/performance/kpis', ...unready },
                    {
                        title: 'Reviews',
                        href: '/performance/reviews',
                        ...unready,
                    },
                ],
            },
        ],
    },
    {
        label: 'Finance & Assets',
        items: [
            {
                title: 'Payroll',
                icon: Wallet,
                permission: 'payroll.view',
                children: [
                    {
                        title: 'Salary Profiles',
                        href: '/payroll/salary-profiles',
                        ...unready,
                    },
                    {
                        title: 'Payroll Runs',
                        href: '/payroll/runs',
                        ...unready,
                    },
                    {
                        title: 'Payslips',
                        href: '/payroll/payslips',
                        ...unready,
                    },
                    {
                        title: 'Loans & Advances',
                        href: '/payroll/loans',
                        ...unready,
                    },
                ],
            },
            {
                title: 'Assets',
                icon: Laptop,
                permission: ['assets.assign', 'assets.view-team'],
                children: [
                    { title: 'All Assets', href: '/assets', ...unready },
                    {
                        title: 'Assignments',
                        href: '/assets/assignments',
                        ...unready,
                    },
                ],
            },
        ],
    },
    {
        label: 'Exit',
        items: [
            {
                title: 'Resignations & Clearance',
                href: '/exit',
                icon: DoorOpen,
                permission: ['exit.clearance', 'exit.approve-team'],
                ...unready,
            },
        ],
    },
    {
        label: 'System',
        items: [
            {
                title: 'Settings',
                href: hrmSettingsIndex(),
                icon: Settings,
                permission: ['settings.manage', 'settings.attendance-leave'],
            },
            {
                title: 'Users & Roles',
                href: usersIndex(),
                icon: ShieldCheck,
                permission: 'users.manage',
            },
            {
                title: 'Audit Log',
                href: auditLogIndex(),
                icon: ScrollText,
                permission: 'audit.view',
            },
        ],
    },
    {
        label: 'My Space',
        items: [
            {
                title: 'My Profile',
                href: '/my/profile',
                icon: UserCircle,
            },
            {
                title: 'My Attendance',
                href: myAttendance(),
                icon: CalendarClock,
            },
            {
                title: 'My Leaves',
                href: '/my/leaves',
                icon: CalendarRange,
                ...unready,
            },
            {
                title: 'My Payslips',
                href: '/my/payslips',
                icon: FileText,
                ...unready,
            },
            {
                title: 'My Assets',
                href: '/my/assets',
                icon: Package,
                ...unready,
            },
            {
                title: 'My Reviews',
                href: '/my/reviews',
                icon: Star,
                ...unready,
            },
        ],
    },
];

const permitted = (
    permission: NavItem['permission'],
    held: Set<string>,
): boolean => {
    if (permission === undefined) {
        return true;
    }

    const needed = Array.isArray(permission) ? permission : [permission];

    return needed.length === 0 || needed.some((p) => held.has(p));
};

type UnreadyMode = 'collapse' | 'full' | 'hide';

function filterItems(
    items: NavItem[],
    held: Set<string>,
    needle: string,
    mode: UnreadyMode,
    parentMatched: boolean,
): NavItem[] {
    const result: NavItem[] = [];

    for (const item of items) {
        if (mode === 'hide' && item.ready === false) {
            continue;
        }

        if (!permitted(item.permission, held)) {
            continue;
        }

        const matches =
            needle === '' || item.title.toLowerCase().includes(needle);

        if (!item.children) {
            if (matches || parentMatched) {
                result.push({ ...item, soon: item.ready === false });
            }

            continue;
        }

        // A matching parent keeps all of its visible children; otherwise only
        // the matching ones survive, and a parent with none is dropped.
        const children = filterItems(
            item.children,
            held,
            needle,
            mode,
            parentMatched || matches,
        );

        if (children.length === 0) {
            continue;
        }

        const allSoon = children.every((c) => c.soon);

        if (allSoon && mode === 'collapse') {
            // Keep the menu compact: one non-expandable soon row.
            const { children: _children, ...rest } = item;
            result.push({ ...rest, soon: true });
        } else {
            result.push({ ...item, children, soon: allSoon });
        }
    }

    return result;
}

/**
 * Filters by permission (any one of an entry's permissions), then by `query`
 * (case-insensitive title). Unbuilt entries are kept and flagged `soon: true`;
 * a parent whose visible children are all unbuilt collapses to one soon item
 * without children. Parents left without children and groups left without
 * items are dropped.
 */
export function filterNavigation(
    groups: NavGroup[],
    permissions: string[],
    query = '',
    options: FilterOptions = {},
): NavGroup[] {
    const held = new Set(permissions);
    const needle = query.trim().toLowerCase();
    const mode: UnreadyMode =
        options.includeUnready === undefined
            ? 'collapse'
            : options.includeUnready
              ? 'full'
              : 'hide';

    return groups
        .map((group) => ({
            ...group,
            items: filterItems(group.items, held, needle, mode, false),
        }))
        .filter((group) => group.items.length > 0);
}

/**
 * The href of the entry that owns `path`: the longest entry URL equal to the
 * path or a parent segment of it (so /assets/assignments beats /assets, and
 * /employees/5 lights up Employees). Considers every entry, ready or not.
 */
export function activeHref(
    path: string,
    groups: NavGroup[] = navigation,
): string | undefined {
    const hrefs = groups
        .flatMap((g) => g.items)
        .flatMap((i) => [i, ...(i.children ?? [])])
        .flatMap((i) => (i.href === undefined ? [] : [toUrl(i.href)]))
        .filter((h) => path === h || path.startsWith(h + '/'));

    return hrefs.sort((a, b) => b.length - a.length)[0];
}
