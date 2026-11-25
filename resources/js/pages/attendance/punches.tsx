import { Head, router, useForm, usePage } from '@inertiajs/react';
import { UserPlus } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import PunchController from '@/actions/App/Http/Controllers/Attendance/PunchController';
import { DataTable } from '@/components/hrm/data-table';
import type {
    DataTableColumn,
    DataTablePagination,
} from '@/components/hrm/data-table';
import { PageHeader } from '@/components/hrm/page-header';
import { StatusPill } from '@/components/hrm/status-pill';
import { Field } from '@/components/settings/settings-form';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { formatClockTime } from '@/lib/clock-time';
import { formatDate } from '@/lib/format-date';
import { nextTableUrl } from '@/lib/table-query';
import { punches as punchesRoute } from '@/routes/attendance';

type PunchRow = {
    id: number;
    /** Calendar date of the punch time, "Y-m-d". */
    date: string;
    /** "H:i:s", the machine's wall clock. */
    time: string;
    /** The attendance day it counts for (the day before when punched before the day cutoff). */
    punch_date: string;
    device_user_id: string;
    employee: { id: number; name: string; code: string | null } | null;
    device: string | null;
    verify: string | null;
    source: string;
};

type EmployeeOption = {
    id: number;
    name: string;
    code: string | null;
    device_user_id: string | null;
};

type Filters = {
    from: string;
    to: string;
    device: number | null;
    employee: number | null;
    unknown: boolean;
    user: string;
    per_page: number;
};

type Props = {
    punches: DataTablePagination & { data: PunchRow[] };
    filters: Filters;
    devices: { id: number; name: string }[];
    employees: EmployeeOption[];
    today: string;
    can: { assign: boolean };
};

const ALL = 'all';

const SOURCES: Record<string, string> = {
    device: 'Machine',
    import: 'Import',
    manual: 'Manual',
};

