import { describe, expect, test } from 'vitest';
import {
    ALL_REQUESTS_HREF,
    LEAVE_STATUS_SERIES,
    MY_LEAVES_HREF,
    PENDING_LEAVES_HREF,
    balanceLineNote,
    balanceLineValue,
    daysLabel,
    halfDayNote,
    leaveBalanceTile,
    leaveStatusBars,
    leaveStatusTotal,
    pendingLeavesTile,
    personRequestsHref,
} from '@/lib/dashboard';
import type { LeaveBalanceLine, LeaveByStatus, MyLeave } from '@/lib/dashboard';

function line(overrides: Partial<LeaveBalanceLine> = {}): LeaveBalanceLine {
    return {
        type_id: 1,
        type_name: 'Annual',
        colour: 'emerald',
        year: 2026,
        is_paid: true,
        unlimited: false,
        allocated: 14,
        carried: 0,
        adjustment: 0,
        opening_used: 0,
        used: 4.5,
        pending: 0,
        remaining: 9.5,
        available: 9.5,
        entitlement: 14,
        overdrawn: false,
        projected: false,
        label: '9.5 of 14',
        ...overrides,
    };
}

const unpaid = line({
    type_id: 4,
    type_name: 'Unpaid',
    colour: 'slate',
    is_paid: false,
    unlimited: true,
    allocated: 0,
    used: 2,
    remaining: null,
    available: null,
    entitlement: null,
    label: '2 used',
});

function mine(
    lines: LeaveBalanceLine[],
    total: number,
    pending = lines.reduce((sum, l) => sum + l.pending, 0),
): MyLeave {
    return {
        year: 2026,
        lines,
        total_available: total,
        pending_total: pending,
    };
}

describe('daysLabel', () => {
    test.each([
        [0, '0 days'],
        [0.5, '0.5 day'],
        [1, '1 day'],
        [1.5, '1.5 days'],
        [12, '12 days'],
        [0.1 + 0.2 + 0.2, '0.5 day'],
    ])('%d', (value, expected) => {
        expect(daysLabel(value)).toBe(expected);
    });
});

describe('leave status chart', () => {
    const byStatus: LeaveByStatus = {
        month: '2026-11',
        label: 'November 2026',
        counts: {
            pending: 4,
            manager_approved: 1,
            approved: 2,
            rejected: 0,
            cancelled: 1,
        },
    };

    test('one bar per status, in order, scaled to the tallest', () => {
        const bars = leaveStatusBars(byStatus);

        expect(bars.map((b) => b.key)).toEqual([...LEAVE_STATUS_SERIES]);
        expect(bars.map((b) => b.count)).toEqual([4, 1, 2, 0, 1]);
        expect(bars.map((b) => b.percent)).toEqual([100, 25, 50, 0, 25]);
        expect(bars[0]?.label).toBe('Pending');
        expect(bars[1]?.label).toBe('Manager approved');
    });

    test('totals every status; an empty month draws no bar and never divides by zero', () => {
        expect(leaveStatusTotal(byStatus)).toBe(8);

        const empty: LeaveByStatus = {
            ...byStatus,
            counts: {
                pending: 0,
                manager_approved: 0,
                approved: 0,
                rejected: 0,
                cancelled: 0,
            },
        };
        expect(leaveStatusTotal(empty)).toBe(0);
        expect(leaveStatusBars(empty).every((b) => b.percent === 0)).toBe(true);
    });
});

describe('halfDayNote', () => {
    test('marks halves', () => {
        expect(halfDayNote('full')).toBe('Full day');
        expect(halfDayNote('am')).toBe('½ day · first half');
        expect(halfDayNote('pm')).toBe('½ day · second half');
    });
});

describe('balance lines', () => {
    test('a paid line shows what is left of the entitlement, with pending and overdrawn', () => {
        expect(balanceLineValue(line())).toBe('9.5 left');
        expect(balanceLineNote(line())).toBe('of 14');
        expect(balanceLineNote(line({ pending: 1.5 }))).toBe(
            'of 14 · 1.5 pending',
        );
        expect(
            balanceLineValue(
                line({ remaining: -1, available: -1, overdrawn: true }),
            ),
        ).toBe('-1 left');
        expect(balanceLineNote(line({ overdrawn: true }))).toBe(
            'of 14 · overdrawn',
        );
    });

    test('an unpaid line is unlimited and shows what was used', () => {
        expect(balanceLineValue(unpaid)).toBe('Unlimited');
        expect(balanceLineNote(unpaid)).toBe('2 used');
    });
});

describe('leaveBalanceTile', () => {
    test('shows the total available paid days and only the paid lines in the footnote', () => {
        const tile = leaveBalanceTile(
            mine(
                [
                    line(),
                    line({ type_id: 2, type_name: 'Sick', available: 3 }),
                    unpaid,
                ],
                12.5,
            ),
        );

        expect(tile).toEqual({
            value: '12.5',
            unit: 'days left',
            note: 'Annual 9.5 · Sick 3',
            href: MY_LEAVES_HREF,
        });
    });

    test('says "day left" for one day and handles no paid types', () => {
        expect(leaveBalanceTile(mine([line({ available: 1 })], 1)).unit).toBe(
            'day left',
        );
        expect(leaveBalanceTile(mine([unpaid], 0)).note).toBe(
            'No paid leave types',
        );
    });
});

describe('pendingLeavesTile', () => {
    test('an approver gets the requests waiting for them, linking to the waiting tab', () => {
        expect(pendingLeavesTile({ count: 3 }, null)).toEqual({
            value: '3',
            note: 'Waiting for your decision',
            href: PENDING_LEAVES_HREF,
        });
        expect(pendingLeavesTile({ count: 0 })?.note).toBe(
            'Nothing waiting for you',
        );
    });

    test('anyone else sees their own days waiting for approval', () => {
        const tile = pendingLeavesTile(
            undefined,
            mine([line({ pending: 1 }), { ...unpaid, pending: 0.5 }], 8.5),
        );

        expect(tile).toEqual({
            label: 'My Pending Leave',
            value: '1.5',
            unit: 'days',
            note: 'Waiting for approval',
            href: MY_LEAVES_HREF,
        });
        expect(pendingLeavesTile(undefined, mine([line()], 9.5))?.note).toBe(
            'Nothing waiting for approval',
        );
    });

    test('counts pending days of every year, next January included', () => {
        // This year's line shows 0 pending; 1.5 days wait in the next leave year.
        const tile = pendingLeavesTile(
            undefined,
            mine([line({ pending: 0 })], 9.5, 1.5),
        );

        expect(tile?.value).toBe('1.5');
        expect(tile?.unit).toBe('days');
    });

    test('nothing to show without either figure', () => {
        expect(pendingLeavesTile(undefined, undefined)).toBeNull();
    });
});

describe('links', () => {
    test('use the typed routes', () => {
        expect(PENDING_LEAVES_HREF).toBe('/leave/requests?tab=waiting');
        expect(ALL_REQUESTS_HREF).toBe('/leave/requests?tab=all');
        expect(MY_LEAVES_HREF).toBe('/my/leaves');
    });

    test('the requests list narrowed to one person', () => {
        expect(personRequestsHref('EMP 001')).toBe(
            '/leave/requests?tab=all&search=EMP+001',
        );
    });
});
