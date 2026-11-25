/**
 * Leave helpers shared by the leave screens.
 *
 * This file was started by the Calendar (Task 9) with only the calendar shaping below. The leave formatting helpers
 * (day amounts, status labels, ...) are appended by the My Leaves / apply work (Task 5).
 *
 * Every date here is the server's "Y-m-d" text; nothing is passed through `new Date()`, so no date can shift with the
 * browser's time zone. Weekdays come from arithmetic on the server's Monday-first index of the 1st.
 */

// ---------------------------------------------------------------------------------------------------------------------
// Calendar shaping (Task 9)
// ---------------------------------------------------------------------------------------------------------------------

/** Calendar columns, Monday first. */
export const CALENDAR_WEEKDAYS = [
    'Mon',
    'Tue',
    'Wed',
    'Thu',
    'Fri',
    'Sat',
    'Sun',
] as const;

/** How many leave chips a day box shows before "+N more". */
export const CALENDAR_CHIP_LIMIT = 3;

export type CalendarHalf = 'full' | 'am' | 'pm';

export type CalendarLeaveEntry = {
    employee_id: number;
    name: string;
    first_name: string;
    type: string;
    colour: string;
    dates: { date: string; half: CalendarHalf }[];
};

export type CalendarPendingEntry = {
    type: string;
    colour: string;
    status: string;
    dates: { date: string; half: CalendarHalf }[];
};

export type CalendarInput = {
    /** "YYYY-MM". */
    month: string;
    /** 0 = Monday ... 6 = Sunday: the weekday of the 1st. */
    first_weekday: number;
    days_in_month: number;
    /** "Y-m-d". */
    today: string;
    holidays: { date: string; name: string }[];
    leave: CalendarLeaveEntry[];
    pending_own: CalendarPendingEntry[];
    birthdays: { day: number; name: string }[];
};

export type CalendarChip = {
    key: string;
    /** Full name (approved leave) or the type name (the viewer's own pending request). */
    name: string;
    /** What the chip shows: the first name, or "Pending" for an own open request. */
    label: string;
    type: string;
    colour: string;
    half: CalendarHalf;
    pending: boolean;
};

export type CalendarDay = {
    day: number;
    date: string;
    /** 0 = Monday ... 6 = Sunday. */
    weekdayIndex: number;
    weekday: (typeof CALENDAR_WEEKDAYS)[number];
    isToday: boolean;
    isWeekend: boolean;
    holidays: string[];
    /** The viewer's own pending requests first, then approved leave by name. */
    chips: CalendarChip[];
    birthdays: string[];
};

/** "2026-12" + 5 -> "2026-12-05". */
export function calendarDate(month: string, day: number): string {
    return `${month}-${String(day).padStart(2, '0')}`;
}

/** Empty boxes before day 1 in a Monday-first grid (clamped to 0..6). */
export function calendarBlanks(firstWeekday: number): number {
    return Number.isInteger(firstWeekday)
        ? Math.min(Math.max(firstWeekday, 0), 6)
        : 0;
}

/** "½" for a half day, "" for a full day. */
export function halfMark(half: CalendarHalf): string {
    return half === 'full' ? '' : '½';
}

/** Words for a half: "First half" / "Second half" / "Full day". */
export function halfLabel(half: CalendarHalf): string {
    if (half === 'am') {
        return 'First half';
    }

    return half === 'pm' ? 'Second half' : 'Full day';
}

/** The tailwind class of a leave colour's dot (the fixed palette of config('hrm.leave.colours')). */
export function leaveDotClass(colour: string): string {
    return DOT_CLASSES[colour] ?? 'bg-slate-500';
}

const DOT_CLASSES: Record<string, string> = {
    emerald: 'bg-emerald-500',
    sky: 'bg-sky-500',
    amber: 'bg-amber-500',
    rose: 'bg-rose-500',
    violet: 'bg-violet-500',
    teal: 'bg-teal-500',
    orange: 'bg-orange-500',
    slate: 'bg-slate-500',
};

/**
 * Every day of the month with what falls on it. Dates outside the month in the input are ignored.
 */
