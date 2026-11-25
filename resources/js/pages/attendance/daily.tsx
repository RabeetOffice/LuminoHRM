import { Head, router, usePage } from '@inertiajs/react';
import {
    AlertTriangle,
    CalendarDays,
    ChevronLeft,
    ChevronRight,
    Pencil,
} from 'lucide-react';
import { useState } from 'react';
import { CorrectionDialog } from '@/components/attendance/correction-dialog';
import {
    AttendanceStatusPill,
    STATUS_META,
    halfLeaveLabel,
} from '@/components/attendance/status-symbol';
import type { AttendanceStatusValue } from '@/components/attendance/status-symbol';
import { Avatar } from '@/components/hrm/avatar';
import { DataTable } from '@/components/hrm/data-table';
import type { DataTableColumn } from '@/components/hrm/data-table';
import { PageHeader } from '@/components/hrm/page-header';
import { StatusPill } from '@/components/hrm/status-pill';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { formatClockTime } from '@/lib/clock-time';
import { formatDate } from '@/lib/format-date';
import { formatMinutes } from '@/lib/minutes';
import { nextTableUrl } from '@/lib/table-query';
import type { QueryChanges } from '@/lib/table-query';
import { cn } from '@/lib/utils';
import { daily } from '@/routes/attendance';

type DailyRow = {
    employee: {
        id: number;
        name: string;
        code: string;
        photo_url: string | null;
        department: string | null;
        department_id: number;
        designation: string | null;
    };
    shift: string | null;
    /** The person has a row for the day. */
    has_record: boolean;
    /** No row and not an off day: "Not in yet" (open day) or "No record" (past day). Never an exempt person on the open day. */
    not_in: boolean;
    /** The person is attendance-exempt (with no row on the open day: shown as Exempt, not missing). */
    exempt: boolean;
    /** The day is before the attendance start date: listed, never corrected. */
    before_start: boolean;
    /** "H:i" wall-clock times. */
    clock_in: string | null;
    clock_out: string | null;
    clock_out_next_day: boolean;
    worked_minutes: number;
    late_minutes: number;
    early_minutes: number;
    overtime_minutes: number;
    /** With no record: the open day's Holiday / Weekend for this person, else null. */
    status: AttendanceStatusValue;
    /** Half-day leave: 'am' first half, 'pm' second half; the status is the worked half. */
    leave_half: 'am' | 'pm' | null;
    is_manual: boolean;
    /** The manual row's status was chosen by hand. */
    status_overridden: boolean;
    needs_attention: boolean;
    note: string | null;
    /** The viewer may correct this person on this day. */
    can_edit: boolean;
};

type Summary = {
    total: number;
    present: number;
    late: number;
    half_day: number;
    absent: number;
    on_leave: number;
    holiday_weekend: number;
    pending: number;
    not_in: number;
    needs_attention: number;
};

type Filters = {
    search: string;
    department: number | null;
    status: string | null;
    attention: boolean;
};

type Props = {
    /** "Y-m-d" of the day shown, and of the open day. */
    date: string;
    today: string;
    isOpen: boolean;
    previousDate: string;
    nextDate: string | null;
    summary: Summary;
    rows: DailyRow[];
    filters: Filters;
    departments: { id: number; name: string }[];
    overrideStatuses: string[];
    can: { edit: boolean };
};

const ALL = 'all';

type Chip = {
    key: string;
    label: string;
    count: number;
    /** The status filter it sets; 'attention' sets the needs-attention filter instead. */
    filter: string;
    dot: string;
};

const STATUS_FILTERS: { value: string; label: string }[] = [
    { value: 'present', label: STATUS_META.present.label },
    { value: 'late', label: STATUS_META.late.label },
    { value: 'half_day', label: STATUS_META.half_day.label },
    { value: 'absent', label: STATUS_META.absent.label },
    { value: 'on_leave', label: STATUS_META.on_leave.label },
    { value: 'holiday_weekend', label: 'Holiday / Weekend' },
    { value: 'not_in', label: 'Not in yet / No record' },
    { value: 'pending', label: 'Pending' },
];

const dash = <span className="text-muted-foreground">–</span>;

const minutesCell = (value: number, tone?: string) =>
    value > 0 ? <span className={tone}>{formatMinutes(value)}</span> : dash;

/** On a phone the worked / late / early / overtime columns are hidden: one compact line instead. */
function compactMinutes(row: DailyRow): string {
    return [
        row.worked_minutes > 0 && `Worked ${formatMinutes(row.worked_minutes)}`,
        row.late_minutes > 0 && `Late ${formatMinutes(row.late_minutes)}`,
        row.early_minutes > 0 && `Early ${formatMinutes(row.early_minutes)}`,
        row.overtime_minutes > 0 && `OT ${formatMinutes(row.overtime_minutes)}`,
    ]
        .filter(Boolean)
        .join(' · ');
}

