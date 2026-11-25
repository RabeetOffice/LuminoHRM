import { Head, Link } from '@inertiajs/react';
import { Cake, ChevronLeft, ChevronRight, Star } from 'lucide-react';
import { MonthCalendar } from '@/components/leave/month-calendar';
import { PageHeader } from '@/components/hrm/page-header';
import { Button } from '@/components/ui/button';
import { calendarAgenda, leaveDotClass, shapeCalendar } from '@/lib/leave';
import type { CalendarLeaveEntry, CalendarPendingEntry } from '@/lib/leave';
import { cn } from '@/lib/utils';
import { calendar } from '@/routes';

type Props = {
    /** "YYYY-MM". */
    month: string;
    /** "December 2026". */
    label: string;
    /** 0 = Monday ... 6 = Sunday: the weekday of the 1st. */
    first_weekday: number;
    days_in_month: number;
    /** The current attendance day, "Y-m-d". */
    today: string;
    previous: string | null;
    next: string | null;
    current: string;
    /** Whose approved leave this viewer sees. */
    leave_scope: 'company' | 'team' | 'own';
    holidays: { date: string; name: string }[];
    leave: CalendarLeaveEntry[];
    pending_own: CalendarPendingEntry[];
    birthdays: { day: number; name: string }[];
    types: { name: string; colour: string }[];
    /** The default shift's weekly offs, Monday-first indexes (shaded). */
    weekly_offs: number[];
};

const SCOPE_TEXT: Record<Props['leave_scope'], string> = {
    company: 'Holidays, everyone’s approved leave and birthdays.',
    team: 'Holidays, approved leave of you and your team, and birthdays.',
    own: 'Holidays, your own leave and birthdays.',
};

/** The company calendar: holidays, approved leave (as far as the viewer may see it) and birthdays, one month at a time. */
export default function CalendarPage(props: Props) {
    // A month is at most 31 days and a few dozen entries: shaped on each render.
    const days = shapeCalendar(props);
    const empty = calendarAgenda(days).length === 0;

    return (
        <>
            <Head title="Calendar" />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title="Calendar"
                    subtitle={SCOPE_TEXT[props.leave_scope]}
                />

                <MonthNav
                    month={props.month}
                    label={props.label}
                    previous={props.previous}
                    next={props.next}
                    current={props.current}
                />

                <section
                    aria-label={props.label}
                    className="rounded-xl border bg-card p-4 shadow-xs sm:p-6"
                >
                    <h2 className="mb-4 text-base font-semibold">
                        {props.label}
                    </h2>
                    <MonthCalendar
                        days={days}
                        firstWeekday={props.first_weekday}
                        label={props.label}
                    />
                    {empty && (
                        <p className="mt-4 hidden text-center text-sm text-muted-foreground sm:block">
                            Nothing on this month: no holidays, leave or
                            birthdays.
                        </p>
                    )}
                    <Legend
                        types={props.types}
                        hasPending={props.pending_own.length > 0}
                    />
                </section>
            </div>
        </>
    );
}

function MonthNav({
    month,
    label,
    previous,
    next,
    current,
}: Pick<Props, 'month' | 'label' | 'previous' | 'next' | 'current'>) {
    return (
        <nav aria-label="Month" className="flex flex-wrap items-center gap-2">
            {previous ? (
                <Button variant="outline" size="icon" asChild>
                    <Link
                        href={calendar({ query: { month: previous } })}
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
            <span
                aria-live="polite"
                className="min-w-36 text-center text-sm font-semibold"
            >
                {label}
            </span>
            {next ? (
                <Button variant="outline" size="icon" asChild>
                    <Link
                        href={calendar({ query: { month: next } })}
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
            {month !== current && (
                <Button variant="ghost" asChild>
                    <Link href={calendar()}>This month</Link>
                </Button>
            )}
        </nav>
    );
}

function Legend({
    types,
    hasPending,
}: {
    types: Props['types'];
    hasPending: boolean;
}) {
    return (
        <ul
            role="list"
            aria-label="Legend"
            className="mt-5 flex flex-wrap gap-x-4 gap-y-2 border-t pt-4 text-xs text-muted-foreground"
        >
            {types.map((type) => (
                <li key={type.name} className="flex items-center gap-1.5">
                    <span
                        aria-hidden="true"
                        className={cn(
                            'size-2.5 rounded-full',
                            leaveDotClass(type.colour),
                        )}
                    />
                    {type.name}
                </li>
            ))}
            <li className="flex items-center gap-1.5">
                <span aria-hidden="true" className="font-medium">
                    ½
                </span>
                Half day
            </li>
            {hasPending && (
                <li className="flex items-center gap-1.5">
                    <span
                        aria-hidden="true"
                        className="h-3 w-5 rounded-sm border border-dashed border-muted-foreground/60"
                    />
                    Your pending request
                </li>
            )}
            <li className="flex items-center gap-1.5">
                <Star
                    aria-hidden="true"
                    className="size-3.5 text-violet-600 dark:text-violet-400"
                />
                Holiday
            </li>
            <li className="flex items-center gap-1.5">
                <Cake
                    aria-hidden="true"
                    className="size-3.5 text-pink-600 dark:text-pink-400"
                />
                Birthday
            </li>
        </ul>
    );
}

CalendarPage.layout = {
    breadcrumbs: [{ title: 'Calendar', href: calendar() }],
};
