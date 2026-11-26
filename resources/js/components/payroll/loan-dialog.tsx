import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import LoanController from '@/actions/App/Http/Controllers/Payroll/LoanController';
import { MoneyInput } from '@/components/payroll/money-input';
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
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import {
    formatMoney,
    monthLabel,
    parseRupees,
    rupeesText,
} from '@/lib/payroll';
import type { LoanType } from '@/lib/payroll';

export type LoanPerson = { id: number; name: string; code: string };

/** The loan being edited (money in paisa). */
export type EditableLoan = {
    id: number;
    type: LoanType;
    amount: number;
    installment: number;
    start_month: string;
    opening_repaid: number;
    note: string | null;
    employee: LoanPerson;
    /** Amount, first month and repaid-before-go-live may still change. */
    core_editable: boolean;
};

type Props = {
    /** Edit this loan; omit to add a new one. */
    loan?: EditableLoan;
    /** People the user may add a loan for (new loans only). */
    employees?: LoanPerson[];
    /** The first month deductions may start (after the latest approved payroll). */
    firstOpen: string | null;
    /** The default first deduction month for a new loan. */
    defaultMonth: string;
    onClose: () => void;
};

/**
 * Adds or edits a loan or advance. Amounts are whole rupees as typed; the
 * server turns them into paisa and checks every rule again. An advance's
 * installment may be left blank: the whole amount in one month. "Repaid
 * before go-live" is for a loan already running when the app starts. Mount it
 * only while open.
 */
