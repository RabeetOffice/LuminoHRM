import { Link } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';
import { StatusPill } from '@/components/hrm/status-pill';
import type { StatusTone } from '@/components/hrm/status-pill';
import { LeaveBalanceList } from '@/components/leave/leave-balance-list';
import { LeaveColourDot } from '@/components/leave/leave-type-badge';
import { MY_LEAVES_HREF, daysLabel, personRequestsHref } from '@/lib/dashboard';
import type { LeaveBalanceLine, LeaveStatusKey } from '@/lib/dashboard';

/** One of the person's latest requests. Never the reason or the attachment. */
export type ProfileLeaveRequest = {
    id: number;
    type: string;
    colour: string;
    from_date: string;
    to_date: string;
    half: 'am' | 'pm' | null;
    /** "Tue 1 Dec 2026 – Thu 3 Dec 2026", built on the server from the text of the dates. */
    dates: string;
    days: number;
    status: LeaveStatusKey;
    partly_cancelled: boolean;
};

/** The profile's Leave tab data (the person, leave.approve-all, leave.approve-team for a direct report). */
export type ProfileLeave = {
    year: number;
    /** The viewer's own record: link to My Leaves instead of Leave → Requests. */
    own: boolean;
    /** What the requests link searches for. */
    code: string;
    lines: LeaveBalanceLine[];
    recent: ProfileLeaveRequest[];
};

const STATUS: Record<LeaveStatusKey, { label: string; tone: StatusTone }> = {
    pending: { label: 'Pending', tone: 'amber' },
    manager_approved: { label: 'Pending', tone: 'amber' },
    approved: { label: 'Approved', tone: 'green' },
    rejected: { label: 'Rejected', tone: 'red' },
    cancelled: { label: 'Cancelled', tone: 'grey' },
};

/** The Leave tab: this year's balance per type and the last five requests, with a link to the full list. */
export function LeavePanel({ leave }: { leave: ProfileLeave }) {
    const href = leave.own ? MY_LEAVES_HREF : personRequestsHref(leave.code);

    return (
        <div className="grid gap-6">
            <section className="grid gap-3">
                <h3 className="text-sm font-semibold">Balance, {leave.year}</h3>
                <LeaveBalanceList lines={leave.lines} />
            </section>

            <section className="grid gap-3">
                <h3 className="text-sm font-semibold">Latest requests</h3>
                {leave.recent.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        No leave requests yet.
                    </p>
                ) : (
                    <ul role="list" className="divide-y rounded-lg border">
                        {leave.recent.map((request) => {
                            const status = STATUS[request.status] ?? {
                                label: request.status,
                                tone: 'grey' as const,
                            };

                            return (
                                <li
                                    key={request.id}
                                    className="flex flex-wrap items-center gap-x-3 gap-y-1 px-3 py-2.5"
                                >
                                    <div className="min-w-0 flex-1">
                                        <div className="flex items-center gap-1.5 text-sm font-medium">
                                            <LeaveColourDot
                                                colour={request.colour}
                                            />
                                            <span className="truncate">
                                                {request.type}
                                            </span>
                                            <span className="font-normal text-muted-foreground tabular-nums">
                                                · {daysLabel(request.days)}
                                            </span>
                                        </div>
                                        <div className="truncate text-xs text-muted-foreground">
                                            {request.dates}
                                            {request.partly_cancelled &&
                                                ' · partly cancelled'}
                                        </div>
                                    </div>
                                    <StatusPill tone={status.tone}>
                                        {status.label}
                                    </StatusPill>
                                </li>
                            );
                        })}
                    </ul>
                )}
            </section>

            <div>
                <Link
                    href={href}
                    className="inline-flex items-center gap-1 text-sm font-medium text-brand-ink hover:underline"
                >
                    {leave.own ? 'Open My Leaves' : 'Open their leave requests'}
                    <ArrowRight aria-hidden="true" className="size-3.5" />
                </Link>
            </div>
        </div>
    );
}
