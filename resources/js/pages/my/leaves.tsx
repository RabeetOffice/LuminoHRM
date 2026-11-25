import { Head, router } from '@inertiajs/react';
import { CalendarRange, Paperclip, Plus } from 'lucide-react';
import { useState } from 'react';
import LeaveApplicationController from '@/actions/App/Http/Controllers/Leave/LeaveApplicationController';
import { ApplyLeaveDialog } from '@/components/leave/apply-leave-dialog';
import type { ApplyLeaveType } from '@/components/leave/apply-leave-dialog';
import { BalanceCards } from '@/components/leave/balance-cards';
import type { BalanceLineData } from '@/components/leave/balance-cards';
import { LeaveTypeBadge } from '@/components/leave/leave-type-badge';
import { ConfirmDialog } from '@/components/hrm/confirm-dialog';
import { DataTable } from '@/components/hrm/data-table';
import type {
    DataTableColumn,
    DataTablePagination,
} from '@/components/hrm/data-table';
import { PageHeader } from '@/components/hrm/page-header';
import { StatusPill } from '@/components/hrm/status-pill';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    formatLeaveDays,
    leaveStageLabel,
    leaveStatusLabel,
    leaveStatusTone,
} from '@/lib/leave';
import type { LeaveHalf, LeaveStatus } from '@/lib/leave';
import { leaves } from '@/routes/my';

type LeaveRow = {
    id: number;
    type: string;
    colour: string;
    /** "Y-m-d". */
    from: string;
    to: string;
    half: LeaveHalf | null;
    /** Server-made label, e.g. "Tue 1 Dec 2026 – Thu 3 Dec 2026". */
    dates: string;
    days: number;
    reason: string;
    status: LeaveStatus;
    stage: 'manager' | 'final' | null;
    /** The manager's first name while the request waits for them. */
    waiting_for: string | null;
    decision_note: string | null;
    can_cancel: boolean;
    has_attachment: boolean;
    /** "Cancelled from Tue 1 Dec 2026" for approved leave cancelled part-way. */
    partly_cancelled: string | null;
};

type Props = {
    /** The viewer's own record; null when the account is not linked to an employee. */
    employee: { name: string } | null;
    year: number;
    years: number[];
    balances: BalanceLineData[];
    types: ApplyLeaveType[];
    requests: DataTablePagination & { data: LeaveRow[] };
    window: { min: string; max: string };
    today: string;
};

