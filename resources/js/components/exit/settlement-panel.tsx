import { useForm, usePage } from '@inertiajs/react';
import { CircleAlert, Download, Plus, RefreshCw, Trash2 } from 'lucide-react';
import { useState } from 'react';
import SettlementController from '@/actions/App/Http/Controllers/Exits/SettlementController';
import { DialogFrame } from '@/components/exit/dialog-frame';
import { SettlementLineDialog } from '@/components/exit/settlement-line-dialog';
import { ActionError, useExitAction } from '@/components/exit/use-exit-action';
import { ConfirmDialog } from '@/components/hrm/confirm-dialog';
import { StatusPill } from '@/components/hrm/status-pill';
import { Field, fieldAria } from '@/components/settings/settings-form';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import {
    generalErrors,
    paidButtonLabel,
    settlementNetText,
    settlementStatusLabel,
    settlementStatusTone,
} from '@/lib/exit';
import { formatDate } from '@/lib/format-date';
import { formatMoney, monthLabel } from '@/lib/payroll';

export type SettlementCheck = {
    key: string;
    severity: string;
    message: string;
};

export type SettlementLine = {
    id: number;
    kind: string;
    type: 'addition' | 'deduction';
    label: string;
    amount: number;
    is_taxable: boolean;
};

export type Settlement = {
    exists: boolean;
    /** Why the viewer may not prepare or edit this settlement (their own, an Admin / HR person's), else null. */
    edit_refusal?: string | null;
    id?: number;
    resignation_id?: number;
    status?: 'draft' | 'approved' | 'paid';
    month?: string;
    stale?: boolean;
    figures?: Record<string, number>;
    encashment?: {
        on: boolean;
        type_name: string | null;
        halves: number;
        amount: number;
    };
    loans?: {
        loan_id: number;
        type: string;
        remaining: number;
        recovered: number;
    }[];
    loan_shortfall?: number;
    day_pay?: number | null;
    owed_by_employee?: number;
    lines?: SettlementLine[];
    tax?: { overridden: boolean; amount: number | null; reason: string | null };
    checks?: SettlementCheck[];
    approved_at?: string | null;
    approved_by?: string | null;
    own_settlement?: boolean;
    paid_on?: string | null;
    paid_by?: string | null;
    statement_url?: string | null;
    paid_label?: string;
    can: { prepare: boolean; edit: boolean; approve: boolean; paid: boolean };
};

const MAX_LINES = 20;

/** Halves of a day as "6.5". */
function halvesText(halves: number): string {
    return halves % 2 === 0
        ? String(halves / 2)
        : `${Math.floor(halves / 2)}.5`;
}

const isBlocking = (check: SettlementCheck) => check.severity === 'block';

function Row({
    label,
    value,
    strong,
    muted,
}: {
    label: string;
    value: string;
    strong?: boolean;
    muted?: boolean;
}) {
    return (
        <div
            className={`flex items-baseline justify-between gap-4 py-1.5 text-sm ${strong ? 'font-semibold' : ''}`}
        >
            <dt className={muted ? 'text-muted-foreground' : ''}>{label}</dt>
            <dd className="tabular-nums">{value}</dd>
        </div>
    );
}

/** Override the draft's tax with an amount and a reason, or clear it. The dialog stays open on a refusal. */
function TaxDialog({
    settlement,
    onClose,
}: {
    settlement: Settlement;
    onClose: () => void;
}) {
    const current = settlement.tax?.overridden
        ? String(Math.round((settlement.tax.amount ?? 0) / 100))
        : '';
    const form = useForm({
        amount: current,
        reason: settlement.tax?.reason ?? '',
    });
    const { errors } = form;
    const general = generalErrors(
        errors as Record<string, string | undefined>,
        ['amount', 'reason'],
    );

    return (
        <DialogFrame
            title="Override the tax"
            description="Replace the calculated tax with an amount. A reason is required. Leave the amount empty to go back to the calculated tax."
            submitLabel="Save tax"
            processing={form.processing}
            onClose={onClose}
            onSubmit={() =>
                form.submit(SettlementController.tax(settlement.id as number), {
                    preserveScroll: true,
                    onSuccess: () => onClose(),
                })
            }
            general={general}
        >
            <Field id="tax-amount" label="Tax (rupees)" error={errors.amount}>
                <Input
                    {...fieldAria('tax-amount', errors.amount)}
                    inputMode="numeric"
                    value={form.data.amount}
                    onChange={(e) =>
                        form.setData(
                            'amount',
                            e.target.value.replace(/[^\d]/g, ''),
                        )
                    }
                    className="sm:w-56"
                />
            </Field>
            <Field
                id="tax-reason"
                label="Reason"
                error={errors.reason}
                hint="Required with an amount. At most 300 characters."
            >
                <Textarea
                    {...fieldAria('tax-reason', errors.reason, true)}
                    value={form.data.reason}
                    onChange={(e) => form.setData('reason', e.target.value)}
                    maxLength={300}
                    rows={2}
                />
            </Field>
        </DialogFrame>
    );
}

