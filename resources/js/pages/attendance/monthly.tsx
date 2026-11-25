import { Head, router, usePage } from '@inertiajs/react';
import { ChevronLeft, ChevronRight, Download } from 'lucide-react';
import { useState } from 'react';
import { CorrectionDialog } from '@/components/attendance/correction-dialog';
import { DayCell } from '@/components/attendance/day-cell';
import { AttendanceLegend } from '@/components/attendance/legend';
import { Avatar } from '@/components/hrm/avatar';
import { DataTable } from '@/components/hrm/data-table';
import type { DataTablePagination } from '@/components/hrm/data-table';
import { PageHeader } from '@/components/hrm/page-header';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    FLAG_MANUAL,
    cellStatus,
    formatCount,
    formatMinutes,
    hasFlag,
    inWindow,
    monthValue,
} from '@/lib/monthly-grid';
import type { GridCell, GridDay, GridTotals } from '@/lib/monthly-grid';
import { nextTableUrl } from '@/lib/table-query';
import { cn } from '@/lib/utils';
import { monthly } from '@/routes/attendance';
import { exportMethod } from '@/routes/attendance/monthly';

type GridRow = {
    id: number;
    name: string;
    code: string;
    designation: string | null;
    photo_url: string | null;
    /** First and last day number inside the employment window this month (null: none). */
    window: [number, number] | null;
    cells: GridCell[];
    totals: GridTotals;
};

type Props = {
    month: {
        value: string;
        label: string;
        previous: string;
        next: string | null;
        current: string;
    };
    days: GridDay[];
    employees: DataTablePagination & { data: GridRow[] };
    filters: { search: string; department_id: number | null };
    departments: { id: number; name: string }[];
    can: { export: boolean; edit: boolean };
};

type Selected = { row: GridRow; day: GridDay; cell: GridCell };

const ALL = 'all';

const MONTH_NAMES = [
    'January',
    'February',
    'March',
    'April',
    'May',
    'June',
    'July',
    'August',
    'September',
    'October',
    'November',
    'December',
];

/** Years offered in the picker: the last six, up to the current one. */
const YEARS_BACK = 5;

