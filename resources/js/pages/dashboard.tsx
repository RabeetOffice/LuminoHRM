import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';
import type { ReactNode } from 'react';
import { MyAssetsCard } from '@/components/assets/assets-panel';
import type { MyAssets } from '@/components/assets/assets-panel';
import { Avatar } from '@/components/hrm/avatar';
import { PageHeader } from '@/components/hrm/page-header';
import { WaitingReviewsCard } from '@/components/performance/waiting-reviews-card';
import type { WaitingReviews } from '@/components/performance/waiting-reviews-card';
import {
    LeaveStatusCard,
    MyLeaveCard,
    OnLeaveTodayCard,
} from '@/components/dashboard/leave-cards';
import { LatestPayslipCard } from '@/components/dashboard/payroll-cards';
import { AttendanceStatusPill } from '@/components/attendance/status-symbol';
import type { AttendanceStatusValue } from '@/components/attendance/status-symbol';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useCan } from '@/hooks/use-can';
import { formatClockTime } from '@/lib/clock-time';
import {
    TREND_SERIES,
    attendanceBannerLine,
    attendanceDayQualifier,
    barPercent,
    dashboardTiles,
    dayPhrase,
    formatDays,
    formatLongDate,
    greetingFor,
    hasTrendData,
    hourIn,
    leaveBalanceTile,
    payrollTile,
    pendingLeavesTile,
    personalAttendanceTiles,
    probationNote,
    teamAttendanceTiles,
    tileTones,
    trendMax,
    trendSeries,
    weekdayDay,
} from '@/lib/dashboard';
import type {
    AttendanceDay,
    AttendanceTileKey,
    AttendanceToday,
    DashboardTileKey,
    LatestPayslip,
    LeaveByStatus,
    MyLeave,
    MyMonthAttendance,
    OnLeavePerson,
    PayrollThisMonth,
    PendingLeaves,
    TileFigure,
    TrendDay,
    TrendSeriesKey,
} from '@/lib/dashboard';
import { formatMinutes } from '@/lib/minutes';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
import { daily } from '@/routes/attendance';
import { show } from '@/routes/employees';
import {
    attendance as myAttendanceRoute,
    reviews as myReviews,
} from '@/routes/my';

/** Sent only to people who may see employees (Admin, HR: the company; Manager: their team). */
type EmployeeCount = {
    scope: 'company' | 'team';
    total: number;
    probation: number;
};

/** Sent only to Admin and HR. Never a date: only the fact that today is their birthday. */
type Birthday = {
    id: number;
    name: string;
    designation: string | null;
    photo_url: string | null;
};

/** Today's late people in the viewer's scope (attendance.view-all / view-team only). */
type LateToday = {
    total: number;
    people: {
        id: number;
        name: string;
        photo_url: string | null;
        clock_in: string | null;
        late_minutes: number;
    }[];
};

/** The last 30 closed days: the dates (oldest first) and grouped counts. */
type AttendanceTrend = {
    days: string[];
    counts: { date: string; status: string; count: number }[];
};

type Props = {
    employeeCount?: EmployeeCount;
    birthdays?: Birthday[];
    /** The attendance day the "today" figures belong to (sent with any attendance figures). */
    attendanceDay?: AttendanceDay;
    attendanceToday?: AttendanceToday;
    lateToday?: LateToday;
    attendanceTrend?: AttendanceTrend;
    myAttendance?: MyMonthAttendance | null;
    /** Leave approvers only (leave.approve-all: everyone; leave.approve-team: own team). */
    pendingLeaves?: PendingLeaves;
    onLeaveToday?: OnLeavePerson[];
    leaveByStatus?: LeaveByStatus;
    /** The viewer's own balance (everyone with an employee record who does not see payroll). */
    myLeave?: MyLeave;
    /** The account is linked to an employee record. */
    hasEmployee?: boolean;
    /** The pay month's run (payroll.view only). */
    payrollThisMonth?: PayrollThisMonth;
    /** The viewer's own latest payslip of an approved or paid month (absent when there is none). */
    latestPayslip?: LatestPayslip;
    /** The viewer's own assets (everyone with an employee record). */
    myAssets?: MyAssets;
    /** Reviews waiting on the viewer (everyone with an employee record); the card shows only above zero. */
    waitingReviews?: WaitingReviews;
};

