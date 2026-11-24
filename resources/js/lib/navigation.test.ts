import { describe, expect, test } from 'vitest';
import { activeHref, filterNavigation, navigation } from '@/config/navigation';
import type { NavGroup } from '@/config/navigation';

// Pages ship over several phases; the structure is exercised in full here.
const full = { includeUnready: true };

const allPermissions = [
    'org.manage',
    'employees.view-all',
    'employees.view-team',
    'attendance.view-all',
    'attendance.view-team',
    'leave.approve-all',
    'leave.approve-team',
    'payroll.view',
    'performance.manage',
    'performance.review-team',
    'assets.assign',
    'assets.view-team',
    'exit.clearance',
    'exit.approve-team',
    'settings.manage',
    'settings.attendance-leave',
    'users.manage',
    'audit.view',
];

test('employee sees only Overview and My Space', () => {
    const labels = filterNavigation(navigation, [], '', full).map(
        (g) => g.label,
    );
    expect(labels).toEqual(['Overview', 'My Space']);
});

test('manager never sees Payroll, Settings, Users or Audit Log', () => {
    const perms = [
        'employees.view-team',
        'attendance.view-team',
        'leave.approve-team',
        'performance.review-team',
        'assets.view-team',
        'exit.approve-team',
    ];
    const titles = JSON.stringify(
        filterNavigation(navigation, perms, '', full),
    );
    for (const t of ['Payroll', 'Settings', 'Users & Roles', 'Audit Log']) {
        expect(titles).not.toContain(t);
    }
});

test('search keeps the parent of a matching child and drops other groups', () => {
    const r = filterNavigation(navigation, allPermissions, 'payslip', full);
    expect(r.map((g) => g.label)).toEqual(['Finance & Assets', 'My Space']);
    const finance = r[0].items;
    expect(finance.map((i) => i.title)).toEqual(['Payroll']);
    expect(finance[0].children?.map((c) => c.title)).toEqual(['Payslips']);
});

test('search with no match returns an empty list', () => {
    expect(filterNavigation(navigation, allPermissions, 'zzzz', full)).toEqual(
        [],
    );
});

test('an item with several permissions shows when the user has any one', () => {
    const r = filterNavigation(navigation, ['employees.view-team'], '', full);
    const titles = r.flatMap((g) => g.items.map((i) => i.title));
    expect(titles).toContain('Employees');
    expect(titles).toContain('Organization Chart');
});

describe('ready flag', () => {
    test('only pages that exist are rendered by default', () => {
        const r = filterNavigation(navigation, allPermissions);
        expect(r.map((g) => g.label)).toEqual([
            'Overview',
            'Workforce',
            'System',
        ]);
        expect(r[0].items.map((i) => i.title)).toEqual(['Dashboard']);
        expect(r[1].items.map((i) => i.title)).toEqual([
            'Departments & Designations',
        ]);
        expect(r[2].items.map((i) => i.title)).toEqual([
            'Settings',
            'Users & Roles',
            'Audit Log',
        ]);
    });

    test('Audit Log is for admin only', () => {
        const titles = (perms: string[]) =>
            filterNavigation(navigation, perms).flatMap((g) =>
                g.items.map((i) => i.title),
            );

        expect(titles(['audit.view'])).toContain('Audit Log');
        expect(titles(['settings.manage', 'users.manage'])).not.toContain(
            'Audit Log',
        );
    });

    test('Settings is for admin and hr (either settings permission)', () => {
        const titles = (perms: string[]) =>
            filterNavigation(navigation, perms).flatMap((g) =>
                g.items.map((i) => i.title),
            );
        expect(titles(['settings.manage'])).toContain('Settings');
        expect(titles(['settings.attendance-leave'])).toContain('Settings');
        expect(titles(['org.manage'])).not.toContain('Settings');
        expect(titles([])).not.toContain('Settings');
    });

    test('Users & Roles is for admin only (users.manage)', () => {
        const titles = (perms: string[]) =>
            filterNavigation(navigation, perms).flatMap((g) =>
                g.items.map((i) => i.title),
            );
        expect(titles(['users.manage'])).toContain('Users & Roles');
        expect(titles(['settings.manage', 'org.manage'])).not.toContain(
            'Users & Roles',
        );
        expect(titles([])).not.toContain('Users & Roles');
    });

    test('Departments & Designations is for admin and hr only', () => {
        const titles = (perms: string[]) =>
            filterNavigation(navigation, perms).flatMap((g) =>
                g.items.map((i) => i.title),
            );
        expect(titles(['org.manage'])).toContain('Departments & Designations');
        expect(titles([])).not.toContain('Departments & Designations');
    });

    test('unready items are hidden even when a search matches them', () => {
        expect(filterNavigation(navigation, allPermissions, 'payslip')).toEqual(
            [],
        );
    });

    test('a parent whose children are all unready disappears', () => {
        const groups: NavGroup[] = [
            {
                label: 'G',
                items: [
                    {
                        title: 'Parent',
                        children: [
                            { title: 'A', href: '/a', ready: false },
                            { title: 'B', href: '/b', ready: false },
                        ],
                    },
                    { title: 'Visible', href: '/v' },
                ],
            },
        ];
        const r = filterNavigation(groups, []);
        expect(r[0].items.map((i) => i.title)).toEqual(['Visible']);
    });

    test('a group left empty disappears', () => {
        const groups: NavGroup[] = [
            {
                label: 'Hidden',
                items: [{ title: 'X', href: '/x', ready: false }],
            },
            { label: 'Shown', items: [{ title: 'Y', href: '/y' }] },
        ];
        expect(filterNavigation(groups, []).map((g) => g.label)).toEqual([
            'Shown',
        ]);
    });
});

describe('urls', () => {
    const reserved = [
        '/app',
        '/config',
        '/database',
        '/deploy',
        '/docs',
        '/resources',
        '/routes',
        '/scripts',
        '/storage',
        '/tests',
        '/vendor',
        '/bootstrap',
        '/node_modules',
        '/iclock',
    ];
    const hrefs = navigation
        .flatMap((g) => g.items.flatMap((i) => [i, ...(i.children ?? [])]))
        .flatMap((i) => (typeof i.href === 'string' ? [i.href] : []));

    test('no static href collides with a path reserved by the web server', () => {
        for (const h of hrefs) {
            for (const r of reserved) {
                expect(h === r || h.startsWith(r + '/')).toBe(false);
            }
        }
    });
});

describe('activeHref', () => {
    test('picks the longest matching entry', () => {
        expect(activeHref('/assets/assignments')).toBe('/assets/assignments');
        expect(activeHref('/assets')).toBe('/assets');
        expect(activeHref('/employees/5')).toBe('/employees');
    });

    test('does not match on a partial segment', () => {
        expect(activeHref('/assetsx')).toBeUndefined();
        expect(activeHref('/settings/profile')).toBeUndefined();
    });
});
