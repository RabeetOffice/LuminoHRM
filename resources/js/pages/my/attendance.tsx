import { Head, Link } from '@inertiajs/react';
import { CalendarClock, ChevronLeft, ChevronRight } from 'lucide-react';
import { useState } from 'react';
import type { FocusEvent, KeyboardEvent } from 'react';
import { DayCell } from '@/components/attendance/day-cell';
import { AttendanceLegend } from '@/components/attendance/legend';
import { RequestCorrectionDialog } from '@/components/attendance/request-correction-dialog';
import { AttendanceStatusPill } from '@/components/attendance/status-symbol';
import { PageHeader } from '@/components/hrm/page-header';
import { Button } from '@/components/ui/button';
import { formatClockTime } from '@/lib/clock-time';
import { formatMinutes } from '@/lib/minutes';
import {
    FLAG_MANUAL,
    cellStatus,
    formatCount,
    hasFlag,
    inWindow,
    shortDate,
} from '@/lib/monthly-grid';
import type { GridCell, GridDay, GridTotals } from '@/lib/monthly-grid';
import {
    WEEKDAYS,
    dayList,
    initialCalendarFocus,
    leadingBlanks,
    moveCalendarFocus,
} from '@/lib/my-attendance';
import { cn } from '@/lib/utils';
import { attendance } from '@/routes/my';

type Props = {
    /** The viewer's own record; null when the account is not linked to an employee. */
    employee: { name: string; code: string } | null;
    month: {
        value: string;
        label: string;
        previous: string | null;
        next: string | null;
        current: string;
    };
    days: GridDay[];
    cells: GridCell[];
    /** First and last day number inside the employment window this month (null: none). */
    window: [number, number] | null;
    totals: GridTotals;
    /** The current attendance day ("Y-m-d"). */
    today: string;
    /** Dates on which "Request correction" is offered. */
    correctable: string[];
};

/** The day the correction request is for, with its recorded times ("H:i"). */
type Correcting = {
    date: string;
    current: { clock_in: string | null; clock_out: string | null };
};

/** The signed-in person's own attendance for one month: calendar, summary and the days with their times. */
export default function MyAttendance({
    employee,
    month,
    days,
    cells,
    window,
    totals,
    today,
    correctable,
}: Props) {
    const [correcting, setCorrecting] = useState<Correcting | null>(null);
    const [open, setOpen] = useState(false);
    const request = (value: Correcting) => {
        setCorrecting(value);
        setOpen(true);
    };

    return (
        <>
            <Head title="My Attendance" />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title="My Attendance"
                    subtitle={
                        employee
                            ? 'Your days, times and totals as the attendance machine recorded them.'
                            : undefined
                    }
                />

                {employee === null ? (
                    <NotLinked />
                ) : (
                    <>
                        <MonthNav month={month} />
                        <Summary totals={totals} />

                        <section className="rounded-xl border bg-card p-4 shadow-xs sm:p-6">
                            <h2 className="mb-4 text-base font-semibold">
                                {month.label}
                            </h2>
                            <Calendar
                                key={month.value}
                                days={days}
                                cells={cells}
                                window={window}
                                today={today}
                            />
                            <AttendanceLegend className="mt-5 border-t pt-4" />
                        </section>

                        <DayList
                            days={days}
                            cells={cells}
                            correctable={correctable}
                            onRequest={request}
                        />
                    </>
                )}
            </div>

            {/* Mounted once; the last chosen day stays while it closes. */}
            {correcting !== null && (
                <RequestCorrectionDialog
                    open={open}
                    onClose={() => setOpen(false)}
                    date={correcting.date}
                    current={correcting.current}
                />
            )}
        </>
    );
}

function NotLinked() {
    return (
        <section className="flex flex-col items-center rounded-xl border bg-card px-6 py-16 text-center shadow-xs">
            <div className="flex size-12 items-center justify-center rounded-full bg-muted text-muted-foreground">
                <CalendarClock aria-hidden="true" className="size-6" />
            </div>
            <p className="mt-4 text-base font-medium">
                Your account is not linked to an employee record yet.
            </p>
            <p className="mt-1 text-sm text-muted-foreground">
                Your attendance appears here once HR links it.
            </p>
        </section>
    );
}

