import { statusMeta } from '@/components/attendance/status-symbol';
import type { AttendanceStatusValue } from '@/components/attendance/status-symbol';
import { formatClockTime } from '@/lib/clock-time';

/**
 * One day of one person on the monthly grid, as the server sends it
 * (App\Support\Attendance\MonthGrid): null when there is nothing to show, else
 * [code, flags, in, out, worked, late, early, overtime]. Times are "H:i"
 * wall-clock strings (FLAG_NEXT_DAY: the clock-out is on the next calendar
 * day), the four numbers are minutes.
 */
export type GridCell =
    | [
          code: string,
          flags: number,
          clockIn: string | null,
          clockOut: string | null,
          worked: number,
          late: number,
          early: number,
          overtime: number,
      ]
    | null;

export type GridDay = {
    day: number;
    /** "Y-m-d", never passed through Date. */
    date: string;
    /** "Mon" ... worked out on the server. */
    weekday: string;
    future: boolean;
    holiday: string | null;
};

export type GridTotals = {
    present: number;
    late: number;
    half_day: number;
    absent: number;
    on_leave: number;
    /** Holidays and weekends. */
    off: number;
    worked_minutes: number;
    late_minutes: number;
    overtime_minutes: number;
    /** present + late + half day / 2 */
    equivalent: number;
    /** present + late + half day + absent */
    working_days: number;
};

export const FLAG_MANUAL = 1;
export const FLAG_EARLY = 2;
export const FLAG_OVERTIME = 4;
export const FLAG_ATTENTION = 8;
/** The clock-out is on the next calendar day (a night shift). */
export const FLAG_NEXT_DAY = 16;

/** The server's one-letter codes; "-" is a day waiting to be calculated. */
const CODE_STATUS: Record<string, AttendanceStatusValue> = {
    P: 'present',
    L: 'late',
    H: 'half_day',
    A: 'absent',
    V: 'on_leave',
    O: 'holiday',
    W: 'weekend',
};

/** The status of a cell's code; pending ("-") and unknown codes give null. */
export function cellStatus(cell: GridCell): AttendanceStatusValue {
    return cell === null ? null : (CODE_STATUS[cell[0]] ?? null);
}

export function hasFlag(cell: GridCell, flag: number): boolean {
    return cell !== null && (cell[1] & flag) !== 0;
}

/** "8h 05m", "45m", "0m". */
export function formatMinutes(minutes: number): string {
    const h = Math.floor(minutes / 60);
    const m = minutes % 60;

    return h === 0 ? `${m}m` : `${h}h ${String(m).padStart(2, '0')}m`;
}

/** "3.5" or "4": half days show one decimal, whole numbers none. */
export function formatCount(value: number): string {
    return Number.isInteger(value) ? String(value) : value.toFixed(1);
}

const MONTH_SHORT = [
    'Jan',
    'Feb',
    'Mar',
    'Apr',
    'May',
    'Jun',
    'Jul',
    'Aug',
    'Sep',
    'Oct',
    'Nov',
    'Dec',
];

/** "12 Nov" from "2026-11-12", read as text (no Date). */
export function shortDate(date: string): string {
    const match = /^\d{4}-(\d{2})-(\d{2})$/.exec(date);

    if (!match) {
        return date;
    }

    return `${Number(match[2])} ${MONTH_SHORT[Number(match[1]) - 1] ?? ''}`;
}

/** "6:00 AM", or "6:00 AM (next day)" for a clock-out after midnight. */
function clockOutText(cell: GridCell, clockOut: string): string {
    const time = formatClockTime(clockOut);

    return hasFlag(cell, FLAG_NEXT_DAY) ? `${time} (next day)` : time;
}

/** Why a blank cell is blank, in words. */
export function blankReason(day: GridDay, inWindow: boolean): string {
    if (day.future) {
        return 'Not yet';
    }

    return inWindow ? 'No record' : 'Not employed';
}

