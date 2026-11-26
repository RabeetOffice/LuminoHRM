import { describe, expect, test } from 'vitest';
import { activeHref, filterNavigation, navigation } from '@/config/navigation';
import type { NavGroup } from '@/config/navigation';
import { toUrl } from '@/lib/utils';

// Pages ship over several phases; the structure is exercised in full here.
const full = { includeUnready: true };
// The old behaviour: unbuilt entries hidden.
const hide = { includeUnready: false };

const allPermissions = [
    'org.manage',
    'employees.view-all',
    'employees.view-team',
    'attendance.view-all',
    'attendance.view-team',
    'attendance.edit',
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
        const r = filterNavigation(navigation, allPermissions, '', hide);
        expect(r.map((g) => g.label)).toEqual([
            'Overview',
            'Workforce',
            'Growth',
            'Finance & Assets',
            'Exit',
            'System',
            'My Space',
        ]);
        expect(r[0].items.map((i) => i.title)).toEqual([
            'Dashboard',
            'Calendar',
        ]);
        expect(r[1].items.map((i) => i.title)).toEqual([
            'Employees',
            'Organization Chart',
            'Departments & Designations',
            'Attendance',
            'Leave',
        ]);
        expect(r[1].items[3].children?.map((c) => c.title)).toEqual([
            'Daily',
            'Monthly',
            'Correction Requests',
            'Shifts',
            'Raw Punches',
        ]);
        expect(r[1].items[4].children?.map((c) => c.title)).toEqual([
            'Requests',
            'Balances',
            'Leave Types',
            'Holidays',
        ]);
        expect(r[2].items.map((i) => i.title)).toEqual(['Performance']);
        expect(r[2].items[0].children?.map((c) => c.title)).toEqual([
            'KPIs',
            'Reviews',
        ]);
        expect(r[3].items.map((i) => i.title)).toEqual(['Payroll', 'Assets']);
        expect(r[3].items[1].children?.map((c) => c.title)).toEqual([
            'All Assets',
            'Assignments',
        ]);
        expect(r[3].items[0].children?.map((c) => c.title)).toEqual([
            'Salary Profiles',
            'Payroll Runs',
            'Payslips',
            'Loans & Advances',
            'Opening Figures',
            'Tax Report',
        ]);
        expect(r[4].items.map((i) => i.title)).toEqual([
            'Resignations & Clearance',
        ]);
        expect(r[5].items.map((i) => i.title)).toEqual([
            'Settings',
            'Users & Roles',
            'Audit Log',
        ]);
        expect(r[6].items.map((i) => i.title)).toEqual([
            'My Profile',
            'My Attendance',
            'My Leaves',
            'My Payslips',
            'My Assets',
            'My Reviews',
            'My Resignation',
        ]);
    });

    test('Audit Log is for admin only', () => {
        const titles = (perms: string[]) =>
            filterNavigation(navigation, perms, '', hide).flatMap((g) =>
                g.items.map((i) => i.title),
            );

        expect(titles(['audit.view'])).toContain('Audit Log');
        expect(titles(['settings.manage', 'users.manage'])).not.toContain(
            'Audit Log',
        );
    });

    test('Settings is for admin and hr (either settings permission)', () => {
        const titles = (perms: string[]) =>
            filterNavigation(navigation, perms, '', hide).flatMap((g) =>
                g.items.map((i) => i.title),
            );
        expect(titles(['settings.manage'])).toContain('Settings');
        expect(titles(['settings.attendance-leave'])).toContain('Settings');
        expect(titles(['org.manage'])).not.toContain('Settings');
        expect(titles([])).not.toContain('Settings');
    });

    test('Users & Roles is for admin only (users.manage)', () => {
        const titles = (perms: string[]) =>
            filterNavigation(navigation, perms, '', hide).flatMap((g) =>
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
            filterNavigation(navigation, perms, '', hide).flatMap((g) =>
                g.items.map((i) => i.title),
            );
        expect(titles(['org.manage'])).toContain('Departments & Designations');
        expect(titles([])).not.toContain('Departments & Designations');
    });

    test('with includeUnready false, unready items are hidden even when a search matches them', () => {
        expect(
            filterNavigation(navigation, allPermissions, 'resignations', hide),
        ).toEqual([]);
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
        const r = filterNavigation(groups, [], '', hide);
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
        expect(
            filterNavigation(groups, [], '', hide).map((g) => g.label),
        ).toEqual(['Shown']);
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

describe('soon entries (default)', () => {
    const titlesOf = (r: NavGroup[]) =>
        r.map((g) => [g.label, g.items.map((i) => i.title)]);

    test('an unready entry the user may see comes back flagged soon', () => {
        const groups: NavGroup[] = [
            {
                label: 'G',
                items: [{ title: 'Later', href: '/later', ready: false }],
            },
        ];
        const r = filterNavigation(groups, []);
        expect(r[0].items[0].soon).toBe(true);
    });

    test('Resignations & Clearance is ready for exit.clearance and exit.approve-team', () => {
        for (const perm of ['exit.clearance', 'exit.approve-team']) {
            const exit = filterNavigation(navigation, [perm])
                .flatMap((g) => g.items)
                .find((i) => i.title === 'Resignations & Clearance');
            expect(exit?.soon).toBe(false);
            expect(toUrl(exit?.href ?? '')).toBe('/exit');
        }

        const without = filterNavigation(navigation, ['employees.view-all'])
            .flatMap((g) => g.items)
            .map((i) => i.title);
        expect(without).not.toContain('Resignations & Clearance');
    });

    test('My Resignation is in My Space for everyone, ready, with no permission', () => {
        const entry = filterNavigation(navigation, [])
            .find((g) => g.label === 'My Space')
            ?.items.find((i) => i.title === 'My Resignation');
        expect(entry?.soon).toBe(false);
        expect(toUrl(entry?.href ?? '')).toBe('/my/resignation');
        expect(entry?.permission).toBeUndefined();
    });

    test('the Calendar is ready for every signed-in user', () => {
        const calendar = filterNavigation(navigation, [])
            .flatMap((g) => g.items)
            .find((i) => i.title === 'Calendar');
        expect(calendar?.soon).toBe(false);
        expect(toUrl(calendar?.href ?? '')).toBe('/calendar');
    });

    test('the Organization Chart is ready for anyone who may see employees', () => {
        for (const perm of ['employees.view-all', 'employees.view-team']) {
            const chart = filterNavigation(navigation, [perm])
                .flatMap((g) => g.items)
                .find((i) => i.title === 'Organization Chart');
            expect(chart?.soon).toBe(false);
            expect(chart?.href).toBe('/organization-chart');
        }
    });

    test('a ready entry is not soon', () => {
        const r = filterNavigation(navigation, []);
        expect(r[0].items.find((i) => i.title === 'Dashboard')?.soon).toBe(
            false,
        );
    });

    test('an unready entry the user lacks permission for is not returned', () => {
        const titles = filterNavigation(navigation, [])
            .flatMap((g) => g.items)
            .map((i) => i.title);
        expect(titles).not.toContain('Employees');
        expect(titles).not.toContain('Payroll');
    });

    test('an employee gets Overview and My Space only', () => {
        const r = filterNavigation(navigation, []);
        expect(titlesOf(r)).toEqual([
            ['Overview', ['Dashboard', 'Calendar']],
            [
                'My Space',
                [
                    'My Profile',
                    'My Attendance',
                    'My Leaves',
                    'My Payslips',
                    'My Assets',
                    'My Reviews',
                    'My Resignation',
                ],
            ],
        ]);
        const soon = r.flatMap((g) => g.items).map((i) => i.soon);
        expect(soon).toEqual([
            false,
            false,
            false,
            false,
            false,
            false,
            false,
            false,
            false,
        ]);
    });

    test('a manager never sees Payroll, Settings, Users or Audit Log, soon or not', () => {
        const perms = [
            'employees.view-team',
            'attendance.view-team',
            'leave.approve-team',
            'performance.review-team',
            'assets.view-team',
            'exit.approve-team',
        ];
        const titles = JSON.stringify(filterNavigation(navigation, perms));
        for (const t of ['Payroll', 'Settings', 'Users & Roles', 'Audit Log']) {
            expect(titles).not.toContain(t);
        }
        expect(titles).toContain('Attendance');
    });

    test('a parent with only unready visible children is one soon row', () => {
        const groups: NavGroup[] = [
            {
                label: 'G',
                items: [
                    {
                        title: 'Parent',
                        children: [{ title: 'A', href: '/a', ready: false }],
                    },
                ],
            },
        ];
        const [parent] = filterNavigation(groups, [])[0].items;
        expect(parent.soon).toBe(true);
        expect(parent.children).toBeUndefined();
    });

    test('the three assets entries are ready for the right roles', () => {
        const assetsFor = (perms: string[]) => {
            const items = filterNavigation(navigation, perms).flatMap(
                (g) => g.items,
            );

            return {
                parent: items.find((i) => i.title === 'Assets'),
                mine: items.find((i) => i.title === 'My Assets'),
            };
        };

        for (const perm of ['assets.assign', 'assets.view-team']) {
            const { parent, mine } = assetsFor([perm]);
            expect(parent?.soon).toBe(false);
            expect(
                parent?.children?.map((c) => [
                    c.title,
                    toUrl(c.href ?? ''),
                    c.soon,
                ]),
            ).toEqual([
                ['All Assets', '/assets', false],
                ['Assignments', '/assets/assignments', false],
            ]);
            expect(mine?.soon).toBe(false);
            expect(toUrl(mine?.href ?? '')).toBe('/my/assets');
        }

        // An employee has no Assets group, only My Assets.
        const { parent, mine } = assetsFor([]);
        expect(parent).toBeUndefined();
        expect(mine?.soon).toBe(false);
    });

    test('payroll.view opens Payroll with Salary Profiles ready', () => {
        const r = filterNavigation(navigation, ['payroll.view']);
        const payroll = r
            .flatMap((g) => g.items)
            .find((i) => i.title === 'Payroll');
        const profiles = payroll?.children?.find(
            (c) => c.title === 'Salary Profiles',
        );
        expect(payroll?.soon).toBe(false);
        expect(profiles?.href).toBe('/payroll/salary-profiles');
        expect(profiles?.soon).toBe(false);
    });

    test('payroll.view opens Payroll Runs', () => {
        const r = filterNavigation(navigation, ['payroll.view']);
        const payroll = r
            .flatMap((g) => g.items)
            .find((i) => i.title === 'Payroll');
        const runs = payroll?.children?.find((c) => c.title === 'Payroll Runs');
        expect(runs?.href).toBe('/payroll/runs');
        expect(runs?.soon).toBe(false);
    });

    test('payroll.view opens Loans & Advances and Opening Figures', () => {
        const r = filterNavigation(navigation, ['payroll.view']);
        const payroll = r
            .flatMap((g) => g.items)
            .find((i) => i.title === 'Payroll');
        const loans = payroll?.children?.find(
            (c) => c.title === 'Loans & Advances',
        );
        const opening = payroll?.children?.find(
            (c) => c.title === 'Opening Figures',
        );
        expect(loans?.href).toBe('/payroll/loans');
        expect(loans?.soon).toBe(false);
        expect(opening?.href).toBe('/payroll/opening');
        expect(opening?.soon).toBe(false);
    });

    test('a parent with mixed children keeps them, each flagged', () => {
        const groups: NavGroup[] = [
            {
                label: 'G',
                items: [
                    {
                        title: 'Parent',
                        children: [
                            { title: 'A', href: '/a' },
                            { title: 'B', href: '/b', ready: false },
                        ],
                    },
                ],
            },
        ];
        const [parent] = filterNavigation(groups, [])[0].items;
        expect(parent.soon).toBe(false);
        expect(parent.children?.map((c) => [c.title, c.soon])).toEqual([
            ['A', false],
            ['B', true],
        ]);
    });

    test('children hidden by search do not decide the parent state', () => {
        const groups: NavGroup[] = [
            {
                label: 'G',
                items: [
                    {
                        title: 'Parent',
                        children: [
                            { title: 'Alpha', href: '/a' },
                            { title: 'Beta', href: '/b', ready: false },
                        ],
                    },
                ],
            },
        ];
        const [parent] = filterNavigation(groups, [], 'beta')[0].items;
        expect(parent.soon).toBe(true);
        expect(parent.children).toBeUndefined();
    });

    test('search matches the exit entry by title', () => {
        const r = filterNavigation(
            navigation,
            ['exit.clearance'],
            'resignations',
        );
        expect(titlesOf(r)).toEqual([['Exit', ['Resignations & Clearance']]]);
        expect(r[0].items[0].soon).toBe(false);
    });

    test('search with no match returns an empty list', () => {
        expect(filterNavigation(navigation, allPermissions, 'zzzz')).toEqual(
            [],
        );
    });

    test('the Exit group shows for exit.clearance', () => {
        const r = filterNavigation(navigation, ['exit.clearance']);
        expect(r.map((g) => g.label)).toContain('Exit');
    });

    test('order and grouping follow the definition', () => {
        const r = filterNavigation(navigation, allPermissions);
        expect(r.map((g) => g.label)).toEqual(navigation.map((g) => g.label));
    });

    test('My Profile is ready for every signed-in user, with no permission', () => {
        const entry = filterNavigation(navigation, [])
            .flatMap((g) => g.items)
            .find((i) => i.title === 'My Profile');
        expect(entry?.soon).toBe(false);
        expect(entry?.href).toBe('/my/profile');
        expect(entry?.permission).toBeUndefined();
    });

    test('the ready entries today are exactly the built pages', () => {
        const ready = navigation
            .flatMap((g) => g.items)
            .filter((i) => i.children === undefined && i.ready !== false)
            .map((i) => i.title);
        expect(ready).toEqual([
            'Dashboard',
            'Calendar',
            'Employees',
            'Organization Chart',
            'Departments & Designations',
            'Resignations & Clearance',
            'Settings',
            'Users & Roles',
            'Audit Log',
            'My Profile',
            'My Attendance',
            'My Leaves',
            'My Payslips',
            'My Assets',
            'My Reviews',
            'My Resignation',
        ]);
    });

    test('Payslips and My Payslips are ready', () => {
        const items = filterNavigation(navigation, ['payroll.view']).flatMap(
            (g) => g.items,
        );
        const payslips = items
            .find((i) => i.title === 'Payroll')
            ?.children?.find((c) => c.title === 'Payslips');
        const mine = items.find((i) => i.title === 'My Payslips');
        expect(payslips?.soon).toBe(false);
        expect(payslips?.href).toBe('/payroll/payslips');
        expect(mine?.soon).toBe(false);
        expect(mine?.href).toBe('/my/payslips');
    });

    test('payroll.view opens the Tax Report', () => {
        const payroll = filterNavigation(navigation, ['payroll.view'])
            .flatMap((g) => g.items)
            .find((i) => i.title === 'Payroll');
        const report = payroll?.children?.find((c) => c.title === 'Tax Report');
        expect(toUrl(report?.href ?? '')).toBe('/payroll/tax-report');
        expect(report?.soon).toBe(false);
    });
});

describe('shifts and holidays', () => {
    const child = (r: NavGroup[], parent: string, title: string) =>
        r
            .flatMap((g) => g.items)
            .find((i) => i.title === parent)
            ?.children?.find((c) => c.title === title);

    test('are ready for Admin and HR', () => {
        const r = filterNavigation(navigation, [
            'attendance.view-all',
            'attendance.edit',
            'leave.approve-all',
            'settings.attendance-leave',
        ]);
        expect(child(r, 'Attendance', 'Shifts')?.soon).toBe(false);
        expect(toUrl(child(r, 'Attendance', 'Shifts')?.href ?? '')).toBe(
            '/attendance/shifts',
        );
        expect(child(r, 'Leave', 'Holidays')?.soon).toBe(false);
        expect(toUrl(child(r, 'Leave', 'Holidays')?.href ?? '')).toBe(
            '/leave/holidays',
        );
        expect(child(r, 'Leave', 'Leave Types')?.soon).toBe(false);
        expect(toUrl(child(r, 'Leave', 'Leave Types')?.href ?? '')).toBe(
            '/leave/types',
        );
    });

    test('leave requests are ready for approvers, a manager included', () => {
        for (const perms of [
            ['leave.approve-all'],
            ['attendance.view-team', 'leave.approve-team'],
        ]) {
            const r = filterNavigation(navigation, perms, '', full);
            expect(child(r, 'Leave', 'Requests')?.soon).toBe(false);
            expect(toUrl(child(r, 'Leave', 'Requests')?.href ?? '')).toBe(
                '/leave/requests',
            );
        }
    });

    test('leave balances are ready for approvers, a manager included', () => {
        for (const perms of [
            ['leave.approve-all'],
            ['attendance.view-team', 'leave.approve-team'],
        ]) {
            const r = filterNavigation(navigation, perms, '', full);
            expect(child(r, 'Leave', 'Balances')?.soon).toBe(false);
            expect(toUrl(child(r, 'Leave', 'Balances')?.href ?? '')).toBe(
                '/leave/balances',
            );
        }
    });

    test('are hidden from a manager', () => {
        const r = filterNavigation(
            navigation,
            ['attendance.view-team', 'leave.approve-team'],
            '',
            full,
        );
        expect(child(r, 'Attendance', 'Shifts')).toBeUndefined();
        expect(child(r, 'Leave', 'Holidays')).toBeUndefined();
        expect(child(r, 'Leave', 'Leave Types')).toBeUndefined();
    });
});

describe('daily attendance', () => {
    test('is ready for anyone who may see team or all attendance', () => {
        for (const permission of [
            'attendance.view-all',
            'attendance.view-team',
        ]) {
            const entry = filterNavigation(navigation, [permission])
                .flatMap((g) => g.items)
                .find((i) => i.title === 'Attendance')
                ?.children?.find((c) => c.title === 'Daily');

            expect(entry?.soon).toBe(false);
            expect(toUrl(entry?.href ?? '')).toBe('/attendance/daily');
        }
    });
});

describe('monthly attendance', () => {
    test('is ready for anyone who may see team or all attendance', () => {
        for (const permission of [
            'attendance.view-all',
            'attendance.view-team',
        ]) {
            const entry = filterNavigation(navigation, [permission])
                .flatMap((g) => g.items)
                .find((i) => i.title === 'Attendance')
                ?.children?.find((c) => c.title === 'Monthly');

            expect(entry?.soon).toBe(false);
            expect(toUrl(entry?.href ?? '')).toBe('/attendance/monthly');
        }
    });
});

describe('raw punches', () => {
    test('is ready for Admin and HR (attendance.view-all) only', () => {
        const find = (permissions: string[]) =>
            filterNavigation(navigation, permissions)
                .flatMap((g) => g.items)
                .find((i) => i.title === 'Attendance')
                ?.children?.find((c) => c.title === 'Raw Punches');

        const entry = find(['attendance.view-all']);
        expect(entry?.soon).toBe(false);
        expect(toUrl(entry?.href ?? '')).toBe('/attendance/punches');
        expect(find(['attendance.view-team'])).toBeUndefined();
    });
});

describe('correction requests', () => {
    test('is ready for Admin, HR and managers, and hidden from a plain employee', () => {
        const find = (permissions: string[]) =>
            filterNavigation(navigation, permissions)
                .flatMap((g) => g.items)
                .find((i) => i.title === 'Attendance')
                ?.children?.find((c) => c.title === 'Correction Requests');

        for (const permission of [
            'attendance.view-all',
            'attendance.view-team',
        ]) {
            const entry = find([permission]);
            expect(entry?.soon).toBe(false);
            expect(toUrl(entry?.href ?? '')).toBe('/attendance/corrections');
        }

        expect(find([])).toBeUndefined();
    });
});

describe('my attendance', () => {
    test('is ready for everyone, with no permission needed', () => {
        const entry = filterNavigation(navigation, [])
            .flatMap((g) => g.items)
            .find((i) => i.title === 'My Attendance');

        expect(entry?.soon).toBe(false);
        expect(toUrl(entry?.href ?? '')).toBe('/my/attendance');
    });
});

describe('my leaves', () => {
    test('is ready for everyone, with no permission needed', () => {
        const entry = filterNavigation(navigation, [])
            .flatMap((g) => g.items)
            .find((i) => i.title === 'My Leaves');

        expect(entry?.soon).toBe(false);
        expect(toUrl(entry?.href ?? '')).toBe('/my/leaves');
    });
});

describe('performance', () => {
    const performanceFor = (perms: string[]) =>
        filterNavigation(navigation, perms)
            .flatMap((g) => g.items)
            .find((i) => i.title === 'Performance');

    test('HR and Admin see KPIs and Reviews, both ready', () => {
        const entry = performanceFor([
            'performance.manage',
            'performance.review-team',
        ]);

        expect(entry?.soon).toBe(false);
        expect(entry?.children?.map((c) => [c.title, c.soon])).toEqual([
            ['KPIs', false],
            ['Reviews', false],
        ]);
        expect(entry?.children?.map((c) => toUrl(c.href ?? ''))).toEqual([
            '/performance/kpis',
            '/performance/reviews',
        ]);
    });

    test('a manager sees Reviews but not KPIs', () => {
        const entry = performanceFor(['performance.review-team']);

        expect(entry?.soon).toBe(false);
        expect(entry?.children?.map((c) => c.title)).toEqual(['Reviews']);
    });

    test('an employee has no Performance group but has My Reviews, ready', () => {
        expect(performanceFor([])).toBeUndefined();

        const mine = filterNavigation(navigation, [])
            .flatMap((g) => g.items)
            .find((i) => i.title === 'My Reviews');

        expect(mine?.soon).toBe(false);
        expect(toUrl(mine?.href ?? '')).toBe('/my/reviews');
    });

    test('activeHref picks the longest match for the performance and my reviews pages', () => {
        expect(activeHref('/performance/kpis')).toBe('/performance/kpis');
        expect(activeHref('/performance/reviews/12')).toBe(
            '/performance/reviews',
        );
        expect(activeHref('/performance/cycles/3')).toBeUndefined();
        expect(activeHref('/my/reviews')).toBe('/my/reviews');
    });
});
