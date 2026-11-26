import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    ArrowLeft,
    Ban,
    CalendarX2,
    HandCoins,
    Pencil,
    Undo2,
} from 'lucide-react';
import { useState } from 'react';
import type { FormEvent, ReactNode } from 'react';
import LoanController from '@/actions/App/Http/Controllers/Payroll/LoanController';
import { PageHeader } from '@/components/hrm/page-header';
import { StatusPill } from '@/components/hrm/status-pill';
import { LoanDialog } from '@/components/payroll/loan-dialog';
import { LoanSkipDialog } from '@/components/payroll/loan-skip-dialog';
import { RepaymentDialog } from '@/components/payroll/repayment-dialog';
import { Field, fieldAria } from '@/components/settings/settings-form';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import {
    formatMoney,
    loanStatusPill,
    loanTypeLabel,
    monthLabel,
} from '@/lib/payroll';
import type { LoanMonths, LoanRow } from '@/pages/payroll/loans/index';
import { index, show } from '@/routes/payroll/loans';

type Loan = LoanRow & {
    note: string | null;
    created_by: string | null;
    created_on: string | null;
    cancelled_by: string | null;
    cancelled_on: string | null;
    cancel_reason: string | null;
    has_repayments: boolean;
    core_editable: boolean;
};

type Repayment = {
    id: number;
    month: string;
    amount: number;
    /** "Payroll November 2026" or "Cash". */
    source: string;
    note: string | null;
    by: string | null;
    recorded_on: string | null;
};

type Skip = {
    id: number;
    month: string;
    reason: string;
    by: string | null;
    /** In or before an approved payroll month: it can no longer be removed. */
    locked: boolean;
};

type Props = {
    loan: Loan;
    repayments: Repayment[];
    skips: Skip[];
    schedule: { month: string; amount: number }[];
    months: LoanMonths;
    can: { edit: boolean; cancel: boolean };
};

function Fact({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="rounded-xl border bg-card px-4 py-3 shadow-xs">
            <dt className="text-sm text-muted-foreground">{label}</dt>
            <dd className="mt-0.5 text-lg font-semibold tabular-nums">
                {children}
            </dd>
        </div>
    );
}

function Section({ title, children }: { title: string; children: ReactNode }) {
    return (
        <section className="overflow-hidden rounded-xl border bg-card shadow-xs">
            <h2 className="border-b px-4 py-3 font-medium">{title}</h2>
            {children}
        </section>
    );
}