export function LoanDialog({
    loan,
    employees = [],
    firstOpen,
    defaultMonth,
    onClose,
}: Props) {
    const editing = loan !== undefined;
    const coreLocked = editing && !loan.core_editable;
    const form = useForm({
        employee_id: '',
        type: (loan?.type ?? 'loan') as LoanType,
        amount: loan ? rupeesText(loan.amount) : '',
        installment: loan ? rupeesText(loan.installment) : '',
        start_month: loan?.start_month ?? defaultMonth,
        opening_repaid: loan ? rupeesText(loan.opening_repaid) : '',
        note: loan?.note ?? '',
    });
    const { data, errors } = form;

    const amount = parseRupees(data.amount);
    const repaid =
        data.opening_repaid === '' ? 0 : parseRupees(data.opening_repaid);
    const remaining =
        amount !== null && repaid !== null && repaid < amount
            ? (amount - repaid) * 100
            : null;

    const submit = (event: FormEvent) => {
        event.preventDefault();

        if (editing) {
            form.transform((d) => ({
                amount: d.amount,
                installment: d.installment,
                start_month: d.start_month,
                opening_repaid: d.opening_repaid,
                note: d.note,
            }));
            form.submit(LoanController.update(loan.id), {
                preserveScroll: true,
                onSuccess: () => onClose(),
            });

            return;
        }

        form.transform((d) => d);
        form.submit(LoanController.store(), { onSuccess: () => onClose() });
    };

    const kind = data.type === 'advance' ? 'advance' : 'loan';

    return (
        <Dialog
            open
            onOpenChange={(open) => !open && !form.processing && onClose()}
        >
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-lg">
                <form onSubmit={submit} className="grid gap-5" noValidate>
                    <DialogHeader>
                        <DialogTitle>
                            {editing
                                ? `Edit ${kind} of ${loan.employee.name}`
                                : 'Add a loan or advance'}
                        </DialogTitle>
                        <DialogDescription>
                            Paid out outside the app; payroll recovers it in
                            monthly installments from the first deduction month.
                        </DialogDescription>
                    </DialogHeader>

                    {!editing && (
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field
                                id="loan-employee"
                                label="Employee"
                                error={errors.employee_id}
                                className="grid content-start gap-2 sm:col-span-2"
                            >
                                <Select
                                    value={data.employee_id}
                                    onValueChange={(v) =>
                                        form.setData('employee_id', v)
                                    }
                                >
                                    <SelectTrigger
                                        {...fieldAria(
                                            'loan-employee',
                                            errors.employee_id,
                                        )}
                                    >
                                        <SelectValue placeholder="Choose a person" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {employees.map((person) => (
                                            <SelectItem
                                                key={person.id}
                                                value={String(person.id)}
                                            >
                                                {person.name} · {person.code}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </Field>
                            <Field
                                id="loan-type"
                                label="Type"
                                error={errors.type}
                                className="grid content-start gap-2 sm:col-span-2"
                            >
                                <Select
                                    value={data.type}
                                    onValueChange={(v) =>
                                        form.setData('type', v as LoanType)
                                    }
                                >
                                    <SelectTrigger
                                        {...fieldAria('loan-type', errors.type)}
                                    >
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="loan">
                                            Loan
                                        </SelectItem>
                                        <SelectItem value="advance">
                                            Advance
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                            </Field>
                        </div>
                    )}

                    {coreLocked && (
                        <p className="rounded-lg border bg-muted/40 p-3 text-sm text-muted-foreground">
                            This {kind} already has repayments or was due in an
                            approved payroll month: only the installment and
                            note can change.
                        </p>
                    )}

                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field
                            id="loan-amount"
                            label="Amount"
                            error={errors.amount}
                        >
                            <MoneyInput
                                {...fieldAria('loan-amount', errors.amount)}
                                value={data.amount}
                                disabled={coreLocked}
                                onChange={(v) => form.setData('amount', v)}
                            />
                        </Field>
                        <Field
                            id="loan-installment"
                            label="Monthly installment"
                            hint={
                                data.type === 'advance'
                                    ? 'Blank: the whole amount in one month.'
                                    : undefined
                            }
                            error={errors.installment}
                        >
                            <MoneyInput
                                {...fieldAria(
                                    'loan-installment',
                                    errors.installment,
                                    data.type === 'advance',
                                )}
                                value={data.installment}
                                onChange={(v) => form.setData('installment', v)}
                            />
                        </Field>
                        <Field
                            id="loan-start"
                            label="First deduction month"
                            hint={
                                firstOpen
                                    ? `${monthLabel(firstOpen)} or later.`
                                    : undefined
                            }
                            error={errors.start_month}
                        >
                            <Input
                                {...fieldAria(
                                    'loan-start',
                                    errors.start_month,
                                    firstOpen !== null,
                                )}
                                type="month"
                                min={firstOpen ?? undefined}
                                value={data.start_month}
                                disabled={coreLocked}
                                onChange={(e) =>
                                    form.setData('start_month', e.target.value)
                                }
                            />
                        </Field>
                        <Field
                            id="loan-repaid"
                            label="Repaid before go-live"
                            hint="Only for a loan already running when the app started."
                            error={errors.opening_repaid}
                        >
                            <MoneyInput
                                {...fieldAria(
                                    'loan-repaid',
                                    errors.opening_repaid,
                                    true,
                                )}
                                value={data.opening_repaid}
                                disabled={coreLocked}
                                placeholder="0"
                                onChange={(v) =>
                                    form.setData('opening_repaid', v)
                                }
                            />
                        </Field>
                    </div>

                    <p
                        className="text-sm text-muted-foreground"
                        aria-live="polite"
                    >
                        To recover:{' '}
                        <span className="font-medium text-foreground tabular-nums">
                            {remaining === null ? '—' : formatMoney(remaining)}
                        </span>
                    </p>

                    <Field id="loan-note" label="Note" error={errors.note}>
                        <Textarea
                            {...fieldAria('loan-note', errors.note)}
                            value={data.note}
                            maxLength={255}
                            rows={2}
                            onChange={(e) =>
                                form.setData('note', e.target.value)
                            }
                        />
                    </Field>

                    {'loan' in errors && (
                        <p role="alert" className="text-sm text-destructive">
                            {(errors as Record<string, string>).loan}
                        </p>
                    )}

                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={onClose}
                            disabled={form.processing}
                        >
                            Cancel
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            {form.processing && <Spinner />}
                            {editing ? 'Save changes' : `Add ${kind}`}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