export default function Monthly({
    month,
    days,
    employees,
    filters,
    departments,
    can,
}: Props) {
    const { url } = usePage();
    const [selected, setSelected] = useState<Selected | null>(null);
    const [dialogOpen, setDialogOpen] = useState(false);

    const year = Number(month.value.slice(0, 4));
    const monthNumber = Number(month.value.slice(5, 7));
    const currentYear = Number(month.current.slice(0, 4));
    const years = Array.from(
        { length: YEARS_BACK + 1 },
        (_, i) => currentYear - i,
    );
    if (!years.includes(year)) {
        years.push(year);
    }

    const visit = (changes: Parameters<typeof nextTableUrl>[1]) =>
        router.get(
            nextTableUrl(url, { ...changes, page: null }),
            {},
            { preserveScroll: true, preserveState: true },
        );

    const goToMonth = (value: string) =>
        visit({ month: value === month.current ? null : value });

    const chooseYear = (value: string) => {
        const target = monthValue(Number(value), monthNumber);
        // The same month of the current year may not have started yet.
        goToMonth(target > month.current ? month.current : target);
    };

    const exportQuery = Object.fromEntries(
        Object.entries({
            month: month.value,
            search: filters.search,
            department_id: filters.department_id,
        }).filter(([, value]) => value !== '' && value !== null),
    );

    const select = (row: GridRow, day: GridDay, cell: GridCell) => {
        setSelected({ row, day, cell });
        setDialogOpen(true);
    };

    const filtering = filters.search !== '' || filters.department_id !== null;

    const grid = (
        <div className="overflow-x-auto overscroll-x-contain">
            <table
                aria-label={`Attendance, ${month.label}`}
                className="w-max min-w-full border-separate border-spacing-0 text-sm"
            >
                <thead>
                    <tr className="bg-muted/50">
                        <th
                            scope="col"
                            className="sticky left-0 z-20 w-40 min-w-40 border-r border-b bg-muted px-3 py-2 text-left text-xs font-medium text-muted-foreground sm:w-56 sm:min-w-56"
                        >
                            Employee
                        </th>
                        {days.map((day) => (
                            <th
                                key={day.date}
                                scope="col"
                                title={day.holiday ?? undefined}
                                className={cn(
                                    'w-9 min-w-9 border-b px-0.5 py-1.5 text-center text-xs font-medium',
                                    day.future
                                        ? 'text-muted-foreground/50'
                                        : 'text-muted-foreground',
                                    day.holiday &&
                                        'bg-violet-50 dark:bg-violet-500/10',
                                )}
                            >
                                <span className="block text-foreground tabular-nums">
                                    {day.day}
                                </span>
                                <span className="block text-[10px] font-normal">
                                    {day.weekday}
                                </span>
                                {day.holiday && (
                                    <span className="sr-only">
                                        , holiday: {day.holiday}
                                    </span>
                                )}
                            </th>
                        ))}
                        <th
                            scope="col"
                            className="border-b border-l px-3 py-2 text-right text-xs font-medium whitespace-nowrap text-muted-foreground"
                        >
                            <abbr
                                title="Present-equivalent (half day counts 0.5) over working days (present, late, half day, absent)"
                                className="no-underline"
                            >
                                Present / Working
                            </abbr>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    {employees.data.map((row) => (
                        <tr key={row.id} className="group">
                            <th
                                scope="row"
                                className="sticky left-0 z-10 w-40 min-w-40 border-r border-b bg-card px-3 py-2 text-left font-normal group-hover:bg-muted sm:w-56 sm:min-w-56"
                            >
                                <div className="flex min-w-0 items-center gap-2">
                                    <Avatar
                                        src={row.photo_url}
                                        name={row.name}
                                        className="hidden sm:flex"
                                    />
                                    <div className="min-w-0">
                                        <p className="truncate font-medium">
                                            {row.name}
                                        </p>
                                        <p className="truncate text-xs text-muted-foreground">
                                            {row.designation ?? row.code}
                                        </p>
                                    </div>
                                </div>
                            </th>
                            {days.map((day, index) => {
                                const cell = row.cells[index] ?? null;
                                const employed = inWindow(row.window, day.day);
                                const editable =
                                    can.edit && employed && !day.future;

                                return (
                                    <td
                                        key={day.date}
                                        className={cn(
                                            'border-b px-0.5 py-1 text-center group-hover:bg-muted/40',
                                            day.holiday &&
                                                'bg-violet-50/60 dark:bg-violet-500/5',
                                        )}
                                    >
                                        <DayCell
                                            day={day}
                                            cell={cell}
                                            inWindow={employed}
                                            onSelect={
                                                editable
                                                    ? () =>
                                                          select(row, day, cell)
                                                    : undefined
                                            }
                                        />
                                    </td>
                                );
                            })}
                            <td className="border-b border-l px-3 py-2 text-right whitespace-nowrap tabular-nums group-hover:bg-muted/40">
                                <span className="font-semibold">
                                    {formatCount(row.totals.equivalent)}
                                </span>
                                <span className="text-muted-foreground">
                                    {' / '}
                                    {row.totals.working_days}
                                </span>
                                <span className="block text-xs text-muted-foreground">
                                    {formatMinutes(row.totals.worked_minutes)}{' '}
                                    worked
                                </span>
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );

    return (
        <>
            <Head title="Monthly Attendance" />
            <div className="flex min-w-0 flex-1 flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title="Monthly Attendance"
                    subtitle="Everyone's days for one month. Hover or focus a day for times and minutes."
                    actions={
                        can.export && (
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
                        )
                    }
                />

                <AttendanceLegend />

                <DataTable
                    label={`Attendance, ${month.label}`}
                    columns={[]}
                    rows={employees.data}
                    rowKey={(row) => row.id}
                    pagination={employees}
                    search={{
                        value: filters.search,
                        placeholder: 'Search by name, ID or email',
                        label: 'Search employees',
                    }}
                    toolbar={
                        <>
                            <div className="flex items-center gap-1">
                                <Button
                                    variant="outline"
                                    size="icon"
                                    className="size-9"
                                    aria-label="Previous month"
                                    onClick={() => goToMonth(month.previous)}
                                >
                                    <ChevronLeft />
                                </Button>
                                <Select
                                    value={String(monthNumber)}
                                    onValueChange={(value) =>
                                        goToMonth(
                                            monthValue(year, Number(value)),
                                        )
                                    }
                                >
                                    <SelectTrigger
                                        className="w-32"
                                        aria-label="Month"
                                    >
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {MONTH_NAMES.map((name, index) => (
                                            <SelectItem
                                                key={name}
                                                value={String(index + 1)}
                                                disabled={
                                                    monthValue(
                                                        year,
                                                        index + 1,
                                                    ) > month.current
                                                }
                                            >
                                                {name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <Select
                                    value={String(year)}
                                    onValueChange={chooseYear}
                                >
                                    <SelectTrigger
                                        className="w-24"
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
                                <Button
                                    variant="outline"
                                    size="icon"
                                    className="size-9"
                                    aria-label="Next month"
                                    disabled={month.next === null}
                                    onClick={() =>
                                        month.next && goToMonth(month.next)
                                    }
                                >
                                    <ChevronRight />
                                </Button>
                            </div>
                            <Select
                                value={
                                    filters.department_id === null
                                        ? ALL
                                        : String(filters.department_id)
                                }
                                onValueChange={(value) =>
                                    visit({
                                        department_id:
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
                    view={grid}
                    emptyText={
                        filtering
                            ? 'No employees match these filters.'
                            : `No one to show for ${month.label}.`
                    }
                />
            </div>

            {can.edit && selected && (
                <CorrectionDialog
                    open={dialogOpen}
                    onClose={() => setDialogOpen(false)}
                    employee={{ id: selected.row.id, name: selected.row.name }}
                    date={selected.day.date}
                    current={{
                        clock_in: selected.cell?.[2] ?? null,
                        clock_out: selected.cell?.[3] ?? null,
                        status: cellStatus(selected.cell),
                        is_manual: hasFlag(selected.cell, FLAG_MANUAL),
                    }}
                />
            )}
        </>
    );
}

Monthly.layout = {
    breadcrumbs: [{ title: 'Monthly Attendance', href: monthly() }],
};