/** Mark an approved settlement paid (or settled) on a day not in the future and not before its approval. */
function PaidDialog({
    settlement,
    today,
    onClose,
}: {
    settlement: Settlement;
    today: string;
    onClose: () => void;
}) {
    const form = useForm({ paid_on: today });
    const { errors } = form;
    const general = generalErrors(
        errors as Record<string, string | undefined>,
        ['paid_on'],
    );
    const net = settlement.figures?.net ?? 0;

    return (
        <DialogFrame
            title={
                net > 0
                    ? 'Mark the settlement paid'
                    : 'Mark the settlement settled'
            }
            description={
                net > 0
                    ? 'Record the day the money was paid out. This only records it; no payment is made from here.'
                    : 'Nothing is paid out on this settlement. Record the day it was closed.'
            }
            submitLabel={paidButtonLabel(net)}
            processing={form.processing}
            onClose={onClose}
            onSubmit={() =>
                form.submit(
                    SettlementController.paid(settlement.id as number),
                    {
                        preserveScroll: true,
                        onSuccess: () => onClose(),
                    },
                )
            }
            general={general}
        >
            <Field id="paid-on" label="Date" error={errors.paid_on}>
                <Input
                    {...fieldAria('paid-on', errors.paid_on)}
                    type="date"
                    max={today}
                    value={form.data.paid_on}
                    onChange={(e) => form.setData('paid_on', e.target.value)}
                    className="sm:w-56"
                />
            </Field>
        </DialogFrame>
    );
}

type Props = {
    settlement: Settlement;
    resignationId: number;
    today: string;
};

/**
 * The final settlement (HR and Admin with payroll rights only; a manager never receives it). Prepare it once the exit is
 * approved; then add lines, override the tax, recalculate and, for an Admin, approve. Every refusal shows above the panel;
 * an action that is not available says why (the checks list, or a line under the button) instead of disappearing.
 */
