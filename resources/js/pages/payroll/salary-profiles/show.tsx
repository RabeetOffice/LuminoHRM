import { Head, Link, router, usePage } from '@inertiajs/react';
import { ArrowLeft, Pencil, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import SalaryProfileController from '@/actions/App/Http/Controllers/Payroll/SalaryProfileController';
import { ConfirmDialog } from '@/components/hrm/confirm-dialog';
import { PageHeader } from '@/components/hrm/page-header';
import { StatusPill } from '@/components/hrm/status-pill';
import { SalaryProfileForm } from '@/components/payroll/salary-profile-form';
import type {
    SalaryComponentRow,
    SalaryFormStart,
} from '@/components/payroll/salary-profile-form';
import { Button } from '@/components/ui/button';
import { formatDate } from '@/lib/format-date';
import { firstOpenDate, formatMoney, monthLabel } from '@/lib/payroll';
import type { TaxBase, TaxMode } from '@/lib/payroll';
import { show as employeeShow } from '@/routes/employees';
import { index, show } from '@/routes/payroll/salary-profiles';

/** One salary row as the server sends it. Money in paisa; tax_value in paisa (fixed) or basis points (percentage). */
export type SalaryHistoryRow = {
    id: number;
    effective_from: string;
    basic: number;
    gross: number;
    taxable_gross: number;
    deductions: number;
    tax_mode: TaxMode;
    tax_value: number;
    tax_base: TaxBase | null;
    /** "5% of gross taxable", "Rs 2,000 fixed", "None". */
    tax: string;
    note: string | null;
    components: SalaryComponentRow[];
    current: boolean;
    upcoming: boolean;
    added_by: string | null;
    can_edit: boolean;
};

type Props = {
    employee: {
        id: number;
        code: string;
        name: string;
        department: string | null;
        designation: string | null;
        joining_date: string;
        status: string;
    };
    history: SalaryHistoryRow[];
    defaults: Omit<SalaryFormStart, 'effective_from' | 'id' | 'note'>;
    /** "2026-11": salaries starting in or before it cannot change. */
    latest_locked: string | null;
    today: string;
    can_edit: boolean;
};

type Editing = { mode: 'new' } | { mode: 'edit'; row: SalaryHistoryRow } | null;

export default function SalaryProfileShow({
    employee,
    history,
    defaults,
    latest_locked,
    today,
    can_edit,
}: Props) {
    const { props } = usePage();
    const dateFormat = props.app.dateFormat;
    const [editing, setEditing] = useState<Editing>(null);
    const [deleting, setDeleting] = useState<SalaryHistoryRow | null>(null);
    const [processing, setProcessing] = useState(false);
    // A refused delete's message, kept apart from the form dialog's own errors so a failed save does not reappear
    // on the page after Cancel.
    const [deleteError, setDeleteError] = useState<string | null>(null);

    const remove = () => {
        if (!deleting) {
            return;
        }

        router.delete(
            SalaryProfileController.destroy({
                employee: employee.id,
                profile: deleting.id,
            }).url,
            {
                preserveScroll: true,
                onStart: () => {
                    setProcessing(true);
                    setDeleteError(null);
                },
                onError: (errors) =>
                    setDeleteError(
                        errors.effective_from ??
                            Object.values(errors)[0] ??
                            null,
                    ),
                onFinish: () => {
                    setProcessing(false);
                    setDeleting(null);
                },
            },
        );
    };

    return (
        <>
            <Head title={`Salary · ${employee.name}`} />
            <div className="flex min-w-0 flex-1 flex-col gap-6 p-4 md:p-6">
                <div>
                    <Link
                        href={index()}
                        className="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
                    >
                        <ArrowLeft aria-hidden="true" className="size-4" />
                        Salary Profiles
                    </Link>
                </div>
                <PageHeader
                    title={employee.name}
                    subtitle={[
                        employee.code,
                        employee.designation,
                        employee.department,
                        `Joined ${formatDate(employee.joining_date, dateFormat)}`,
                    ]
                        .filter(Boolean)
                        .join(' · ')}
                    actions={
                        <div className="flex flex-wrap gap-2">
                            <Button asChild variant="outline">
                                <Link href={employeeShow(employee.id)}>
                                    Open profile
                                </Link>
                            </Button>
                            {can_edit && (
                                <Button
                                    onClick={() => setEditing({ mode: 'new' })}
                                >
                                    <Plus />
                                    New salary
                                </Button>
                            )}
                        </div>
                    }
                />

                {!can_edit && (
                    <p className="rounded-lg border bg-muted/40 p-3 text-sm text-muted-foreground">
                        You can see this salary but not change it: your own
                        salary, and an Admin's or HR person's, are changed by an
                        Admin.
                    </p>
                )}
                {deleteError && (
                    <p
                        role="alert"
                        className="rounded-lg border border-red-200 bg-red-50/60 p-3 text-sm text-destructive dark:border-red-500/30 dark:bg-red-500/10"
                    >
                        {deleteError}
                    </p>
                )}
                {latest_locked && (
                    <p className="text-xs text-muted-foreground">
                        Payroll up to {monthLabel(latest_locked)} is approved:
                        salaries starting in or before it can no longer change.
                        Add a new salary instead.
                    </p>
                )}

                {history.length === 0 ? (
                    <div className="rounded-xl border border-dashed p-8 text-center">
                        <StatusPill tone="amber">No salary</StatusPill>
                        <p className="mt-3 text-sm text-muted-foreground">
                            {employee.name} has no salary yet, so payroll cannot
                            pay them.
                        </p>
                        {can_edit && (
                            <Button
                                className="mt-4"
                                onClick={() => setEditing({ mode: 'new' })}
                            >
                                <Plus />
                                Add salary
                            </Button>
                        )}
                    </div>
                ) : (
                    <ol role="list" className="grid gap-4">
                        {history.map((row) => (
                            <li key={row.id}>
                                <SalaryCard
                                    row={row}
                                    date={formatDate(
                                        row.effective_from,
                                        dateFormat,
                                    )}
                                    onEdit={() =>
                                        setEditing({ mode: 'edit', row })
                                    }
                                    onDelete={() => setDeleting(row)}
                                />
                            </li>
                        ))}
                    </ol>
                )}
            </div>

            {editing && (
                <SalaryProfileForm
                    employee={employee}
                    editing={editing.mode === 'edit'}
                    start={
                        editing.mode === 'edit'
                            ? editing.row
                            : {
                                  ...defaults,
                                  effective_from: firstOpenDate(
                                      today,
                                      latest_locked,
                                      employee.joining_date,
                                  ),
                              }
                    }
                    onClose={() => setEditing(null)}
                />
            )}

            <ConfirmDialog
                open={deleting !== null}
                onOpenChange={(open) => !open && setDeleting(null)}
                title="Delete this salary?"
                description={
                    deleting
                        ? `The salary from ${formatDate(deleting.effective_from, dateFormat)} (basic ${formatMoney(deleting.basic)}) is removed. The audit log keeps its figures.`
                        : ''
                }
                confirmLabel="Delete salary"
                onConfirm={remove}
                processing={processing}
            />
        </>
    );
}

/** One salary row: date, figures, components, tax and note, with edit and delete while no approved payroll used it. */
function SalaryCard({
    row,
    date,
    onEdit,
    onDelete,
}: {
    row: SalaryHistoryRow;
    date: string;
    onEdit: () => void;
    onDelete: () => void;
}) {
    const allowances = row.components.filter((c) => c.type === 'allowance');
    const deductions = row.components.filter((c) => c.type === 'deduction');
    const figures: [string, string][] = [
        ['Basic', formatMoney(row.basic)],
        ['Gross', formatMoney(row.gross)],
        ['Taxable', formatMoney(row.taxable_gross)],
        ['Fixed deductions', formatMoney(row.deductions)],
    ];

    return (
        <section
            aria-label={`Salary from ${date}`}
            className="rounded-xl border bg-card p-4 shadow-xs sm:p-6"
        >
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="flex flex-wrap items-center gap-2">
                    <h2 className="text-base font-semibold">From {date}</h2>
                    {row.current && (
                        <StatusPill tone="green">Current</StatusPill>
                    )}
                    {row.upcoming && (
                        <StatusPill tone="blue">Upcoming</StatusPill>
                    )}
                </div>
                {row.can_edit && (
                    <div className="flex gap-1">
                        <Button variant="ghost" size="sm" onClick={onEdit}>
                            <Pencil />
                            Edit
                        </Button>
                        <Button
                            variant="ghost"
                            size="sm"
                            className="text-destructive hover:text-destructive"
                            onClick={onDelete}
                        >
                            <Trash2 />
                            Delete
                        </Button>
                    </div>
                )}
            </div>

            <dl className="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
                {figures.map(([label, value]) => (
                    <div key={label} className="rounded-lg border p-3">
                        <dt className="text-xs text-muted-foreground">
                            {label}
                        </dt>
                        <dd className="mt-0.5 font-semibold tabular-nums">
                            {value}
                        </dd>
                    </div>
                ))}
            </dl>

            <div className="mt-4 grid gap-4 text-sm sm:grid-cols-2">
                <ComponentList title="Allowances" items={allowances} />
                <ComponentList title="Fixed deductions" items={deductions} />
            </div>

            <p className="mt-4 text-sm">
                <span className="text-muted-foreground">Tax: </span>
                {row.tax}
            </p>
            {row.note && (
                <p className="mt-1 text-sm break-words text-muted-foreground">
                    {row.note}
                </p>
            )}
            {row.added_by && (
                <p className="mt-1 text-xs text-muted-foreground">
                    Added by {row.added_by}
                </p>
            )}
        </section>
    );
}

function ComponentList({
    title,
    items,
}: {
    title: string;
    items: SalaryComponentRow[];
}) {
    return (
        <div>
            <h3 className="mb-1.5 text-xs font-medium text-muted-foreground">
                {title}
            </h3>
            {items.length === 0 ? (
                <p className="text-muted-foreground">None</p>
            ) : (
                <ul role="list" className="divide-y rounded-lg border">
                    {items.map((item, i) => (
                        <li
                            key={i}
                            className="flex items-center justify-between gap-3 px-3 py-2"
                        >
                            <span className="min-w-0 truncate">
                                {item.name}
                                {item.type === 'allowance' &&
                                    !item.is_taxable && (
                                        <span className="ml-1.5 text-xs text-muted-foreground">
                                            not taxable
                                        </span>
                                    )}
                            </span>
                            <span className="tabular-nums">
                                {formatMoney(item.amount)}
                            </span>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

SalaryProfileShow.layout = (props: Props) => ({
    breadcrumbs: [
        { title: 'Salary Profiles', href: index() },
        { title: props.employee.name, href: show(props.employee.id) },
    ],
});
