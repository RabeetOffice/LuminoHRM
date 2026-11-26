import { Head, router } from '@inertiajs/react';
import { Download } from 'lucide-react';
import { DataTable } from '@/components/hrm/data-table';
import type { DataTableColumn } from '@/components/hrm/data-table';
import { PageHeader } from '@/components/hrm/page-header';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import TaxReportController from '@/actions/App/Http/Controllers/Payroll/TaxReportController';

type Month = { month: string; label: string; title: string };

/** Money arrives formatted by the server ("Rs 3,570"; "–" for nothing). */
type Figures = {
    opening_tax: string;
    opening_taxable: string;
    /** "Y-m" → tax of that month. */
    months: Record<string, string>;
    total_tax: string;
    total_taxable: string;
};

type Row = Figures & { id: number; code: string; name: string };

type Props = {
    year: number;
    label: string;
    years: { value: number; label: string }[];
    months: Month[];
    rows: Row[];
    totals: Figures;
};

const figure = 'text-right tabular-nums whitespace-nowrap';

export default function TaxReport({
    year,
    label,
    years,
    months,
    rows,
    totals,
}: Props) {
    const totalRow: Row = { ...totals, id: 0, code: '', name: 'Total' };
    const tableRows = rows.length === 0 ? [] : [...rows, totalRow];

    const columns: DataTableColumn<Row>[] = [
        {
            key: 'employee',
            header: 'Employee',
            className: 'min-w-44',
            cell: (row) =>
                row.id === 0 ? (
                    <span className="font-semibold">Total</span>
                ) : (
                    <div className="min-w-0">
                        <p className="truncate font-medium">{row.name}</p>
                        <p className="truncate text-xs text-muted-foreground">
                            {row.code}
                        </p>
                    </div>
                ),
        },
        {
            key: 'opening',
            header: <span title="Tax deducted before go-live">Opening</span>,
            className: figure,
            cell: (row) => row.opening_tax,
        },
        ...months.map((month): DataTableColumn<Row> => ({
            key: month.month,
            header: <span title={month.title}>{month.label}</span>,
            className: figure,
            cell: (row) => row.months[month.month] ?? '–',
        })),
        {
            key: 'total_tax',
            header: 'Total tax',
            className: `${figure} font-semibold`,
            cell: (row) => row.total_tax,
        },
        {
            key: 'total_taxable',
            header: 'Taxable pay',
            className: figure,
            cell: (row) => row.total_taxable,
        },
    ];

    return (
        <>
            <Head title="Tax Report" />
            <div className="flex min-w-0 flex-1 flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title="Tax Report"
                    subtitle={`Tax deducted per person in tax year ${label}: opening figures before go-live plus every approved payroll month.`}
                    actions={
                        <Button asChild variant="outline">
                            {/* A file download, not a page visit. */}
                            <a
                                href={TaxReportController.exportMethod.url({
                                    query: { year: String(year) },
                                })}
                            >
                                <Download />
                                Export
                            </a>
                        </Button>
                    }
                />

                <DataTable
                    label={`Tax report, ${label}`}
                    columns={columns}
                    rows={tableRows}
                    rowKey={(row) => row.id}
                    toolbar={
                        <Select
                            value={String(year)}
                            onValueChange={(value) =>
                                router.get(
                                    TaxReportController.index.url({
                                        query: { year: value },
                                    }),
                                    {},
                                    { preserveScroll: true },
                                )
                            }
                        >
                            <SelectTrigger
                                className="w-full sm:w-32"
                                aria-label="Tax year"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {years.map((option) => (
                                    <SelectItem
                                        key={option.value}
                                        value={String(option.value)}
                                    >
                                        {option.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    }
                    emptyText={`No tax figures for ${label} yet. Approved payroll months and opening figures appear here.`}
                />

                <p className="text-xs text-muted-foreground">
                    Draft payroll months are not counted until approved. Taxable
                    pay is the year’s total including the opening taxable
                    income.
                </p>
            </div>
        </>
    );
}

TaxReport.layout = {
    breadcrumbs: [
        { title: 'Tax Report', href: TaxReportController.index.url() },
    ],
};