export default function Punches({
    punches,
    filters,
    devices,
    employees,
    can,
}: Props) {
    const { url, props } = usePage();
    const dateFormat = props.app.dateFormat;
    const [assigning, setAssigning] = useState<string | null>(null);

    const filter = (changes: Record<string, string | null>) => {
        router.get(
            nextTableUrl(url, { ...changes, page: null }),
            {},
            { preserveScroll: true, preserveState: true, replace: true },
        );
    };

    const filtering =
        filters.from !== '' ||
        filters.to !== '' ||
        filters.device !== null ||
        filters.employee !== null ||
        filters.unknown ||
        filters.user !== '';

    const columns: DataTableColumn<PunchRow>[] = [
        {
            key: 'time',
            header: 'Time',
            className: 'whitespace-nowrap tabular-nums',
            cell: (row) => (
                <div className="grid gap-0.5">
                    <span className="font-medium">
                        {formatClockTime(row.time)}
                    </span>
                    <span className="text-xs text-muted-foreground">
                        {formatDate(row.date, dateFormat)}
                    </span>
                    {row.punch_date !== row.date && (
                        <span className="text-xs text-amber-700 dark:text-amber-300">
                            Counts for {formatDate(row.punch_date, dateFormat)}
                        </span>
                    )}
                </div>
            ),
        },
        {
            key: 'user',
            header: 'Machine user ID',
            className: 'tabular-nums',
            cell: (row) => row.device_user_id,
        },
        {
            key: 'employee',
            header: 'Employee',
            cell: (row) =>
                row.employee ? (
                    <div className="grid gap-0.5">
                        <span className="font-medium">{row.employee.name}</span>
                        {row.employee.code && (
                            <span className="text-xs text-muted-foreground">
                                {row.employee.code}
                            </span>
                        )}
                    </div>
                ) : (
                    <div className="flex flex-wrap items-center gap-2">
                        <StatusPill tone="amber">Unknown</StatusPill>
                        {can.assign && (
                            <Button
                                variant="outline"
                                size="sm"
                                className="h-7"
                                onClick={() => setAssigning(row.device_user_id)}
                            >
                                <UserPlus />
                                Assign
                            </Button>
                        )}
                    </div>
                ),
        },
        {
            key: 'device',
            header: 'Device',
            className: 'hidden text-muted-foreground md:table-cell',
            cell: (row) => row.device ?? '–',
        },
        {
            key: 'verify',
            header: 'Verified by',
            className: 'hidden text-muted-foreground lg:table-cell',
            cell: (row) => row.verify ?? '–',
        },
        {
            key: 'source',
            header: 'Source',
            className: 'hidden text-muted-foreground sm:table-cell',
            cell: (row) => SOURCES[row.source] ?? row.source,
        },
    ];

    return (
        <>
            <Head title="Raw Punches" />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title="Raw Punches"
                    subtitle="Every punch the machines sent, newest first, exactly as received. A punch before the day cutoff counts for the previous day."
                />

                <DataTable
                    label="Punches"
                    columns={columns}
                    rows={punches.data}
                    rowKey={(row) => row.id}
                    pagination={punches}
                    search={{
                        value: filters.user,
                        param: 'user',
                        placeholder: 'Machine user ID',
                        label: 'Filter by machine user ID',
                    }}
                    toolbar={
                        <>
                            <Select
                                value={
                                    filters.device === null
                                        ? ALL
                                        : String(filters.device)
                                }
                                onValueChange={(value) =>
                                    filter({
                                        device: value === ALL ? null : value,
                                    })
                                }
                            >
                                <SelectTrigger
                                    className="w-full sm:w-40"
                                    aria-label="Filter by device"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={ALL}>
                                        All devices
                                    </SelectItem>
                                    {devices.map((device) => (
                                        <SelectItem
                                            key={device.id}
                                            value={String(device.id)}
                                        >
                                            {device.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <Select
                                value={
                                    filters.employee === null
                                        ? ALL
                                        : String(filters.employee)
                                }
                                disabled={filters.unknown}
                                onValueChange={(value) =>
                                    filter({
                                        employee: value === ALL ? null : value,
                                    })
                                }
                            >
                                <SelectTrigger
                                    className="w-full sm:w-48"
                                    aria-label="Filter by employee"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={ALL}>
                                        All employees
                                    </SelectItem>
                                    {employees.map((employee) => (
                                        <SelectItem
                                            key={employee.id}
                                            value={String(employee.id)}
                                        >
                                            {employee.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <Input
                                type="date"
                                value={filters.from}
                                max={filters.to || undefined}
                                onChange={(e) =>
                                    filter({ from: e.target.value || null })
                                }
                                aria-label="From attendance day"
                                className="w-[calc(50%-0.25rem)] sm:w-36"
                            />
                            <Input
                                type="date"
                                value={filters.to}
                                min={filters.from || undefined}
                                onChange={(e) =>
                                    filter({ to: e.target.value || null })
                                }
                                aria-label="To attendance day"
                                className="w-[calc(50%-0.25rem)] sm:w-36"
                            />
                            <div className="flex items-center gap-2">
                                <Checkbox
                                    id="punches-unknown"
                                    checked={filters.unknown}
                                    onCheckedChange={(v) =>
                                        filter({
                                            unknown: v === true ? '1' : null,
                                            employee: null,
                                        })
                                    }
                                />
                                <Label
                                    htmlFor="punches-unknown"
                                    className="font-normal whitespace-nowrap"
                                >
                                    Unknown IDs only
                                </Label>
                            </div>
                            {filtering && (
                                <Button
                                    variant="ghost"
                                    onClick={() =>
                                        filter({
                                            from: null,
                                            to: null,
                                            device: null,
                                            employee: null,
                                            unknown: null,
                                            user: null,
                                        })
                                    }
                                >
                                    Clear filters
                                </Button>
                            )}
                        </>
                    }
                    emptyText={
                        filtering
                            ? 'No punches match your filters.'
                            : 'No punches yet. They appear here as soon as a machine sends them.'
                    }
                />
            </div>

            {assigning !== null && (
                <AssignDialog
                    key={assigning}
                    deviceUserId={assigning}
                    employees={employees.filter(
                        (e) => e.device_user_id === null,
                    )}
                    onClose={() => setAssigning(null)}
                />
            )}
        </>
    );
}

function AssignDialog({
    deviceUserId,
    employees,
    onClose,
}: {
    deviceUserId: string;
    /** Employees without a machine user ID. */
    employees: EmployeeOption[];
    onClose: () => void;
}) {
    const form = useForm({
        device_user_id: deviceUserId,
        employee_id: '',
    });
    const { data, errors } = form;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.submit(PunchController.assign(), {
            preserveScroll: true,
            onSuccess: () => onClose(),
        });
    };

    return (
        <Dialog
            open
            onOpenChange={(open) => !open && !form.processing && onClose()}
        >
            <DialogContent>
                <form onSubmit={submit} className="grid gap-4" noValidate>
                    <DialogHeader>
                        <DialogTitle>
                            Assign machine user ID {deviceUserId}
                        </DialogTitle>
                        <DialogDescription>
                            This saves the ID on the employee's record, as the
                            employee form does. Their punches under this ID from
                            the joining date on are then worked into attendance.
                        </DialogDescription>
                    </DialogHeader>

                    <Field
                        id="assign-employee"
                        label="Employee"
                        error={
                            errors.employee_id ??
                            errors.device_user_id ??
                            (errors as Record<string, string>).email
                        }
                        hint={
                            employees.length === 0
                                ? 'Every employee already has a machine user ID. Change one on the employee form.'
                                : 'Only employees without a machine user ID are listed.'
                        }
                    >
                        <Select
                            value={data.employee_id}
                            onValueChange={(value) =>
                                form.setData('employee_id', value)
                            }
                        >
                            <SelectTrigger
                                id="assign-employee"
                                aria-invalid={
                                    errors.employee_id ? true : undefined
                                }
                            >
                                <SelectValue placeholder="Choose an employee" />
                            </SelectTrigger>
                            <SelectContent>
                                {employees.map((employee) => (
                                    <SelectItem
                                        key={employee.id}
                                        value={String(employee.id)}
                                    >
                                        {employee.name}
                                        {employee.code
                                            ? ` (${employee.code})`
                                            : ''}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </Field>

                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={onClose}
                            disabled={form.processing}
                        >
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            disabled={
                                form.processing || data.employee_id === ''
                            }
                        >
                            {form.processing && <Spinner />}
                            Assign
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

Punches.layout = {
    breadcrumbs: [{ title: 'Raw Punches', href: punchesRoute() }],
};
