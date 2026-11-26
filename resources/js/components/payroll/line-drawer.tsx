import { router, useForm } from '@inertiajs/react';
import { AlertTriangle, Download, Plus, RefreshCw, Trash2 } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import type { FormEvent, ReactNode } from 'react';
import PayrollItemController from '@/actions/App/Http/Controllers/Payroll/PayrollItemController';
import PayrollRunController from '@/actions/App/Http/Controllers/Payroll/PayrollRunController';
import { StatusPill } from '@/components/hrm/status-pill';
import { MoneyInput } from '@/components/payroll/money-input';
import { Field, fieldAria } from '@/components/settings/settings-form';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Skeleton } from '@/components/ui/skeleton';
import { Spinner } from '@/components/ui/spinner';
import { bpToPercent, formatMoney, rupeesText } from '@/lib/payroll';
import type { RunLine } from '@/lib/payroll';

type EarningOrDeduction = {
    key: string;
    label: string;
    amount: number;
    taxable?: boolean;
    adjustment_id?: number;
};

type Breakdown = {
    period: { days_in_month: number; days_paid: number };
    earnings: EarningOrDeduction[];
    deductions: EarningOrDeduction[];
    tax: {
        mode: string;
        base: string | null;
        rate_bp: number | null;
        taxable_pay: number;
        amount: number;
        overridden: boolean;
        override_reason: string | null;
    };
    attendance: Record<string, number>;
};

type DrawerLoan = {
    id: number;
    type: string;
    installment: number;
    remaining: number;
    deduction: number;
    skipped: boolean;
    skip_reason: string | null;
};

type Detail = {
    id: number;
    employee: { id: number; name: string; code: string };
    status: string;
    figures: Record<string, number>;
    breakdown: Breakdown | null;
    warnings: { key: string; message: string }[];
    blocking: string[];
    excluded: boolean;
    excluded_reason: string | null;
    tax_overridden: boolean;
    tax_override: number | null;
    tax_override_reason: string | null;
    adjustments: {
        id: number;
        label: string;
        type: 'addition' | 'deduction';
        amount: number;
        is_taxable: boolean;
    }[];
    loans: DrawerLoan[];
    can_edit: boolean;
};

type Props = {
    runId: number;
    /** The line to show, or null when closed. */
    line: RunLine | null;
    onClose: () => void;
};

const ATTENDANCE_LABELS: [string, string][] = [
    ['present', 'Present'],
    ['late', 'Late'],
    ['half_day', 'Half days'],
    ['absent', 'Absent'],
    ['paid_leave', 'Paid leave'],
    ['unpaid_leave', 'Unpaid leave'],
    ['off', 'Days off'],
    ['late_days_deducted', 'Late days deducted'],
];

function Block({ title, children }: { title: string; children: ReactNode }) {
    return (
        <section className="grid gap-2">
            <h3 className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                {title}
            </h3>
            {children}
        </section>
    );
}

function AmountRow({
    label,
    amount,
    note,
    strong,
}: {
    label: ReactNode;
    amount: number;
    note?: ReactNode;
    strong?: boolean;
}) {
    return (
        <div
            className={
                strong
                    ? 'flex items-baseline justify-between gap-3 border-t pt-2 font-semibold'
                    : 'flex items-baseline justify-between gap-3 text-sm'
            }
        >
            <dt className="min-w-0">
                {label}
                {note && (
                    <span className="block text-xs text-muted-foreground">
                        {note}
                    </span>
                )}
            </dt>
            <dd className="shrink-0 tabular-nums">{formatMoney(amount)}</dd>
        </div>
    );
}

function taxNote(detail: Detail): string {
    const tax = detail.breakdown?.tax;

    if (!tax) {
        return '';
    }

    if (tax.overridden) {
        return `Set for this month${tax.override_reason ? `: ${tax.override_reason}` : ''}`;
    }

    if (tax.mode === 'percentage') {
        return `${bpToPercent(tax.rate_bp ?? 0)}% of ${tax.base === 'basic' ? 'basic' : 'taxable pay'} (${formatMoney(tax.taxable_pay)})`;
    }

    return tax.mode === 'fixed' ? 'Fixed amount' : 'No tax';
}

