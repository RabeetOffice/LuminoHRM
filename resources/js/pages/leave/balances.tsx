import { Head, Link, router, usePage } from '@inertiajs/react';
import { Download, Upload } from 'lucide-react';
import { useState } from 'react';
import { DataTable } from '@/components/hrm/data-table';
import type {
    DataTableColumn,
    DataTablePagination,
} from '@/components/hrm/data-table';
import { PageHeader } from '@/components/hrm/page-header';
import { BalanceDialog } from '@/components/leave/balance-dialog';
import { LeaveColourDot } from '@/components/leave/leave-type-badge';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { cellText, formatDays } from '@/lib/leave-balance';
import type { BalanceCell } from '@/lib/leave-balance';
import { nextTableUrl } from '@/lib/table-query';
import type { QueryChanges } from '@/lib/table-query';
import { cn } from '@/lib/utils';
import { exportMethod, importMethod, index } from '@/routes/leave/balances';

type BalanceType = {
    id: number;
    name: string;
    colour: string;
    is_paid: boolean;
};

type BalanceRow = {
    id: number;
    name: string;
    first_name: string;
    code: string;
    department: string | null;
    /** type id → cell (a type the person has no line for is absent). */
    cells: Record<string, BalanceCell>;
};

type Props = {
    year: number;
    years: number[];
    current_year: number;
    /** Active types, paid and unpaid, in sort order. */
    types: BalanceType[];
    rows: DataTablePagination & { data: BalanceRow[] };
    filters: { search: string; department: number | null };
    departments: { id: number; name: string }[];
    can: { edit: boolean; export: boolean; import: boolean };
};

const ALL = 'all';

type Selected = { row: BalanceRow; cell: BalanceCell };

export default function Balances({
    year,
    years,
    current_year,
    types,
    rows,
    filters,
    departments,
    can,
}: Props) {
    const { url } = usePage();
    const [selected, setSelected] = useState<Selected | null>(null);

    const visit = (changes: QueryChanges) =>
        router.get(
            nextTableUrl(url, { ...changes, page: null }),
            {},
            { preserveScroll: true, preserveState: true },
        );

    const exportQuery: Record<string, string> = { year: String(year) };
    if (filters.search !== '') {
        exportQuery.search = filters.search;
    }
    if (filters.department !== null) {
        exportQuery.department = String(filters.department);
    }

    const filtering = filters.search !== '' || filters.department !== null;

    const columns: DataTableColumn<BalanceRow>[] = [
        {
            key: 'employee',
            header: 'Employee',
            className: 'min-w-44',
            cell: (row) => (
                <div className="min-w-0">
                    <p className="truncate font-medium">{row.name}</p>
                    <p className="truncate text-xs text-muted-foreground">
                        {row.code}
                        {row.department ? ` · ${row.department}` : ''}
                    </p>
                </div>
            ),
        },
        ...types.map((type): DataTableColumn<BalanceRow> => ({
            key: `type-${type.id}`,
            header: (
                <span className="inline-flex items-center gap-1.5 whitespace-nowrap">
                    <LeaveColourDot colour={type.colour} />
                    {type.name}
                </span>
            ),
            className: 'whitespace-nowrap',
            cell: (row) => {
                const cell = row.cells[String(type.id)];

                if (!cell) {
                    return <span className="text-muted-foreground">—</span>;
                }

                return (
                    <button
                        type="button"
                        onClick={() => setSelected({ row, cell })}
                        aria-label={`${type.name} for ${row.name}: ${cell.label}${cell.projected ? ', projected' : ''}${cell.can_edit ? '. Open to adjust' : '. Open for details'}`}
                        title={
                            cell.projected
                                ? 'Projected: no balance row yet, the expected allocation'
                                : undefined
                        }
                        className={cn(
                            '-mx-1.5 rounded-md px-1.5 py-1 text-left tabular-nums transition hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                            cell.projected && 'text-muted-foreground italic',
                        )}
                    >
                        <span
                            className={cn(
                                'font-medium',
                                cell.overdrawn &&
                                    'text-red-600 dark:text-red-400',
                            )}
                        >
                            {cellText(cell)}
                        </span>
                        {cell.pending > 0 && (
                            <span className="ml-1 text-xs text-muted-foreground">
                                ({formatDays(cell.pending)} pending)
                            </span>
                        )}
                        {cell.projected && (
                            <span className="ml-1 text-[10px] font-normal tracking-wide uppercase not-italic">
                                projected
                            </span>
                        )}
                    </button>
                );
            },
        })),
    ];

    return (
        <>
            <Head title="Leave Balances" />
            <div className="flex min-w-0 flex-1 flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title="Leave Balances"
                    subtitle={
                        can.edit
                            ? 'Remaining of each paid leave type per person and year. Select a figure to see its breakdown or adjust it.'
                            : 'Remaining of each paid leave type for your team. Select a figure to see its breakdown.'
                    }
                    actions={
                        (can.export || can.import) && (
                            <div className="flex flex-wrap gap-2">
                                {can.import && (
                                    <Button asChild variant="outline">
                                        <Link href={importMethod()}>
                                            <Upload />
                                            Import opening balances
                                        </Link>
                                    </Button>
                                )}
                                {can.export && (
                                    <Button asChild variant="outline">
                                        {/* A file download, not a page visit. */}
                                        <a
                                            href={exportMethod.url({
                                                query: exportQuery,
                                            })}
                                        >
                                            <Download />
                                            Export
                                        </a>
                                    </Button>
                                )}
                            </div>
                        )
                    }
                />

                <p className="text-xs text-muted-foreground">
                    Each figure is remaining / entitlement (allocated + carried
                    + adjustment); unpaid leave shows the days used.
                    {year > current_year &&
                        ' Next year is a projection: carried days follow this year’s remaining and pending leave.'}
                </p>

                <DataTable
                    label={`Leave balances, ${year}`}
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
                                value={String(year)}
                                onValueChange={(value) =>
                                    visit({ year: value })
                                }
                            >
                                <SelectTrigger
                                    className="w-full sm:w-28"
                                    aria-label="Year"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {years.map((option) => (
                                        <SelectItem
                                            key={option}
                                            value={String(option)}
                                        >
                                            {option}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
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
                        </>
                    }
                    emptyText={
                        filtering
                            ? 'No employees match these filters.'
                            : `No one to show for ${year}.`
                    }
                />
            </div>

            {selected && (
                <BalanceDialog
                    key={`${selected.row.id}-${selected.cell.type_id}-${selected.cell.year}`}
                    employee={{
                        id: selected.row.id,
                        name: selected.row.name,
                        first_name: selected.row.first_name,
                    }}
                    cell={selected.cell}
                    onClose={() => setSelected(null)}
                />
            )}
        </>
    );
}

Balances.layout = {
    breadcrumbs: [{ title: 'Leave Balances', href: index() }],
};
