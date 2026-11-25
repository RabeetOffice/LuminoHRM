import { describe, expect, test } from 'vitest';
import {
    calendarAgenda,
    calendarBlanks,
    calendarDate,
    calendarWeeks,
    halfLabel,
    halfMark,
    leaveDotClass,
    shapeCalendar,
    visibleChips,
} from '@/lib/leave';
import type { CalendarChip, CalendarInput } from '@/lib/leave';

const december: CalendarInput = {
    month: '2026-12',
    first_weekday: 1, // Tuesday
    days_in_month: 31,
    today: '2026-12-10',
    holidays: [{ date: '2026-12-25', name: 'Christmas' }],
    leave: [
        {
            employee_id: 2,
            name: 'Zara Malik',
            first_name: 'Zara',
            type: 'Annual',
            colour: 'emerald',
            dates: [
                { date: '2026-12-14', half: 'full' },
                { date: '2026-12-15', half: 'full' },
            ],
        },
        {
            employee_id: 1,
            name: 'Asma Khan',
            first_name: 'Asma',
            type: 'Sick',
            colour: 'rose',
            dates: [{ date: '2026-12-14', half: 'pm' }],
        },
    ],
    pending_own: [
        {
            type: 'Casual',
            colour: 'sky',
            status: 'pending',
            dates: [{ date: '2026-12-14', half: 'am' }],
        },
    ],
    birthdays: [
        { day: 3, name: 'Probation Two' },
        { day: 15, name: 'Active One' },
    ],
};

describe('calendar grid shaping', () => {
    test('the 1st sits on the right weekday and weekdays follow on', () => {
        const days = shapeCalendar(december);

        expect(days).toHaveLength(31);
        expect(days[0]).toMatchObject({
            day: 1,
            date: '2026-12-01',
            weekday: 'Tue',
            weekdayIndex: 1,
        });
        expect(days[5]).toMatchObject({ weekday: 'Sun', isWeekend: true });
        expect(days[6]).toMatchObject({ weekday: 'Mon', isWeekend: false });
        expect(days[30]).toMatchObject({ date: '2026-12-31', weekday: 'Thu' });
    });

    test('weeks are Monday first with blanks before the 1st and after the last day', () => {
        const days = shapeCalendar(december);
        const weeks = calendarWeeks(days, december.first_weekday);

        expect(weeks).toHaveLength(5);
        expect(weeks.every((w) => w.length === 7)).toBe(true);
        expect(weeks[0][0]).toBeNull();
        expect(weeks[0][1]?.day).toBe(1);
        expect(weeks[4][3]?.day).toBe(31);
        expect(weeks[4].slice(4)).toEqual([null, null, null]);
    });

    test('a month starting on Monday has no leading blank; one on Sunday has six', () => {
        const feb = shapeCalendar({
            ...december,
            month: '2027-02',
            first_weekday: 0,
            days_in_month: 28,
        });
        expect(calendarWeeks(feb, 0)).toHaveLength(4);
        expect(calendarWeeks(feb, 0)[0][0]?.day).toBe(1);

        const nov = shapeCalendar({
            ...december,
            month: '2026-11',
            first_weekday: 6,
            days_in_month: 30,
        });
        expect(nov[0].weekday).toBe('Sun');
        expect(calendarWeeks(nov, 6)[0].slice(0, 6)).toEqual(
            Array(6).fill(null),
        );
    });

    test('dates are built from text, padded, never through Date', () => {
        expect(calendarDate('2026-03', 1)).toBe('2026-03-01');
        expect(calendarDate('2026-03', 31)).toBe('2026-03-31');
        expect(calendarBlanks(-2)).toBe(0);
        expect(calendarBlanks(9)).toBe(6);
        expect(calendarBlanks(Number.NaN)).toBe(0);
    });

    test('today, holidays and birthdays land on their day', () => {
        const days = shapeCalendar(december);

        expect(days.filter((d) => d.isToday).map((d) => d.date)).toEqual([
            '2026-12-10',
        ]);
        expect(days[24].holidays).toEqual(['Christmas']);
        expect(days[2].birthdays).toEqual(['Probation Two']);
        expect(days[14].birthdays).toEqual(['Active One']);
    });

    test('own pending first, then approved leave by name, halves kept', () => {
        const chips = shapeCalendar(december)[13].chips;

        expect(
            chips.map((c) => [c.label, c.half, c.pending, c.colour]),
        ).toEqual([
            ['Pending', 'am', true, 'sky'],
            ['Asma', 'pm', false, 'rose'],
            ['Zara', 'full', false, 'emerald'],
        ]);
        expect(new Set(chips.map((c) => c.key)).size).toBe(3);
    });

    test('halves are labelled', () => {
        expect(halfMark('am')).toBe('½');
        expect(halfMark('pm')).toBe('½');
        expect(halfMark('full')).toBe('');
        expect(halfLabel('am')).toBe('First half');
        expect(halfLabel('pm')).toBe('Second half');
        expect(halfLabel('full')).toBe('Full day');
    });

    test('entries dated outside the month are ignored', () => {
        const days = shapeCalendar({
            ...december,
            holidays: [{ date: '2027-01-01', name: 'New Year' }],
            birthdays: [{ day: 32, name: 'Nobody' }],
            leave: [
                {
                    ...december.leave[0],
                    dates: [{ date: '2026-11-30', half: 'full' }],
                },
            ],
            pending_own: [],
        });

        expect(calendarAgenda(days)).toEqual([]);
    });
});

describe('chips and the agenda', () => {
    const chip = (n: number): CalendarChip => ({
        key: String(n),
        name: `P${n}`,
        label: `P${n}`,
        type: 'Annual',
        colour: 'emerald',
        half: 'full',
        pending: false,
    });

    test('up to three chips, then "+N more"', () => {
        expect(visibleChips([chip(1), chip(2)])).toEqual({
            shown: [chip(1), chip(2)],
            more: 0,
        });

        const five = visibleChips([1, 2, 3, 4, 5].map(chip));
        expect(five.shown.map((c) => c.key)).toEqual(['1', '2', '3']);
        expect(five.more).toBe(2);
    });

    test('the agenda keeps only days with something, in order', () => {
        expect(
            calendarAgenda(shapeCalendar(december)).map((d) => d.day),
        ).toEqual([3, 14, 15, 25]);
    });

    test('an empty month has an empty agenda', () => {
        const days = shapeCalendar({
            ...december,
            holidays: [],
            leave: [],
            pending_own: [],
            birthdays: [],
        });

        expect(days).toHaveLength(31);
        expect(calendarAgenda(days)).toEqual([]);
    });

    test('leave colours map to the fixed palette, unknown to slate', () => {
        expect(leaveDotClass('rose')).toBe('bg-rose-500');
        expect(leaveDotClass('nope')).toBe('bg-slate-500');
    });
});
