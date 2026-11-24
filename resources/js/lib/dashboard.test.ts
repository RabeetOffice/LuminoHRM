import { describe, expect, test } from 'vitest';
import {
    dashboardTiles,
    formatLongDate,
    greetingFor,
    hourIn,
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
    const base = [
        'Total Employees',
        'Present Today',
        'Absent Today',
        'Late Today',
        'Pending Leaves',
    ];

    test('with payroll.view the sixth tile is Payroll This Month', () => {
        expect(dashboardTiles(true).map((t) => t.label)).toEqual([
            ...base,
            'Payroll This Month',
        ]);
    });

    test('without payroll.view the sixth tile is Leave Balance', () => {
        const labels = dashboardTiles(false).map((t) => t.label);
        expect(labels).toEqual([...base, 'Leave Balance']);
        expect(labels).not.toContain('Payroll This Month');
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