/** Add a one-off addition or deduction to the line. */
function AddItemForm({
    runId,
    itemId,
    disabled,
    onBusy,
    onDone,
}: {
    runId: number;
    itemId: number;
    disabled: boolean;
    onBusy: (busy: boolean) => void;
    onDone: () => void;
}) {
    const form = useForm({
        label: '',
        type: 'addition',
        amount: '',
        taxable: true,
    });
    const { data, errors } = form;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.submit(
            PayrollItemController.storeAdjustment({ run: runId, item: itemId }),
            {
                preserveScroll: true,
                onStart: () => onBusy(true),
                onFinish: () => onBusy(false),
                onSuccess: () => {
                    form.reset();
                    onDone();
                },
            },
        );
    };

    return (
        <form onSubmit={submit} className="grid gap-3" noValidate>
            <Field id="adj-label" label="Label" error={errors.label}>
                <Input
                    {...fieldAria('adj-label', errors.label)}
                    value={data.label}
                    maxLength={100}
                    onChange={(e) => form.setData('label', e.target.value)}
                />
            </Field>
            <div className="grid grid-cols-2 gap-3">
                <Field id="adj-type" label="Type" error={errors.type}>
                    <Select
                        value={data.type}
                        onValueChange={(v) => form.setData('type', v)}
                    >
                        <SelectTrigger {...fieldAria('adj-type', errors.type)}>
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="addition">Addition</SelectItem>
                            <SelectItem value="deduction">Deduction</SelectItem>
                        </SelectContent>
                    </Select>
                </Field>
                <Field id="adj-amount" label="Amount" error={errors.amount}>
                    <MoneyInput
                        {...fieldAria('adj-amount', errors.amount)}
                        value={data.amount}
                        onChange={(v) => form.setData('amount', v)}
                    />
                </Field>
            </div>
            {data.type === 'addition' && (
                <div className="flex items-center gap-2">
                    <Checkbox
                        id="adj-taxable"
                        checked={data.taxable}
                        onCheckedChange={(c) =>
                            form.setData('taxable', c === true)
                        }
                    />
                    <Label htmlFor="adj-taxable" className="font-normal">
                        Taxable
                    </Label>
                </div>
            )}
            <Button
                type="submit"
                variant="outline"
                disabled={form.processing || disabled}
                className="justify-self-start"
            >
                {form.processing ? <Spinner /> : <Plus />}
                Add
            </Button>
        </form>
    );
}

/** Tax for this month: a whole-rupee amount with a reason, or back to the calculated tax. */
function TaxForm({
    runId,
    detail,
    disabled,
    onBusy,
    onDone,
}: {
    runId: number;
    detail: Detail;
    disabled: boolean;
    onBusy: (busy: boolean) => void;
    onDone: () => void;
}) {
    const form = useForm({
        amount:
            detail.tax_override === null ? '' : rupeesText(detail.tax_override),
        reason: detail.tax_override_reason ?? '',
    });
    const { data, errors } = form;
    const target = PayrollItemController.tax({ run: runId, item: detail.id });
    const visit = {
        preserveScroll: true,
        onStart: () => onBusy(true),
        onFinish: () => onBusy(false),
        onSuccess: () => onDone(),
    };

    return (
        <form
            onSubmit={(event) => {
                event.preventDefault();
                form.transform((d) => d);
                form.submit(target, visit);
            }}
            className="grid gap-3"
            noValidate
        >
            <div className="grid gap-3 sm:grid-cols-2">
                <Field
                    id="tax-amount"
                    label="Tax this month"
                    error={errors.amount}
                >
                    <MoneyInput
                        {...fieldAria('tax-amount', errors.amount)}
                        value={data.amount}
                        onChange={(v) => form.setData('amount', v)}
                    />
                </Field>
                <Field id="tax-reason" label="Reason" error={errors.reason}>
                    <Input
                        {...fieldAria('tax-reason', errors.reason)}
                        value={data.reason}
                        maxLength={255}
                        onChange={(e) => form.setData('reason', e.target.value)}
                    />
                </Field>
            </div>
            <div className="flex flex-wrap gap-2">
                <Button
                    type="submit"
                    variant="outline"
                    disabled={form.processing || disabled || data.amount === ''}
                >
                    {form.processing && <Spinner />}
                    Set tax
                </Button>
                {detail.tax_overridden && (
                    <Button
                        type="button"
                        variant="ghost"
                        disabled={form.processing || disabled}
                        onClick={() => {
                            form.transform(() => ({ amount: '', reason: '' }));
                            form.submit(target, {
                                ...visit,
                                onSuccess: () => {
                                    form.setData({ amount: '', reason: '' });
                                    onDone();
                                },
                            });
                        }}
                    >
                        Use calculated tax
                    </Button>
                )}
            </div>
        </form>
    );
}