export function shapeCalendar(input: CalendarInput): CalendarDay[] {
    const count = Math.max(0, Math.floor(input.days_in_month));
    const blanks = calendarBlanks(input.first_weekday);
    const days: CalendarDay[] = [];
    const byDate = new Map<string, CalendarDay>();

    for (let day = 1; day <= count; day++) {
        const weekdayIndex = (blanks + day - 1) % 7;
        const date = calendarDate(input.month, day);
        const item: CalendarDay = {
            day,
            date,
            weekdayIndex,
            weekday: CALENDAR_WEEKDAYS[weekdayIndex],
            isToday: date === input.today,
            isWeekend: weekdayIndex >= 5,
            holidays: [],
            chips: [],
            birthdays: [],
        };
        days.push(item);
        byDate.set(date, item);
    }

    for (const holiday of input.holidays) {
        byDate.get(holiday.date)?.holidays.push(holiday.name);
    }

    input.pending_own.forEach((entry, entryIndex) => {
        for (const { date, half } of entry.dates) {
            byDate.get(date)?.chips.push({
                key: `p${entryIndex}-${date}-${half}`,
                name: entry.type,
                label: 'Pending',
                type: entry.type,
                colour: entry.colour,
                half,
                pending: true,
            });
        }
    });

    const approved = [...input.leave]
        .map((entry, entryIndex) => ({ entry, entryIndex }))
        .sort(
            (a, b) =>
                a.entry.name.localeCompare(b.entry.name) ||
                a.entry.employee_id - b.entry.employee_id ||
                a.entryIndex - b.entryIndex,
        );

    for (const { entry, entryIndex } of approved) {
        for (const { date, half } of entry.dates) {
            byDate.get(date)?.chips.push({
                key: `l${entryIndex}-${date}-${half}`,
                name: entry.name,
                label: entry.first_name || entry.name,
                type: entry.type,
                colour: entry.colour,
                half,
                pending: false,
            });
        }
    }

    for (const birthday of input.birthdays) {
        days[birthday.day - 1]?.birthdays.push(birthday.name);
    }

    return days;
}

/** The weeks of the grid: rows of seven boxes, null for the blanks before the 1st and after the last day. */
export function calendarWeeks(
    days: CalendarDay[],
    firstWeekday: number,
): (CalendarDay | null)[][] {
    const boxes: (CalendarDay | null)[] = [
        ...Array.from({ length: calendarBlanks(firstWeekday) }, () => null),
        ...days,
    ];

    while (boxes.length % 7 !== 0) {
        boxes.push(null);
    }

    const weeks: (CalendarDay | null)[][] = [];

    for (let i = 0; i < boxes.length; i += 7) {
        weeks.push(boxes.slice(i, i + 7));
    }

    return weeks;
}

/** The chips a day box shows and how many are left for "+N more". */
export function visibleChips(
    chips: CalendarChip[],
    limit: number = CALENDAR_CHIP_LIMIT,
): { shown: CalendarChip[]; more: number } {
    const max = Math.max(0, limit);

    return {
        shown: chips.slice(0, max),
        more: Math.max(0, chips.length - max),
    };
}

/** A day has something to show. */
export function dayHasEvents(day: CalendarDay): boolean {
    return (
        day.holidays.length > 0 ||
        day.chips.length > 0 ||
        day.birthdays.length > 0
    );
}

/** The phone agenda: only the days that have something, in date order. */
export function calendarAgenda(days: CalendarDay[]): CalendarDay[] {
    return days.filter(dayHasEvents);
}

// ---------------------------------------------------------------------------------------------------------------------
// Applying and My Leaves (Task 5)
// ---------------------------------------------------------------------------------------------------------------------

export type LeaveStatus =
    | 'pending'
    | 'manager_approved'
    | 'approved'
    | 'rejected'
    | 'cancelled';

export type LeaveHalf = 'am' | 'pm';

/** Whole half days, so 0.1 + 0.2 style drift never reaches a label. */
const toHalves = (days: number) => Math.round(days * 2);

