import { Head, Link, router, usePage } from '@inertiajs/react';
import { ArrowLeft, Check, Paperclip, TriangleAlert, X } from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import LeaveApplicationController from '@/actions/App/Http/Controllers/Leave/LeaveApplicationController';
import { Avatar } from '@/components/hrm/avatar';
import { ConfirmDialog } from '@/components/hrm/confirm-dialog';
import { PageHeader } from '@/components/hrm/page-header';
import { StatusPill } from '@/components/hrm/status-pill';
import { CancelApprovedDialog } from '@/components/leave/cancel-approved-dialog';
import { DecisionDialog } from '@/components/leave/decision-dialog';
import type { DecisionChoice } from '@/components/leave/decision-dialog';
import { LeaveTypeBadge } from '@/components/leave/leave-type-badge';
import { Button } from '@/components/ui/button';
import { formatClockTime } from '@/lib/clock-time';
import { formatDate } from '@/lib/format-date';
import {
    formatDayAmount,
    formatLeaveDays,
    leaveDateLabel,
    leaveRangeLabel,
} from '@/lib/leave';
import { dayHalfLabel, requestPill } from '@/lib/leave-requests';
import { show } from '@/routes/leave/requests';
import type { LeaveRequestRow } from './requests';

type Detail = LeaveRequestRow & {
    reason: string;
    applied_by: string | null;
    applied_time: string | null;
    manager_decider: string | null;
    manager_decided_on: string | null;
    hr_decider: string | null;
    hr_decided_on: string | null;
    manager_skipped: boolean;
    decision_note: string | null;
    /** Each step's own note (from the history): the manager's and the final (HR) decision's. */
    manager_note: string | null;
    hr_note: string | null;
    canceller: string | null;
    cancelled_on: string | null;
    cancel_reason: string | null;
    attachment_url: string | null;
    is_own: boolean;
};

type BalanceNow = {
    used: number;
    pending: number;
    remaining: number | null;
    available: number | null;
    entitlement: number | null;
    overdrawn: boolean;
    label: string;
};

type BackLink = { url: string; label: string };

type Props = {
    /** Where "back" goes: the requests list for approvers, My Leaves for everyone else. */
    back: BackLink;
    request: Detail;
    days: { date: string; half: 'full' | 'am' | 'pm'; amount: number }[];
    /** ledger: the recorded leave days; plan: how the days count now (while open); none: no days. */
    days_source: 'ledger' | 'plan' | 'none';
    plan_refusal: string | null;
    /** Paid types, shown to the requester and the approvers only. */
    balance: { year: number; before: number | null; now: BalanceNow }[] | null;
    needs_attention: string[];
    /** Days whose leave was removed while their attendance row was corrected by hand (it still shows leave). */
    removed_manual: string[];
    history: {
        description: string;
        by: string;
        on: string | null;
        time: string | null;
        note: string | null;
    }[];
    today: string;
};

