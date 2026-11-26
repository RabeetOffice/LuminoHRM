import { Head } from '@inertiajs/react';
import { Download, FileText } from 'lucide-react';
import { DataTable } from '@/components/hrm/data-table';
import type { DataTableColumn } from '@/components/hrm/data-table';
import { PageHeader } from '@/components/hrm/page-header';
import { Button } from '@/components/ui/button';
import { payslips as myPayslips } from '@/routes/my';
import { download } from '@/routes/my/payslips';

type PayslipRow = {
    id: number;
    /** "Y-m". */
    month: string;
    /** "November 2026". */
    label: string;
    /** Formatted on the server, e.g. "Rs 64,930". */
    net: string;
    gross: string;
    tax: string;
};

type Props = {
    /** The viewer's own record; null when the account is not linked to an employee. */
    employee: { name: string } | null;
    payslips: PayslipRow[];
};

/** The signed-in person's own payslips of approved payroll months, newest first. */
export default function MyPayslips({ employee, payslips }: Props) {
    const columns: DataTableColumn<PayslipRow>[] = [
        {
            key: 'month',
            header: 'Month',
            cell: (row) => (
                <div>
                    <div className="font-medium">{row.label}</div>
                    {/* Phones: the figures sit under the month. */}
                    <div className="text-xs text-muted-foreground sm:hidden">
                        Gross {row.gross} · Tax {row.tax}
                    </div>
                </div>
            ),
        },
        {
            key: 'gross',
            header: 'Gross',
            className: 'hidden text-right whitespace-nowrap sm:table-cell',
            cell: (row) => row.gross,
        },
        {
            key: 'tax',
            header: 'Tax',
            className: 'hidden text-right whitespace-nowrap sm:table-cell',
            cell: (row) => row.tax,
        },
        {
            key: 'net',
            header: 'Net pay',
            className: 'text-right font-medium whitespace-nowrap',
            cell: (row) => row.net,
        },
        {
            key: 'actions',
            header: <span className="sr-only">Download</span>,
            className: 'w-12 sm:w-32',
            cell: (row) => (
                <div className="flex justify-end">
                    <Button
                        asChild
                        variant="ghost"
                        size="sm"
                        aria-label={`Download the payslip for ${row.label}`}
                    >
                        <a
                            href={download(row.id).url}
                            target="_blank"
                            rel="noopener"
                        >
                            <Download />
                            <span className="hidden sm:inline">PDF</span>
                        </a>
                    </Button>
                </div>
            ),
        },
    ];

    return (
        <>
            <Head title="My Payslips" />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title="My Payslips"
                    subtitle={
                        employee
                            ? 'Your payslip for each approved payroll month. Open one to view or save the PDF.'
                            : undefined
                    }
                />

                {employee === null ? (
                    <NotLinked />
                ) : (
                    <DataTable
                        label="My payslips"
                        columns={columns}
                        rows={payslips}
                        rowKey={(row) => row.id}
                        emptyText="No payslips yet. Each month's payslip appears here once payroll is approved."
                    />
                )}
            </div>
        </>
    );
}

function NotLinked() {
    return (
        <section className="flex flex-col items-center rounded-xl border bg-card px-6 py-16 text-center shadow-xs">
            <div className="flex size-12 items-center justify-center rounded-full bg-muted text-muted-foreground">
                <FileText aria-hidden="true" className="size-6" />
            </div>
            <p className="mt-4 text-base font-medium">
                Your account is not linked to an employee record yet.
            </p>
            <p className="mt-1 text-sm text-muted-foreground">
                Your payslips appear here once HR links it.
            </p>
        </section>
    );
}

MyPayslips.layout = {
    breadcrumbs: [{ title: 'My Payslips', href: myPayslips() }],
};
