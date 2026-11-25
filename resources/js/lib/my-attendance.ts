import type { GridCell, GridDay } from '@/lib/monthly-grid';

/** Calendar columns, Monday first (the weekday names MonthGrid sends, "D" format). */
export const WEEKDAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

/** How many empty boxes come before day 1 in a Monday-first calendar (0 for an unknown weekday). */
export function leadingBlanks(firstWeekday: string | undefined): number {
    const index = WEEKDAYS.indexOf(firstWeekday ?? '');

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