/** One loan's installment for this month: skip it with a reason, or bring it back. */
function LoanRow({
    runId,
    itemId,
    loan,
    disabled,
    onBusy,
    onDone,
}: {
    runId: number;
    itemId: number;
    loan: DrawerLoan;
    disabled: boolean;
    onBusy: (busy: boolean) => void;
    onDone: () => void;
}) {
    const form = useForm({ skipped: true, reason: '' });
    const target = PayrollItemController.loanSkip({
        run: runId,
        item: itemId,
        loan: loan.id,
    });
    const visit = {
        preserveScroll: true,
        onStart: () => onBusy(true),
        onFinish: () => onBusy(false),
        onSuccess: () => {
            form.reset();
            onDone();
        },
    };
    const kind = loan.type === 'advance' ? 'Advance' : 'Loan';

    return (
        <li className="grid gap-2 rounded-lg border p-3 text-sm">
            <div className="flex items-baseline justify-between gap-3">
                <span className="font-medium">
                    {kind}, {formatMoney(loan.installment)} a month
                </span>
                <span className="text-xs text-muted-foreground tabular-nums">
                    {formatMoney(loan.remaining)} left
                </span>
            </div>
            {loan.skipped ? (
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <span className="text-muted-foreground">
                        Skipped this month
                        {loan.skip_reason ? `: ${loan.skip_reason}` : ''}
                    </span>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        disabled={form.processing || disabled}
                        onClick={() => {
                            form.transform(() => ({ skipped: false }));
                            form.submit(target, visit);
                        }}
                    >
                        {form.processing && <Spinner />}
                        Bring back
                    </Button>
                </div>
            ) : (
                <form
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.transform((d) => ({ ...d, skipped: true }));
                        form.submit(target, visit);
                    }}
                    className="grid gap-2"
                    noValidate
                >
                    <p className="text-muted-foreground">
                        Deducted this month: {formatMoney(loan.deduction)}
                    </p>
                    <Field
                        id={`skip-reason-${loan.id}`}
                        label="Reason for skipping"
                        error={form.errors.reason}
                    >
                        <Input
                            {...fieldAria(
                                `skip-reason-${loan.id}`,
                                form.errors.reason,
                            )}
                            value={form.data.reason}
                            maxLength={255}
                            onChange={(e) =>
                                form.setData('reason', e.target.value)
                            }
                        />
                    </Field>
                    <Button
                        type="submit"
                        variant="outline"
                        size="sm"
                        className="justify-self-start"
                        disabled={form.processing || disabled}
                    >
                        {form.processing && <Spinner />}
                        Skip this month
                    </Button>
                </form>
            )}
        </li>
    );
}

