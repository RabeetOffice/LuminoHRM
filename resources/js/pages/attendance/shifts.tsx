import { Head, router } from '@inertiajs/react';
import { Pencil, Plus, Star, Trash2 } from 'lucide-react';
import { useState } from 'react';
import ShiftController from '@/actions/App/Http/Controllers/Attendance/ShiftController';
import {
    CLOSED_DAYS_NOTE,
    ShiftDialog,
    dayLabel,
} from '@/components/attendance/shift-dialog';
import type { ShiftRow } from '@/components/attendance/shift-dialog';
import { ConfirmDialog } from '@/components/hrm/confirm-dialog';
import { DataTable } from '@/components/hrm/data-table';
import type { DataTableColumn } from '@/components/hrm/data-table';
import { PageHeader } from '@/components/hrm/page-header';
import { StatusPill } from '@/components/hrm/status-pill';
import { Button } from '@/components/ui/button';
import { useCan } from '@/hooks/use-can';
import { formatClockRange } from '@/lib/clock-time';
import { index } from '@/routes/attendance/shifts';

type Props = {
    /** Every shift, the default first, then by name. */
    shifts: ShiftRow[];
    weekdays: string[];
};

type Pending = { kind: 'delete' | 'default'; row: ShiftRow };

const hours = (value: number) =>
    `${Number.isInteger(value) ? value : value.toFixed(1)} h`;

const offsText = (offs: string[]) =>
    offs.length === 0
        ? 'None'
        : offs.map((day) => dayLabel(day).slice(0, 3)).join(', ');

const employeesText = (count: number) =>
    count === 1 ? '1 employee' : `${count} employees`;

export default function Shifts({ shifts, weekdays }: Props) {
    const can = useCan();
    const canEdit = can('attendance.edit');

    const [editing, setEditing] = useState<ShiftRow | 'new' | null>(null);
    // The last row asked about stays set while the dialog animates closed, so its text does not change mid-close.
    const [pending, setPending] = useState<Pending | null>(null);
    const [confirmOpen, setConfirmOpen] = useState(false);
    const [busy, setBusy] = useState(false);

    const ask = (kind: Pending['kind'], row: ShiftRow) => {
        setPending({ kind, row });
        setConfirmOpen(true);
    };

    const confirm = () => {
        if (!pending) {
            return;
        }

        const options = {
            preserveScroll: true,
            onStart: () => setBusy(true),
            // Success and refusal both end here; the toast says which.
            onFinish: () => {
                setBusy(false);
                setConfirmOpen(false);
            },
        };

        if (pending.kind === 'delete') {
            router.delete(ShiftController.destroy(pending.row.id).url, options);
        } else {
            router.post(
                ShiftController.makeDefault(pending.row.id).url,
                {},
                options,
            );
        }
    };

    const columns: DataTableColumn<ShiftRow>[] = [
        {
            key: 'name',
            header: 'Name',
            className: 'font-medium',
            cell: (shift) => (
                <div className="grid gap-1">
                    <div className="flex flex-wrap items-center gap-2">
                        {shift.name}
                        {shift.is_default && (
                            <StatusPill tone="green">Default</StatusPill>
                        )}
                    </div>
                    {/* The columns hidden on smaller screens, as one line under the name. */}
                    <p className="text-xs font-normal text-muted-foreground lg:hidden">
                        <span className="md:hidden">
                            Grace {shift.grace_minutes} min · Half day under{' '}
                            {hours(shift.half_day_hours)} ·{' '}
                        </span>
                        Offs {offsText(shift.weekly_offs)}
                        <span className="sm:hidden">
                            {' '}
                            · {employeesText(shift.employees_count)}
                        </span>
                    </p>
                </div>
            ),
        },
        {
            key: 'hours',
            header: 'Hours',
            className: 'whitespace-nowrap tabular-nums',
            cell: (shift) => formatClockRange(shift.start_time, shift.end_time),
        },
        {
            key: 'grace',
            header: 'Grace',
            className:
                'hidden text-muted-foreground tabular-nums md:table-cell',
            cell: (shift) => `${shift.grace_minutes} min`,
        },
        {
            key: 'half-day',
            header: 'Half day under',
            className:
                'hidden text-muted-foreground tabular-nums md:table-cell',
            cell: (shift) => hours(shift.half_day_hours),
        },
        {
            key: 'offs',
            header: 'Weekly offs',
            className: 'hidden text-muted-foreground lg:table-cell',
            cell: (shift) => offsText(shift.weekly_offs),
        },
        {
            key: 'employees',
            header: 'Employees',
            className:
                'hidden text-muted-foreground tabular-nums sm:table-cell',
            cell: (shift) => shift.employees_count,
        },
        {
            key: 'actions',
            header: <span className="sr-only">Actions</span>,
            className: 'w-28',
            cell: (shift) =>
                canEdit && (
                    <div className="flex justify-end gap-1">
                        {!shift.is_default && (
                            <Button
                                variant="ghost"
                                size="icon"
                                className="size-8"
                                aria-label={`Make ${shift.name} the default shift`}
                                title="Make default"
                                onClick={() => ask('default', shift)}
                            >
                                <Star />
                            </Button>
                        )}
                        <Button
                            variant="ghost"
                            size="icon"
                            className="size-8"
                            aria-label={`Edit ${shift.name}`}
                            title="Edit"
                            onClick={() => setEditing(shift)}
                        >
                            <Pencil />
                        </Button>
                        {!shift.is_default && (
                            <Button
                                variant="ghost"
                                size="icon"
                                className="size-8 text-destructive hover:text-destructive"
                                aria-label={`Delete ${shift.name}`}
                                title="Delete"
                                onClick={() => ask('delete', shift)}
                            >
                                <Trash2 />
                            </Button>
                        )}
                    </div>
                ),
        },
    ];

    return (
        <>
            <Head title="Shifts" />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title="Shifts"
                    subtitle="Working hours, grace period, half-day rule and weekly offs. People without a shift of their own work the default shift."
                    actions={
                        canEdit && (
                            <Button onClick={() => setEditing('new')}>
                                <Plus />
                                Add shift
                            </Button>
                        )
                    }
                />

                <DataTable
                    label="Shifts"
                    columns={columns}
                    rows={shifts}
                    rowKey={(shift) => shift.id}
                    emptyText="No shifts yet."
                />
            </div>

            {editing !== null && (
                <ShiftDialog
                    key={editing === 'new' ? 'new' : editing.id}
                    shift={editing === 'new' ? null : editing}
                    weekdays={weekdays}
                    onClose={() => setEditing(null)}
                />
            )}

            <ConfirmDialog
                open={confirmOpen}
                onOpenChange={setConfirmOpen}
                title={
                    pending?.kind === 'default'
                        ? 'Make this the default shift?'
                        : 'Delete shift?'
                }
                description={
                    pending?.kind === 'default'
                        ? `Everyone without a shift of their own will work "${pending.row.name}" from today. ${CLOSED_DAYS_NOTE}`
                        : pending
                          ? `"${pending.row.name}" will be deleted. This cannot be undone.`
                          : ''
                }
                confirmLabel={
                    pending?.kind === 'default' ? 'Make default' : undefined
                }
                destructive={pending?.kind !== 'default'}
                processing={busy}
                onConfirm={confirm}
            />
        </>
    );
}

Shifts.layout = {
    breadcrumbs: [{ title: 'Shifts', href: index() }],
};