/** A day amount with at most one decimal: 14 → "14", 9.5 → "9.5", -1.5 → "-1.5". */
export function formatDayAmount(days: number): string {
    const halves = toHalves(days);

    return halves % 2 === 0 ? String(halves / 2) : (halves / 2).toFixed(1);
}

/** "1 day", "0.5 day", "2.5 days", "0 days". */
export function formatLeaveDays(days: number): string {
    const halves = toHalves(days);
    const unit = halves === 1 || halves === 2 ? 'day' : 'days';

    return `${formatDayAmount(days)} ${unit}`;
}

/** "Left after this: 6.5 days". */
export function leftAfterLabel(available: number, asked: number): string {
    return `Left after this: ${formatLeaveDays((toHalves(available) - toHalves(asked)) / 2)}`;
}

const MONTHS = [
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
] as const;

const WEEKDAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as const;

/** The parts of a "Y-m-d" string, or null when it is not one. */
function dateParts(date: string): { y: number; m: number; d: number } | null {
    const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(date);

    if (!match) {
        return null;
    }

    const [y, m, d] = [Number(match[1]), Number(match[2]), Number(match[3])];

    return m >= 1 && m <= 12 && d >= 1 && d <= 31 ? { y, m, d } : null;
}

/** 0 = Sunday ... 6 = Saturday, by arithmetic (Sakamoto), never through Date. */
export function weekdayOf(date: string): number | null {
    const parts = dateParts(date);

    if (!parts) {
        return null;
    }

    const offsets = [0, 3, 2, 5, 0, 3, 5, 1, 4, 6, 2, 4];
    const y = parts.m < 3 ? parts.y - 1 : parts.y;

    return (
        (y +
            Math.floor(y / 4) -
            Math.floor(y / 100) +
            Math.floor(y / 400) +
            offsets[parts.m - 1] +
            parts.d) %
        7
    );
}

/** "2026-12-01" → "Tue 1 Dec 2026"; anything else is returned as it came. */
export function leaveDateLabel(date: string): string {
    const parts = dateParts(date);
    const weekday = weekdayOf(date);

    if (!parts || weekday === null) {
        return date;
    }

    return `${WEEKDAYS[weekday]} ${parts.d} ${MONTHS[parts.m - 1]} ${parts.y}`;
}

/**
 * The dates of a request: "Tue 1 Dec 2026 – Thu 3 Dec 2026", "Tue 1 Dec 2026" for one full day, or
 * "Tue 1 Dec 2026, first half" for a half day.
 */
export function leaveRangeLabel(
    from: string,
    to: string,
    half: LeaveHalf | null,
): string {
    if (half) {
        return `${leaveDateLabel(from)}, ${half === 'am' ? 'first half' : 'second half'}`;
    }

    return from === to
        ? leaveDateLabel(from)
        : `${leaveDateLabel(from)} – ${leaveDateLabel(to)}`;
}

/** The word for a request's status. */
export function leaveStatusLabel(status: LeaveStatus): string {
    switch (status) {
        case 'pending':
        case 'manager_approved':
            return 'Pending';
        case 'approved':
            return 'Approved';
        case 'rejected':
            return 'Rejected';
        case 'cancelled':
            return 'Cancelled';
    }
}

/** The StatusPill tone of a status: amber open, green approved, red rejected, grey cancelled. */
export function leaveStatusTone(
    status: LeaveStatus,
): 'amber' | 'green' | 'red' | 'grey' {
    switch (status) {
        case 'pending':
        case 'manager_approved':
            return 'amber';
        case 'approved':
            return 'green';
        case 'rejected':
            return 'red';
        case 'cancelled':
            return 'grey';
    }
}

/**
 * Who an open request waits for: "Waiting for Ahmed" (the manager's first name) at the manager step, "Waiting for HR"
 * at the final step, null once decided.
 */
export function leaveStageLabel(
    stage: 'manager' | 'final' | null,
    managerFirstName: string | null,
): string | null {
    if (stage === 'manager') {
        return managerFirstName
            ? `Waiting for ${managerFirstName}`
            : 'Waiting for the manager';
    }

    return stage === 'final' ? 'Waiting for HR' : null;
}