export function SettlementPanel({ settlement, resignationId, today }: Props) {
    const dateFormat = usePage().props.app.dateFormat;
    const { busy, error, run, clear } = useExitAction();
    const [dialog, setDialog] = useState<'line' | 'tax' | 'paid' | null>(null);
    const [confirm, setConfirm] = useState<'approve' | null>(null);
    const [removing, setRemoving] = useState<number | null>(null);

    const header = (
        <div className="flex flex-wrap items-center gap-2 border-b px-5 py-4">
            <h2 className="text-base font-semibold">Final settlement</h2>
            {settlement.exists && settlement.status && (
                <StatusPill tone={settlementStatusTone(settlement.status)}>
                    {settlementStatusLabel(settlement.status)}
                </StatusPill>
            )}
            {settlement.exists && settlement.month && (
                <span className="text-sm text-muted-foreground">
                    {monthLabel(settlement.month)}
                </span>
            )}
        </div>
    );

    if (!settlement.exists) {
        return (
            <section
                className="rounded-xl border bg-card shadow-xs"
                aria-label="Final settlement"
            >
                {header}
                <div className="grid gap-3 p-5">
                    <ActionError messages={error} />
                    <p className="text-sm text-muted-foreground">
                        No settlement yet. Prepare it to work out the final
                        month's pay, leave encashment and loan recovery. It uses
                        the same calculation as a payroll run.
                    </p>
                    <div>
                        <Button
                            type="button"
                            disabled={!settlement.can.prepare || busy !== null}
                            onClick={() =>
                                run(
                                    'prepare',
                                    SettlementController.prepare(resignationId),
                                )
                            }
                        >
                            Prepare settlement
                        </Button>
                        {!settlement.can.prepare && (
                            <p className="mt-2 text-sm text-muted-foreground">
                                {settlement.edit_refusal ??
                                    'You cannot prepare this settlement: it needs HR or Admin with payroll rights.'}
                            </p>
                        )}
                    </div>
                </div>
            </section>
        );
    }

    const id = settlement.id as number;
    const figures = settlement.figures ?? {};
    const lines = settlement.lines ?? [];
    const checks = settlement.checks ?? [];
    const draft = settlement.status === 'draft';
    const blocking = checks.filter(isBlocking);
    const net = figures.net ?? 0;
    const encashment = settlement.encashment;

    return (
        <section
            className="rounded-xl border bg-card shadow-xs"
            aria-label="Final settlement"
        >
            {header}
            <div className="grid gap-5 p-5">
                <ActionError messages={error} />

                {settlement.stale && draft && (
                    <p
                        role="status"
                        className="flex items-start gap-2 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800 dark:bg-amber-500/15 dark:text-amber-200"
                    >
                        <CircleAlert
                            className="mt-0.5 size-4 shrink-0"
                            aria-hidden="true"
                        />
                        The last working day changed. Recalculate the settlement
                        before approving it.
                    </p>
                )}

                <div>
                    <p className="text-xs text-muted-foreground">
                        {net < 0 ? 'Owed by the employee' : 'Net settlement'}
                    </p>
                    <p className="text-3xl font-semibold tabular-nums">
                        {net < 0 ? formatMoney(-net) : formatMoney(net)}
                    </p>
                    {net < 0 && (
                        <p className="mt-1 text-sm text-muted-foreground">
                            {settlementNetText(net)}. Nothing is paid out and no
                            loan is recovered.
                        </p>
                    )}
                </div>

                <dl className="divide-y rounded-lg border px-4">
                    <div className="py-2">
                        <Row
                            label="Basic"
                            value={formatMoney(figures.basic ?? 0)}
                        />
                        <Row
                            label="Allowances"
                            value={formatMoney(figures.allowances ?? 0)}
                        />
                        <Row
                            label="Overtime"
                            value={formatMoney(figures.overtime ?? 0)}
                        />
                        <Row
                            label="Additions (bonus lines, leave encashment)"
                            value={formatMoney(figures.additions ?? 0)}
                        />
                        {encashment?.on ? (
                            <Row
                                muted
                                label={`Leave encashment (${halvesText(encashment.halves)} days${encashment.type_name ? `, ${encashment.type_name}` : ''})`}
                                value={formatMoney(encashment.amount)}
                            />
                        ) : (
                            <Row muted label="Leave encashment" value="Off" />
                        )}
                        <Row
                            strong
                            label="Gross"
                            value={formatMoney(figures.gross ?? 0)}
                        />
                    </div>
                    <div className="py-2">
                        <Row
                            label="Attendance deduction"
                            value={formatMoney(
                                figures.attendance_deduction ?? 0,
                            )}
                        />
                        <Row
                            label="Fixed deductions"
                            value={formatMoney(figures.fixed_deductions ?? 0)}
                        />
                        <Row
                            label="Other deductions (lines)"
                            value={formatMoney(figures.other_deductions ?? 0)}
                        />
                        <Row
                            label={
                                settlement.tax?.overridden
                                    ? 'Tax (overridden)'
                                    : 'Tax'
                            }
                            value={formatMoney(figures.tax ?? 0)}
                        />
                        <Row
                            label="Loan recovery"
                            value={formatMoney(figures.loan_deduction ?? 0)}
                        />
                    </div>
                    <div className="py-2">
                        <Row
                            strong
                            label={net < 0 ? 'Net (owed by employee)' : 'Net'}
                            value={
                                net < 0
                                    ? `-${formatMoney(-net)}`
                                    : formatMoney(net)
                            }
                        />
                    </div>
                </dl>

                {settlement.tax?.overridden && (
                    <p className="text-sm text-muted-foreground">
                        Tax overridden
                        {settlement.tax.reason
                            ? `: ${settlement.tax.reason}`
                            : '.'}
                    </p>
                )}

                {(settlement.loans?.length ?? 0) > 0 && (
                    <section
                        className="grid gap-1.5"
                        aria-label="Loan recovery"
                    >
                        <h3 className="text-sm font-semibold">
                            Loans recovered in full
                        </h3>
                        <ul
                            role="list"
                            className="divide-y rounded-lg border text-sm"
                        >
                            {settlement.loans?.map((loan) => (
                                <li
                                    key={loan.loan_id}
                                    className="flex justify-between gap-3 px-4 py-2"
                                >
                                    <span className="capitalize">
                                        {loan.type}
                                    </span>
                                    <span className="tabular-nums">
                                        {formatMoney(loan.recovered)} of{' '}
                                        {formatMoney(loan.remaining)}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}
                {(settlement.loan_shortfall ?? 0) > 0 && (
                    <p
                        role="status"
                        className="rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800 dark:bg-amber-500/15 dark:text-amber-200"
                    >
                        Loan balance{' '}
                        {formatMoney(settlement.loan_shortfall ?? 0)} is more
                        than the settlement can recover; the rest stays on the
                        loan.
                    </p>
                )}

                <section className="grid gap-2" aria-label="Settlement lines">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <h3 className="text-sm font-semibold">
                            Lines{' '}
                            <span className="font-normal text-muted-foreground">
                                ({lines.length} of {MAX_LINES})
                            </span>
                        </h3>
                        {settlement.can.edit && draft && (
                            <div className="flex flex-wrap gap-2">
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={() => setDialog('tax')}
                                >
                                    {settlement.tax?.overridden
                                        ? 'Change tax override'
                                        : 'Override tax'}
                                </Button>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    disabled={lines.length >= MAX_LINES}
                                    onClick={() => {
                                        clear();
                                        setDialog('line');
                                    }}
                                >
                                    <Plus /> Add line
                                </Button>
                            </div>
                        )}
                    </div>
                    {lines.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            No lines. Add a bonus or a deduction if HR needs
                            one.
                        </p>
                    ) : (
                        <ul role="list" className="divide-y rounded-lg border">
                            {lines.map((line) => (
                                <li
                                    key={line.id}
                                    className="flex items-center justify-between gap-3 px-4 py-2.5 text-sm"
                                >
                                    <div className="min-w-0">
                                        <p className="truncate font-medium">
                                            {line.label}
                                        </p>
                                        <p className="text-xs text-muted-foreground">
                                            {line.type === 'addition'
                                                ? `Added${line.is_taxable ? ', taxable' : ', not taxable'}`
                                                : 'Deducted'}
                                        </p>
                                    </div>
                                    <div className="flex shrink-0 items-center gap-2">
                                        <span className="tabular-nums">
                                            {line.type === 'addition'
                                                ? '+'
                                                : '-'}
                                            {formatMoney(line.amount)}
                                        </span>
                                        {settlement.can.edit && draft && (
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="icon"
                                                aria-label={`Remove ${line.label}`}
                                                disabled={busy !== null}
                                                onClick={() => {
                                                    setRemoving(line.id);
                                                    run(
                                                        `remove-${line.id}`,
                                                        SettlementController.removeLine(
                                                            {
                                                                settlement: id,
                                                                line: line.id,
                                                            },
                                                        ),
                                                        {
                                                            onError: () =>
                                                                setRemoving(
                                                                    null,
                                                                ),
                                                            onSuccess: () =>
                                                                setRemoving(
                                                                    null,
                                                                ),
                                                        },
                                                    );
                                                }}
                                            >
                                                <Trash2
                                                    className={
                                                        removing === line.id
                                                            ? 'animate-pulse'
                                                            : ''
                                                    }
                                                />
                                            </Button>
                                        )}
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>

                {draft && checks.length > 0 && (
                    <section className="grid gap-1.5" aria-label="Checks">
                        <h3 className="text-sm font-semibold">
                            Checks before approval
                        </h3>
                        <ul role="list" className="grid gap-1.5 text-sm">
                            {checks.map((check) => (
                                <li
                                    key={`${check.key}-${check.message}`}
                                    className={
                                        isBlocking(check)
                                            ? 'flex items-start gap-2 text-red-700 dark:text-red-300'
                                            : 'flex items-start gap-2 text-amber-700 dark:text-amber-300'
                                    }
                                >
                                    <CircleAlert
                                        className="mt-0.5 size-4 shrink-0"
                                        aria-hidden="true"
                                    />
                                    <span>{check.message}</span>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}

                {settlement.status !== 'draft' && (
                    <p className="text-sm text-muted-foreground">
                        Approved
                        {settlement.approved_by
                            ? ` by ${settlement.approved_by}`
                            : ''}
                        {settlement.approved_at
                            ? ` on ${formatDate(settlement.approved_at, dateFormat)}`
                            : ''}
                        {settlement.own_settlement
                            ? ' (their own, as the only Admin)'
                            : ''}
                        .
                        {settlement.paid_on
                            ? ` ${net > 0 ? 'Paid' : 'Settled'} on ${formatDate(settlement.paid_on, dateFormat)}.`
                            : ''}
                    </p>
                )}

                <div className="flex flex-wrap items-center gap-2">
                    {settlement.can.edit && draft && (
                        <Button
                            type="button"
                            variant={settlement.stale ? 'default' : 'outline'}
                            disabled={busy !== null}
                            onClick={() =>
                                run(
                                    'recalculate',
                                    SettlementController.recalculate(id),
                                )
                            }
                        >
                            <RefreshCw
                                className={
                                    busy === 'recalculate' ? 'animate-spin' : ''
                                }
                            />{' '}
                            Recalculate
                        </Button>
                    )}
                    {draft &&
                        (settlement.can.approve || settlement.can.edit) && (
                            <Button
                                type="button"
                                disabled={
                                    !settlement.can.approve ||
                                    busy !== null ||
                                    blocking.length > 0 ||
                                    settlement.stale === true
                                }
                                onClick={() => {
                                    clear();
                                    setConfirm('approve');
                                }}
                            >
                                Approve settlement
                            </Button>
                        )}
                    {settlement.status === 'approved' &&
                        settlement.can.paid && (
                            <Button
                                type="button"
                                onClick={() => setDialog('paid')}
                            >
                                {paidButtonLabel(net)}
                            </Button>
                        )}
                    {settlement.statement_url && (
                        <Button variant="outline" asChild>
                            <a href={settlement.statement_url}>
                                <Download /> Statement PDF
                            </a>
                        </Button>
                    )}
                </div>

                {draft && !settlement.can.approve && settlement.can.edit && (
                    <p className="text-sm text-muted-foreground">
                        Only an Admin can approve a settlement, and never their
                        own while another Admin can.
                    </p>
                )}
                {draft &&
                    settlement.can.approve &&
                    (blocking.length > 0 || settlement.stale) && (
                        <p className="text-sm text-muted-foreground">
                            Approval is not available yet:{' '}
                            {settlement.stale
                                ? 'recalculate first'
                                : blocking[0]?.message}
                        </p>
                    )}
                {draft && !settlement.can.edit && !settlement.can.approve && (
                    <p className="text-sm text-muted-foreground">
                        {settlement.edit_refusal ??
                            'You can view this settlement but not change it.'}
                    </p>
                )}
            </div>

            {dialog === 'line' && (
                <SettlementLineDialog
                    settlementId={id}
                    count={lines.length}
                    dayPay={settlement.day_pay ?? null}
                    onClose={() => setDialog(null)}
                />
            )}
            {dialog === 'tax' && (
                <TaxDialog
                    settlement={settlement}
                    onClose={() => setDialog(null)}
                />
            )}
            {dialog === 'paid' && (
                <PaidDialog
                    settlement={settlement}
                    today={today}
                    onClose={() => setDialog(null)}
                />
            )}
            {confirm === 'approve' && (
                <ConfirmDialog
                    open
                    onOpenChange={(next) => !next && setConfirm(null)}
                    title="Approve this settlement?"
                    description={
                        <>
                            The figures are recalculated first; if any changed
                            you will be asked to review and approve again. Once
                            approved, nothing can change: attendance, leave,
                            salary and loans for the final month are frozen, and
                            loans are recovered as shown.
                            {error.map((message) => (
                                <span
                                    key={message}
                                    role="alert"
                                    className="mt-3 block text-sm text-red-600 dark:text-red-400"
                                >
                                    {message}
                                </span>
                            ))}
                        </>
                    }
                    confirmLabel="Approve settlement"
                    destructive={false}
                    processing={busy === 'approve'}
                    onConfirm={() =>
                        run('approve', SettlementController.approve(id), {
                            onSuccess: () => setConfirm(null),
                        })
                    }
                />
            )}
        </section>
    );
}
