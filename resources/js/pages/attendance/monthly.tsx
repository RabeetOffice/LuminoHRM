import { Head, router, usePage } from '@inertiajs/react';
import { ChevronLeft, ChevronRight, Download } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import type { FocusEvent, KeyboardEvent } from 'react';
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
    FLAG_OVERRIDDEN,
    cellStatus,
    formatCount,
    formatMinutes,
    hasFlag,
    inWindow,
    monthValue,
    moveGridFocus,
} from '@/lib/monthly-grid';
import type {
    GridCell,
    GridDay,
    GridPosition,
    GridTotals,
} from '@/lib/monthly-grid';
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
    /** The viewer may correct this person's days (worked out on the server). */
    can_edit: boolean;
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
    /** The statuses that may be chosen by hand (ApplyManualCorrection::OVERRIDE_STATUSES). */
    overrideStatuses: string[];
    can: { export: boolean; edit: boolean };
};

/** The day being corrected, and the grid position of the cell that opened it (focus returns there). */
type Selected = {
    row: GridRow;
    day: GridDay;
    cell: GridCell;
    rowIndex: number;
    dayIndex: number;
};

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
    overrideStatuses,
    can,
}: Props) {
    const { url } = usePage();
    const [selected, setSelected] = useState<Selected | null>(null);
    const [dialogOpen, setDialogOpen] = useState(false);
    // The grid's single tab stop (roving tabindex).
    const [active, setActive] = useState<GridPosition>({ row: 0, col: 0 });
    const tableRef = useRef<HTMLTableElement>(null);

    // The latest rows and days, read by the one stable cell callback below.
    const latest = useRef({ rows: employees.data, days });
    useEffect(() => {
        latest.current = { rows: employees.data, days };
    }, [employees.data, days]);

    const rowCount = employees.data.length;
    const colCount = days.length;
    const tabStop: GridPosition = {
        row: Math.min(active.row, Math.max(rowCount - 1, 0)),
        col: Math.min(active.col, Math.max(colCount - 1, 0)),
    };

    const activate = useCallback((rowIndex: number, dayIndex: number) => {
        const row = latest.current.rows[rowIndex];
        const day = latest.current.days[dayIndex];

        if (!row || !day) {
            return;
        }

        setSelected({
            row,
            day,
            cell: row.cells[dayIndex] ?? null,
            rowIndex,
            dayIndex,
        });
        setDialogOpen(true);
    }, []);

    // When the dialog has closed, the focus goes back to the cell that opened it (it is not a Radix trigger).
    const returnFocus = (event: Event) => {
        if (!selected) {
            return;
        }

        event.preventDefault();
        tableRef.current
            ?.querySelector<HTMLElement>(
                `[data-row="${selected.rowIndex}"][data-col="${selected.dayIndex}"]`,
            )
            ?.focus();
    };

    const position = (target: EventTarget): GridPosition | null => {
        if (!(target instanceof HTMLElement)) {
            return null;
        }

        const row = Number(target.dataset.row);
        const col = Number(target.dataset.col);

        return target.dataset.row === undefined ||
            Number.isNaN(row) ||
            Number.isNaN(col)
            ? null
            : { row, col };
    };

    // A click or Tab onto a cell makes it the tab stop.
    const onGridFocus = (event: FocusEvent<HTMLTableSectionElement>) => {
        const at = position(event.target);

        if (at && (at.row !== active.row || at.col !== active.col)) {
            setActive(at);
        }
    };

    // Arrows, Home / End (Ctrl: whole grid) move the focus between cells.
    // Enter and Space are left to the cell (a button when it can be corrected).
    const onGridKeyDown = (event: KeyboardEvent<HTMLTableSectionElement>) => {
        const from = position(event.target);

        if (!from) {
            return;
        }

        const to = moveGridFocus(
            from,
            event.key,
            event.ctrlKey || event.metaKey,
            rowCount,
            colCount,
        );

        if (!to) {
            return;
        }

        event.preventDefault();
        setActive(to);
        tableRef.current
            ?.querySelector<HTMLElement>(
                `[data-row="${to.row}"][data-col="${to.col}"]`,
            )
            ?.focus();
    };

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

    const filtering = filters.search !== '' || filters.department_id !== null;

    const grid = (
        <div className="overflow-x-auto overscroll-x-contain">
            <table
                ref={tableRef}
                role="grid"
                aria-label={`Attendance, ${month.label}. Use the arrow keys to move between days.`}
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
                <tbody onFocus={onGridFocus} onKeyDown={onGridKeyDown}>
                    {employees.data.map((row, rowIndex) => (
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
                                    can.edit &&
                                    row.can_edit &&
                                    employed &&
                                    !day.future;

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
                                            rowIndex={rowIndex}
                                            dayIndex={index}
                                            day={day}
                                            cell={cell}
                                            inWindow={employed}
                                            editable={editable}
                                            tabbable={
                                                tabStop.row === rowIndex &&
                                                tabStop.col === index
                                            }
                                            onActivate={activate}
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
                    subtitle="Everyone's days for one month. Hover or focus a day for times and minutes; the arrow keys move between days."
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
                        status_overridden: hasFlag(
                            selected.cell,
                            FLAG_OVERRIDDEN,
                        ),
                    }}
                    overrideStatuses={overrideStatuses}
                    onCloseAutoFocus={returnFocus}
                />
            )}
        </>
    );
}

Monthly.layout = {
    breadcrumbs: [{ title: 'Monthly Attendance', href: monthly() }],
};
