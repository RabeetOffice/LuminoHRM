import { describe, expect, test } from 'vitest';
import {
    dayList,
    initialCalendarFocus,
    leadingBlanks,
    moveCalendarFocus,
} from '@/lib/my-attendance';
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

describe('moveCalendarFocus', () => {
    // November 2026: 30 days, the 1st is a Sunday (6 blanks before it).
    const move = (index: number, key: string, ctrl = false) =>
        moveCalendarFocus(index, key, ctrl, 30, 6);

    test('arrows move one day or one week', () => {
        expect(move(10, 'ArrowRight')).toBe(11);
        expect(move(10, 'ArrowLeft')).toBe(9);
        expect(move(10, 'ArrowDown')).toBe(17);
        expect(move(10, 'ArrowUp')).toBe(3);
    });

    test('stops at the edges of the month and never lands on a blank box', () => {
        expect(move(0, 'ArrowLeft')).toBe(0);
        expect(move(0, 'ArrowUp')).toBe(0);
        expect(move(29, 'ArrowRight')).toBe(29);
        expect(move(29, 'ArrowDown')).toBe(29);
        // The 28th (a Saturday) down: no day below it, so the last day.
        expect(move(27, 'ArrowDown')).toBe(29);
    });

    test('Home / End: the week; with Ctrl, the month', () => {
        // The 11th is a Wednesday: its week runs from the 9th to the 15th.
        expect(move(10, 'Home')).toBe(8);
        expect(move(10, 'End')).toBe(14);
        // The first week holds only the 1st.
        expect(move(0, 'Home')).toBe(0);
        expect(move(10, 'Home', true)).toBe(0);
        expect(move(10, 'End', true)).toBe(29);
    });

    test('ignores other keys and an empty month', () => {
        expect(move(10, 'Enter')).toBeNull();
        expect(moveCalendarFocus(0, 'ArrowRight', false, 0, 0)).toBeNull();
    });
});

describe('initialCalendarFocus', () => {
    test('today when it is in the month, else the first day', () => {
        const days = [day(1, 'Sun'), day(2, 'Mon'), day(3, 'Tue')];

        expect(initialCalendarFocus(days, '2026-11-02')).toBe(1);
        expect(initialCalendarFocus(days, '2026-12-02')).toBe(0);
    });
});
