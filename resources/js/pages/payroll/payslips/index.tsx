import { Head, Link, router, usePage } from '@inertiajs/react';
import { Download, Mail } from 'lucide-react';
import { useState } from 'react';
import { DataTable } from '@/components/hrm/data-table';
import type {
    DataTableColumn,
    DataTablePagination,
} from '@/components/hrm/data-table';
import { PageHeader } from '@/components/hrm/page-header';
import { StatusPill } from '@/components/hrm/status-pill';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { nextTableUrl } from '@/lib/table-query';
import { show as showEmployee } from '@/routes/employees';
import { download, index, resend } from '@/routes/payroll/payslips';

type PayslipRow = {
    id: number;
    /** "Y-m". */
    month: string;
    month_label: string;
    employee: { id: number; name: string; code: string };
    /** Formatted on the server, e.g. "Rs 64,930". */
    net: string;
    /** ISO 8601 instants, or null when not yet generated / emailed. */
    generated_at: string | null;
    emailed_at: string | null;
};

type Props = {
    rows: DataTablePagination & { data: PayslipRow[] };
    filters: { search: string; month: string | null };
    months: { value: string; label: string }[];
    can_resend: boolean;
};

const ALL_MONTHS = 'all';

/** Payroll > Payslips: every payslip of approved payroll months, with download and resend. */
export default function Payslips({ rows, filters, months, can_resend }: Props) {
    const { url, props } = usePage();
    const timezone = props.app.timezone;
    const errors = props.errors as Record<string, string | undefined>;
    const [sending, setSending] = useState<number | null>(null);

    const when = (iso: string | null) =>
        iso
            ? new Intl.DateTimeFormat('en-GB', {
                  day: 'numeric',
                  month: 'short',
                  year: 'numeric',
                  hour: '2-digit',
                  minute: '2-digit',
                  timeZone: timezone,
              }).format(new Date(iso))
            : null;

    const filterByMonth = (value: string) => {
        router.get(
            nextTableUrl(url, {
                month: value === ALL_MONTHS ? null : value,
                page: null,
            }),
            {},
            { preserveScroll: true, preserveState: true, replace: true },
        );
    };

    const sendAgain = (row: PayslipRow) => {
        router.post(
            resend(row.id).url,
            {},
            {
                preserveScroll: true,
                onStart: () => setSending(row.id),
                onFinish: () => setSending(null),
            },
        );
    };

    const columns: DataTableColumn<PayslipRow>[] = [
        {
            key: 'employee',
            header: 'Employee',
            cell: (row) => (
                <div className="min-w-0">
                    <Link
                        href={showEmployee(row.employee.id)}
                        className="block truncate font-medium hover:underline"
                    >
                        {row.employee.name}
                    </Link>
                    <div className="text-xs whitespace-nowrap text-muted-foreground">
                        {row.employee.code}
                        <span className="sm:hidden"> · {row.month_label}</span>
                    </div>
                    {/* The columns hidden below xl (a laptop with the sidebar open has about 650px), stacked under the name. */}
                    <div className="text-xs text-muted-foreground xl:hidden">
                        {row.emailed_at
                            ? `Emailed ${when(row.emailed_at)}`
                            : 'Not emailed'}
                    </div>
                </div>
            ),
        },
        {
            key: 'month',
            header: 'Month',
            className: 'hidden whitespace-nowrap sm:table-cell',
            cell: (row) => row.month_label,
        },
        {
            key: 'net',
            header: 'Net pay',
            className: 'text-right font-medium whitespace-nowrap',
            cell: (row) => row.net,
        },
        {
            key: 'generated',
            header: 'Generated',
            className:
                'hidden whitespace-nowrap text-muted-foreground xl:table-cell',
            cell: (row) => when(row.generated_at) ?? 'Not yet',
        },
        {
            key: 'emailed',
            header: 'Emailed',
            className: 'hidden whitespace-nowrap xl:table-cell',
            cell: (row) =>
                row.emailed_at ? (
                    <StatusPill tone="green">{when(row.emailed_at)}</StatusPill>
                ) : (
                    <StatusPill tone="grey">Not emailed</StatusPill>
                ),
        },
        {
            key: 'actions',
            header: <span className="sr-only">Actions</span>,
            className: 'w-24',
            cell: (row) => (
                <div className="flex justify-end gap-1">
                    <Button
                        asChild
                        variant="ghost"
                        size="icon"
                        className="size-8"
                    >
                        <a
                            href={download(row.id).url}
                            target="_blank"
                            rel="noopener"
                            aria-label={`Download the payslip of ${row.employee.name} for ${row.month_label}`}
                            title="Download PDF"
                        >
                            <Download />
                        </a>
                    </Button>
                    {can_resend && (
                        <Button
                            variant="ghost"
                            size="icon"
                            className="size-8"
                            aria-label={`Email the payslip to ${row.employee.name} again`}
                            title="Email again"
                            disabled={sending === row.id}
                            onClick={() => sendAgain(row)}
                        >
                            <Mail />
                        </Button>
                    )}
                </div>
            ),
        },
    ];

    const filtering = filters.search !== '' || filters.month !== null;

    return (
        <>
            <Head title="Payslips" />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title="Payslips"
                    subtitle="Payslips of approved payroll months. Download a PDF or email it to the employee again."
                />

                {errors.payslip && (
                    <Alert variant="destructive">
                        <AlertDescription>{errors.payslip}</AlertDescription>
                    </Alert>
                )}

                <DataTable
                    label="Payslips"
                    columns={columns}
                    rows={rows.data}
                    rowKey={(row) => row.id}
                    pagination={rows}
                    pageParam="page"
                    search={{
                        value: filters.search,
                        placeholder: 'Search by name or code',
                        label: 'Search payslips',
                    }}
                    toolbar={
                        <Select
                            value={filters.month ?? ALL_MONTHS}
                            onValueChange={filterByMonth}
                        >
                            <SelectTrigger
                                className="w-full sm:w-48"
                                aria-label="Filter by month"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value={ALL_MONTHS}>
                                    All months
                                </SelectItem>
                                {months.map((option) => (
                                    <SelectItem
                                        key={option.value}
                                        value={option.value}
                                    >
                                        {option.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    }
                    emptyText={
                        filtering
                            ? 'No payslips match your filters.'
                            : 'No payslips yet. They appear here once a payroll month is approved.'
                    }
                />
            </div>
        </>
    );
}

Payslips.layout = {
    breadcrumbs: [{ title: 'Payslips', href: index() }],
};
