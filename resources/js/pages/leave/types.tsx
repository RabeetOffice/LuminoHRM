import { Head, router } from '@inertiajs/react';
import { Archive, Pencil, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import LeaveTypeController from '@/actions/App/Http/Controllers/Leave/LeaveTypeController';
import { ConfirmDialog } from '@/components/hrm/confirm-dialog';
import { DataTable } from '@/components/hrm/data-table';
import type { DataTableColumn } from '@/components/hrm/data-table';
import { PageHeader } from '@/components/hrm/page-header';
import { StatusPill } from '@/components/hrm/status-pill';
import { LeaveColourDot } from '@/components/leave/leave-type-badge';
import { LeaveTypeDialog } from '@/components/leave/leave-type-dialog';
import type { LeaveTypeRow } from '@/components/leave/leave-type-dialog';
import { Button } from '@/components/ui/button';
import { useCan } from '@/hooks/use-can';
import { index } from '@/routes/leave/types';

type Props = {
    /** Every leave type, in sort order. */
    types: LeaveTypeRow[];
    /** The palette, config('hrm.leave.colours'). */
    colours: string[];
};

/** 14 → "14 days", 1 → "1 day", 7.5 → "7.5 days". */
const daysText = (value: number) => {
    const shown = Number.isInteger(value) ? String(value) : value.toFixed(1);

    return value === 1 ? '1 day' : `${shown} days`;
};

const quotaText = (type: LeaveTypeRow) =>
    type.is_paid ? daysText(type.yearly_quota) : 'No limit';

const carryText = (type: LeaveTypeRow) =>
    type.is_paid && type.carry_forward
        ? `Up to ${daysText(type.carry_forward_cap)}`
        : 'No';

export default function LeaveTypes({ types, colours }: Props) {
    const can = useCan();
    const canEdit = can('settings.attendance-leave');

    const [editing, setEditing] = useState<LeaveTypeRow | 'new' | null>(null);
    // The last row asked about stays set while the dialog animates closed, so its text does not change mid-close.
    const [deleting, setDeleting] = useState<LeaveTypeRow | null>(null);
    const [confirmOpen, setConfirmOpen] = useState(false);
    const [busy, setBusy] = useState(false);

    const askDelete = (row: LeaveTypeRow) => {
        setDeleting(row);
        setConfirmOpen(true);
    };

    const confirmDelete = () => {
        if (!deleting) {
            return;
        }

        const options = {
            preserveScroll: true,
            onStart: () => setBusy(true),
            // A refusal sent back as a validation error (not a flash message) would otherwise show nothing.
            onError: (errors: Record<string, string>) => {
                toast.error(
                    Object.values(errors)[0] ??
                        'Something went wrong. Please try again.',
                );
            },
            // Success and flash refusals both end here; the flash toast says which.
            onFinish: () => {
                setBusy(false);
                setConfirmOpen(false);
            },
        };

        if (deleting.has_history) {
            // A type with history is never deleted: make it inactive instead. Only the flag is sent, so a stale copy
            // of the other fields can never overwrite someone else's edit.
            router.patch(
                LeaveTypeController.setActive(deleting.id).url,
                { is_active: false },
                options,
            );
        } else {
            router.delete(
                LeaveTypeController.destroy(deleting.id).url,
                options,
            );
        }
    };

    const columns: DataTableColumn<LeaveTypeRow>[] = [
        {
            key: 'name',
            header: 'Name',
            className: 'font-medium',
            cell: (type) => (
                <div className="grid gap-1">
                    <div className="flex items-center gap-2">
                        <LeaveColourDot colour={type.colour} />
                        {type.name}
                    </div>
                    {/* The columns hidden on smaller screens, as one line under the name. */}
                    <p className="pl-[18px] text-xs font-normal text-muted-foreground md:hidden">
                        {quotaText(type)} · {type.is_paid ? 'Paid' : 'Unpaid'} ·
                        Carry forward {carryText(type).toLowerCase()}
                    </p>
                </div>
            ),
        },
        {
            key: 'quota',
            header: 'Yearly quota',
            className:
                'hidden whitespace-nowrap text-muted-foreground tabular-nums md:table-cell',
            cell: (type) => quotaText(type),
        },
        {
            key: 'paid',
            header: 'Pay',
            className: 'hidden md:table-cell',
            cell: (type) =>
                type.is_paid ? (
                    <StatusPill tone="green">Paid</StatusPill>
                ) : (
                    <StatusPill tone="grey">Unpaid</StatusPill>
                ),
        },
        {
            key: 'carry',
            header: 'Carry forward',
            className:
                'hidden whitespace-nowrap text-muted-foreground tabular-nums md:table-cell',
            cell: (type) => carryText(type),
        },
        {
            key: 'status',
            header: 'Status',
            cell: (type) =>
                type.is_active ? (
                    <StatusPill tone="green">Active</StatusPill>
                ) : (
                    <StatusPill tone="grey">Inactive</StatusPill>
                ),
        },
        {
            key: 'actions',
            header: <span className="sr-only">Actions</span>,
            className: 'w-24',
            cell: (type) =>
                canEdit && (
                    <div className="flex justify-end gap-1">
                        <Button
                            variant="ghost"
                            size="icon"
                            className="size-8"
                            aria-label={`Edit ${type.name}`}
                            title="Edit"
                            onClick={() => setEditing(type)}
                        >
                            <Pencil />
                        </Button>
                        {/* The system type is never deleted; an inactive type with history has nothing left to offer. */}
                        {!type.is_system &&
                            !(type.has_history && !type.is_active) &&
                            (type.has_history ? (
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    className="size-8"
                                    aria-label={`Make ${type.name} inactive`}
                                    title="Make inactive"
                                    onClick={() => askDelete(type)}
                                >
                                    <Archive />
                                </Button>
                            ) : (
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    className="size-8 text-destructive hover:text-destructive"
                                    aria-label={`Delete ${type.name}`}
                                    title="Delete"
                                    onClick={() => askDelete(type)}
                                >
                                    <Trash2 />
                                </Button>
                            ))}
                    </div>
                ),
        },
    ];

    return (
        <>
            <Head title="Leave Types" />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title="Leave Types"
                    subtitle="The kinds of leave staff can apply for, their yearly quota, carry forward and calendar colour."
                    actions={
                        canEdit && (
                            <Button onClick={() => setEditing('new')}>
                                <Plus />
                                Add leave type
                            </Button>
                        )
                    }
                />

                <DataTable
                    label="Leave types"
                    columns={columns}
                    rows={types}
                    rowKey={(type) => type.id}
                    emptyText="No leave types yet."
                />
            </div>

            {editing !== null && (
                <LeaveTypeDialog
                    key={editing === 'new' ? 'new' : editing.id}
                    type={editing === 'new' ? null : editing}
                    colours={colours}
                    onClose={() => setEditing(null)}
                />
            )}

            <ConfirmDialog
                open={confirmOpen}
                onOpenChange={setConfirmOpen}
                title={
                    deleting?.has_history
                        ? 'Make this type inactive?'
                        : 'Delete leave type?'
                }
                description={
                    deleting
                        ? deleting.has_history
                            ? `"${deleting.name}" has leave history, so it cannot be deleted. Make it inactive instead: it is no longer offered when applying, and its history stays.`
                            : `"${deleting.name}" will be deleted. This cannot be undone.`
                        : ''
                }
                confirmLabel={
                    deleting?.has_history ? 'Make inactive' : undefined
                }
                destructive={!deleting?.has_history}
                processing={busy}
                onConfirm={confirmDelete}
            />
        </>
    );
}

LeaveTypes.layout = {
    breadcrumbs: [{ title: 'Leave Types', href: index() }],
};
