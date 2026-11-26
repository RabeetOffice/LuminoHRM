import { useForm } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import type { FormEvent } from 'react';
import SalaryProfileController from '@/actions/App/Http/Controllers/Payroll/SalaryProfileController';
import { MoneyInput } from '@/components/payroll/money-input';
import { TaxFields } from '@/components/payroll/tax-fields';
import type { TaxDraft } from '@/components/payroll/tax-fields';
import { Field, fieldAria } from '@/components/settings/settings-form';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import {
    bpToPercent,
    formatMoney,
    parseRupees,
    rupeesText,
    salaryTotals,
} from '@/lib/payroll';
import type { ComponentDraft, TaxBase, TaxMode } from '@/lib/payroll';

/** One allowance or fixed deduction as the server sends it (paisa). */
export type SalaryComponentRow = {
    name: string;
    type: 'allowance' | 'deduction';
    amount: number;
    is_taxable: boolean;
};

/** What the form starts from: an existing profile (edit) or the defaults (new). */
export type SalaryFormStart = {
    id?: number;
    effective_from: string;
    basic: number | null;
    tax_mode: TaxMode;
    /** Paisa (fixed) or basis points (percentage). */
    tax_value: number;
    tax_base: TaxBase | null;
    note?: string | null;
    components: SalaryComponentRow[];
};

type Props = {
    employee: { id: number; name: string; joining_date: string };
    start: SalaryFormStart;
    /** True when `start` is an existing profile to change. */
    editing: boolean;
    maxComponents?: number;
    onClose: () => void;
};

type FormData = TaxDraft & {
    effective_from: string;
    basic: string;
    note: string;
    components: ComponentDraft[];
};

/**
 * Typed whole rupees as the number the server takes, or the text as typed (the server explains what is wrong). A
 * well-formed amount above the limit goes as a number too (grouping stripped), so the server's limit message shows
 * rather than "Enter ... in whole rupees".
 */
function rupeesOut(text: string): number | string {
    const parsed = parseRupees(text);

    if (parsed !== null) {
        return parsed;
    }

    const value = text.trim();
    const wellFormed = /^(\d{1,3}(,\d{3})*|\d+)$/.test(value);
    const stripped = Number(value.replace(/,/g, ''));

    return wellFormed && Number.isSafeInteger(stripped) ? stripped : value;
}

/**
 * The salary form in a dialog: effective date, basic, allowances (taxable or
 * not), fixed deductions, tax and a note, with live totals. A new salary is a
 * new row from its date (history is kept); editing changes a row no approved
 * payroll has used. Mount it only while open.
 */