const SERIES_META: Record<TrendSeriesKey, { label: string; bar: string }> = {
    present: { label: 'Present', bar: 'bg-emerald-500' },
    late: { label: 'Late', bar: 'bg-amber-400' },
    half_day: { label: 'Half Day', bar: 'bg-orange-400' },
    absent: { label: 'Absent', bar: 'bg-rose-500' },
    on_leave: { label: 'On leave', bar: 'bg-blue-500' },
};

export default function Dashboard({
    employeeCount,
    birthdays,
    attendanceDay,
    attendanceToday,
    lateToday,
    attendanceTrend,
    myAttendance,
    pendingLeaves,
    onLeaveToday,
    leaveByStatus,
    myLeave,
    hasEmployee,
    payrollThisMonth,
    latestPayslip,
    myAssets,
    waitingReviews,
}: Props) {
    const { auth, app } = usePage().props;
    const can = useCan();
    const now = new Date();
    const firstName = auth.user.name.trim().split(/\s+/)[0] ?? '';
    const today = formatLongDate(now, app.timezone);
    const tiles = dashboardTiles(
        can('payroll.view'),
        employeeCount?.scope ?? null,
    );

    // The attendance tiles: the team's or company's day, or a plain Employee's own month.
    let figures: Partial<Record<AttendanceTileKey, TileFigure>> = {};

    if (attendanceToday) {
        figures = teamAttendanceTiles(attendanceToday, attendanceDay);
    } else if (myAttendance) {
        figures = personalAttendanceTiles(myAttendance);
    }

    // The leave tiles: requests waiting for the viewer (or their own pending days) and their own balance.
    const leaveFigures: Partial<Record<DashboardTileKey, TileFigure>> = {};
    const pendingFigure = pendingLeavesTile(pendingLeaves, myLeave);

    if (pendingFigure) {
        leaveFigures['pending-leaves'] = pendingFigure;
    }

    if (myLeave) {
        leaveFigures['leave-balance'] = leaveBalanceTile(myLeave);
    }

    if (payrollThisMonth) {
        leaveFigures.payroll = payrollTile(payrollThisMonth);
    }

    // After midnight and before the day cutoff the figures are still the previous attendance day's: say so.
    const qualifier = attendanceDayQualifier(attendanceDay);

    return (
        <>
            <Head title="Dashboard" />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title="Dashboard"
                    subtitle="A summary of your workforce today."
                />

                <section className="rounded-xl bg-banner px-6 py-7 text-banner-foreground shadow-xs">
                    <h2 className="text-2xl font-semibold tracking-tight">
                        {greetingFor(hourIn(now, app.timezone))}, {firstName}
                    </h2>
                    <p className="mt-1 text-sm text-banner-foreground/70">
                        {today}
                    </p>
                    {attendanceToday && (
                        <p className="mt-3 text-sm font-medium text-banner-foreground/90">
                            {attendanceBannerLine(
                                attendanceToday,
                                attendanceDay,
                            )}
                        </p>
                    )}
                    {qualifier && (
                        <p className="mt-1 text-xs text-banner-foreground/70">
                            {qualifier}
                        </p>
                    )}
                </section>

                <section
                    aria-label="Key figures"
                    className={cn(
                        'grid grid-cols-2 gap-4 md:grid-cols-3',
                        tiles.length === 6
                            ? 'xl:grid-cols-6'
                            : 'xl:grid-cols-5',
                    )}
                >
                    {tiles.map(({ key, label, icon: Icon, color }) => {
                        const figure =
                            key === 'present' ||
                            key === 'absent' ||
                            key === 'late'
                                ? figures[key]
                                : leaveFigures[key];

                        return (
                            <TileFrame
                                key={key}
                                href={figure?.href}
                                className={tileTones[color].tile}
                            >
                                <div
                                    className={cn(
                                        'flex size-10 items-center justify-center rounded-lg',
                                        tileTones[color].icon,
                                    )}
                                >
                                    <Icon
                                        className="size-5"
                                        aria-hidden="true"
                                    />
                                </div>
                                <p className="mt-3 text-sm text-muted-foreground">
                                    {figure?.label ?? label}
                                </p>
                                {key === 'employees' && employeeCount ? (
                                    <>
                                        <p className="mt-1 text-2xl font-semibold tabular-nums">
                                            {employeeCount.total}
                                        </p>
                                        <p className="mt-1 text-xs text-muted-foreground">
                                            {probationNote(
                                                employeeCount.probation,
                                            )}
                                        </p>
                                    </>
                                ) : figure ? (
                                    <>
                                        <p className="mt-1 text-xl font-semibold break-words tabular-nums sm:text-2xl">
                                            {figure.value === '–' ? (
                                                <span
                                                    role="img"
                                                    aria-label={
                                                        figure.valueLabel ??
                                                        'Not known yet'
                                                    }
                                                >
                                                    –
                                                </span>
                                            ) : (
                                                figure.value
                                            )}
                                            {figure.unit && (
                                                <span className="ml-1 text-sm font-medium text-muted-foreground">
                                                    {figure.unit}
                                                </span>
                                            )}
                                        </p>
                                        <p className="mt-1 text-xs text-muted-foreground">
                                            {figure.note}
                                        </p>
                                    </>
                                ) : (
                                    <>
                                        <p className="mt-1 text-2xl font-semibold">
                                            <span
                                                role="img"
                                                aria-label="No data yet"
                                            >
                                                —
                                            </span>
                                        </p>
                                        <p className="mt-1 text-xs text-muted-foreground">
                                            No data yet
                                        </p>
                                    </>
                                )}
                            </TileFrame>
                        );
                    })}
                </section>

                <section className="grid gap-4 lg:grid-cols-2 xl:grid-cols-3">
                    {attendanceTrend ? (
                        <TrendCard trend={attendanceTrend} />
                    ) : myAttendance ? (
                        <MyMonthCard mine={myAttendance} day={attendanceDay} />
                    ) : (
                        <PlaceholderCard
                            title="Attendance"
                            note="Your attendance appears here once your account is linked to an employee record."
                        />
                    )}
                    {lateToday && (
                        <LateCard late={lateToday} day={attendanceDay} />
                    )}
                    {leaveByStatus ? (
                        <LeaveStatusCard byStatus={leaveByStatus} />
                    ) : myLeave ? (
                        <MyLeaveCard mine={myLeave} />
                    ) : hasEmployee === false ? (
                        <PlaceholderCard
                            title="Leave"
                            note="Your leave appears here once your account is linked to an employee record."
                        />
                    ) : null}
                    {onLeaveToday && (
                        <OnLeaveTodayCard
                            people={onLeaveToday}
                            day={attendanceDay}
                        />
                    )}
                    {latestPayslip ? (
                        <LatestPayslipCard payslip={latestPayslip} />
                    ) : hasEmployee ? (
                        <LatestPayslipCard payslip={null} />
                    ) : null}
                    {myAssets && <MyAssetsCard mine={myAssets} />}
                    {waitingReviews && (
                        <WaitingReviewsCard
                            waiting={waitingReviews}
                            myReviewsHref={myReviews.url()}
                        />
                    )}
                    {birthdays && <BirthdaysCard people={birthdays} />}
                </section>
            </div>
        </>
    );
}