function MonthNav({ month }: { month: Props['month'] }) {
    return (
        <nav aria-label="Month" className="flex flex-wrap items-center gap-2">
            {month.previous ? (
                <Button variant="outline" size="icon" asChild>
                    <Link
                        href={attendance({ query: { month: month.previous } })}
                        aria-label="Previous month"
                        preserveScroll
                    >
                        <ChevronLeft />
                    </Link>
                </Button>
            ) : (
                <Button
                    variant="outline"
                    size="icon"
                    disabled
                    aria-label="Previous month"
                >
                    <ChevronLeft />
                </Button>
            )}
            <span className="min-w-36 text-center text-sm font-semibold">
                {month.label}
            </span>
            {month.next ? (
                <Button variant="outline" size="icon" asChild>
                    <Link
                        href={attendance({ query: { month: month.next } })}
                        aria-label="Next month"
                        preserveScroll
                    >
                        <ChevronRight />
                    </Link>
                </Button>
            ) : (
                <Button
                    variant="outline"
                    size="icon"
                    disabled
                    aria-label="Next month"
                >
                    <ChevronRight />
                </Button>
            )}
            {month.value !== month.current && (
                <Button variant="ghost" asChild>
                    <Link href={attendance()}>This month</Link>
                </Button>
            )}
        </nav>
    );
}

/** Totals of decided days only (pending never counts; half day counts 0.5 in the present figure). */
function Summary({ totals }: { totals: GridTotals }) {
    const items: { label: string; value: string; tone?: string }[] = [
        {
            label: 'Present',
            value: String(totals.present),
            tone: 'text-emerald-600 dark:text-emerald-400',
        },
        {
            label: 'Late',
            value: String(totals.late),
            tone: 'text-amber-600 dark:text-amber-400',
        },
        {
            label: 'Half day',
            value: String(totals.half_day),
            tone: 'text-orange-600 dark:text-orange-400',
        },
        {
            label: 'Absent',
            value: String(totals.absent),
            tone: 'text-red-600 dark:text-red-400',
        },
        {
            label: 'Worked',
            value: formatMinutes(totals.worked_minutes) || '0h',
        },
        {
            label: 'Late minutes',
            value: formatMinutes(totals.late_minutes) || '0m',
        },
    ];

    return (
        <section aria-label="Summary" className="grid gap-3">
            <dl className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                {items.map((item) => (
                    <div
                        key={item.label}
                        className="rounded-xl border bg-card p-4 shadow-xs"
                    >
                        <dt className="text-xs text-muted-foreground">
                            {item.label}
                        </dt>
                        <dd
                            className={cn(
                                'mt-1 text-2xl font-semibold tabular-nums',
                                item.tone,
                            )}
                        >
                            {item.value}
                        </dd>
                    </div>
                ))}
            </dl>
            <p className="text-sm text-muted-foreground">
                <span className="font-medium text-foreground tabular-nums">
                    {formatCount(totals.equivalent)} / {totals.working_days}
                </span>{' '}
                working days present (a half day counts as half).
            </p>
        </section>
    );
}

/**
 * A Monday-first month calendar; each day uses the shared grid cell (symbol, markers, times on hover/focus). The
 * calendar is ONE tab stop (roving tabindex, like the monthly grid): Tab lands on today (or the first day), the
 * arrow keys move by a day or a week, Home / End to the ends of the week, Ctrl+Home / Ctrl+End to the ends of the
 * month. Remounted per month (key), so the stop starts on today again.
 */