/** The detail lines of a cell: times and minutes, then the markers. */
export function cellDetails(cell: GridCell): string[] {
    if (cell === null) {
        return [];
    }

    const [, , clockIn, clockOut, worked, late, early, overtime] = cell;
    const lines: string[] = [];

    if (clockIn !== null || clockOut !== null) {
        lines.push(
            `In ${clockIn ? formatClockTime(clockIn) : '—'}, out ${clockOut ? clockOutText(cell, clockOut) : '—'}`,
        );
    }

    if (worked > 0) {
        lines.push(`Worked ${formatMinutes(worked)}`);
    }

    if (late > 0) {
        lines.push(`Late ${formatMinutes(late)}`);
    }

    if (early > 0) {
        lines.push(`Left early ${formatMinutes(early)}`);
    }

    if (overtime > 0) {
        lines.push(`Overtime ${formatMinutes(overtime)}`);
    }

    if (hasFlag(cell, FLAG_MANUAL)) {
        lines.push('Corrected by hand');
    }

    if (hasFlag(cell, FLAG_ATTENTION)) {
        lines.push('Needs attention');
    }

    return lines;
}

/**
 * The accessible name of a cell: "12 Nov, Late, in 9:20 AM, out 6:02 PM".
 * Blank cells say why ("12 Nov, Not yet").
 */
export function cellLabel(
    day: GridDay,
    cell: GridCell,
    inWindow: boolean,
): string {
    const parts = [shortDate(day.date)];

    if (cell === null) {
        parts.push(blankReason(day, inWindow));

        return parts.join(', ');
    }

    parts.push(statusMeta(cellStatus(cell)).label);

    const [, , clockIn, clockOut] = cell;

    if (clockIn) {
        parts.push(`in ${formatClockTime(clockIn)}`);
    }

    if (clockOut) {
        parts.push(`out ${clockOutText(cell, clockOut)}`);
    }

    if (hasFlag(cell, FLAG_EARLY)) {
        parts.push('left early');
    }

    if (hasFlag(cell, FLAG_OVERTIME)) {
        parts.push('overtime');
    }

    if (hasFlag(cell, FLAG_MANUAL)) {
        parts.push('corrected by hand');
    }

    if (hasFlag(cell, FLAG_ATTENTION)) {
        parts.push('needs attention');
    }

    return parts.join(', ');
}

/** Whether a day number (1-based) is inside a person's employment window. */
export function inWindow(
    window: [number, number] | null,
    day: number,
): boolean {
    return window !== null && day >= window[0] && day <= window[1];
}

/** A cell position on the grid: row index (person) and column index (day). */
export type GridPosition = { row: number; col: number };

/**
 * Where a key press moves the focus on a grid of `rows` x `cols` cells (the
 * roving tab stop): arrows by one, Home / End to the first / last day of the
 * row, Ctrl+Home / Ctrl+End to the first / last cell of the grid. Null for a
 * key the grid does not handle. Moves stop at the edges.
 */
export function moveGridFocus(
    from: GridPosition,
    key: string,
    ctrl: boolean,
    rows: number,
    cols: number,
): GridPosition | null {
    if (rows < 1 || cols < 1) {
        return null;
    }

    const clamp = (row: number, col: number): GridPosition => ({
        row: Math.min(Math.max(row, 0), rows - 1),
        col: Math.min(Math.max(col, 0), cols - 1),
    });

    switch (key) {
        case 'ArrowLeft':
            return clamp(from.row, from.col - 1);
        case 'ArrowRight':
            return clamp(from.row, from.col + 1);
        case 'ArrowUp':
            return clamp(from.row - 1, from.col);
        case 'ArrowDown':
            return clamp(from.row + 1, from.col);
        case 'Home':
            return ctrl ? clamp(0, 0) : clamp(from.row, 0);
        case 'End':
            return ctrl ? clamp(rows - 1, cols - 1) : clamp(from.row, cols - 1);
        default:
            return null;
    }
}

/** "YYYY-MM" from a year and a 1-based month. */
export function monthValue(year: number, month: number): string {
    return `${year}-${String(month).padStart(2, '0')}`;
}
