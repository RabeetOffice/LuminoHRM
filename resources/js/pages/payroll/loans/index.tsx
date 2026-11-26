import { Head, Link, router, usePage } from '@inertiajs/react';
import { FileSpreadsheet, Plus } from 'lucide-react';
import { useState } from 'react';
import { DataTable } from '@/components/hrm/data-table';
import type { DataTableColumn } from '@/components/hrm/data-table';
import { PageHeader } from '@/components/hrm/page-header';
import { StatusPill } from '@/components/hrm/status-pill';
import { StatusTabs } from '@/components/hrm/status-tabs';
import { LoanDialog } from '@/components/payroll/loan-dialog';
import type { LoanPerson } from '@/components/payroll/loan-dialog';
import { Button } from '@/components/ui/button';
import { useCan } from '@/hooks/use-can';
import {
    formatMoney,
    loanStatusPill,
    loanTypeLabel,
    shortMonthLabel,
} from '@/lib/payroll';
import type { LoanStatus, LoanType } from '@/lib/payroll';
import { statusPanelId, statusTabId } from '@/lib/tabs';
import { nextTableUrl } from '@/lib/table-query';
import { index, show } from '@/routes/payroll/loans';
import { importMethod } from '@/routes/payroll/opening';

type Tab = 'active' | 'cleared' | 'cancelled' | 'all';

export type LoanRow = {
    id: number;
    employee: LoanPerson;
    type: LoanType;
    /** Paisa. */
    amount: number;
    installment: number;
    start_month: string;
    opening_repaid: number;
    repaid: number;
    remaining: number;
    /** The next month payroll deducts it, or null when nothing is due. */
    next_month: string | null;
    status: LoanStatus;
};

export type LoanMonths = {
    current: string;
    first_open: string | null;
    latest_locked: string | null;
};

type Props = {
    loans: LoanRow[];
    counts: Record<Tab, number>;
    filters: { status: Tab; search: string };
    employees: LoanPerson[];
    months: LoanMonths;
};

const TAB_LABELS: Record<Tab, string> = {
    active: 'Active',
    cleared: 'Cleared',
    cancelled: 'Cancelled',
    all: 'All',
};

const ID_PREFIX = 'loans';

export default function LoansIndex({
    loans,
    counts,
    filters,
    employees,
    months,
}: Props) {
    const { url } = usePage();
    const can = useCan();
    const [adding, setAdding] = useState(false);
    const mayRun = can('payroll.run');

    const visit = (changes: Parameters<typeof nextTableUrl>[1]) =>
        router.get(
            nextTableUrl(url, changes),
            {},
            { preserveScroll: true, preserveState: true, replace: true },
        );

    const defaultMonth =
        months.first_open !== null && months.first_open > months.current
            ? months.first_open
            : months.current;

    const columns: DataTableColumn<LoanRow>[] = [
        {
            key: 'employee',
            header: 'Employee',
            cell: (row) => (
                <Link
                    href={show(row.id)}
                    className="block rounded-sm outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
                >
                    <span className="block font-medium whitespace-nowrap hover:underline">
                        {row.employee.name}
                    </span>
                    <span className="block text-xs text-muted-foreground">
                        {row.employee.code}
                    </span>
                    {/* The columns hidden below xl (a laptop with the sidebar open has about 650px), stacked under the name. */}
                    <span className="block text-xs text-muted-foreground xl:hidden">
                        {loanTypeLabel(row.type)} · {formatMoney(row.amount)} ·{' '}
                        {formatMoney(row.installment)} a month
                    </span>
                    <span className="block text-xs text-muted-foreground xl:hidden">
                        Starts {shortMonthLabel(row.start_month)} · repaid{' '}
                        {formatMoney(row.repaid)} · next{' '}
                        {row.next_month ? shortMonthLabel(row.next_month) : '—'}
                    </span>
                </Link>
            ),
        },
        {
            key: 'type',
            header: 'Type',
            className: 'hidden xl:table-cell',
            cell: (row) => loanTypeLabel(row.type),
        },
        {
            key: 'amount',
            header: 'Amount',
            className:
                'hidden text-right xl:table-cell tabular-nums whitespace-nowrap',
            cell: (row) => formatMoney(row.amount),
        },
        {
            key: 'installment',
            header: 'Installment',
            className:
                'hidden text-right xl:table-cell tabular-nums whitespace-nowrap',
            cell: (row) => formatMoney(row.installment),
        },
        {
            key: 'start',
            header: 'Starts',
            className: 'hidden whitespace-nowrap xl:table-cell',
            cell: (row) => shortMonthLabel(row.start_month),
        },
        {
            key: 'repaid',
            header: 'Repaid',
            className:
                'hidden text-right xl:table-cell tabular-nums whitespace-nowrap',
            cell: (row) => formatMoney(row.repaid),
        },
        {
            key: 'remaining',
            header: 'Remaining',
            className: 'text-right font-medium tabular-nums whitespace-nowrap',
            cell: (row) => formatMoney(row.remaining),
        },
        {
            key: 'next',
            header: 'Next deduction',
            className: 'hidden whitespace-nowrap xl:table-cell',
            cell: (row) =>
                row.next_month ? shortMonthLabel(row.next_month) : '—',
        },
        {
            key: 'status',
            header: 'Status',
            cell: (row) => {
                const pill = loanStatusPill(row.status);

                return <StatusPill tone={pill.tone}>{pill.label}</StatusPill>;
            },
        },
    ];

    return (
        <>
            <Head title="Loans & Advances" />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title="Loans & Advances"
                    subtitle="Paid out outside the app and recovered through payroll, month by month."
                    actions={
                        mayRun ? (
                            <>
                                <Button asChild variant="outline">
                                    <Link href={importMethod('loans')}>
                                        <FileSpreadsheet />
                                        Import opening loans
                                    </Link>
                                </Button>
                                <Button onClick={() => setAdding(true)}>
                                    <Plus />
                                    Add loan
                                </Button>
                            </>
                        ) : undefined
                    }
                />

                <div className="overflow-hidden rounded-xl border bg-card shadow-xs">
                    <StatusTabs
                        label="Loan status"
                        idPrefix={ID_PREFIX}
                        value={filters.status}
                        onChange={(key) =>
                            key !== filters.status &&
                            visit({ status: key === 'active' ? null : key })
                        }
                        tabs={(Object.keys(TAB_LABELS) as Tab[]).map((key) => ({
                            key,
                            label: TAB_LABELS[key],
                            count: counts[key],
                        }))}
                    />
                    <div
                        id={statusPanelId(ID_PREFIX)}
                        role="tabpanel"
                        aria-labelledby={statusTabId(ID_PREFIX, filters.status)}
                    >
                        <DataTable
                            label="Loans and advances"
                            bordered={false}
                            columns={columns}
                            rows={loans}
                            rowKey={(row) => row.id}
                            search={{
                                value: filters.search,
                                placeholder: 'Search by name or code',
                            }}
                            emptyText={
                                filters.search
                                    ? 'No loans match this search.'
                                    : 'No loans here.'
                            }
                        />
                    </div>
                </div>
            </div>

            {adding && (
                <LoanDialog
                    employees={employees}
                    firstOpen={months.first_open}
                    defaultMonth={defaultMonth}
                    onClose={() => setAdding(false)}
                />
            )}
        </>
    );
}

LoansIndex.layout = {
    breadcrumbs: [{ title: 'Loans & Advances', href: index() }],
};
