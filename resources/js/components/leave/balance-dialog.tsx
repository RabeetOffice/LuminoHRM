import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import BalanceController from '@/actions/App/Http/Controllers/Leave/BalanceController';
import { LeaveTypeBadge } from '@/components/leave/leave-type-badge';
import { Field, fieldAria } from '@/components/settings/settings-form';
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
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { daysLabel, editedFigures, remainingAfter } from '@/lib/leave-balance';
import type { BalanceCell } from '@/lib/leave-balance';
import { cn } from '@/lib/utils';

type Props = {
    employee: { id: number; name: string; first_name: string };
    cell: BalanceCell;
    onClose: () => void;
};

/**
 * The breakdown of one balance (employee, type, year) and, when the viewer may
 * administer it, HR's edit of allocated, adjustment and opening used with a
 * reason. Remaining is recomputed live; a result below zero needs the
 * "overdrawn" box ticked (the server checks again). Mount it only while open.
 */
export function BalanceDialog({ employee, cell, onClose }: Props) {
    const editable = cell.can_edit && cell.is_paid;
    const form = useForm({
        allocated: String(cell.allocated),
        adjustment: String(cell.adjustment),
        opening_used: String(cell.opening_used),
        reason: '',
        confirm_overdrawn: false,
        // The figures the dialog was opened with: the server refuses the save
        // when the balance changed since (someone else saved in between).
        original_allocated: cell.allocated,
        original_adjustment: cell.adjustment,
        original_opening_used: cell.opening_used,
    });
    const { data, errors } = form;
    const staleError = (errors as Record<string, string | undefined>).balance;

    const remaining = editable
        ? remainingAfter(cell, data)
        : (cell.remaining ?? null);
    const shown = editable
        ? editedFigures(cell, data)
        : {
              allocated: cell.allocated,
              adjustment: cell.adjustment,
              opening_used: cell.opening_used,
          };
    const overdrawn = remaining !== null && remaining < 0;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.submit(
            BalanceController.update({
                employee: employee.id,
                type: cell.type_id,
                year: cell.year,
            }),
            { preserveScroll: true, onSuccess: () => onClose() },
        );
    };

    const rows: { label: string; value: string; strong?: boolean }[] =
        cell.unlimited
            ? [
                  { label: 'Used', value: daysLabel(cell.used) },
                  { label: 'Pending', value: daysLabel(cell.pending) },
              ]
            : [
                  {
                      label: cell.projected
                          ? 'Allocated (expected)'
                          : 'Allocated',
                      value: daysLabel(shown.allocated),
                  },
                  { label: 'Carried forward', value: daysLabel(cell.carried) },
                  { label: 'Adjustment', value: daysLabel(shown.adjustment) },
                  {
                      label: 'Opening used',
                      value: daysLabel(shown.opening_used),
                  },
                  { label: 'Used', value: daysLabel(cell.used) },
                  { label: 'Pending', value: daysLabel(cell.pending) },
              ];

    return (
        <Dialog
            open
            onOpenChange={(open) => !open && !form.processing && onClose()}
        >
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-lg">
                <form onSubmit={submit} className="grid gap-5" noValidate>
                    <DialogHeader>
                        <DialogTitle>
                            {employee.name} · {cell.year}
                        </DialogTitle>
                        <DialogDescription asChild>
                            <div className="flex flex-wrap items-center gap-2">
                                <LeaveTypeBadge
                                    name={cell.type_name}
                                    colour={cell.colour}
                                />
                                {cell.projected && (
                                    <span className="text-xs">
                                        No balance row yet: the figures are the
                                        expected allocation.
                                    </span>
                                )}
                            </div>
                        </DialogDescription>
                    </DialogHeader>

                    <dl className="grid grid-cols-2 gap-x-6 gap-y-1.5 rounded-lg border bg-muted/40 p-3 text-sm">
                        {rows.map((row) => (
                            <div key={row.label} className="contents">
                                <dt className="text-muted-foreground">
                                    {row.label}
                                </dt>
                                <dd className="text-right tabular-nums">
                                    {row.value}
                                </dd>
                            </div>
                        ))}
                        {!cell.unlimited && (
                            <>
                                <dt className="border-t pt-1.5 font-medium">
                                    Remaining
                                </dt>
                                <dd
                                    aria-live="polite"
                                    className={cn(
                                        'border-t pt-1.5 text-right font-semibold tabular-nums',
                                        overdrawn &&
                                            'text-red-600 dark:text-red-400',
                                    )}
                                >
                                    {remaining === null
                                        ? '—'
                                        : daysLabel(remaining)}
                                </dd>
                            </>
                        )}
                    </dl>

                    {staleError && (
                        <p
                            role="alert"
                            className="rounded-lg border border-red-200 bg-red-50/60 p-3 text-sm text-destructive dark:border-red-500/30 dark:bg-red-500/10"
                        >
                            {staleError}
                        </p>
                    )}

                    {cell.unlimited && (
                        <p className="text-sm text-muted-foreground">
                            Unpaid leave has no limit and no balance to adjust.
                        </p>
                    )}

                    {editable && (
                        <>
                            <div className="grid gap-4 sm:grid-cols-3">
                                <DaysInput
                                    id="balance-allocated"
                                    label="Allocated"
                                    min={0}
                                    value={data.allocated}
                                    error={errors.allocated}
                                    onChange={(v) =>
                                        form.setData('allocated', v)
                                    }
                                />
                                <DaysInput
                                    id="balance-adjustment"
                                    label="Adjustment"
                                    min={-365}
                                    value={data.adjustment}
                                    error={errors.adjustment}
                                    onChange={(v) =>
                                        form.setData('adjustment', v)
                                    }
                                />
                                <DaysInput
                                    id="balance-opening"
                                    label="Opening used"
                                    min={0}
                                    value={data.opening_used}
                                    error={errors.opening_used}
                                    onChange={(v) =>
                                        form.setData('opening_used', v)
                                    }
                                />
                            </div>

                            <Field
                                id="balance-reason"
                                label="Reason"
                                hint="Required. Kept in the audit log with the old and new figures."
                                error={errors.reason}
                            >
                                <Textarea
                                    {...fieldAria(
                                        'balance-reason',
                                        errors.reason,
                                        true,
                                    )}
                                    value={data.reason}
                                    maxLength={255}
                                    rows={2}
                                    onChange={(e) =>
                                        form.setData('reason', e.target.value)
                                    }
                                />
                            </Field>

                            {(overdrawn || errors.confirm_overdrawn) && (
                                <div className="grid gap-1.5 rounded-lg border border-red-200 bg-red-50/60 p-3 dark:border-red-500/30 dark:bg-red-500/10">
                                    <div className="flex items-start gap-2">
                                        <Checkbox
                                            id="balance-confirm"
                                            checked={data.confirm_overdrawn}
                                            onCheckedChange={(v) =>
                                                form.setData(
                                                    'confirm_overdrawn',
                                                    v === true,
                                                )
                                            }
                                            aria-describedby={
                                                errors.confirm_overdrawn
                                                    ? 'balance-confirm-error'
                                                    : undefined
                                            }
                                        />
                                        <Label
                                            htmlFor="balance-confirm"
                                            className="leading-snug"
                                        >
                                            {remaining !== null && remaining < 0
                                                ? `This leaves ${employee.first_name} overdrawn by ${daysLabel(-remaining)}. I confirm this.`
                                                : 'I confirm this balance may be overdrawn.'}
                                        </Label>
                                    </div>
                                    {errors.confirm_overdrawn && (
                                        <p
                                            id="balance-confirm-error"
                                            role="alert"
                                            className="pl-6 text-sm text-destructive"
                                        >
                                            {errors.confirm_overdrawn}
                                        </p>
                                    )}
                                </div>
                            )}
                        </>
                    )}

                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={onClose}
                            disabled={form.processing}
                        >
                            {editable ? 'Cancel' : 'Close'}
                        </Button>
                        {editable && (
                            <Button type="submit" disabled={form.processing}>
                                {form.processing && <Spinner />}
                                Save balance
                            </Button>
                        )}
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

/** A number of days in steps of 0.5. The value stays a string while typing; the server checks it. */
function DaysInput({
    id,
    label,
    min,
    value,
    error,
    onChange,
}: {
    id: string;
    label: string;
    min: number;
    value: string;
    error?: string;
    onChange: (value: string) => void;
}) {
    return (
        <Field id={id} label={label} error={error}>
            <Input
                {...fieldAria(id, error)}
                type="number"
                inputMode="decimal"
                min={min}
                max={365}
                step={0.5}
                value={value}
                onChange={(e) => onChange(e.target.value)}
            />
        </Field>
    );
}
