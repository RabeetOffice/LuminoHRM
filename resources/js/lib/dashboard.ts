import {
    CalendarClock,
    Clock,
    UserCheck,
    UserX,
    Users,
    Wallet,
    WalletCards,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { formatClockTime } from '@/lib/clock-time';

export type TileColor = 'green' | 'blue' | 'rose' | 'amber' | 'violet' | 'teal';

// Soft tinted tile: background and border from its own colour, icon square a
// little stronger. Dark mode uses a low-opacity tint over the dark surface.
// Shared so later pages can reuse the same palette.
export const tileTones: Record<TileColor, { tile: string; icon: string }> = {
    green: {
        tile: 'border-emerald-200 bg-emerald-50 dark:border-emerald-500/20 dark:bg-emerald-500/10',
        icon: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-300',
    },
    blue: {
        tile: 'border-blue-200 bg-blue-50 dark:border-blue-500/20 dark:bg-blue-500/10',
        icon: 'bg-blue-100 text-blue-700 dark:bg-blue-500/20 dark:text-blue-300',
    },
    rose: {
        tile: 'border-rose-200 bg-rose-50 dark:border-rose-500/20 dark:bg-rose-500/10',
        icon: 'bg-rose-100 text-rose-700 dark:bg-rose-500/20 dark:text-rose-300',
    },
    amber: {
        tile: 'border-amber-200 bg-amber-50 dark:border-amber-500/20 dark:bg-amber-500/10',
        icon: 'bg-amber-100 text-amber-700 dark:bg-amber-500/20 dark:text-amber-300',
    },
    violet: {
        tile: 'border-violet-200 bg-violet-50 dark:border-violet-500/20 dark:bg-violet-500/10',
        icon: 'bg-violet-100 text-violet-700 dark:bg-violet-500/20 dark:text-violet-300',
    },
    teal: {
        tile: 'border-teal-200 bg-teal-50 dark:border-teal-500/20 dark:bg-teal-500/10',
        icon: 'bg-teal-100 text-teal-700 dark:bg-teal-500/20 dark:text-teal-300',
    },
};

export type DashboardTileKey =
    | 'employees'
    | 'present'
    | 'absent'
    | 'late'
    | 'pending-leaves'
    | 'payroll'
    | 'leave-balance';

export type DashboardTile = {
    key: DashboardTileKey;
    label: string;
    icon: LucideIcon;
    color: TileColor;
};

/** Whose employees the first tile counts: the company (Admin, HR), the viewer's team (Manager), or no tile (null). */
export type EmployeeTileScope = 'company' | 'team' | null;

/**
 * Spec 9.1 tiles. The first counts employees and exists only when the server sent a count (`employeeCount.scope`):
 * "Total Employees" for the company, "Team Members" for a Manager, and nothing at all for a plain Employee (five
 * tiles). The last depends on whether the user may see payroll.
 */
export function dashboardTiles(
    canViewPayroll: boolean,
    employeeScope: EmployeeTileScope,
): DashboardTile[] {
    const employees: DashboardTile[] =
        employeeScope === null
            ? []
            : [
                  {
                      key: 'employees',
                      label:
                          employeeScope === 'team'
                              ? 'Team Members'
                              : 'Total Employees',
                      icon: Users,
                      color: 'green',
                  },
              ];

    return [
        ...employees,
        {
            key: 'present',
            label: 'Present Today',
            icon: UserCheck,
            color: 'blue',
        },
        { key: 'absent', label: 'Absent Today', icon: UserX, color: 'rose' },
        { key: 'late', label: 'Late Today', icon: Clock, color: 'amber' },
        {
            key: 'pending-leaves',
            label: 'Pending Leaves',
            icon: CalendarClock,
            color: 'violet',
        },
        canViewPayroll
            ? {
                  key: 'payroll',
                  label: 'Payroll This Month',
                  icon: Wallet,
                  color: 'teal',
              }
            : {
                  key: 'leave-balance',
                  label: 'Leave Balance',
                  icon: WalletCards,
                  color: 'teal',
              },
    ];
}

/** The employee tile's footnote. */
export function probationNote(count: number): string {
    return count === 0 ? 'No one on probation' : `${count} on probation`;
}

/** Morning 05:00-11:59, afternoon 12:00-16:59, evening otherwise. */
export function greetingFor(
    hour: number,
): 'Good morning' | 'Good afternoon' | 'Good evening' {
    if (hour >= 5 && hour < 12) {
        return 'Good morning';
    }

    return hour >= 12 && hour < 17 ? 'Good afternoon' : 'Good evening';
}

/**
 * An unusable timezone (empty, missing or not an IANA name) must not blank the dashboard:
 * return undefined so Intl uses the browser zone.
 */
function usableTimeZone(
    timeZone: string | null | undefined,
): string | undefined {
    if (!timeZone) {
        return undefined;
    }

    try {
        new Intl.DateTimeFormat('en-GB', { timeZone });

        return timeZone;
    } catch {
        return undefined;
    }
}

/** Hour of day (0-23) of an instant in the given IANA timezone. */
export function hourIn(date: Date, timeZone: string): number {
    const hour = new Intl.DateTimeFormat('en-GB', {
        hour: 'numeric',
        hourCycle: 'h23',
        timeZone: usableTimeZone(timeZone),
    }).format(date);

    return Number(hour);
}

/** e.g. "Monday, 23 November 2026", in the given timezone. */
export function formatLongDate(date: Date, timeZone: string): string {
    return new Intl.DateTimeFormat('en-GB', {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
        year: 'numeric',
        timeZone: usableTimeZone(timeZone),
    })
        .format(date)
        .replace(/^(\w+) /, '$1, ');
}

// ---------------------------------------------------------------- attendance

/** Today's figures as DashboardController sends them (attendance.view-all / view-team, in the viewer's scope). */
export type AttendanceToday = {
    /** The attendance day ("Y-m-d"), never passed through Date. */
    date: string;
    /** The day is still open (until the next day's cutoff): nobody is absent from the punches yet. */
    open: boolean;
    /** attendance.start_date is set and not after today. */
    started: boolean;
    start_date: string | null;
    /** Clocked in today (present or late). */
    present: number;
    late: number;
    /** Marked absent today (only a manual correction can do that on an open day). */
    absent: number;
    /** Punches waiting to be calculated: never counted as present. */
    pending: number;
    /** On a full day of approved leave today (a half-day leave day counts by its worked half). */
    on_leave: number;
    /** Expected today with no punch yet; null before attendance has started. */
    not_in: number | null;
    expected: number | null;
    holiday: boolean;
    /** The latest stored punch: calendar date and "H:i" (machine wall clock). */
    last_punch: { date: string; time: string } | null;
};

/** The MonthGrid totals block (present-equivalent = present + late + half day / 2). */
export type AttendanceTotals = {
    present: number;
    late: number;
    half_day: number;
    absent: number;
    on_leave: number;
    off: number;
    worked_minutes: number;
    late_minutes: number;
    overtime_minutes: number;
    equivalent: number;
    working_days: number;
};

/** A plain Employee's own month (instead of the company tiles). */
export type MyMonthAttendance = {
    month: string;
    label: string;
    totals: AttendanceTotals;
    today: {
        status: string | null;
        clock_in: string | null;
        clock_out: string | null;
    } | null;
};

export const TREND_SERIES = [
    'present',
    'late',
    'half_day',
    'absent',
    'on_leave',
] as const;

export type TrendSeriesKey = (typeof TREND_SERIES)[number];

export type TrendDay = Record<TrendSeriesKey, number> & {
    date: string;
    /** "24 Nov". */
    label: string;
    total: number;
};

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
];

