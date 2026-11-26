import { Head, Link, router, usePage } from '@inertiajs/react';
import { DataTable } from '@/components/hrm/data-table';
import type {
    DataTableColumn,
    DataTablePagination,
} from '@/components/hrm/data-table';
import { PageHeader } from '@/components/hrm/page-header';
import { StatusPill } from '@/components/hrm/status-pill';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { formatDate } from '@/lib/format-date';
import { formatMoney } from '@/lib/payroll';
import { nextTableUrl } from '@/lib/table-query';
import type { QueryChanges } from '@/lib/table-query';
import { index, show } from '@/routes/payroll/salary-profiles';

type Row = {
    id: number;
    code: string;
    name: string;
    department: string | null;
    /** The salary in force today, else the first upcoming one; null when the person has none. Money in paisa. */
    salary: {
        basic: number;
        gross: number;
        tax: string;
        effective_from: string;
        upcoming: boolean;
    } | null;
};

type Props = {
    rows: DataTablePagination & { data: Row[] };
    filters: { search: string; department: number | null; missing: boolean };
    departments: { id: number; name: string }[];
};

const ALL = 'all';

export default function SalaryProfiles({ rows, filters, departments }: Props) {
    const { url, props } = usePage();
    const dateFormat = props.app.dateFormat;

    const visit = (changes: QueryChanges) =>
        router.get(
            nextTableUrl(url, { ...changes, page: null }),
            {},
            { preserveScroll: true, preserveState: true },
        );

    const filtering =
        filters.search !== '' || filters.department !== null || filters.missing;

    const money = (
        row: Row,
        value: (s: NonNullable<Row['salary']>) => number,
    ) =>
        row.salary ? (
            <span className="tabular-nums">
                {formatMoney(value(row.salary))}
            </span>
        ) : (
            <span className="text-muted-foreground">—</span>
        );

    const columns: DataTableColumn<Row>[] = [
        {
            key: 'employee',
            header: 'Employee',
            className: 'min-w-44',
            cell: (row) => (
                <div className="min-w-0">
                    <Link
                        href={show(row.id)}
                        className="block truncate font-medium hover:underline"
                    >
                        {row.name}
                    </Link>
                    <p className="truncate text-xs text-muted-foreground">
                        {row.code}
                        {row.department ? ` · ${row.department}` : ''}
                    </p>
                </div>
            ),
        },
        {
            key: 'basic',
            header: 'Basic',
            className: 'whitespace-nowrap text-right',
            headerClassName: 'text-right',
            cell: (row) => money(row, (s) => s.basic),
        },
        {
            key: 'gross',
            header: 'Gross',
            className: 'whitespace-nowrap text-right',
            headerClassName: 'text-right',
            cell: (row) => money(row, (s) => s.gross),
        },
        {
            key: 'tax',
            header: 'Tax',
            className: 'whitespace-nowrap',
            cell: (row) =>
                row.salary ? (
                    row.salary.tax
                ) : (
                    <span className="text-muted-foreground">—</span>
                ),
        },
        {
            key: 'effective',
            header: 'Effective from',
            className: 'whitespace-nowrap',
            cell: (row) =>
                row.salary ? (
                    <span className="inline-flex items-center gap-2">
                        {formatDate(row.salary.effective_from, dateFormat)}
                        {row.salary.upcoming && (
                            <StatusPill tone="blue">Upcoming</StatusPill>
                        )}
                    </span>
                ) : (
                    <StatusPill tone="amber">No salary</StatusPill>
                ),
        },
    ];

    return (
        <>
            <Head title="Salary Profiles" />
            <div className="flex min-w-0 flex-1 flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title="Salary Profiles"
                    subtitle="Each person's current salary. Open someone to see their salary history or add a new salary."
                />

                <DataTable
                    label="Salary profiles"
                    columns={columns}
                    rows={rows.data}
                    rowKey={(row) => row.id}
                    pagination={rows}
                    search={{
                        value: filters.search,
                        placeholder: 'Search by name, ID or email',
                        label: 'Search employees',
                    }}
                    toolbar={
                        <>
                            <Select
                                value={
                                    filters.department === null
                                        ? ALL
                                        : String(filters.department)
                                }
                                onValueChange={(value) =>
                                    visit({
                                        department:
                                            value === ALL ? null : value,
                                    })
                                }
                            >
                                <SelectTrigger
                                    className="w-full sm:w-48"
                                    aria-label="Filter by department"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={ALL}>
                                        All Departments
                                    </SelectItem>
                                    {departments.map((option) => (
                                        <SelectItem
                                            key={option.id}
                                            value={String(option.id)}
                                        >
                                            {option.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <Select
                                value={filters.missing ? 'missing' : ALL}
                                onValueChange={(value) =>
                                    visit({
                                        missing: value === ALL ? null : '1',
                                    })
                                }
                            >
                                <SelectTrigger
                                    className="w-full sm:w-44"
                                    aria-label="Filter by salary"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={ALL}>
                                        Everyone
                                    </SelectItem>
                                    <SelectItem value="missing">
                                        No salary only
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        </>
                    }
                    emptyText={
                        filtering
                            ? 'No employees match these filters.'
                            : 'No employees yet.'
                    }
                />
            </div>
        </>
    );
}

SalaryProfiles.layout = {
    breadcrumbs: [{ title: 'Salary Profiles', href: index() }],
};
