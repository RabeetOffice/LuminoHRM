import { Head, router } from '@inertiajs/react';
import { Pencil, Plus, Star, Trash2 } from 'lucide-react';
import { useState } from 'react';
import ShiftController from '@/actions/App/Http/Controllers/Attendance/ShiftController';
import { ShiftDialog, dayLabel } from '@/components/attendance/shift-dialog';
import type { ShiftRow } from '@/components/attendance/shift-dialog';
import { ConfirmDialog } from '@/components/hrm/confirm-dialog';
import { PageHeader } from '@/components/hrm/page-header';
import { StatusPill } from '@/components/hrm/status-pill';
import { Button } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useCan } from '@/hooks/use-can';
import { formatClockRange } from '@/lib/clock-time';
import { index } from '@/routes/attendance/shifts';

type Props = {
    /** Every shift, the default first, then by name. */
    shifts: ShiftRow[];
    weekdays: string[];
};

type Pending =
    | { kind: 'delete'; row: ShiftRow }
    | { kind: 'default'; row: ShiftRow }
    | null;

const hours = (value: number) =>
    `${Number.isInteger(value) ? value : value.toFixed(1)} h`;

const offsText = (offs: string[]) =>
    offs.length === 0
        ? 'None'
        : offs.map((day) => dayLabel(day).slice(0, 3)).join(', ');

export default function Shifts({ shifts, weekdays }: Props) {
    const can = useCan();
    const canEdit = can('attendance.edit');

    const [editing, setEditing] = useState<ShiftRow | 'new' | null>(null);
    const [pending, setPending] = useState<Pending>(null);
    const [busy, setBusy] = useState(false);

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
                setPending(null);
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

                <div className="overflow-hidden rounded-xl border bg-card shadow-xs">
                    <Table aria-label="Shifts">
                        <TableHeader>
                            <TableRow>
                                <TableHead>Name</TableHead>
                                <TableHead>Hours</TableHead>
                                <TableHead className="hidden md:table-cell">
                                    Grace
                                </TableHead>
                                <TableHead className="hidden md:table-cell">
                                    Half day under
                                </TableHead>
                                <TableHead className="hidden lg:table-cell">
                                    Weekly offs
                                </TableHead>
                                <TableHead className="hidden sm:table-cell">
                                    Employees
                                </TableHead>
                                <TableHead className="w-28">
                                    <span className="sr-only">Actions</span>
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {shifts.map((shift) => (
                                <TableRow key={shift.id}>
                                    <TableCell className="font-medium">
                                        <div className="flex flex-wrap items-center gap-2">
                                            {shift.name}
                                            {shift.is_default && (
                                                <StatusPill tone="green">
                                                    Default
                                                </StatusPill>
                                            )}
                                        </div>
                                    </TableCell>
                                    <TableCell className="whitespace-nowrap tabular-nums">
                                        {formatClockRange(
                                            shift.start_time,
                                            shift.end_time,
                                        )}
                                    </TableCell>
                                    <TableCell className="hidden text-muted-foreground tabular-nums md:table-cell">
                                        {shift.grace_minutes} min
                                    </TableCell>
                                    <TableCell className="hidden text-muted-foreground tabular-nums md:table-cell">
                                        {hours(shift.half_day_hours)}
                                    </TableCell>
                                    <TableCell className="hidden text-muted-foreground lg:table-cell">
                                        {offsText(shift.weekly_offs)}
                                    </TableCell>
                                    <TableCell className="hidden text-muted-foreground tabular-nums sm:table-cell">
                                        {shift.employees_count}
                                    </TableCell>
                                    <TableCell>
                                        {canEdit && (
                                            <div className="flex justify-end gap-1">
                                                {!shift.is_default && (
                                                    <Button
                                                        variant="ghost"
                                                        size="icon"
                                                        className="size-8"
                                                        aria-label={`Make ${shift.name} the default shift`}
                                                        title="Make default"
                                                        onClick={() =>
                                                            setPending({
                                                                kind: 'default',
                                                                row: shift,
                                                            })
                                                        }
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
                                                    onClick={() =>
                                                        setEditing(shift)
                                                    }
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
                                                        onClick={() =>
                                                            setPending({
                                                                kind: 'delete',
                                                                row: shift,
                                                            })
                                                        }
                                                    >
                                                        <Trash2 />
                                                    </Button>
                                                )}
                                            </div>
                                        )}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>
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
                open={pending !== null}
                onOpenChange={(open) => !open && setPending(null)}
                title={
                    pending?.kind === 'default'
                        ? 'Make this the default shift?'
                        : 'Delete shift?'
                }
                description={
                    pending?.kind === 'default'
                        ? `Everyone without a shift of their own will work "${pending.row.name}" from today. Days already closed keep their values.`
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