/** "24 Nov" from "2026-11-24", read as text (never through Date, so it cannot move with a time zone). */
export function shortDay(date: string): string {
    const match = /^\d{4}-(\d{2})-(\d{2})$/.exec(date);
    const month = match ? MONTHS[Number(match[1]) - 1] : undefined;

    if (!match || !month) {
        return date;
    }

    return `${Number(match[2])} ${month}`;
}

/**
 * The 30-day chart's bars: one per day the server listed (oldest first), each with the count of every series from
 * the server's grouped rows (missing = 0). Rows for days not listed and statuses not charted are ignored.
 */
export function trendSeries(
    days: string[],
    counts: { date: string; status: string; count: number }[],
): TrendDay[] {
    const byDay = new Map<string, TrendDay>();

    for (const date of days) {
        byDay.set(date, {
            date,
            label: shortDay(date),
            present: 0,
            late: 0,
            half_day: 0,
            absent: 0,
            on_leave: 0,
            total: 0,
        });
    }

    for (const row of counts) {
        const day = byDay.get(row.date);

        if (
            day &&
            (TREND_SERIES as readonly string[]).includes(row.status) &&
            row.count > 0
        ) {
            day[row.status as TrendSeriesKey] += row.count;
            day.total += row.count;
        }
    }

    return [...byDay.values()];
}