export default function LeaveRequestShow({
    back,
    request,
    days,
    days_source,
    plan_refusal,
    balance,
    needs_attention,
    removed_manual,
    history,
    today,
}: Props) {
    const { props } = usePage();
    const dateFormat = props.app.dateFormat;
    const pill = requestPill(
        request.status,
        request.stage,
        request.manager_first_name,
        request.partly_cancelled,
    );

    const [decision, setDecision] = useState<DecisionChoice>(null);
    const [decideOpen, setDecideOpen] = useState(false);
    const [cancelOpen, setCancelOpen] = useState(false);
    const [withdrawOpen, setWithdrawOpen] = useState(false);
    const [busy, setBusy] = useState(false);

    const decide = (choice: DecisionChoice) => {
        setDecision(choice);
        setDecideOpen(true);
    };

    const withdraw = () =>
        router.post(
            LeaveApplicationController.cancel(request.id).url,
            {},
            {
                preserveScroll: true,
                onStart: () => setBusy(true),
                onFinish: () => {
                    setBusy(false);
                    setWithdrawOpen(false);
                },
            },
        );

    const { can } = request;

    return (
        <>
            <Head title={`Leave request · ${request.employee.name}`} />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <div>
                    <Button variant="ghost" size="sm" asChild className="-ml-2">
                        <Link href={back.url}>
                            <ArrowLeft />
                            {back.label}
                        </Link>
                    </Button>
                </div>

                <PageHeader
                    title={`${request.type.name} leave`}
                    subtitle={leaveRangeLabel(
                        request.from,
                        request.to,
                        request.half,
                    )}
                    actions={
                        <div className="flex flex-wrap gap-2">
                            {can.skips_manager && (
                                <Button
                                    variant="outline"
                                    onClick={() => decide(null)}
                                >
                                    Decide as HR (skips manager)
                                </Button>
                            )}
                            {can.decide && !can.skips_manager && (
                                <>
                                    <Button onClick={() => decide('approve')}>
                                        <Check />
                                        Approve
                                    </Button>
                                    <Button
                                        variant="outline"
                                        className="text-destructive hover:text-destructive"
                                        onClick={() => decide('reject')}
                                    >
                                        <X />
                                        Reject
                                    </Button>
                                </>
                            )}
                            {can.cancel_approved && (
                                <Button
                                    variant="outline"
                                    onClick={() => setCancelOpen(true)}
                                >
                                    Cancel leave
                                </Button>
                            )}
                            {can.cancel_open && (
                                <Button
                                    variant="outline"
                                    onClick={() => setWithdrawOpen(true)}
                                >
                                    Cancel request
                                </Button>
                            )}
                        </div>
                    }
                />

                <div className="grid gap-6 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
                    <div className="grid content-start gap-6">
                        <Card title="Request">
                            <div className="mb-4 flex flex-wrap items-center gap-3">
                                <Avatar
                                    src={request.employee.photo_url}
                                    name={request.employee.name}
                                    size="md"
                                />
                                <div className="grid gap-0.5">
                                    <span className="font-medium">
                                        {request.employee.name}
                                        {request.is_own && (
                                            <span className="ml-1.5 text-xs font-normal text-muted-foreground">
                                                (you)
                                            </span>
                                        )}
                                    </span>
                                    <span className="text-xs text-muted-foreground">
                                        {request.employee.code}
                                    </span>
                                </div>
                                <div className="ml-auto grid justify-items-end gap-1">
                                    <StatusPill tone={pill.tone}>
                                        {pill.label}
                                    </StatusPill>
                                    {pill.note && (
                                        <span className="text-xs text-muted-foreground">
                                            {pill.note}
                                            {request.partly_cancelled &&
                                            request.cancelled_from
                                                ? ` from ${formatDate(request.cancelled_from, dateFormat)}`
                                                : ''}
                                        </span>
                                    )}
                                </div>
                            </div>
                            <dl className="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-[auto_1fr]">
                                <Fact label="Type">
                                    <LeaveTypeBadge
                                        name={request.type.name}
                                        colour={request.type.colour}
                                    />
                                </Fact>
                                <Fact label="Dates">
                                    {leaveRangeLabel(
                                        request.from,
                                        request.to,
                                        request.half,
                                    )}
                                </Fact>
                                <Fact label="Days">
                                    <span className="tabular-nums">
                                        {formatLeaveDays(request.days)}
                                    </span>
                                </Fact>
                                <Fact label="Applied">
                                    {formatDate(request.applied_on, dateFormat)}
                                    {request.applied_time &&
                                        `, ${formatClockTime(request.applied_time)}`}
                                    {request.applied_by &&
                                        ` · by ${request.applied_by} on their behalf`}
                                </Fact>
                                <Fact label="Reason">
                                    <span className="break-words whitespace-pre-line">
                                        {request.reason}
                                    </span>
                                </Fact>
                                {request.attachment_url && (
                                    <Fact label="Attachment">
                                        <a
                                            href={request.attachment_url}
                                            className="inline-flex items-center gap-1.5 text-primary underline-offset-4 hover:underline"
                                        >
                                            <Paperclip className="size-4" />
                                            Download
                                        </a>
                                    </Fact>
                                )}
                                {request.manager_decider && (
                                    <Fact label="Manager">
                                        {request.manager_decider}
                                        {request.manager_decided_on &&
                                            `, ${formatDate(request.manager_decided_on, dateFormat)}`}
                                    </Fact>
                                )}
                                {request.hr_decider && (
                                    <Fact label="Final decision">
                                        {request.hr_decider}
                                        {request.hr_decided_on &&
                                            `, ${formatDate(request.hr_decided_on, dateFormat)}`}
                                        {request.manager_skipped &&
                                            ' (manager step skipped)'}
                                    </Fact>
                                )}
                                {request.manager_note && (
                                    <Fact label="Manager's note">
                                        <span className="break-words">
                                            {request.manager_note}
                                        </span>
                                    </Fact>
                                )}
                                {request.hr_note && (
                                    <Fact label="HR's note">
                                        <span className="break-words">
                                            {request.hr_note}
                                        </span>
                                    </Fact>
                                )}
                                {!request.manager_note &&
                                    !request.hr_note &&
                                    request.decision_note && (
                                        <Fact label="Decision note">
                                            <span className="break-words">
                                                {request.decision_note}
                                            </span>
                                        </Fact>
                                    )}
                                {request.canceller && (
                                    <Fact label="Cancelled">
                                        {request.canceller}
                                        {request.cancelled_on &&
                                            `, ${formatDate(request.cancelled_on, dateFormat)}`}
                                        {request.cancel_reason && (
                                            <span className="block break-words text-muted-foreground">
                                                {request.cancel_reason}
                                            </span>
                                        )}
                                    </Fact>
                                )}
                            </dl>
                        </Card>

                        {(needs_attention.length > 0 ||
                            removed_manual.length > 0) && (
                            <div
                                role="note"
                                className="flex gap-3 rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-500/40 dark:bg-amber-500/10 dark:text-amber-200"
                            >
                                <TriangleAlert className="mt-0.5 size-4 shrink-0" />
                                <div className="grid gap-1">
                                    <p className="font-medium">
                                        Needs attention
                                    </p>
                                    <ul className="grid gap-0.5">
                                        {needs_attention.map((date) => (
                                            <li key={date}>
                                                {leaveDateLabel(date)}:
                                                corrected by hand on the Daily
                                                screen; leave was not applied to
                                                this day.
                                            </li>
                                        ))}
                                        {removed_manual.map((date) => (
                                            <li key={`removed-${date}`}>
                                                {leaveDateLabel(date)}: leave
                                                was removed, but the day was
                                                corrected by hand on the Daily
                                                screen and still shows leave.
                                                Check it.
                                            </li>
                                        ))}
                                    </ul>
                                </div>
                            </div>
                        )}

                        <Card
                            title={
                                days_source === 'plan'
                                    ? 'Days that count (as of now)'
                                    : 'Leave days'
                            }
                        >
                            {days.length === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    {plan_refusal ??
                                        (request.status === 'cancelled'
                                            ? 'Every day of this leave was cancelled.'
                                            : 'No leave days are recorded for this request.')}
                                </p>
                            ) : (
                                <ul className="divide-y text-sm">
                                    {days.map((day) => (
                                        <li
                                            key={`${day.date}-${day.half}`}
                                            className="flex items-center justify-between gap-3 py-2"
                                        >
                                            <span>
                                                {leaveDateLabel(day.date)}
                                            </span>
                                            <span className="text-muted-foreground tabular-nums">
                                                {dayHalfLabel(day.half)} ·{' '}
                                                {formatLeaveDays(day.amount)}
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            )}
                            {days_source === 'plan' && days.length > 0 && (
                                <p className="mt-3 text-xs text-muted-foreground">
                                    Weekly offs and holidays are left out. The
                                    days are counted again when the request is
                                    approved.
                                </p>
                            )}
                        </Card>
                    </div>

                    <div className="grid content-start gap-6">
                        {balance && balance.length > 0 && (
                            <Card title={`${request.type.name} balance`}>
                                <ul className="grid gap-4 text-sm">
                                    {balance.map((b) => (
                                        <li
                                            key={b.year}
                                            className="grid gap-1.5"
                                        >
                                            <span className="font-medium">
                                                {b.year}
                                            </span>
                                            <dl className="grid grid-cols-[1fr_auto] gap-x-4 gap-y-1">
                                                {b.before !== null && (
                                                    <>
                                                        <dt className="text-muted-foreground">
                                                            Available when
                                                            applied
                                                        </dt>
                                                        <dd className="tabular-nums">
                                                            {formatDayAmount(
                                                                b.before,
                                                            )}
                                                        </dd>
                                                    </>
                                                )}
                                                <dt className="text-muted-foreground">
                                                    Remaining now
                                                </dt>
                                                <dd
                                                    className={
                                                        b.now.overdrawn
                                                            ? 'font-medium text-destructive tabular-nums'
                                                            : 'tabular-nums'
                                                    }
                                                >
                                                    {b.now.label}
                                                </dd>
                                                <dt className="text-muted-foreground">
                                                    Pending
                                                </dt>
                                                <dd className="tabular-nums">
                                                    {formatDayAmount(
                                                        b.now.pending,
                                                    )}
                                                </dd>
                                                {b.now.available !== null && (
                                                    <>
                                                        <dt className="text-muted-foreground">
                                                            Available now
                                                        </dt>
                                                        <dd className="tabular-nums">
                                                            {formatDayAmount(
                                                                b.now.available,
                                                            )}
                                                        </dd>
                                                    </>
                                                )}
                                            </dl>
                                        </li>
                                    ))}
                                </ul>
                            </Card>
                        )}

                        <Card title="History">
                            {history.length === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    No decisions yet.
                                </p>
                            ) : (
                                <ol className="grid gap-4 text-sm">
                                    {history.map((h, i) => (
                                        <li
                                            key={i}
                                            className="grid gap-0.5 border-l-2 pl-3"
                                        >
                                            <span className="font-medium">
                                                {h.description}
                                            </span>
                                            <span className="text-xs text-muted-foreground">
                                                {h.by}
                                                {h.on &&
                                                    ` · ${formatDate(h.on, dateFormat)}`}
                                                {h.time &&
                                                    `, ${formatClockTime(h.time)}`}
                                            </span>
                                            {h.note && (
                                                <span className="break-words">
                                                    “{h.note}”
                                                </span>
                                            )}
                                        </li>
                                    ))}
                                </ol>
                            )}
                        </Card>
                    </div>
                </div>
            </div>

            <DecisionDialog
                open={decideOpen}
                onOpenChange={setDecideOpen}
                subject={request}
                decision={decision}
            />

            <CancelApprovedDialog
                open={cancelOpen}
                onOpenChange={setCancelOpen}
                subject={request}
                today={today}
            />

            <ConfirmDialog
                open={withdrawOpen}
                onOpenChange={setWithdrawOpen}
                title="Cancel this leave request?"
                description={`The request for ${leaveRangeLabel(request.from, request.to, request.half)} will be withdrawn.`}
                confirmLabel="Cancel request"
                cancelLabel="Keep it"
                destructive
                processing={busy}
                onConfirm={withdraw}
            />
        </>
    );
}

function Card({ title, children }: { title: string; children: ReactNode }) {
    return (
        <section className="rounded-xl border bg-card p-4 shadow-xs md:p-5">
            <h2 className="mb-3 text-sm font-semibold">{title}</h2>
            {children}
        </section>
    );
}

function Fact({ label, children }: { label: string; children: ReactNode }) {
    return (
        <>
            <dt className="text-muted-foreground">{label}</dt>
            <dd>{children}</dd>
        </>
    );
}

LeaveRequestShow.layout = (props: {
    back: BackLink;
    request: { id: number };
}) => ({
    breadcrumbs: [
        { title: props.back.label, href: props.back.url },
        { title: 'Request', href: show(props.request.id) },
    ],
});