/** Leave this person out of this month's payroll (with a reason), or put them back. */
function ExcludeForm({
    runId,
    detail,
    disabled,
    onBusy,
    onDone,
}: {
    runId: number;
    detail: Detail;
    disabled: boolean;
    onBusy: (busy: boolean) => void;
    onDone: () => void;
}) {
    const form = useForm({ excluded: !detail.excluded, reason: '' });
    const target = PayrollItemController.exclude({
        run: runId,
        item: detail.id,
    });
    const visit = {
        preserveScroll: true,
        onStart: () => onBusy(true),
        onFinish: () => onBusy(false),
        onSuccess: () => {
            form.reset();
            onDone();
        },
    };

    if (detail.excluded) {
        return (
            <div className="grid gap-2 text-sm">
                <p className="text-muted-foreground">
                    Excluded: {detail.excluded_reason}
                </p>
                <Button
                    type="button"
                    variant="outline"
                    className="justify-self-start"
                    disabled={form.processing || disabled}
                    onClick={() => {
                        form.transform(() => ({ excluded: false }));
                        form.submit(target, visit);
                    }}
                >
                    {form.processing && <Spinner />}
                    Include again
                </Button>
            </div>
        );
    }

    return (
        <form
            onSubmit={(event) => {
                event.preventDefault();
                form.transform((d) => ({ ...d, excluded: true }));
                form.submit(target, visit);
            }}
            className="grid gap-3"
            noValidate
        >
            <Field
                id="exclude-reason"
                label="Reason"
                hint="Paid outside this payroll, for example on unpaid leave all month."
                error={form.errors.reason}
            >
                <Input
                    {...fieldAria('exclude-reason', form.errors.reason, true)}
                    value={form.data.reason}
                    maxLength={255}
                    onChange={(e) => form.setData('reason', e.target.value)}
                />
            </Field>
            <Button
                type="submit"
                variant="outline"
                className="justify-self-start"
                disabled={form.processing || disabled}
            >
                {form.processing && <Spinner />}
                Exclude from this payroll
            </Button>
        </form>
    );
}

/**
 * The right-hand drawer for one line: the stored breakdown (earnings,
 * deductions, tax, net, attendance), and, on a draft the user may edit, the
 * inputs (one-off items, tax for the month, loan installments, exclusion).
 * It reads /payroll/runs/{run}/items/{item} and reads it again after each
 * change. It cannot be closed while a change is being saved.
 */
