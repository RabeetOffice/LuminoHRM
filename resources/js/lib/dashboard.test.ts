import { describe, expect, test } from 'vitest';
import {
    dashboardTiles,
    formatLongDate,
    greetingFor,
    hourIn,
    probationNote,
} from '@/lib/dashboard';

describe('greetingFor', () => {
    test.each([
        [0, 'Good evening'],
        [4, 'Good evening'],
        [5, 'Good morning'],
        [11, 'Good morning'],
        [12, 'Good afternoon'],
        [16, 'Good afternoon'],
        [17, 'Good evening'],
        [23, 'Good evening'],
    ])('hour %i', (hour, expected) => {
        expect(greetingFor(hour)).toBe(expected);
    });
});

describe('dashboardTiles', () => {
    const rest = [
        'Present Today',
        'Absent Today',
        'Late Today',
        'Pending Leaves',
    ];
    const labels = (...args: Parameters<typeof dashboardTiles>) =>
        dashboardTiles(...args).map((t) => t.label);

    test('Admin and HR: Total Employees first, then the rest, Payroll last with payroll.view', () => {
        expect(labels(true, 'company')).toEqual([
            'Total Employees',
            ...rest,
            'Payroll This Month',
        ]);
    });

    test('without payroll.view the sixth tile is Leave Balance', () => {
        const result = labels(false, 'company');
        expect(result).toEqual(['Total Employees', ...rest, 'Leave Balance']);
        expect(result).not.toContain('Payroll This Month');
    });

    test('a Manager gets the same slot labelled Team Members', () => {
        const tiles = dashboardTiles(false, 'team');
        expect(tiles.map((t) => t.label)).toEqual([
            'Team Members',
            ...rest,
            'Leave Balance',
        ]);
        expect(tiles[0].key).toBe('employees');
    });

    test('a plain Employee gets five tiles and no employee tile at all', () => {
        const tiles = dashboardTiles(false, null);
        expect(tiles).toHaveLength(5);
        expect(tiles.map((t) => t.label)).toEqual([...rest, 'Leave Balance']);
        expect(tiles.map((t) => t.key)).not.toContain('employees');
    });

    test('every tile has a unique key', () => {
        const keys = dashboardTiles(true, 'company').map((t) => t.key);
        expect(new Set(keys).size).toBe(keys.length);
    });
});

describe('probationNote', () => {
    test.each([
        [0, 'No one on probation'],
        [1, '1 on probation'],
        [12, '12 on probation'],
    ])('%i', (n, expected) => {
        expect(probationNote(n)).toBe(expected);
    });
});

describe('app timezone', () => {
    // 2026-11-23 20:30 UTC is already Tuesday 01:30 in Karachi (UTC+5).
    const instant = new Date('2026-11-23T20:30:00Z');

    test('hour is read in the given timezone', () => {
        expect(hourIn(instant, 'Asia/Karachi')).toBe(1);
        expect(hourIn(instant, 'UTC')).toBe(20);
    });

    test('date is a fixed day-month-year long format', () => {
        expect(formatLongDate(instant, 'Asia/Karachi')).toBe(
            'Tuesday, 24 November 2026',
        );
        expect(formatLongDate(instant, 'UTC')).toBe('Monday, 23 November 2026');
    });
});

describe('invalid or missing app timezone', () => {
    const instant = new Date('2026-11-22T20:00:00Z');

    test('hourIn falls back to the browser zone instead of throwing', () => {
        for (const bad of ['', 'Not/AZone', undefined, null] as unknown[]) {
            const hour = hourIn(instant, bad as string);
            expect(hour).toBeGreaterThanOrEqual(0);
            expect(hour).toBeLessThanOrEqual(23);
        }
    });

    test('formatLongDate falls back to the browser zone instead of throwing', () => {
        for (const bad of ['', 'Not/AZone', undefined, null] as unknown[]) {
            expect(formatLongDate(instant, bad as string)).toMatch(
                /^\w+, \d{1,2} \w+ 2026$/,
            );
        }
    });
});
