import { describe, expect, test } from 'vitest';
import {
    attendanceBannerLine,
    attendanceDayQualifier,
    barPercent,
    dayPhrase,
    dashboardTiles,
    formatDays,
    formatLongDate,
    greetingFor,
    hasTrendData,
    hourIn,
    personalAttendanceTiles,
    probationNote,
    shortDay,
    TREND_SERIES,
    teamAttendanceTiles,
    trendMax,
    trendSeries,
    weekdayDay,
} from '@/lib/dashboard';
import type {
    AttendanceDay,
    AttendanceToday,
    MyMonthAttendance,
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

describe('shortDay', () => {
    test('reads the date as text', () => {
        expect(shortDay('2026-11-24')).toBe('24 Nov');
        expect(shortDay('2026-01-05')).toBe('5 Jan');
    });

    test('returns anything else unchanged', () => {
        expect(shortDay('2026-13-01')).toBe('2026-13-01');
        expect(shortDay('nonsense')).toBe('nonsense');
    });
});

describe('trendSeries', () => {
    const days = ['2026-11-21', '2026-11-22', '2026-11-23'];

    test('one bar per listed day, oldest first, zeros where nothing was sent', () => {
        const series = trendSeries(days, [
            { date: '2026-11-23', status: 'present', count: 4 },
            { date: '2026-11-23', status: 'late', count: 2 },
            { date: '2026-11-21', status: 'absent', count: 1 },
            { date: '2026-11-21', status: 'half_day', count: 3 },
        ]);

        expect(series.map((d) => d.date)).toEqual(days);
        expect(series[0]).toEqual({
            date: '2026-11-21',
            label: '21 Nov',
            present: 0,
            late: 0,
            half_day: 3,
            absent: 1,
            on_leave: 0,
            total: 4,
        });
        expect(series[1].total).toBe(0);
        expect(series[2]).toMatchObject({ present: 4, late: 2, total: 6 });
    });

    test('ignores days not listed and statuses not charted', () => {
        const series = trendSeries(days, [
            { date: '2026-11-24', status: 'present', count: 9 },
            { date: '2026-11-22', status: 'weekend', count: 5 },
            { date: '2026-11-22', status: 'holiday', count: 1 },
        ]);

        expect(series.map((d) => d.total)).toEqual([0, 0, 0]);
        expect(hasTrendData(series)).toBe(false);
    });

    test('charts full leave days as their own series', () => {
        const series = trendSeries(days, [
            { date: '2026-11-23', status: 'on_leave', count: 2 },
            { date: '2026-11-23', status: 'present', count: 5 },
        ]);

        expect(TREND_SERIES).toContain('on_leave');
        expect(series[2]).toMatchObject({ on_leave: 2, present: 5, total: 7 });
    });

    test('scale: the tallest bar, never below 1, and segment heights as percentages', () => {
        const series = trendSeries(days, [
            { date: '2026-11-22', status: 'present', count: 3 },
            { date: '2026-11-22', status: 'late', count: 1 },
            { date: '2026-11-23', status: 'present', count: 1 },
        ]);

        expect(trendMax(series)).toBe(4);
        expect(trendMax(trendSeries(days, []))).toBe(1);
        expect(barPercent(1, 4)).toBe(25);
        expect(barPercent(1, 3)).toBe(33.3);
        expect(barPercent(0, 4)).toBe(0);
        expect(hasTrendData(series)).toBe(true);
    });

    test('an empty list of days gives no bars', () => {
        expect(trendSeries([], [])).toEqual([]);
    });
});

const baseToday: AttendanceToday = {
    date: '2026-11-24',
    open: true,
    started: true,
    start_date: '2026-01-01',
    present: 7,
    late: 2,
    absent: 0,
    pending: 0,
    on_leave: 0,
    not_in: 3,
    expected: 10,
    holiday: false,
    last_punch: { date: '2026-11-24', time: '13:05' },
};

describe('attendanceBannerLine', () => {
    test('present count and the last punch in 12-hour form', () => {
        expect(attendanceBannerLine(baseToday)).toBe(
            '7 present today · last punch received 1:05 PM',
        );
    });

    test('names the day when the last punch is not from today', () => {
        expect(
            attendanceBannerLine({
                ...baseToday,
                last_punch: { date: '2026-11-22', time: '00:05' },
            }),
        ).toBe('7 present today · last punch received 22 Nov, 12:05 AM');
    });

    test('leaves the punch out when there are none', () => {
        expect(
            attendanceBannerLine({
                ...baseToday,
                present: 0,
                last_punch: null,
            }),
        ).toBe('0 present today');
    });
});

// 02:00 on Wed 25 Nov with the 04:00 cutoff: the figures are still Tuesday's.
const afterMidnight: AttendanceDay = {
    date: '2026-11-24',
    calendar_date: '2026-11-25',
    differs: true,
    cutoff: '04:00',
};

const sameDay: AttendanceDay = {
    date: '2026-11-24',
    calendar_date: '2026-11-24',
    differs: false,
    cutoff: '04:00',
};

describe('the attendance day label', () => {
    test('weekdayDay reads the text date (no Date), across months, years and leap days', () => {
        expect(weekdayDay('2026-11-24')).toBe('Tue 24 Nov');
        expect(weekdayDay('2026-11-25')).toBe('Wed 25 Nov');
        expect(weekdayDay('2026-01-01')).toBe('Thu 1 Jan');
        expect(weekdayDay('2024-02-29')).toBe('Thu 29 Feb');
        expect(weekdayDay('2027-03-01')).toBe('Mon 1 Mar');
        expect(weekdayDay('2000-12-31')).toBe('Sun 31 Dec');
        expect(weekdayDay('nonsense')).toBe('nonsense');
    });

    test('a qualifier only when the attendance day is not the calendar date', () => {
        expect(attendanceDayQualifier(afterMidnight)).toBe(
            'Figures for Tue 24 Nov — the day closes at 4:00 AM',
        );
        expect(
            attendanceDayQualifier({ ...afterMidnight, cutoff: '05:30' }),
        ).toBe('Figures for Tue 24 Nov — the day closes at 5:30 AM');
        expect(attendanceDayQualifier(sameDay)).toBeNull();
        expect(attendanceDayQualifier(undefined)).toBeNull();
    });

    test('"today" becomes the day when they differ', () => {
        expect(dayPhrase(sameDay)).toBe('today');
        expect(dayPhrase(undefined)).toBe('today');
        expect(dayPhrase(afterMidnight)).toBe('on Tue 24 Nov');
    });

    test('the banner and the tiles say the day instead of "today"', () => {
        expect(attendanceBannerLine(baseToday, afterMidnight)).toBe(
            '7 present on Tue 24 Nov · last punch received 1:05 PM',
        );
        expect(attendanceBannerLine(baseToday, sameDay)).toBe(
            '7 present today · last punch received 1:05 PM',
        );

        const tiles = teamAttendanceTiles(baseToday, afterMidnight);
        expect(tiles.present).toEqual({
            label: 'Present',
            value: '7',
            note: 'Clocked in on Tue 24 Nov',
        });
        expect(tiles.absent.note).toBe('of 10 expected on Tue 24 Nov');
        expect(tiles.late.label).toBe('Late');
        expect(
            teamAttendanceTiles({ ...baseToday, late: 0 }, afterMidnight).late
                .note,
        ).toBe('Nobody late on Tue 24 Nov');

        // Same day: the tiles keep their own "... Today" labels.
        expect(teamAttendanceTiles(baseToday, sameDay).present.label).toBe(
            undefined,
        );
    });
});

describe('teamAttendanceTiles', () => {
    test('a day nobody is expected (a weekend) says so instead of "0 of 0"', () => {
        const weekend = { ...baseToday, expected: 0, not_in: 0 };

        expect(teamAttendanceTiles(weekend).absent).toEqual({
            label: 'Not in yet',
            value: '0',
            note: 'Nobody expected today',
        });
        expect(teamAttendanceTiles(weekend, afterMidnight).absent.note).toBe(
            'Nobody expected on Tue 24 Nov',
        );
    });

    test('open day: "Not in yet" out of the expected people', () => {
        const tiles = teamAttendanceTiles(baseToday);

        expect(tiles.present).toEqual({
            value: '7',
            note: 'Clocked in today',
        });
        expect(tiles.absent).toEqual({
            label: 'Not in yet',
            value: '3',
            note: 'of 10 expected today',
        });
        expect(tiles.late.value).toBe('2');
    });

    test('a closed day reads "Absent Today" and adds those marked absent', () => {
        const tiles = teamAttendanceTiles({
            ...baseToday,
            open: false,
            absent: 1,
        });

        expect(tiles.absent).toEqual({
            label: 'Absent Today',
            value: '4',
            note: 'of 10 expected',
        });
    });

    test('before attendance has started: a dash and a hint, never "everyone absent"', () => {
        const notStarted = {
            ...baseToday,
            started: false,
            start_date: null,
            not_in: null,
            expected: null,
        };

        expect(teamAttendanceTiles(notStarted).absent).toEqual({
            label: 'Not in yet',
            value: '–',
            note: 'Attendance has not started',
        });
        expect(
            teamAttendanceTiles({ ...notStarted, start_date: '2026-12-01' })
                .absent.note,
        ).toBe('Attendance starts 1 Dec');
        expect(teamAttendanceTiles(notStarted).present.value).toBe('7');
    });

    test('notes: holiday, marked absent, pending, nobody late', () => {
        expect(
            teamAttendanceTiles({ ...baseToday, holiday: true, not_in: 0 })
                .absent.note,
        ).toBe('Holiday today');
        expect(
            teamAttendanceTiles({ ...baseToday, absent: 2 }).absent.note,
        ).toBe('2 marked absent');
        expect(
            teamAttendanceTiles({ ...baseToday, pending: 3 }).present.note,
        ).toBe('Clocked in today · 3 being calculated');
        expect(teamAttendanceTiles({ ...baseToday, late: 0 }).late.note).toBe(
            'Nobody late today',
        );
    });
});

describe('personalAttendanceTiles', () => {
    const mine: MyMonthAttendance = {
        month: '2026-11',
        label: 'November 2026',
        totals: {
            present: 2,
            late: 1,
            half_day: 1,
            absent: 1,
            on_leave: 0,
            off: 4,
            worked_minutes: 1800,
            late_minutes: 65,
            overtime_minutes: 0,
            equivalent: 3.5,
            working_days: 5,
        },
        today: null,
    };

    test('present-equivalent with half days, out of working days', () => {
        const tiles = personalAttendanceTiles(mine);

        expect(tiles.present).toEqual({
            label: 'Present This Month',
            value: '3.5',
            note: 'of 5 working days',
        });
        expect(tiles.absent).toEqual({
            label: 'Absent This Month',
            value: '1',
            note: 'November 2026',
        });
        expect(tiles.late).toEqual({
            label: 'Late This Month',
            value: '1',
            note: '1h 5m late in total',
        });
    });

    test('an empty month', () => {
        const empty = personalAttendanceTiles({
            ...mine,
            totals: {
                ...mine.totals,
                present: 0,
                late: 0,
                half_day: 0,
                absent: 0,
                late_minutes: 0,
                equivalent: 0,
                working_days: 0,
            },
        });

        expect(empty.present.value).toBe('0');
        expect(empty.present.note).toBe('No working days yet');
        expect(empty.late.note).toBe('Never late');
    });
});

describe('formatDays', () => {
    test.each([
        [0, '0'],
        [4, '4'],
        [3.5, '3.5'],
    ])('%d', (value, expected) => {
        expect(formatDays(value)).toBe(expected);
    });
});