/** The signed-in person's own leave: balances for a year, their requests, and the apply dialog. */
export default function MyLeaves({
    employee,
    year,
    years,
    balances,
    types,
    requests,
    window,
}: Props) {
    const [applying, setApplying] = useState(false);
    const [cancelling, setCancelling] = useState<LeaveRow | null>(null);
    const [busy, setBusy] = useState(false);

    const cancel = () => {
        if (!cancelling) {
            return;
        }

        router.post(
            LeaveApplicationController.cancel.url(cancelling.id),
            {},
            {
                preserveScroll: true,
                onStart: () => setBusy(true),
                onFinish: () => {
                    setBusy(false);
                    setCancelling(null);
                },
            },
        );
    };

    const columns: DataTableColumn<LeaveRow>[] = [
        {
            key: 'type',
            header: 'Type',
            cell: (row) => (
                <LeaveTypeBadge name={row.type} colour={row.colour} />
            ),
        },
        {
            key: 'dates',
            header: 'Dates',
            cell: (row) => (
                <div className="grid gap-0.5">
                    <span>{row.dates}</span>
                    {row.partly_cancelled && (
                        <span className="text-xs text-muted-foreground">
                            {row.partly_cancelled}
                        </span>
                    )}
                </div>
            ),
        },
        {
            key: 'days',
            header: 'Days',
            cell: (row) => formatLeaveDays(row.days),
            className: 'whitespace-nowrap',
        },
        {
            key: 'status',
            header: 'Status',
            cell: (row) => {
                const stage = leaveStageLabel(row.stage, row.waiting_for);

                return (
                    <div className="grid justify-items-start gap-1">
                        <StatusPill tone={leaveStatusTone(row.status)}>
                            {leaveStatusLabel(row.status)}
                        </StatusPill>
                        {stage && (
                            <span className="text-xs text-muted-foreground">
                                {stage}
                            </span>
                        )}
                        {row.decision_note && (
                            <span className="text-xs text-muted-foreground">
                                {row.decision_note}
                            </span>
                        )}
                    </div>
                );
            },
        },
        {
            key: 'actions',
            header: <span className="sr-only">Actions</span>,
            className: 'text-right',
            cell: (row) => (
                <div className="flex justify-end gap-2">
                    {row.has_attachment && (
                        <Button variant="ghost" size="icon" asChild>
                            <a
                                href={LeaveApplicationController.attachment.url(
                                    row.id,
                                )}
                                aria-label="Download the attachment"
                            >
                                <Paperclip />
                            </a>
                        </Button>
                    )}
                    {row.can_cancel && (
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={() => setCancelling(row)}
                        >
                            Cancel
                        </Button>
                    )}
                </div>
            ),
        },
    ];

    return (
        <>
            <Head title="My Leaves" />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title="My Leaves"
                    subtitle={
                        employee
                            ? 'Your balances and leave requests.'
                            : undefined
                    }
                    actions={
                        employee &&
                        types.length > 0 && (
                            <Button onClick={() => setApplying(true)}>
                                <Plus /> Apply for leave
                            </Button>
                        )
                    }
                />

                {employee === null ? (
                    <NotLinked />
                ) : (
                    <>
                        <section className="grid gap-3">
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <h2 className="text-base font-semibold">
                                    Balances
                                </h2>
                                <Select
                                    value={String(year)}
                                    onValueChange={(value) =>
                                        router.get(
                                            leaves.url({
                                                query: { year: value },
                                            }),
                                            {},
                                            { preserveScroll: true },
                                        )
                                    }
                                >
                                    <SelectTrigger
                                        aria-label="Year"
                                        className="w-28"
                                    >
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {years.map((y) => (
                                            <SelectItem
                                                key={y}
                                                value={String(y)}
                                            >
                                                {y}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <BalanceCards lines={balances} />
                        </section>

                        <section className="grid gap-3">
                            <h2 className="text-base font-semibold">
                                My requests
                            </h2>
                            <DataTable
                                label="My leave requests"
                                columns={columns}
                                rows={requests.data}
                                rowKey={(row) => row.id}
                                pagination={requests}
                                emptyText="You have not applied for leave yet."
                            />
                        </section>
                    </>
                )}
            </div>

            {employee && (
                <ApplyLeaveDialog
                    open={applying}
                    onClose={() => setApplying(false)}
                    types={types}
                    window={window}
                />
            )}

            <ConfirmDialog
                open={cancelling !== null}
                onOpenChange={(open) => !open && !busy && setCancelling(null)}
                title="Cancel this request?"
                description={
                    cancelling
                        ? `${cancelling.type}, ${cancelling.dates}. Nobody has decided it yet; you can apply again later.`
                        : ''
                }
                confirmLabel="Cancel request"
                cancelLabel="Keep it"
                onConfirm={cancel}
                processing={busy}
            />
        </>
    );
}

function NotLinked() {
    return (
        <section className="flex flex-col items-center rounded-xl border bg-card px-6 py-16 text-center shadow-xs">
            <div className="flex size-12 items-center justify-center rounded-full bg-muted text-muted-foreground">
                <CalendarRange aria-hidden="true" className="size-6" />
            </div>
            <p className="mt-4 text-base font-medium">
                Your account is not linked to an employee record yet.
            </p>
            <p className="mt-1 text-sm text-muted-foreground">
                Your leave appears here once HR links it.
            </p>
        </section>
    );
}

MyLeaves.layout = {
    breadcrumbs: [{ title: 'My Leaves', href: leaves() }],
};
