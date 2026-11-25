import { describe, expect, test } from 'vitest';
import { dayList, leadingBlanks } from '@/lib/my-attendance';
import type { GridCell, GridDay } from '@/lib/monthly-grid';

const day = (n: number, weekday: string): GridDay => ({
    day: n,
    date: `2026-11-${String(n).padStart(2, '0')}`,
    weekday,
    future: false,
    holiday: null,
});

describe('leadingBlanks', () => {
    test.each([
        ['Mon', 0],
        ['Wed', 2],
        ['Sun', 6],
        ['nope', 0],
        [undefined, 0],
    ])('%s', (weekday, expected) => {
        expect(leadingBlanks(weekday)).toBe(expected);
    });
});

describe('dayList', () => {
    const days = [day(1, 'Sun'), day(2, 'Mon'), day(3, 'Tue'), day(4, 'Wed')];
    const present: GridCell = ['P', 0, '09:00', '18:00', 540, 0, 0, 0];
    const pending: GridCell = ['-', 0, '09:05', null, 0, 0, 0, 0];

    test('only days with something to show, newest first, with the correction flag', () => {
        const items = dayList(
            days,
            [null, present, null, pending],
            ['2026-11-02', '2026-11-03'],
        );

        expect(items.map((i) => i.day.date)).toEqual([
            '2026-11-04',
            '2026-11-02',
        ]);
        expect(items.map((i) => i.correctable)).toEqual([false, true]);
    });

    test('an empty month gives an empty list', () => {
        expect(dayList(days, [null, null, null, null], [])).toEqual([]);
        expect(dayList([], [], [])).toEqual([]);
    });
});
