import { Link } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';
import type { ReactNode } from 'react';
import { Avatar } from '@/components/hrm/avatar';
import { LeaveBalanceList } from '@/components/leave/leave-balance-list';
import { LeaveTypeBadge } from '@/components/leave/leave-type-badge';
import {
    MY_LEAVES_HREF,
    ALL_REQUESTS_HREF,
    dayPhrase,
    halfDayNote,
    leaveStatusBars,
    leaveStatusTotal,
    weekdayDay,
} from '@/lib/dashboard';
import type {
    AttendanceDay,
    LeaveByStatus,
    MyLeave,
    OnLeavePerson,
} from '@/lib/dashboard';
import { cn } from '@/lib/utils';
import { show } from '@/routes/employees';

/** The dashboard card frame (the same look as the page's other cards). */
function LeaveCardShell({
    title,
    action,
    children,
}: {
    title: string;
    action?: ReactNode;
    children: ReactNode;
}) {
    return (
        <div className="flex min-h-64 flex-col rounded-xl border bg-card shadow-xs">
            <div className="flex items-center justify-between gap-3 border-b px-5 py-4">
                <h3 className="text-base font-semibold">{title}</h3>
                {action}
            </div>
            {children}
        </div>
    );
}

function CardLink({ href, children }: { href: string; children: ReactNode }) {
    return (
        <Link
            href={href}
            className="inline-flex items-center gap-1 text-sm font-medium text-brand-ink hover:underline"
        >
            {children}
            <ArrowRight aria-hidden="true" className="size-3.5" />
        </Link>
    );
}

function Empty({ children }: { children: ReactNode }) {
    return (
        <div className="flex flex-1 items-center justify-center p-6 text-center text-sm text-muted-foreground">
            {children}
        </div>
    );
}

/**
 * This month's requests by status, one horizontal bar each, drawn with plain elements (no chart library). The
 * numbers are written next to each bar, so the chart reads the same without the bars.
 */
export function LeaveStatusCard({ byStatus }: { byStatus: LeaveByStatus }) {
    const bars = leaveStatusBars(byStatus);
    const total = leaveStatusTotal(byStatus);

    return (
        <LeaveCardShell
            title="Leave requests this month"
            action={<CardLink href={ALL_REQUESTS_HREF}>Requests</CardLink>}
        >
            {total === 0 ? (
                <Empty>No leave requests in {byStatus.label} yet.</Empty>
            ) : (
                <div className="grid gap-3 p-5">
                    <p className="text-xs text-muted-foreground">
                        {total} {total === 1 ? 'request' : 'requests'} starting
                        in {byStatus.label}
                    </p>
                    <ul role="list" className="grid gap-3">
                        {bars.map((bar) => (
                            <li key={bar.key} className="grid gap-1">
                                <div className="flex items-center justify-between gap-3 text-sm">
                                    <span>{bar.label}</span>
                                    <span className="font-semibold tabular-nums">
                                        {bar.count}
                                    </span>
                                </div>
                                <div
                                    aria-hidden="true"
                                    className="h-2 overflow-hidden rounded-full bg-muted"
                                >
                                    <div
                                        className={cn(
                                            'h-full rounded-full',
                                            bar.bar,
                                        )}
                                        style={{ width: `${bar.percent}%` }}
                                    />
                                </div>
                            </li>
                        ))}
                    </ul>
                </div>
            )}
        </LeaveCardShell>
    );
}

/** People in the viewer's scope on approved leave on the attendance day; half days are marked ½. */
export function OnLeaveTodayCard({
    people,
    day,
}: {
    people: OnLeavePerson[];
    day?: AttendanceDay;
}) {
    return (
        <LeaveCardShell
            title={
                day?.differs
                    ? `On Leave, ${weekdayDay(day.date)}`
                    : 'On Leave Today'
            }
        >
            {people.length === 0 ? (
                <Empty>Nobody is on leave {dayPhrase(day)}.</Empty>
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
                            <div className="min-w-0 flex-1">
                                <Link
                                    href={show(person.id)}
                                    className="block truncate text-sm font-medium hover:underline"
                                >
                                    {person.name}
                                </Link>
                                <div className="text-xs text-muted-foreground">
                                    {halfDayNote(person.half)}
                                </div>
                            </div>
                            <LeaveTypeBadge
                                name={
                                    person.half === 'full'
                                        ? person.type
                                        : `${person.type} ½`
                                }
                                colour={person.colour}
                            />
                        </li>
                    ))}
                </ul>
            )}
        </LeaveCardShell>
    );
}

/** The viewer's own balance for the year, with a link to My Leaves. */
export function MyLeaveCard({ mine }: { mine: MyLeave }) {
    return (
        <LeaveCardShell
            title={`My leave, ${mine.year}`}
            action={<CardLink href={MY_LEAVES_HREF}>My Leaves</CardLink>}
        >
            <div className="p-5">
                <LeaveBalanceList lines={mine.lines} />
            </div>
        </LeaveCardShell>
    );
}