/** A key-figure tile; a link (with a hover and focus ring) when the figure leads somewhere. */
function TileFrame({
    href,
    className,
    children,
}: {
    href?: string;
    className: string;
    children: ReactNode;
}) {
    const classes = cn('min-w-0 rounded-xl border p-4 shadow-xs', className);

    if (!href) {
        return <div className={classes}>{children}</div>;
    }

    return (
        <Link
            href={href}
            className={cn(
                classes,
                'block transition-shadow hover:shadow-md focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
            )}
        >
            {children}
        </Link>
    );
}

function CardShell({
    title,
    action,
    children,
    className,
}: {
    title: string;
    action?: ReactNode;
    children: ReactNode;
    className?: string;
}) {
    return (
        <div
            className={cn(
                'flex min-h-64 flex-col rounded-xl border bg-card shadow-xs',
                className,
            )}
        >
            <div className="flex items-center justify-between gap-3 border-b px-5 py-4">
                <h3 className="text-base font-semibold">{title}</h3>
                {action}
            </div>
            {children}
        </div>
    );
}

function EmptyNote({ children }: { children: ReactNode }) {
    return (
        <div className="flex flex-1 items-center justify-center p-6 text-center text-sm text-muted-foreground">
            {children}
        </div>
    );
}

function PlaceholderCard({ title, note }: { title: string; note: string }) {
    return (
        <CardShell title={title}>
            <EmptyNote>{note}</EmptyNote>
        </CardShell>
    );
}

/**
 * Stacked bars per closed day (present, late, half day, absent, on leave), drawn with plain elements: no chart library. The
 * bars are decoration for sighted users; the same numbers are in a table for screen readers.
 */
