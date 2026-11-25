import { moveGridFocus } from '@/lib/monthly-grid';
import type { GridCell, GridDay } from '@/lib/monthly-grid';

/** Calendar columns, Monday first (the weekday names MonthGrid sends, "D" format). */
export const WEEKDAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

/** How many empty boxes come before day 1 in a Monday-first calendar (0 for an unknown weekday). */
export function leadingBlanks(firstWeekday: string | undefined): number {
    const index = WEEKDAYS.indexOf(firstWeekday ?? '');

    return index < 0 ? 0 : index;
}

/**
 * Where a key press moves the calendar's one tab stop: the days laid out Monday first, seven to a week, after
 * `blanks` empty boxes. The move is the monthly grid's (moveGridFocus: arrows by one day or one week, Home / End to
 * the first / last day of the week, Ctrl+Home / Ctrl+End to the first / last day of the month), kept on real days.
 * Returns the new day index (0-based), or null for a key the calendar does not handle.
 */
export function moveCalendarFocus(
    index: number,
    key: string,
    ctrl: boolean,
    count: number,
    blanks: number,
): number | null {
    if (count < 1) {
        return null;
    }

    const at = index + blanks;
    const next = moveGridFocus(
        { row: Math.floor(at / 7), col: at % 7 },
        key,
        ctrl,
        Math.ceil((count + blanks) / 7),
        7,
    );

    if (next === null) {
        return null;
    }

    return Math.min(Math.max(next.row * 7 + next.col - blanks, 0), count - 1);
}

/** The calendar's tab stop when the page opens: today when it is in this month, else the first day. */
export function initialCalendarFocus(days: GridDay[], today: string): number {
    const index = days.findIndex((day) => day.date === today);

    return index < 0 ? 0 : index;
}

export type DayListItem = {
    day: GridDay;
    cell: Exclude<GridCell, null>;
    /** "Request correction" is offered on this day. */
    correctable: boolean;
};

/**
 * The days of the month that have something to show (a stored, in-window day: MonthGrid already blanks everything
 * else), newest first, each marked when a correction may be requested for it.
 */
export function dayList(
    days: GridDay[],
    cells: GridCell[],
    correctable: string[],
): DayListItem[] {
    const allowed = new Set(correctable);
    const items: DayListItem[] = [];

    days.forEach((day, index) => {
        const cell = cells[index] ?? null;

        if (cell !== null) {
            items.push({ day, cell, correctable: allowed.has(day.date) });
        }
    });

    return items.reverse();
}
