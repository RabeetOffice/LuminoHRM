import { describe, expect, it } from 'vitest';
import {
    FLAG_HALF_LEAVE,
    FLAG_MANUAL,
    cellDetails,
    cellLabel,
    cellStatus,
    formatCount,
    formatMinutes,
    inWindow,
    monthValue,
    moveGridFocus,
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

describe('next-day clock-out and needs attention', () => {
    const night: GridCell = ['P', 16 | 8, '21:00', '06:00', 540, 0, 0, 0];

    it('says "(next day)" in the label and the details', () => {
        expect(cellLabel(day('2026-11-02'), night, true)).toBe(
            '2 Nov, Present, in 9:00 PM, out 6:00 AM (next day), needs attention',
        );
        expect(cellDetails(night)[0]).toBe(
            'In 9:00 PM, out 6:00 AM (next day)',
        );
    });

    it('does not say it for a same-day clock-out', () => {
        expect(
            cellLabel(
                day('2026-11-02'),
                ['P', 0, '09:00', '18:00', 540, 0, 0, 0],
                true,
            ),
        ).toBe('2 Nov, Present, in 9:00 AM, out 6:00 PM');
    });
});

describe('moveGridFocus', () => {
    const at = (row: number, col: number) => ({ row, col });

    it('moves by one with the arrows and stops at the edges', () => {
        expect(moveGridFocus(at(1, 1), 'ArrowRight', false, 3, 30)).toEqual(
            at(1, 2),
        );
        expect(moveGridFocus(at(1, 1), 'ArrowLeft', false, 3, 30)).toEqual(
            at(1, 0),
        );
        expect(moveGridFocus(at(1, 1), 'ArrowUp', false, 3, 30)).toEqual(
            at(0, 1),
        );
        expect(moveGridFocus(at(1, 1), 'ArrowDown', false, 3, 30)).toEqual(
            at(2, 1),
        );
        expect(moveGridFocus(at(0, 0), 'ArrowLeft', false, 3, 30)).toEqual(
            at(0, 0),
        );
        expect(moveGridFocus(at(2, 29), 'ArrowDown', false, 3, 30)).toEqual(
            at(2, 29),
        );
    });

    it('jumps with Home / End along the row, with Ctrl across the grid', () => {
        expect(moveGridFocus(at(1, 5), 'Home', false, 3, 30)).toEqual(at(1, 0));
        expect(moveGridFocus(at(1, 5), 'End', false, 3, 30)).toEqual(at(1, 29));
        expect(moveGridFocus(at(1, 5), 'Home', true, 3, 30)).toEqual(at(0, 0));
        expect(moveGridFocus(at(1, 5), 'End', true, 3, 30)).toEqual(at(2, 29));
    });

    it('ignores other keys and an empty grid', () => {
        expect(moveGridFocus(at(0, 0), 'Enter', false, 3, 30)).toBeNull();
        expect(moveGridFocus(at(0, 0), 'ArrowRight', false, 0, 30)).toBeNull();
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

describe('half-day leave', () => {
    const present: GridCell = [
        'P',
        FLAG_HALF_LEAVE,
        '13:30',
        '18:00',
        270,
        0,
        0,
        0,
    ];
    const absent: GridCell = ['A', FLAG_HALF_LEAVE, null, null, 0, 0, 0, 0];

    it('says so in the accessible name, after the worked half', () => {
        expect(cellLabel(day('2026-11-02'), present, true)).toBe(
            '2 Nov, Present, half-day leave, in 1:30 PM, out 6:00 PM',
        );
        expect(cellLabel(day('2026-11-03'), absent, true)).toBe(
            '3 Nov, Absent, half-day leave',
        );
    });

    it('adds a detail line, and none without the flag', () => {
        expect(cellDetails(present)).toContain('Half-day leave');
        expect(
            cellDetails(['P', FLAG_MANUAL, '09:00', '18:00', 540, 0, 0, 0]),
        ).not.toContain('Half-day leave');
    });

    it('formats half totals with one decimal', () => {
        expect(formatCount(2.5)).toBe('2.5');
        expect(formatCount(0.5)).toBe('0.5');
        expect(formatCount(3)).toBe('3');
    });
});