function CancelDialog({ loan, onClose }: { loan: Loan; onClose: () => void }) {
    const form = useForm({ reason: '' });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.submit(LoanController.cancel(loan.id), {
            preserveScroll: true,
            onSuccess: () => onClose(),
        });
    };

    return (
        <Dialog
            open
            onOpenChange={(open) => !open && !form.processing && onClose()}
        >
            <DialogContent className="sm:max-w-md">
                <form onSubmit={submit} className="grid gap-5" noValidate>
                    <DialogHeader>
                        <DialogTitle>
                            Cancel this {loanTypeLabel(loan.type).toLowerCase()}
                            ?
                        </DialogTitle>
                        <DialogDescription>
                            The remaining {formatMoney(loan.remaining)} is
                            written off and payroll stops deducting it.
                            Repayments already made stay. This cannot be undone.
                        </DialogDescription>
                    </DialogHeader>
                    <Field
                        id="cancel-reason"
                        label="Reason"
                        error={form.errors.reason}
                    >
                        <Textarea
                            {...fieldAria('cancel-reason', form.errors.reason)}
                            value={form.data.reason}
                            maxLength={255}
                            rows={2}
                            onChange={(e) =>
                                form.setData('reason', e.target.value)
                            }
                        />
                    </Field>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={onClose}
                            disabled={form.processing}
                        >
                            Keep it
                        </Button>
                        <Button
                            type="submit"
                            variant="destructive"
                            disabled={form.processing}
                        >
                            {form.processing && <Spinner />}
                            Cancel {loanTypeLabel(loan.type).toLowerCase()}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export default function LoanShow({
    loan,
    repayments,
    skips,
    schedule,
    months,
    can,
}: Props) {
    const [dialog, setDialog] = useState<
        'edit' | 'skip' | 'repay' | 'cancel' | null
    >(null);
    const [unskipping, setUnskipping] = useState<string | null>(null);
    const pill = loanStatusPill(loan.status);
    const active = loan.status === 'active';
    const firstOpen = months.first_open;
    const openFrom =
        firstOpen !== null && firstOpen > loan.start_month
            ? firstOpen
            : loan.start_month;
    const repayMonth =
        firstOpen !== null && firstOpen > months.current
            ? firstOpen
            : months.current;

    const unskip = (month: string) =>
        router.delete(LoanController.unskip({ loan: loan.id, month }).url, {
            preserveScroll: true,
            onStart: () => setUnskipping(month),
            onFinish: () => setUnskipping(null),
        });

    return (
        <>
            <Head
                title={`${loanTypeLabel(loan.type)} · ${loan.employee.name}`}
            />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title={`${loanTypeLabel(loan.type)} of ${loan.employee.name}`}
                    subtitle={`${loan.employee.code} · deductions from ${monthLabel(loan.start_month)}`}
                    actions={
                        <>
                            <Button asChild variant="ghost">
                                <Link href={index()}>
                                    <ArrowLeft />
                                    All loans
                                </Link>
                            </Button>
                            {can.edit && (
                                <Button
                                    variant="outline"
                                    onClick={() => setDialog('edit')}
                                >
                                    <Pencil />
                                    Edit
                                </Button>
                            )}
                            {can.edit && active && (
                                <>
                                    <Button
                                        variant="outline"
                                        onClick={() => setDialog('skip')}
                                    >
                                        <CalendarX2 />
                                        Skip a month
                                    </Button>
                                    <Button
                                        variant="outline"
                                        onClick={() => setDialog('repay')}
                                    >
                                        <HandCoins />
                                        Record repayment
                                    </Button>
                                </>
                            )}
                            {can.cancel && (
                                <Button
                                    variant="destructive"
                                    onClick={() => setDialog('cancel')}
                                >
                                    <Ban />
                                    Cancel
                                </Button>
                            )}
                        </>
                    }
                />

                <div className="flex flex-wrap items-center gap-2">
                    <StatusPill tone={pill.tone}>{pill.label}</StatusPill>
                    {loan.next_month && (
                        <span className="text-sm text-muted-foreground">
                            Next deduction: {monthLabel(loan.next_month)}
                        </span>
                    )}
                </div>

                <dl className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                    <Fact label="Amount">{formatMoney(loan.amount)}</Fact>
                    <Fact label="Monthly installment">
                        {formatMoney(loan.installment)}
                    </Fact>
                    <Fact label="Repaid">{formatMoney(loan.repaid)}</Fact>
                    <Fact label="Remaining">{formatMoney(loan.remaining)}</Fact>
                </dl>

                <dl className="grid gap-x-6 gap-y-2 rounded-xl border bg-card p-4 text-sm shadow-xs sm:grid-cols-2">
                    {loan.opening_repaid > 0 && (
                        <div className="flex justify-between gap-4">
                            <dt className="text-muted-foreground">
                                Repaid before go-live
                            </dt>
                            <dd className="tabular-nums">
                                {formatMoney(loan.opening_repaid)}
                            </dd>
                        </div>
                    )}
                    <div className="flex justify-between gap-4">
                        <dt className="text-muted-foreground">Added</dt>
                        <dd>
                            {loan.created_on ?? '—'}
                            {loan.created_by ? ` by ${loan.created_by}` : ''}
                        </dd>
                    </div>
                    {loan.note && (
                        <div className="flex justify-between gap-4 sm:col-span-2">
                            <dt className="text-muted-foreground">Note</dt>
                            <dd className="text-right">{loan.note}</dd>
                        </div>
                    )}
                    {loan.status === 'cancelled' && (
                        <div className="flex justify-between gap-4 sm:col-span-2">
                            <dt className="text-muted-foreground">Cancelled</dt>
                            <dd className="text-right">
                                {loan.cancelled_on}
                                {loan.cancelled_by
                                    ? ` by ${loan.cancelled_by}`
                                    : ''}
                                {loan.cancel_reason
                                    ? `: ${loan.cancel_reason}`
                                    : ''}
                            </dd>
                        </div>
                    )}
                </dl>

                <div className="grid gap-6 lg:grid-cols-2">
                    <Section title="Projected schedule">
                        {schedule.length === 0 ? (
                            <p className="p-4 text-sm text-muted-foreground">
                                Nothing left to deduct.
                            </p>
                        ) : (
                            <ul className="max-h-96 divide-y overflow-y-auto text-sm">
                                {schedule.map((entry) => (
                                    <li
                                        key={entry.month}
                                        className="flex justify-between px-4 py-2"
                                    >
                                        <span>{monthLabel(entry.month)}</span>
                                        <span className="tabular-nums">
                                            {formatMoney(entry.amount)}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Section>

                    <Section title="Skipped months">
                        {skips.length === 0 ? (
                            <p className="p-4 text-sm text-muted-foreground">
                                No month skipped.
                            </p>
                        ) : (
                            <ul className="divide-y text-sm">
                                {skips.map((skip) => (
                                    <li
                                        key={skip.id}
                                        className="flex items-start justify-between gap-3 px-4 py-2"
                                    >
                                        <div className="min-w-0">
                                            <p className="font-medium">
                                                {monthLabel(skip.month)}
                                            </p>
                                            <p className="text-muted-foreground">
                                                {skip.reason}
                                                {skip.by ? ` · ${skip.by}` : ''}
                                            </p>
                                        </div>
                                        {can.edit && active && !skip.locked && (
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                disabled={
                                                    unskipping === skip.month
                                                }
                                                onClick={() =>
                                                    unskip(skip.month)
                                                }
                                            >
                                                {unskipping === skip.month ? (
                                                    <Spinner />
                                                ) : (
                                                    <Undo2 />
                                                )}
                                                Undo skip
                                            </Button>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Section>
                </div>

                <Section title="Repayments">
                    {repayments.length === 0 ? (
                        <p className="p-4 text-sm text-muted-foreground">
                            No repayment yet.
                        </p>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[36rem] text-sm">
                                <thead className="bg-muted/50 text-left text-xs font-medium text-muted-foreground uppercase">
                                    <tr>
                                        <th scope="col" className="px-4 py-2">
                                            Month
                                        </th>
                                        <th scope="col" className="px-4 py-2">
                                            Source
                                        </th>
                                        <th
                                            scope="col"
                                            className="px-4 py-2 text-right"
                                        >
                                            Amount
                                        </th>
                                        <th scope="col" className="px-4 py-2">
                                            Note
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {repayments.map((r) => (
                                        <tr key={r.id}>
                                            <td className="px-4 py-2 whitespace-nowrap">
                                                {monthLabel(r.month)}
                                            </td>
                                            <td className="px-4 py-2">
                                                {r.source}
                                            </td>
                                            <td className="px-4 py-2 text-right tabular-nums">
                                                {formatMoney(r.amount)}
                                            </td>
                                            <td className="px-4 py-2 text-muted-foreground">
                                                {r.note ?? '—'}
                                                {r.by ? ` · ${r.by}` : ''}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </Section>
            </div>

            {dialog === 'edit' && (
                <LoanDialog
                    loan={loan}
                    firstOpen={firstOpen}
                    defaultMonth={loan.start_month}
                    onClose={() => setDialog(null)}
                />
            )}
            {dialog === 'skip' && (
                <LoanSkipDialog
                    loanId={loan.id}
                    minMonth={openFrom}
                    onClose={() => setDialog(null)}
                />
            )}
            {dialog === 'repay' && (
                <RepaymentDialog
                    loanId={loan.id}
                    remaining={loan.remaining}
                    month={repayMonth}
                    minMonth={firstOpen}
                    onClose={() => setDialog(null)}
                />
            )}
            {dialog === 'cancel' && (
                <CancelDialog loan={loan} onClose={() => setDialog(null)} />
            )}
        </>
    );
}

LoanShow.layout = (props: Props) => ({
    breadcrumbs: [
        { title: 'Loans & Advances', href: index() },
        { title: props.loan.employee.name, href: show(props.loan.id) },
    ],
});