/** The tallest bar (at least 1, so empty days never divide by zero). */
export function trendMax(series: TrendDay[]): number {
    return Math.max(1, ...series.map((day) => day.total));
}

/** Whether any day has something to draw. */
export function hasTrendData(series: TrendDay[]): boolean {
    return series.some((day) => day.total > 0);
}

/** A bar segment's height as a percentage of the chart (one decimal). */
export function barPercent(value: number, max: number): number {
    return max <= 0 ? 0 : Math.round((value / max) * 1000) / 10;
}

/** "3.5" or "4": half days show one decimal, whole numbers none. */
export function formatDays(value: number): string {
    return Number.isInteger(value) ? String(value) : value.toFixed(1);
}

/**
 * Which attendance day the "today" figures belong to, as the server sends it. After midnight and before the day
 * cutoff the figures are still the previous attendance day's: `differs` is true and the page says so.
 */
export type AttendanceDay = {
    /** The attendance day ("Y-m-d"). */
    date: string;
    /** The calendar date in the company's zone ("Y-m-d"). */
    calendar_date: string;
    differs: boolean;
    /** The day cutoff, "H:i". */
    cutoff: string;
};

const WEEKDAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

/**
 * "Wed 25 Nov" from "2026-11-25", read as text. The weekday is worked out from the numbers (Sakamoto's method), so
 * the date never passes through Date and cannot move with a time zone.
 */
export function weekdayDay(date: string): string {
    const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(date);

    if (!match) {
        return date;
    }

    const month = Number(match[2]);
    const day = Number(match[3]);
    const offsets = [0, 3, 2, 5, 0, 3, 5, 1, 4, 6, 2, 4];
    const offset = offsets[month - 1];

    if (offset === undefined || day < 1 || day > 31) {
        return date;
    }

    const year = Number(match[1]) - (month < 3 ? 1 : 0);
    const weekday =
        (year +
            Math.floor(year / 4) -
            Math.floor(year / 100) +
            Math.floor(year / 400) +
            offset +
            day) %
        7;

    return `${WEEKDAYS[weekday]} ${shortDay(date)}`;
}

/**
 * "today", or "on Wed 25 Nov" when the figures belong to an attendance day that is not the calendar date (after
 * midnight, before the cutoff). Without the server's day it is "today".
 */
export function dayPhrase(day?: AttendanceDay | null): string {
    return day?.differs ? `on ${weekdayDay(day.date)}` : 'today';
}

/**
 * The qualifier shown with the figures when the attendance day is not the calendar date:
 * "Figures for Wed 25 Nov — the day closes at 4:00 AM". Null when it is the calendar date.
 */
export function attendanceDayQualifier(
    day?: AttendanceDay | null,
): string | null {
    if (!day?.differs) {
        return null;
    }

    const closes = formatClockTime(day.cutoff);

    return closes
        ? `Figures for ${weekdayDay(day.date)} — the day closes at ${closes}`
        : `Figures for ${weekdayDay(day.date)}`;
}

