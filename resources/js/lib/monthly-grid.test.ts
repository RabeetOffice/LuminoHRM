import { describe, expect, it } from 'vitest';
import {
    cellDetails,
    cellLabel,
    cellStatus,
    formatCount,
    formatMinutes,
    inWindow,
    monthValue,
    shortDate,
} from './monthly-grid';
import type { GridCell, GridDay } from './monthly-grid';

const day = (date: string, future = false): GridDay => ({
    day: Number(date.slice(8)),
    date,
    weekday: 'Thu',
    future,
    holiday: null,
});

describe('cellStatus', () => {
    it('maps the server codes and treats pending as null', () => {
        expect(cellStatus(['P', 0, null, null, 0, 0, 0, 0])).toBe('present');
        expect(cellStatus(['H', 0, null, null, 0, 0, 0, 0])).toBe('half_day');
        expect(cellStatus(['O', 0, null, null, 0, 0, 0, 0])).toBe('holiday');
        expect(cellStatus(['-', 0, null, null, 0, 0, 0, 0])).toBeNull();
        expect(cellStatus(null)).toBeNull();
    });
});

describe('cellLabel', () => {
    it('reads date, status and times', () => {
        const cell: GridCell = ['L', 0, '09:20', '18:02', 522, 20, 0, 0];

        expect(cellLabel(day('2026-11-12'), cell, true)).toBe(
            '12 Nov, Late, in 9:20 AM, out 6:02 PM',
        );
    });

    it('names the markers', () => {
        const cell: GridCell = ['P', 1 | 2 | 4, '08:00', null, 0, 0, 30, 40];

        expect(cellLabel(day('2026-11-03'), cell, true)).toBe(
            '3 Nov, Present, in 8:00 AM, left early, overtime, corrected by hand',
        );
    });

    it('says pending, never present, for a day waiting to be calculated', () => {
        expect(
            cellLabel(
                day('2026-11-03'),
                ['-', 0, '09:00', null, 0, 0, 0, 0],
                true,
            ),
        ).toBe('3 Nov, Pending, in 9:00 AM');
    });

    it('says why a blank cell is blank', () => {
        expect(cellLabel(day('2026-11-30', true), null, true)).toBe(
            '30 Nov, Not yet',
        );
        expect(cellLabel(day('2026-11-02'), null, true)).toBe(
            '2 Nov, No record',
        );
        expect(cellLabel(day('2026-11-02'), null, false)).toBe(
            '2 Nov, Not employed',
        );
    });
});

describe('cellDetails', () => {
    it('lists times and minutes', () => {
        expect(cellDetails(['L', 8, '09:20', null, 485, 20, 0, 65])).toEqual([
            'In 9:20 AM, out —',
            'Worked 8h 05m',
            'Late 20m',
            'Overtime 1h 05m',
            'Needs attention',
        ]);
        expect(cellDetails(null)).toEqual([]);
    });
});

describe('small formatters', () => {
    it('formats minutes, counts, dates and months', () => {
        expect(formatMinutes(0)).toBe('0m');
        expect(formatMinutes(125)).toBe('2h 05m');
        expect(formatCount(4)).toBe('4');
        expect(formatCount(3.5)).toBe('3.5');
        expect(shortDate('2026-01-09')).toBe('9 Jan');
        expect(monthValue(2026, 3)).toBe('2026-03');
        expect(inWindow([4, 25], 4)).toBe(true);
        expect(inWindow([4, 25], 26)).toBe(false);
        expect(inWindow(null, 1)).toBe(false);
    });
});