export function SalaryProfileForm({
    employee,
    start,
    editing,
    maxComponents = 20,
    onClose,
}: Props) {
    const form = useForm<FormData>({
        effective_from: start.effective_from,
        basic: start.basic === null ? '' : rupeesText(start.basic),
        tax_mode: start.tax_mode,
        tax_value_rupees:
            start.tax_mode === 'fixed' ? rupeesText(start.tax_value) : '',
        tax_percent:
            start.tax_mode === 'percentage' ? bpToPercent(start.tax_value) : '',
        tax_base: start.tax_base ?? 'gross_taxable',
        note: editing ? (start.note ?? '') : '',
        components: start.components.map((c) => ({
            name: c.name,
            type: c.type,
            amount: rupeesText(c.amount),
            is_taxable: c.is_taxable,
        })),
    });
    const { data, errors } = form;
    const errorFor = (key: string): string | undefined =>
        (errors as Record<string, string | undefined>)[key];
    const totals = salaryTotals(data.basic, data.components);
    const full = data.components.length >= maxComponents;

    const setComponent = (index: number, patch: Partial<ComponentDraft>) =>
        form.setData(
            'components',
            data.components.map((c, i) =>
                i === index ? { ...c, ...patch } : c,
            ),
        );
    const addComponent = (type: ComponentDraft['type']) =>
        form.setData('components', [
            ...data.components,
            { name: '', type, amount: '', is_taxable: type === 'allowance' },
        ]);
    const removeComponent = (index: number) =>
        form.setData(
            'components',
            data.components.filter((_, i) => i !== index),
        );

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((d) => ({
            effective_from: d.effective_from,
            basic: rupeesOut(d.basic),
            tax_mode: d.tax_mode,
            tax_value_rupees:
                d.tax_mode === 'fixed' ? rupeesOut(d.tax_value_rupees) : null,
            tax_percent:
                d.tax_mode === 'percentage' ? d.tax_percent.trim() : null,
            tax_base: d.tax_mode === 'percentage' ? d.tax_base : null,
            note: d.note,
            components: d.components.map((c) => ({
                name: c.name,
                type: c.type,
                amount: rupeesOut(c.amount),
                is_taxable: c.type === 'allowance' && c.is_taxable,
            })),
        }));

        const options = { preserveScroll: true, onSuccess: () => onClose() };

        if (editing && start.id !== undefined) {
            form.submit(
                SalaryProfileController.update({
                    employee: employee.id,
                    profile: start.id,
                }),
                options,
            );
        } else {
            form.submit(SalaryProfileController.store(employee.id), options);
        }
    };

    const section = (type: ComponentDraft['type']) => {
        const rows = data.components
            .map((component, index) => ({ component, index }))
            .filter(({ component }) => component.type === type);
        const allowance = type === 'allowance';

        return (
            <fieldset className="grid gap-2">
                <legend className="mb-1 text-sm font-medium">
                    {allowance ? 'Allowances' : 'Fixed deductions'}
                </legend>
                {rows.length === 0 && (
                    <p className="text-sm text-muted-foreground">
                        {allowance
                            ? 'No allowances.'
                            : 'No fixed deductions (for example a provident fund).'}
                    </p>
                )}
                {rows.map(({ component, index }) => {
                    const nameId = `salary-c${index}-name`;
                    const amountId = `salary-c${index}-amount`;
                    const taxableId = `salary-c${index}-taxable`;
                    const nameError = errorFor(`components.${index}.name`);
                    const amountError = errorFor(`components.${index}.amount`);

                    return (
                        <div
                            key={index}
                            className="grid grid-cols-[minmax(0,1fr)_auto] gap-2 rounded-lg border p-2 sm:grid-cols-[minmax(0,1fr)_9rem_auto_auto] sm:items-start sm:border-0 sm:p-0"
                        >
                            <div className="col-span-2 grid gap-1 sm:col-span-1">
                                <label htmlFor={nameId} className="sr-only">
                                    {allowance ? 'Allowance' : 'Deduction'} name
                                </label>
                                <Input
                                    {...fieldAria(nameId, nameError)}
                                    placeholder={
                                        allowance
                                            ? 'House rent'
                                            : 'Provident fund'
                                    }
                                    maxLength={60}
                                    value={component.name}
                                    onChange={(e) =>
                                        setComponent(index, {
                                            name: e.target.value,
                                        })
                                    }
                                />
                                {nameError && (
                                    <p
                                        id={`${nameId}-error`}
                                        role="alert"
                                        className="text-xs text-destructive"
                                    >
                                        {nameError}
                                    </p>
                                )}
                            </div>
                            <div className="grid gap-1">
                                <label htmlFor={amountId} className="sr-only">
                                    Amount a month
                                </label>
                                <MoneyInput
                                    {...fieldAria(amountId, amountError)}
                                    value={component.amount}
                                    onChange={(v) =>
                                        setComponent(index, { amount: v })
                                    }
                                />
                                {amountError && (
                                    <p
                                        id={`${amountId}-error`}
                                        role="alert"
                                        className="text-xs text-destructive"
                                    >
                                        {amountError}
                                    </p>
                                )}
                            </div>
                            {allowance ? (
                                <div className="flex h-9 items-center gap-2">
                                    <Checkbox
                                        id={taxableId}
                                        checked={component.is_taxable}
                                        onCheckedChange={(v) =>
                                            setComponent(index, {
                                                is_taxable: v === true,
                                            })
                                        }
                                    />
                                    <label
                                        htmlFor={taxableId}
                                        className="text-sm"
                                    >
                                        Taxable
                                    </label>
                                </div>
                            ) : (
                                <span className="hidden sm:block" />
                            )}
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                className="justify-self-end"
                                aria-label={`Remove ${component.name || (allowance ? 'this allowance' : 'this deduction')}`}
                                onClick={() => removeComponent(index)}
                            >
                                <Trash2 />
                            </Button>
                        </div>
                    );
                })}
                <div>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        disabled={full}
                        onClick={() => addComponent(type)}
                    >
                        <Plus />
                        {allowance ? 'Add allowance' : 'Add deduction'}
                    </Button>
                </div>
            </fieldset>
        );
    };

    return (
        <Dialog
            open
            onOpenChange={(open) => !open && !form.processing && onClose()}
        >
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                <form onSubmit={submit} className="grid gap-5" noValidate>
                    <DialogHeader>
                        <DialogTitle>
                            {editing ? 'Edit salary' : 'New salary'} ·{' '}
                            {employee.name}
                        </DialogTitle>
                        <DialogDescription>
                            {editing
                                ? 'Changes this salary row. Payroll already approved never changes.'
                                : 'A change of salary is a new row from its effective date; earlier salaries are kept.'}{' '}
                            Amounts are whole rupees a month.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field
                            id="salary-effective"
                            label="Effective from"
                            error={errors.effective_from}
                        >
                            <Input
                                {...fieldAria(
                                    'salary-effective',
                                    errors.effective_from,
                                )}
                                type="date"
                                min={employee.joining_date}
                                value={data.effective_from}
                                onChange={(e) =>
                                    form.setData(
                                        'effective_from',
                                        e.target.value,
                                    )
                                }
                            />
                        </Field>
                        <Field
                            id="salary-basic"
                            label="Basic salary a month"
                            error={errors.basic}
                        >
                            <MoneyInput
                                {...fieldAria('salary-basic', errors.basic)}
                                value={data.basic}
                                onChange={(v) => form.setData('basic', v)}
                            />
                        </Field>
                    </div>

                    {section('allowance')}
                    {section('deduction')}
                    {(errorFor('components') || full) && (
                        <p
                            role={errorFor('components') ? 'alert' : undefined}
                            className={
                                errorFor('components')
                                    ? 'text-sm text-destructive'
                                    : 'text-xs text-muted-foreground'
                            }
                        >
                            {errorFor('components') ??
                                `At most ${maxComponents} allowances and deductions.`}
                        </p>
                    )}

                    <TaxFields
                        idPrefix="salary"
                        value={data}
                        errors={errors}
                        onChange={(key, value) =>
                            form.setData({ ...data, [key]: value })
                        }
                    />

                    <Field
                        id="salary-note"
                        label="Note (optional)"
                        error={errors.note}
                    >
                        <Textarea
                            {...fieldAria('salary-note', errors.note)}
                            rows={2}
                            maxLength={255}
                            value={data.note}
                            onChange={(e) =>
                                form.setData('note', e.target.value)
                            }
                        />
                    </Field>

                    <p
                        aria-live="polite"
                        className="rounded-lg border bg-muted/40 p-3 text-sm font-medium tabular-nums"
                    >
                        Gross {formatMoney(totals.gross)} · Taxable{' '}
                        {formatMoney(totals.taxable)} · Deductions{' '}
                        {formatMoney(totals.deductions)}
                    </p>

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
                            {editing ? 'Save changes' : 'Save salary'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