export function LineDrawer({ runId, line, onClose }: Props) {
    const [detail, setDetail] = useState<Detail | null>(null);
    const [failed, setFailed] = useState(false);
    const [busy, setBusy] = useState(false);
    const [version, setVersion] = useState(0);
    const lineId = line?.id ?? null;
    const lineStamp = line
        ? `${line.net}:${line.tax}:${line.excluded}:${line.loan_deduction}`
        : '';

    useEffect(() => {
        if (lineId === null) {
            return;
        }

        const controller = new AbortController();

        fetch(PayrollItemController.show({ run: runId, item: lineId }).url, {
            headers: { Accept: 'application/json' },
            signal: controller.signal,
        })
            .then((response) => {
                if (!response.ok) {
                    throw new Error('failed');
                }

                return response.json() as Promise<Detail>;
            })
            .then((data) => {
                setDetail(data);
                setFailed(false);
            })
            .catch((error: unknown) => {
                if ((error as Error).name !== 'AbortError') {
                    setFailed(true);
                }
            });

        return () => controller.abort();
    }, [runId, lineId, lineStamp, version]);

    useEffect(() => {
        // A different person: forget the previous one while it loads.
        // eslint-disable-next-line react-hooks/set-state-in-effect
        setDetail(null);
        setFailed(false);
    }, [lineId]);

    const changed = useCallback(() => setVersion((v) => v + 1), []);
    const current = detail !== null && detail.id === lineId ? detail : null;
    const breakdown = current?.breakdown ?? null;
    const edit = current?.can_edit === true;

    return (
        <Sheet
            open={line !== null}
            onOpenChange={(open) => !open && !busy && onClose()}
        >
            <SheetContent className="w-full overflow-y-auto sm:max-w-lg">
                <SheetHeader>
                    <SheetTitle>
                        {line?.employee.name ?? 'Payroll line'}
                    </SheetTitle>
                    <SheetDescription>
                        {line?.employee.code}
                        {line?.employee.department
                            ? ` · ${line.employee.department}`
                            : ''}
                    </SheetDescription>
                </SheetHeader>

                <div className="grid gap-6 px-4 pb-6">
                    {failed && (
                        <p role="alert" className="text-sm text-destructive">
                            This line could not be loaded. Close the drawer and
                            try again.
                        </p>
                    )}
                    {current === null && !failed && (
                        <div className="grid gap-3" aria-busy="true">
                            <Skeleton className="h-5 w-1/3" />
                            <Skeleton className="h-24 w-full" />
                            <Skeleton className="h-24 w-full" />
                        </div>
                    )}

                    {current !== null && (
                        <>
                            {current.excluded && (
                                <StatusPill tone="grey">
                                    Excluded from this payroll
                                </StatusPill>
                            )}
                            {current.blocking.length > 0 &&
                                !current.excluded && (
                                    <p
                                        role="alert"
                                        className="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-900 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-200"
                                    >
                                        This line blocks approval. See the
                                        checks on the run page.
                                    </p>
                                )}

                            {breakdown && !current.excluded && (
                                <>
                                    <Block title="Earnings">
                                        <dl className="grid gap-1.5">
                                            {breakdown.earnings.map((e, i) => (
                                                <AmountRow
                                                    key={`${e.key}-${i}`}
                                                    label={e.label}
                                                    amount={e.amount}
                                                    note={
                                                        e.taxable === false
                                                            ? 'Not taxable'
                                                            : undefined
                                                    }
                                                />
                                            ))}
                                            <AmountRow
                                                strong
                                                label="Gross pay"
                                                amount={current.figures.gross}
                                            />
                                        </dl>
                                    </Block>

                                    <Block title="Deductions">
                                        <dl className="grid gap-1.5">
                                            {breakdown.deductions.length ===
                                                0 && (
                                                <p className="text-sm text-muted-foreground">
                                                    None.
                                                </p>
                                            )}
                                            {breakdown.deductions.map(
                                                (d, i) => (
                                                    <AmountRow
                                                        key={`${d.key}-${i}`}
                                                        label={d.label}
                                                        amount={d.amount}
                                                    />
                                                ),
                                            )}
                                        </dl>
                                    </Block>

                                    <Block title="Tax">
                                        <dl className="grid gap-1.5">
                                            <AmountRow
                                                label="Income tax"
                                                amount={current.figures.tax}
                                                note={taxNote(current)}
                                            />
                                            <AmountRow
                                                strong
                                                label="Net pay"
                                                amount={current.figures.net}
                                            />
                                        </dl>
                                    </Block>

                                    <Block title="Attendance">
                                        <p className="text-sm text-muted-foreground">
                                            {breakdown.period.days_paid} of{' '}
                                            {breakdown.period.days_in_month}{' '}
                                            days paid.
                                        </p>
                                        <dl className="grid grid-cols-2 gap-x-6 gap-y-1 text-sm sm:grid-cols-4">
                                            {ATTENDANCE_LABELS.map(
                                                ([key, label]) => (
                                                    <div key={key}>
                                                        <dt className="text-xs text-muted-foreground">
                                                            {label}
                                                        </dt>
                                                        <dd className="tabular-nums">
                                                            {breakdown
                                                                .attendance[
                                                                key
                                                            ] ?? 0}
                                                        </dd>
                                                    </div>
                                                ),
                                            )}
                                        </dl>
                                    </Block>
                                </>
                            )}

                            {current.warnings.length > 0 && (
                                <Block title="Warnings">
                                    <ul className="grid gap-1.5">
                                        {current.warnings.map((w) => (
                                            <li
                                                key={w.key}
                                                className="flex items-start gap-2 text-sm text-amber-800 dark:text-amber-200"
                                            >
                                                <AlertTriangle
                                                    aria-hidden="true"
                                                    className="mt-0.5 size-4 shrink-0"
                                                />
                                                {w.message}
                                            </li>
                                        ))}
                                    </ul>
                                </Block>
                            )}

                            {(current.adjustments.length > 0 || edit) && (
                                <Block title="One-off items">
                                    {current.adjustments.length === 0 ? (
                                        <p className="text-sm text-muted-foreground">
                                            None added.
                                        </p>
                                    ) : (
                                        <ul className="divide-y rounded-lg border">
                                            {current.adjustments.map((a) => (
                                                <li
                                                    key={a.id}
                                                    className="flex items-center justify-between gap-3 px-3 py-2 text-sm"
                                                >
                                                    <span className="min-w-0">
                                                        {a.label}
                                                        <span className="block text-xs text-muted-foreground">
                                                            {a.type ===
                                                            'addition'
                                                                ? a.is_taxable
                                                                    ? 'Addition, taxable'
                                                                    : 'Addition, not taxable'
                                                                : 'Deduction'}
                                                        </span>
                                                    </span>
                                                    <span className="flex shrink-0 items-center gap-2">
                                                        <span className="tabular-nums">
                                                            {a.type ===
                                                            'deduction'
                                                                ? '-'
                                                                : ''}
                                                            {formatMoney(
                                                                a.amount,
                                                            )}
                                                        </span>
                                                        {edit && (
                                                            <Button
                                                                type="button"
                                                                variant="ghost"
                                                                size="icon"
                                                                aria-label={`Remove ${a.label}`}
                                                                disabled={busy}
                                                                onClick={() =>
                                                                    router.delete(
                                                                        PayrollItemController.destroyAdjustment(
                                                                            {
                                                                                run: runId,
                                                                                item: current.id,
                                                                                adjustment:
                                                                                    a.id,
                                                                            },
                                                                        ).url,
                                                                        {
                                                                            preserveScroll: true,
                                                                            onStart:
                                                                                () =>
                                                                                    setBusy(
                                                                                        true,
                                                                                    ),
                                                                            onFinish:
                                                                                () =>
                                                                                    setBusy(
                                                                                        false,
                                                                                    ),
                                                                            onSuccess:
                                                                                changed,
                                                                        },
                                                                    )
                                                                }
                                                            >
                                                                <Trash2 />
                                                            </Button>
                                                        )}
                                                    </span>
                                                </li>
                                            ))}
                                        </ul>
                                    )}
                                </Block>
                            )}

                            {edit && (
                                <>
                                    <Block title="Add a one-off item">
                                        <AddItemForm
                                            runId={runId}
                                            itemId={current.id}
                                            disabled={busy}
                                            onBusy={setBusy}
                                            onDone={changed}
                                        />
                                    </Block>

                                    {!current.excluded && (
                                        <Block title="Tax for this month">
                                            <TaxForm
                                                key={`${current.tax_overridden}-${current.tax_override}`}
                                                runId={runId}
                                                detail={current}
                                                disabled={busy}
                                                onBusy={setBusy}
                                                onDone={changed}
                                            />
                                        </Block>
                                    )}

                                    {current.loans.length > 0 && (
                                        <Block title="Loan installments">
                                            <ul className="grid gap-2">
                                                {current.loans.map((loan) => (
                                                    <LoanRow
                                                        key={`${loan.id}-${loan.skipped}`}
                                                        runId={runId}
                                                        itemId={current.id}
                                                        loan={loan}
                                                        disabled={busy}
                                                        onBusy={setBusy}
                                                        onDone={changed}
                                                    />
                                                ))}
                                            </ul>
                                        </Block>
                                    )}

                                    <Block title="Leave out of this payroll">
                                        <ExcludeForm
                                            key={`${current.excluded}`}
                                            runId={runId}
                                            detail={current}
                                            disabled={busy}
                                            onBusy={setBusy}
                                            onDone={changed}
                                        />
                                    </Block>

                                    <Button
                                        type="button"
                                        variant="outline"
                                        disabled={busy}
                                        className="justify-self-start"
                                        onClick={() =>
                                            router.post(
                                                PayrollRunController.recalculate(
                                                    runId,
                                                ).url,
                                                {
                                                    employee_id:
                                                        current.employee.id,
                                                },
                                                {
                                                    preserveScroll: true,
                                                    onStart: () =>
                                                        setBusy(true),
                                                    onFinish: () =>
                                                        setBusy(false),
                                                    onSuccess: changed,
                                                },
                                            )
                                        }
                                    >
                                        <RefreshCw />
                                        Recalculate this line
                                    </Button>
                                </>
                            )}

                            {line?.payslip_url && (
                                <Button
                                    asChild
                                    variant="outline"
                                    className="justify-self-start"
                                >
                                    <a href={line.payslip_url}>
                                        <Download />
                                        Payslip
                                    </a>
                                </Button>
                            )}
                        </>
                    )}
                </div>
            </SheetContent>
        </Sheet>
    );
}