export default function Daily({
    date,
    today,
    isOpen,
    previousDate,
    nextDate,
    summary,
    rows,
    filters,
    departments,
    overrideStatuses,
    can,
}: Props) {
    const { url, props } = usePage();
    const dateFormat = props.app.dateFormat;
    const [editing, setEditing] = useState<DailyRow | null>(null);
    const [dialogOpen, setDialogOpen] = useState(false);

    const visit = (changes: QueryChanges) =>
        router.get(
            nextTableUrl(url, changes),
            {},
            { preserveScroll: true, preserveState: true },
        );

    const goTo = (day: string) => visit({ date: day === today ? null : day });

    const toggleStatus = (value: string) =>
        visit({ status: filters.status === value ? null : value });

    const filtering =
        filters.search !== '' ||
        filters.department !== null ||
        filters.status !== null ||
        filters.attention;

    const chips: Chip[] = [
        {
            key: 'present',
            label: 'Present',
            count: summary.present,
            filter: 'present',
            dot: STATUS_META.present.dotClass,
        },
        {
            key: 'late',
            label: 'Late',
            count: summary.late,
            filter: 'late',
            dot: STATUS_META.late.dotClass,
        },
        {
            key: 'half_day',
            label: 'Half Day',
            count: summary.half_day,
            filter: 'half_day',
            dot: STATUS_META.half_day.dotClass,
        },
        {
            key: 'absent',
            label: 'Absent',
            count: summary.absent,
            filter: 'absent',
            dot: STATUS_META.absent.dotClass,
        },
        {
            key: 'on_leave',
            label: 'On Leave',
            count: summary.on_leave,
            filter: 'on_leave',
            dot: STATUS_META.on_leave.dotClass,
        },
        {
            key: 'holiday_weekend',
            label: 'Holiday / Weekend',
            count: summary.holiday_weekend,
            filter: 'holiday_weekend',
            dot: STATUS_META.holiday.dotClass,
        },
    ];

    if (summary.not_in > 0) {
        chips.push({
            key: 'not_in',
            label: isOpen ? 'Not in yet' : 'No record',
            count: summary.not_in,
            filter: 'not_in',
            dot: STATUS_META.pending.dotClass,
        });
    }

    if (summary.pending > 0) {
        chips.push({
            key: 'pending',
            label: 'Pending',
            count: summary.pending,
            filter: 'pending',
            dot: STATUS_META.pending.dotClass,
        });
    }

    const statusCell = (row: DailyRow) => {
        let pill;

        if (!row.has_record && row.status === null && !row.not_in) {
            pill = <StatusPill tone="grey">Exempt</StatusPill>;
        } else if (!row.has_record && row.status === null) {
            pill = (
                <StatusPill tone="grey">
                    {isOpen ? 'Not in yet' : 'No record'}
                </StatusPill>
            );
        } else {
            pill = <AttendanceStatusPill status={row.status} />;
        }

        return (
            <div className="grid gap-1">
                <div className="flex flex-wrap items-center gap-1.5">
                    {pill}
                    {halfLeaveLabel(row.leave_half) !== null && (
                        <span className="rounded-full bg-blue-50 px-2 py-0.5 text-[11px] font-medium text-blue-700 dark:bg-blue-500/15 dark:text-blue-300">
                            {halfLeaveLabel(row.leave_half)}
                        </span>
                    )}
                    {row.is_manual && (
                        <span
                            className="rounded-full border px-2 py-0.5 text-[11px] font-medium text-muted-foreground"
                            title={row.note ?? 'Corrected by hand'}
                        >
                            Manual
                        </span>
                    )}
                    {row.needs_attention && (
                        <span
                            className="inline-flex text-amber-600 dark:text-amber-400"
                            title="Needs attention"
                        >
                            <AlertTriangle
                                aria-hidden="true"
                                className="size-4"
                            />
                            <span className="sr-only">Needs attention</span>
                        </span>
                    )}
                </div>
                {row.before_start && (
                    <p className="text-xs text-muted-foreground">
                        Before start date
                    </p>
                )}
                {row.is_manual && row.note && (
                    // Visible text, so the note is readable by keyboard, touch and screen readers alike.
                    <p className="max-w-56 text-xs break-words text-muted-foreground">
                        {row.note}
                    </p>
                )}
            </div>
        );
    };

    const columns: DataTableColumn<DailyRow>[] = [
        {
            key: 'employee',
            header: 'Employee',
            cell: (row) => (
                <div className="flex min-w-0 items-center gap-3">
                    <Avatar
                        src={row.employee.photo_url}
                        name={row.employee.name}
                    />
                    <div className="min-w-0">
                        <p className="truncate font-medium">
                            {row.employee.name}
                        </p>
                        <p className="truncate text-xs text-muted-foreground">
                            {row.employee.code}
                            {row.employee.department && (
                                <span className="hidden sm:inline">
                                    {' '}
                                    · {row.employee.department}
                                </span>
                            )}
                        </p>
                    </div>
                </div>
            ),
        },
        {
            key: 'shift',
            header: 'Shift',
            className: 'hidden text-muted-foreground xl:table-cell',
            cell: (row) => row.shift ?? dash,
        },
        {
            key: 'in',
            header: 'Clock-in',
            className: 'whitespace-nowrap tabular-nums',
            cell: (row) => {
                const compact = compactMinutes(row);

                return (
                    <div>
                        {row.clock_in ? formatClockTime(row.clock_in) : dash}
                        {compact !== '' && (
                            <p className="mt-0.5 text-xs whitespace-normal text-muted-foreground lg:hidden">
                                {compact}
                            </p>
                        )}
                    </div>
                );
            },
        },
        {
            key: 'out',
            header: 'Clock-out',
            className: 'whitespace-nowrap tabular-nums',
            cell: (row) =>
                row.clock_out ? (
                    <span>
                        {formatClockTime(row.clock_out)}
                        {row.clock_out_next_day && (
                            <span
                                className="ml-1 text-xs text-muted-foreground"
                                title="The next morning"
                            >
                                +1
                            </span>
                        )}
                    </span>
                ) : (
                    dash
                ),
        },
        {
            key: 'worked',
            header: 'Worked',
            className: 'hidden whitespace-nowrap tabular-nums md:table-cell',
            cell: (row) => minutesCell(row.worked_minutes),
        },
        {
            key: 'late',
            header: 'Late',
            className: 'hidden whitespace-nowrap tabular-nums lg:table-cell',
            cell: (row) =>
                minutesCell(
                    row.late_minutes,
                    'text-amber-700 dark:text-amber-300',
                ),
        },
        {
            key: 'early',
            header: 'Early',
            className: 'hidden whitespace-nowrap tabular-nums lg:table-cell',
            cell: (row) =>
                minutesCell(
                    row.early_minutes,
                    'text-amber-700 dark:text-amber-300',
                ),
        },
        {
            key: 'overtime',
            header: 'Overtime',
            className: 'hidden whitespace-nowrap tabular-nums lg:table-cell',
            cell: (row) =>
                minutesCell(
                    row.overtime_minutes,
                    'text-emerald-700 dark:text-emerald-300',
                ),
        },
        {
            key: 'status',
            header: 'Status',
            cell: statusCell,
        },
        {
            key: 'actions',
            header: <span className="sr-only">Actions</span>,
            className: 'w-12',
            cell: (row) =>
                can.edit &&
                row.can_edit && (
                    <div className="flex justify-end">
                        <Button
                            variant="ghost"
                            size="icon"
                            className="size-8"
                            aria-label={`Edit attendance of ${row.employee.name}`}
                            title="Edit"
                            onClick={() => {
                                setEditing(row);
                                setDialogOpen(true);
                            }}
                        >
                            <Pencil />
                        </Button>
                    </div>
                ),
        },
    ];

    return (
        <>
            <Head title="Daily Attendance" />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title="Daily Attendance"
                    subtitle={`${formatDate(date, dateFormat)}${isOpen ? ' · Today, still open' : ''}`}
                    actions={
                        <div className="flex flex-wrap items-center gap-2">
                            <Button
                                variant="outline"
                                size="icon"
                                aria-label="Previous day"
                                onClick={() => goTo(previousDate)}
                            >
                                <ChevronLeft />
                            </Button>
                            <div className="relative">
                                <CalendarDays
                                    aria-hidden="true"
                                    className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                                />
                                <Input
                                    type="date"
                                    aria-label="Day"
                                    value={date}
                                    max={today}
                                    className="w-44 pl-9"
                                    onChange={(e) => {
                                        const value = e.target.value;

                                        if (
                                            /^\d{4}-\d{2}-\d{2}$/.test(value) &&
                                            value <= today
                                        ) {
                                            goTo(value);
                                        }
                                    }}
                                />
                            </div>
                            <Button
                                variant="outline"
                                size="icon"
                                aria-label="Next day"
                                disabled={nextDate === null}
                                onClick={() => nextDate && goTo(nextDate)}
                            >
                                <ChevronRight />
                            </Button>
                            <Button
                                variant="outline"
                                disabled={isOpen}
                                onClick={() => goTo(today)}
                            >
                                Today
                            </Button>
                        </div>
                    }
                />

                <div
                    role="group"
                    aria-label="Summary"
                    className="grid grid-cols-2 gap-2 sm:grid-cols-4 lg:flex lg:flex-wrap"
                >
                    {chips.map((chip) => {
                        const active = filters.status === chip.filter;

                        return (
                            <button
                                key={chip.key}
                                type="button"
                                aria-pressed={active}
                                onClick={() => toggleStatus(chip.filter)}
                                className={cn(
                                    'flex items-center justify-between gap-3 rounded-[10px] border bg-card px-3 py-2 text-left text-sm shadow-xs transition-colors hover:bg-muted/60 lg:min-w-36',
                                    active &&
                                        'border-primary ring-1 ring-primary',
                                )}
                            >
                                <span className="flex items-center gap-2 text-muted-foreground">
                                    <span
                                        aria-hidden="true"
                                        className={cn(
                                            'size-2 rounded-full',
                                            chip.dot,
                                        )}
                                    />
                                    {chip.label}
                                </span>
                                <span className="text-base font-semibold tabular-nums">
                                    {chip.count}
                                </span>
                            </button>
                        );
                    })}
                    <button
                        type="button"
                        aria-pressed={filters.attention}
                        onClick={() =>
                            visit({ attention: filters.attention ? null : 1 })
                        }
                        className={cn(
                            'flex items-center justify-between gap-3 rounded-[10px] border bg-card px-3 py-2 text-left text-sm shadow-xs transition-colors hover:bg-muted/60 lg:min-w-36',
                            filters.attention &&
                                'border-amber-500 ring-1 ring-amber-500',
                        )}
                    >
                        <span className="flex items-center gap-2 text-muted-foreground">
                            <AlertTriangle
                                aria-hidden="true"
                                className="size-3.5 text-amber-600 dark:text-amber-400"
                            />
                            Needs attention
                        </span>
                        <span className="text-base font-semibold tabular-nums">
                            {summary.needs_attention}
                        </span>
                    </button>
                </div>

                <DataTable
                    label="Daily attendance"
                    columns={columns}
                    rows={rows}
                    rowKey={(row) => row.employee.id}
                    search={{
                        value: filters.search,
                        placeholder: 'Search by name or ID',
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
                                    className="w-full sm:w-44"
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
                                value={filters.status ?? ALL}
                                onValueChange={(value) =>
                                    visit({
                                        status: value === ALL ? null : value,
                                    })
                                }
                            >
                                <SelectTrigger
                                    className="w-full sm:w-44"
                                    aria-label="Filter by status"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={ALL}>
                                        All Statuses
                                    </SelectItem>
                                    {STATUS_FILTERS.map((option) => (
                                        <SelectItem
                                            key={option.value}
                                            value={option.value}
                                        >
                                            {option.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <div className="flex items-center gap-2 py-1">
                                <Checkbox
                                    id="daily-attention"
                                    checked={filters.attention}
                                    onCheckedChange={(v) =>
                                        visit({
                                            attention: v === true ? 1 : null,
                                        })
                                    }
                                />
                                <Label
                                    htmlFor="daily-attention"
                                    className="font-normal whitespace-nowrap"
                                >
                                    Needs attention only
                                </Label>
                            </div>
                        </>
                    }
                    emptyText={
                        filtering
                            ? 'Nobody matches these filters.'
                            : 'Nobody is expected to have attendance on this day.'
                    }
                    emptyAction={
                        filtering ? (
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() =>
                                    visit({
                                        search: null,
                                        department: null,
                                        status: null,
                                        attention: null,
                                    })
                                }
                            >
                                Clear filters
                            </Button>
                        ) : undefined
                    }
                />
            </div>

            {can.edit && editing && (
                <CorrectionDialog
                    open={dialogOpen}
                    onClose={() => setDialogOpen(false)}
                    employee={{
                        id: editing.employee.id,
                        name: editing.employee.name,
                    }}
                    date={date}
                    today={today}
                    current={{
                        clock_in: editing.clock_in,
                        clock_out: editing.clock_out,
                        status: editing.status,
                        is_manual: editing.is_manual,
                        status_overridden: editing.status_overridden,
                    }}
                    overrideStatuses={overrideStatuses}
                />
            )}
        </>
    );
}

Daily.layout = {
    breadcrumbs: [{ title: 'Daily Attendance', href: daily() }],
};
