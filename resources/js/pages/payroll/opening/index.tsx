import { Head, Link, router, useForm } from '@inertiajs/react';
import { CalendarDays, FileSpreadsheet, HandCoins, Pencil } from 'lucide-react';
import { useMemo, useState } from 'react';
import type { FormEvent, ReactNode } from 'react';
import OpeningFiguresController from '@/actions/App/Http/Controllers/Payroll/OpeningFiguresController';
import { PageHeader } from '@/components/hrm/page-header';
import { StatusPill } from '@/components/hrm/status-pill';
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
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { formatMoney, rupeesText } from '@/lib/payroll';
import { importMethod as leaveImport } from '@/routes/leave/balances';
import { importMethod, index } from '@/routes/payroll/opening';

type Row = {
    employee: { id: number; name: string; code: string };
    /** Paisa. */
    taxable_income: number;
    tax_deducted: number;
    note: string | null;
    saved: boolean;
    can_edit: boolean;
};

type Props = {
    tax_year: number;
    tax_year_label: string;
    years: { value: number; label: string }[];
    rows: Row[];
    totals: { people: number; taxable_income: number; tax_deducted: number };
    can: { import: boolean; leave_import: boolean };
};

function LinkCard({
    icon,
    title,
    text,
    action,
}: {
    icon: ReactNode;
    title: string;
    text: string;
    action: ReactNode;
}) {
    return (
        <div className="flex flex-col gap-3 rounded-xl border bg-card p-4 shadow-xs">
            <div className="flex items-start gap-3">
                <span
                    aria-hidden="true"
                    className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary"
                >
                    {icon}
                </span>
                <div className="min-w-0">
                    <h2 className="font-medium">{title}</h2>
                    <p className="text-sm text-muted-foreground">{text}</p>
                </div>
            </div>
            <div className="mt-auto">{action}</div>
        </div>
    );
}