/**
 * The banner line: "4 present today · last punch received 9:05 AM". The punch part is left out when there are no
 * punches, and names the day when the latest punch is not from the attendance day ("... received 22 Nov, 6:01 PM").
 * When the attendance day is not the calendar date, "today" becomes the day ("4 present on Wed 25 Nov").
 */
export function attendanceBannerLine(
    today: AttendanceToday,
    day?: AttendanceDay | null,
): string {
    const parts = [`${today.present} present ${dayPhrase(day)}`];
    const punch = today.last_punch;

    if (punch) {
        const time = formatClockTime(punch.time) || punch.time;
        parts.push(
            punch.date === today.date
                ? `last punch received ${time}`
                : `last punch received ${shortDay(punch.date)}, ${time}`,
        );
    }

    return parts.join(' · ');
}

export type TileFigure = {
    /** Replaces the tile's own label when set. */
    label?: string;
    /** The big number, or "–" when it cannot be known yet. */
    value: string;
    note: string;
};

export type AttendanceTileKey = 'present' | 'absent' | 'late';

/**
 * The three attendance tiles for people who see a team or the company. The dashboard shows the open day, so the
 * second tile is "Not in yet" (expected today, no punch yet); it reads "Absent Today" only for a closed day. Before
 * attendance has started nobody can be said to be missing: "–" with a short hint. When the attendance day is not the
 * calendar date (after midnight, before the cutoff) "today" is replaced by that day in labels and notes.
 */
export function teamAttendanceTiles(
    today: AttendanceToday,
    day?: AttendanceDay | null,
): Record<AttendanceTileKey, TileFigure> {
    const when = dayPhrase(day);
    const differs = day?.differs === true;
    const pending =
        today.pending > 0 ? ` · ${today.pending} being calculated` : '';

    let absent: TileFigure;

    if (!today.started || today.not_in === null) {
        absent = {
            label: 'Not in yet',
            value: '–',
            note: today.start_date
                ? `Attendance starts ${shortDay(today.start_date)}`
                : 'Attendance has not started',
        };
    } else if (today.open) {
        const expected = today.expected ?? 0;
        let note =
            expected === 0
                ? `Nobody expected ${when}`
                : `of ${expected} expected ${when}`;

        if (today.holiday) {
            note = `Holiday ${when}`;
        } else if (today.absent > 0) {
            note = `${today.absent} marked absent`;
        }

        absent = { label: 'Not in yet', value: String(today.not_in), note };
    } else {
        absent = {
            label: differs ? 'Absent' : 'Absent Today',
            value: String(today.not_in + today.absent),
            note: `of ${today.expected ?? 0} expected`,
        };
    }

    const present: TileFigure = {
        value: String(today.present),
        note: `Clocked in ${when}${pending}`,
    };
    const late: TileFigure = {
        value: String(today.late),
        note: today.late === 0 ? `Nobody late ${when}` : 'After the grace time',
    };

    if (differs) {
        present.label = 'Present';
        late.label = 'Late';
    }

    return { present, absent, late };
}

/** "1h 5m" / "45m". */
function shortDuration(minutes: number): string {
    const h = Math.floor(minutes / 60);
    const m = minutes % 60;

    if (h === 0) {
        return `${m}m`;
    }

    return m === 0 ? `${h}h` : `${h}h ${m}m`;
}

/** A plain Employee's three tiles: their own month so far (pending days never counted; half day = 0.5). */
export function personalAttendanceTiles(
    mine: MyMonthAttendance,
): Record<AttendanceTileKey, TileFigure> {
    const { totals } = mine;

    return {
        present: {
            label: 'Present This Month',
            value: formatDays(totals.equivalent),
            note:
                totals.working_days === 0
                    ? 'No working days yet'
                    : `of ${totals.working_days} working days`,
        },
        absent: {
            label: 'Absent This Month',
            value: String(totals.absent),
            note: mine.label,
        },
        late: {
            label: 'Late This Month',
            value: String(totals.late),
            note:
                totals.late_minutes > 0
                    ? `${shortDuration(totals.late_minutes)} late in total`
                    : 'Never late',
        },
    };
}