function TrendCard({ trend }: { trend: AttendanceTrend }) {
    const series = trendSeries(trend.days, trend.counts);
    const max = trendMax(series);
    const first = series[0];
    const last = series[series.length - 1];

    return (
        <CardShell title="Attendance, last 30 days" className="lg:col-span-2">
            {!hasTrendData(series) ? (
                <EmptyNote>
                    Nothing to show yet. Each day appears here once it has
                    closed.
                </EmptyNote>
            ) : (
                <div className="flex flex-1 flex-col gap-3 p-5">
                    <ul
                        aria-label="Chart legend"
                        className="flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground"
                    >
                        {TREND_SERIES.map((key) => (
                            <li key={key} className="flex items-center gap-1.5">
                                <span
                                    aria-hidden="true"
                                    className={cn(
                                        'size-2.5 rounded-sm',
                                        SERIES_META[key].bar,
                                    )}
                                />
                                {SERIES_META[key].label}
                            </li>
                        ))}
                    </ul>
                    <div
                        aria-hidden="true"
                        className="flex h-44 items-end gap-[2px] border-b sm:gap-1"
                    >
                        {series.map((day) => (
                            <TrendBar key={day.date} day={day} max={max} />
                        ))}
                    </div>
                    <div
                        aria-hidden="true"
                        className="flex justify-between text-xs text-muted-foreground"
                    >
                        <span>{first?.label}</span>
                        <span>{last?.label}</span>
                    </div>
                    <table className="sr-only">
                        <caption>Attendance per closed day</caption>
                        <thead>
                            <tr>
                                <th scope="col">Day</th>
                                {TREND_SERIES.map((key) => (
                                    <th key={key} scope="col">
                                        {SERIES_META[key].label}
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody>
                            {series
                                .filter((day) => day.total > 0)
                                .map((day) => (
                                    <tr key={day.date}>
                                        <th scope="row">{day.label}</th>
                                        {TREND_SERIES.map((key) => (
                                            <td key={key}>{day[key]}</td>
                                        ))}
                                    </tr>
                                ))}
                        </tbody>
                    </table>
                </div>
            )}
        </CardShell>
    );
}

function TrendBar({ day, max }: { day: TrendDay; max: number }) {
    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <div className="flex h-full min-w-0 flex-1 flex-col-reverse rounded-t-sm hover:bg-muted/60">
                    {TREND_SERIES.map((key) =>
                        day[key] > 0 ? (
                            <div
                                key={key}
                                className={cn(
                                    SERIES_META[key].bar,
                                    'w-full first:rounded-none last:rounded-t-sm',
                                )}
                                style={{
                                    height: `${barPercent(day[key], max)}%`,
                                }}
                            />
                        ) : null,
                    )}
                </div>
            </TooltipTrigger>
            <TooltipContent side="top" className="grid gap-0.5 text-left">
                <span className="font-semibold">{day.label}</span>
                {day.total === 0 ? (
                    <span>No records</span>
                ) : (
                    TREND_SERIES.filter((key) => day[key] > 0).map((key) => (
                        <span key={key}>
                            {SERIES_META[key].label}: {day[key]}
                        </span>
                    ))
                )}
            </TooltipContent>
        </Tooltip>
    );
}

/** Today's late people in the viewer's scope, earliest clock-in first. */
function LateCard({ late, day }: { late: LateToday; day?: AttendanceDay }) {
    return (
        <CardShell
            title={
                day?.differs
                    ? `Late Arrivals, ${weekdayDay(day.date)}`
                    : 'Late Arrivals Today'
            }
            action={
                <Link
                    href={daily()}
                    className="text-sm font-medium text-brand-ink hover:underline"
                >
                    Daily view
                </Link>
            }
        >
            {late.people.length === 0 ? (
                <EmptyNote>No late arrivals {dayPhrase(day)}.</EmptyNote>
            ) : (
                <>
                    <ul role="list" className="divide-y overflow-y-auto">
                        {late.people.map((person) => (
                            <li
                                key={person.id}
                                className="flex items-center gap-3 px-5 py-3"
                            >
                                <Avatar
                                    src={person.photo_url}
                                    name={person.name}
                                    size="md"
                                />
                                <div className="min-w-0 flex-1">
                                    <Link
                                        href={show(person.id)}
                                        className="block truncate text-sm font-medium hover:underline"
                                    >
                                        {person.name}
                                    </Link>
                                    <div className="text-xs text-muted-foreground">
                                        In at{' '}
                                        {formatClockTime(person.clock_in) ||
                                            '—'}
                                    </div>
                                </div>
                                <span className="rounded-md bg-amber-50 px-2 py-0.5 text-xs font-medium whitespace-nowrap text-amber-700 tabular-nums dark:bg-amber-500/15 dark:text-amber-300">
                                    {formatMinutes(person.late_minutes) || '0m'}{' '}
                                    late
                                </span>
                            </li>
                        ))}
                    </ul>
                    {late.total > late.people.length && (
                        <p className="border-t px-5 py-3 text-xs text-muted-foreground">
                            and {late.total - late.people.length} more on the
                            daily view
                        </p>
                    )}
                </>
            )}
        </CardShell>
    );
}

/** A plain Employee's own month so far, with a link to My Attendance. */
function MyMonthCard({
    mine,
    day,
}: {
    mine: MyMonthAttendance;
    day?: AttendanceDay;
}) {
    const { totals } = mine;
    const items: [string, string][] = [
        ['Present', String(totals.present)],
        ['Late', String(totals.late)],
        ['Half day', String(totals.half_day)],
        ['Absent', String(totals.absent)],
        ['Worked', formatMinutes(totals.worked_minutes) || '0m'],
        ['Late minutes', formatMinutes(totals.late_minutes) || '0m'],
    ];

    return (
        <CardShell
            title={`My attendance, ${mine.label}`}
            action={
                <Link
                    href={myAttendanceRoute()}
                    className="inline-flex items-center gap-1 text-sm font-medium text-brand-ink hover:underline"
                >
                    Open
                    <ArrowRight aria-hidden="true" className="size-3.5" />
                </Link>
            }
        >
            <div className="grid gap-4 p-5">
                <div className="flex flex-wrap items-center gap-2 text-sm">
                    <span className="text-muted-foreground">
                        {day?.differs ? `${weekdayDay(day.date)}:` : 'Today:'}
                    </span>
                    {mine.today ? (
                        <>
                            {/* An unknown or null status shows as Pending (statusMeta). */}
                            <AttendanceStatusPill
                                status={
                                    mine.today.status as AttendanceStatusValue
                                }
                            />
                            {mine.today.clock_in && (
                                <span className="text-muted-foreground">
                                    in at {formatClockTime(mine.today.clock_in)}
                                </span>
                            )}
                        </>
                    ) : (
                        <span>No record yet</span>
                    )}
                </div>
                <dl className="grid grid-cols-2 gap-3 sm:grid-cols-3">
                    {items.map(([label, value]) => (
                        <div key={label} className="rounded-lg border p-3">
                            <dt className="text-xs text-muted-foreground">
                                {label}
                            </dt>
                            <dd className="mt-0.5 text-lg font-semibold tabular-nums">
                                {value}
                            </dd>
                        </div>
                    ))}
                </dl>
                <p className="text-xs text-muted-foreground">
                    Present days counting a half day as half:{' '}
                    {formatDays(totals.equivalent)} of {totals.working_days}{' '}
                    working days.
                </p>
            </div>
        </CardShell>
    );
}

/** Admin and HR only: who has a birthday today (in the company's time zone, decided on the server). */
function BirthdaysCard({ people }: { people: Birthday[] }) {
    return (
        <CardShell title="Today's Birthdays">
            {people.length === 0 ? (
                <EmptyNote>No birthdays today.</EmptyNote>
            ) : (
                <ul role="list" className="divide-y overflow-y-auto">
                    {people.map((person) => (
                        <li
                            key={person.id}
                            className="flex items-center gap-3 px-5 py-3"
                        >
                            <Avatar
                                src={person.photo_url}
                                name={person.name}
                                size="md"
                            />
                            <div className="min-w-0">
                                <Link
                                    href={show(person.id)}
                                    className="block truncate text-sm font-medium hover:underline"
                                >
                                    {person.name}
                                </Link>
                                {person.designation && (
                                    <div className="truncate text-xs text-muted-foreground">
                                        {person.designation}
                                    </div>
                                )}
                            </div>
                        </li>
                    ))}
                </ul>
            )}
        </CardShell>
    );
}

Dashboard.layout = {
    breadcrumbs: [
        {
            title: 'Dashboard',
            href: dashboard(),
        },
    ],
};