function EditDialog({
    row,
    taxYear,
    label,
    onClose,
}: {
    row: Row;
    taxYear: number;
    label: string;
    onClose: () => void;
}) {
    const form = useForm({
        taxable_income: rupeesText(row.taxable_income),
        tax_deducted: rupeesText(row.tax_deducted),
        note: row.note ?? '',
    });
    const { data, errors } = form;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.submit(
            OpeningFiguresController.update({
                employee: row.employee.id,
                taxYear,
            }),
            { preserveScroll: true, onSuccess: () => onClose() },
        );
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
                            {row.employee.name} · {label}
                        </DialogTitle>
                        <DialogDescription>
                            What was paid and deducted in this tax year before
                            the app. The figures replace what is stored.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field
                            id="opening-income"
                            label="Taxable income to date"
                            error={errors.taxable_income}
                        >
                            <MoneyInput
                                {...fieldAria(
                                    'opening-income',
                                    errors.taxable_income,
                                )}
                                value={data.taxable_income}
                                onChange={(v) =>
                                    form.setData('taxable_income', v)
                                }
                            />
                        </Field>
                        <Field
                            id="opening-tax"
                            label="Tax deducted to date"
                            error={errors.tax_deducted}
                        >
                            <MoneyInput
                                {...fieldAria(
                                    'opening-tax',
                                    errors.tax_deducted,
                                )}
                                value={data.tax_deducted}
                                onChange={(v) =>
                                    form.setData('tax_deducted', v)
                                }
                            />
                        </Field>
                    </div>
                    <Field id="opening-note" label="Note" error={errors.note}>
                        <Textarea
                            {...fieldAria('opening-note', errors.note)}
                            value={data.note}
                            maxLength={255}
                            rows={2}
                            onChange={(e) =>
                                form.setData('note', e.target.value)
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
                            Cancel
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            {form.processing && <Spinner />}
                            Save
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export default function OpeningFigures({
    tax_year,
    tax_year_label,
    years,
    rows,
    totals,
    can,
}: Props) {
    const [editing, setEditing] = useState<Row | null>(null);
    const [search, setSearch] = useState('');

    const shown = useMemo(() => {
        const term = search.trim().toLowerCase();

        return term === ''
            ? rows
            : rows.filter(
                  (row) =>
                      row.employee.name.toLowerCase().includes(term) ||
                      row.employee.code.toLowerCase().includes(term),
              );
    }, [rows, search]);

    return (
        <>
            <Head title="Opening Figures" />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title="Opening Figures"
                    subtitle="What happened before the app went live: tax to date, loans in progress and leave used."
                    actions={
                        <div className="grid gap-1.5">
                            <Label htmlFor="opening-year" className="sr-only">
                                Tax year
                            </Label>
                            <Select
                                value={String(tax_year)}
                                onValueChange={(value) =>
                                    router.get(
                                        index.url({
                                            query: { tax_year: value },
                                        }),
                                        {},
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                <SelectTrigger
                                    id="opening-year"
                                    className="w-40"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {years.map((year) => (
                                        <SelectItem
                                            key={year.value}
                                            value={String(year.value)}
                                        >
                                            Tax year {year.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                    }
                />

                <div className="grid gap-3 md:grid-cols-3">
                    {can.import && (
                        <LinkCard
                            icon={<FileSpreadsheet className="size-5" />}
                            title="Import opening tax"
                            text="Taxable income and tax deducted to date for many people at once, from Excel."
                            action={
                                <Button asChild variant="outline" size="sm">
                                    <Link href={importMethod('tax')}>
                                        Import tax figures
                                    </Link>
                                </Button>
                            }
                        />
                    )}
                    {can.import && (
                        <LinkCard
                            icon={<HandCoins className="size-5" />}
                            title="Import opening loans"
                            text="Loans and advances already running, with what was repaid before go-live."
                            action={
                                <Button asChild variant="outline" size="sm">
                                    <Link href={importMethod('loans')}>
                                        Import loans
                                    </Link>
                                </Button>
                            }
                        />
                    )}
                    {can.leave_import && (
                        <LinkCard
                            icon={<CalendarDays className="size-5" />}
                            title="Leave used this year"
                            text="Imported in Leave > Balances: the days each person used before the app."
                            action={
                                <Button asChild variant="outline" size="sm">
                                    <Link href={leaveImport()}>
                                        Import leave used
                                    </Link>
                                </Button>
                            }
                        />
                    )}
                </div>

                <dl className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                    <div className="rounded-xl border bg-card px-4 py-3 shadow-xs">
                        <dt className="text-sm text-muted-foreground">
                            People with figures
                        </dt>
                        <dd className="text-2xl font-semibold tabular-nums">
                            {totals.people.toLocaleString()}
                        </dd>
                    </div>
                    <div className="rounded-xl border bg-card px-4 py-3 shadow-xs">
                        <dt className="text-sm text-muted-foreground">
                            Taxable income to date
                        </dt>
                        <dd className="text-2xl font-semibold tabular-nums">
                            {formatMoney(totals.taxable_income)}
                        </dd>
                    </div>
                    <div className="rounded-xl border bg-card px-4 py-3 shadow-xs">
                        <dt className="text-sm text-muted-foreground">
                            Tax deducted to date
                        </dt>
                        <dd className="text-2xl font-semibold tabular-nums">
                            {formatMoney(totals.tax_deducted)}
                        </dd>
                    </div>
                </dl>

                <div className="overflow-hidden rounded-xl border bg-card shadow-xs">
                    <div className="border-b p-3 sm:p-4">
                        <Label htmlFor="opening-search" className="sr-only">
                            Search people
                        </Label>
                        <Input
                            id="opening-search"
                            type="search"
                            placeholder="Search by name or code"
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            className="sm:max-w-xs"
                        />
                    </div>
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[40rem] text-sm">
                            <caption className="sr-only">
                                Opening tax figures for tax year{' '}
                                {tax_year_label}
                            </caption>
                            <thead className="bg-muted/50 text-left text-xs font-medium text-muted-foreground uppercase">
                                <tr>
                                    <th scope="col" className="px-4 py-2">
                                        Employee
                                    </th>
                                    <th
                                        scope="col"
                                        className="px-4 py-2 text-right"
                                    >
                                        Taxable income to date
                                    </th>
                                    <th
                                        scope="col"
                                        className="px-4 py-2 text-right"
                                    >
                                        Tax deducted to date
                                    </th>
                                    <th scope="col" className="px-4 py-2">
                                        <span className="sr-only">Actions</span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {shown.length === 0 && (
                                    <tr>
                                        <td
                                            colSpan={4}
                                            className="px-4 py-8 text-center text-muted-foreground"
                                        >
                                            No one matches.
                                        </td>
                                    </tr>
                                )}
                                {shown.map((row) => (
                                    <tr key={row.employee.id}>
                                        <td className="px-4 py-2">
                                            <p className="font-medium">
                                                {row.employee.name}
                                            </p>
                                            <p className="text-xs text-muted-foreground">
                                                {row.employee.code}
                                            </p>
                                        </td>
                                        <td className="px-4 py-2 text-right tabular-nums">
                                            {row.saved
                                                ? formatMoney(
                                                      row.taxable_income,
                                                  )
                                                : '—'}
                                        </td>
                                        <td className="px-4 py-2 text-right tabular-nums">
                                            {row.saved
                                                ? formatMoney(row.tax_deducted)
                                                : '—'}
                                        </td>
                                        <td className="px-4 py-2 text-right">
                                            {row.can_edit ? (
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    onClick={() =>
                                                        setEditing(row)
                                                    }
                                                    aria-label={`Edit opening figures of ${row.employee.name}`}
                                                >
                                                    <Pencil />
                                                    Edit
                                                </Button>
                                            ) : (
                                                !row.saved && (
                                                    <StatusPill tone="grey">
                                                        Not set
                                                    </StatusPill>
                                                )
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            {editing && (
                <EditDialog
                    row={editing}
                    taxYear={tax_year}
                    label={tax_year_label}
                    onClose={() => setEditing(null)}
                />
            )}
        </>
    );
}

OpeningFigures.layout = {
    breadcrumbs: [{ title: 'Opening Figures', href: index() }],
};