function Calendar({
    days,
    cells,
    window,
    today,
}: {
    days: GridDay[];
    cells: GridCell[];
    window: [number, number] | null;
    today: string;
}) {
    const blanks = leadingBlanks(days[0]?.weekday);
    const [active, setActive] = useState(() =>
        initialCalendarFocus(days, today),
    );

    const indexOf = (target: EventTarget): number | null => {
        const col = (target as HTMLElement).dataset?.col;

        return col === undefined ? null : Number(col);
    };

    const onFocus = (event: FocusEvent<HTMLDivElement>) => {
        const index = indexOf(event.target);

        if (index !== null) {
            setActive(index);
        }
    };

    const onKeyDown = (event: KeyboardEvent<HTMLDivElement>) => {
        const index = indexOf(event.target);

        if (index === null) {
            return;
        }

        const next = moveCalendarFocus(
            index,
            event.key,
            event.ctrlKey || event.metaKey,
            days.length,
            blanks,
        );

        if (next === null) {
            return;
        }

        event.preventDefault();
        setActive(next);
        event.currentTarget
            .querySelector<HTMLElement>(`[data-col="${next}"]`)
            ?.focus();
    };

    return (
        <div
            role="group"
            aria-label="Calendar. Use the arrow keys to move between days."
            className="grid grid-cols-7 gap-1 sm:gap-2"
            onFocus={onFocus}
            onKeyDown={onKeyDown}
        >
            {WEEKDAYS.map((weekday) => (
                <div
                    key={weekday}
                    aria-hidden="true"
                    className="pb-1 text-center text-xs font-medium text-muted-foreground"
                >
                    {weekday}
                </div>
            ))}
            {Array.from({ length: blanks }, (_, i) => (
                <div key={`blank-${i}`} aria-hidden="true" />
            ))}
            {days.map((day, index) => (
                <div
                    key={day.date}
                    className={cn(
                        'flex min-h-16 flex-col items-center gap-1 rounded-lg border p-1 sm:min-h-20 sm:p-1.5',
                        day.holiday && 'bg-violet-50/60 dark:bg-violet-500/5',
                        day.date === today && 'border-primary',
                        day.future && 'opacity-60',
                    )}
                >
                    <span
                        className={cn(
                            'self-start text-xs text-muted-foreground tabular-nums',
                            day.date === today &&
                                'font-semibold text-foreground',
                        )}
                    >
                        {day.day}
                    </span>
                    <DayCell
                        dayIndex={index}
                        day={day}
                        cell={cells[index] ?? null}
                        inWindow={inWindow(window, day.day)}
                        tabbable={index === active}
                    />
                    {day.holiday && (
                        <span className="hidden w-full truncate text-center text-[10px] text-muted-foreground sm:block">
                            {day.holiday}
                        </span>
                    )}
                </div>
            ))}
        </div>
    );
}

/** The days with a record, newest first, with their times. */
function DayList({
    days,
    cells,
    correctable,
    onRequest,
}: {
    days: GridDay[];
    cells: GridCell[];
    correctable: string[];
    onRequest: (value: Correcting) => void;
}) {
    const items = dayList(days, cells, correctable);

    return (
        <section className="rounded-xl border bg-card shadow-xs">
            <h2 className="border-b px-4 py-4 text-base font-semibold sm:px-6">
                Days
            </h2>
            {items.length === 0 ? (
                <p className="px-4 py-12 text-center text-sm text-muted-foreground sm:px-6">
                    No attendance recorded this month yet.
                </p>
            ) : (
                <ul role="list" className="divide-y">
                    {items.map(({ day, cell, correctable: canRequest }) => {
                        const [, , clockIn, clockOut, worked, late] = cell;

                        return (
                            <li
                                key={day.date}
                                className="flex flex-wrap items-center gap-x-4 gap-y-2 px-4 py-3 sm:px-6"
                            >
                                <div className="w-20 shrink-0">
                                    <div className="text-sm font-medium">
                                        {shortDate(day.date)}
                                    </div>
                                    <div className="text-xs text-muted-foreground">
                                        {day.weekday}
                                    </div>
                                </div>
                                <AttendanceStatusPill
                                    status={cellStatus(cell)}
                                />
                                {hasFlag(cell, FLAG_MANUAL) && (
                                    <span className="text-xs text-muted-foreground">
                                        Corrected by HR
                                    </span>
                                )}
                                <div className="flex flex-1 flex-wrap gap-x-4 gap-y-1 text-sm text-muted-foreground tabular-nums">
                                    <span>
                                        In{' '}
                                        <span className="text-foreground">
                                            {formatClockTime(clockIn) || '—'}
                                        </span>
                                    </span>
                                    <span>
                                        Out{' '}
                                        <span className="text-foreground">
                                            {formatClockTime(clockOut) || '—'}
                                        </span>
                                    </span>
                                    {worked > 0 && (
                                        <span>
                                            Worked{' '}
                                            <span className="text-foreground">
                                                {formatMinutes(worked)}
                                            </span>
                                        </span>
                                    )}
                                    {late > 0 && (
                                        <span>
                                            Late{' '}
                                            <span className="text-amber-600 dark:text-amber-400">
                                                {formatMinutes(late)}
                                            </span>
                                        </span>
                                    )}
                                </div>
                                {canRequest && (
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        onClick={() =>
                                            onRequest({
                                                date: day.date,
                                                current: {
                                                    clock_in: clockIn,
                                                    clock_out: clockOut,
                                                },
                                            })
                                        }
                                    >
                                        Request correction
                                    </Button>
                                )}
                            </li>
                        );
                    })}
                </ul>
            )}
        </section>
    );
}

MyAttendance.layout = {
    breadcrumbs: [{ title: 'My Attendance', href: attendance() }],
};
