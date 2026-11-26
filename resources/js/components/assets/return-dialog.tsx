import { useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';
import AssignmentController from '@/actions/App/Http/Controllers/Assets/AssignmentController';
import { Field, fieldAria } from '@/components/settings/settings-form';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import {
    assetConditionOptions,
    firstError,
    personLabel,
    returnStatusOptions,
} from '@/lib/assets';
import type { AssetPerson } from '@/lib/assets';
import { formatDate } from '@/lib/format-date';

type Props = {
    asset: { asset_code: string; name: string; condition: string };
    /** Who has it and since when. */
    holder: AssetPerson & { assignment_id: number; assigned_on: string };
    /** The attendance day "Y-m-d": the default return date and the latest the server accepts. */
    today: string;
    /** Retiring an asset is an Admin action. */
    canRetire: boolean;
    onClose: () => void;
};

/**
 * Take the asset back (assets.assignments.return). Mount it only while open. The server's refusals show in the dialog:
 * "already returned" (key `assignment`), the date rules (`returned_on`), the next status (`next_status`) and the note
 * (`return_note`).
 */
export function ReturnDialog({
    asset,
    holder,
    today,
    canRetire,
    onClose,
}: Props) {
    const dateFormat = usePage().props.app.dateFormat;
    const form = useForm({
        returned_on: today,
        return_condition: asset.condition,
        next_status: 'available',
        return_note: '',
    });
    const { data, errors } = form;
    // Refusals for the whole action have no field of their own.
    const general = firstError(errors as Record<string, string | undefined>, [
        'assignment',
    ]);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.submit(AssignmentController.returnMethod(holder.assignment_id), {
            preserveScroll: true,
            onSuccess: () => onClose(),
        });
    };

    return (
        <Dialog
            open
            onOpenChange={(open) => !open && !form.processing && onClose()}
        >
            <DialogContent className="max-h-[90dvh] overflow-y-auto sm:max-w-lg">
                <form onSubmit={submit} className="grid gap-5" noValidate>
                    <DialogHeader>
                        <DialogTitle>Return {asset.asset_code}</DialogTitle>
                        <DialogDescription>
                            {asset.name} has been with {personLabel(holder)}{' '}
                            since {formatDate(holder.assigned_on, dateFormat)}.
                            Record the day it came back, its condition and what
                            happens to it next.
                        </DialogDescription>
                    </DialogHeader>

                    {general && (
                        <p
                            role="alert"
                            className="rounded-lg bg-destructive/10 px-3 py-2 text-sm text-destructive"
                        >
                            {general}
                        </p>
                    )}

                    <div className="grid gap-5 sm:grid-cols-2">
                        <Field
                            id="return-date"
                            label="Returned on"
                            error={errors.returned_on}
                        >
                            <Input
                                {...fieldAria(
                                    'return-date',
                                    errors.returned_on,
                                )}
                                type="date"
                                min={holder.assigned_on}
                                max={today}
                                value={data.returned_on}
                                onChange={(e) =>
                                    form.setData('returned_on', e.target.value)
                                }
                            />
                        </Field>
                        <Field
                            id="return-condition"
                            label="Condition on return"
                            error={errors.return_condition}
                        >
                            <Select
                                value={data.return_condition}
                                onValueChange={(value) =>
                                    form.setData('return_condition', value)
                                }
                            >
                                <SelectTrigger
                                    {...fieldAria(
                                        'return-condition',
                                        errors.return_condition,
                                    )}
                                    className="w-full"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {assetConditionOptions().map((option) => (
                                        <SelectItem
                                            key={option.value}
                                            value={option.value}
                                        >
                                            {option.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </Field>
                    </div>

                    <Field
                        id="return-next"
                        label="Then the asset is"
                        error={errors.next_status}
                    >
                        <Select
                            value={data.next_status}
                            onValueChange={(value) =>
                                form.setData('next_status', value)
                            }
                        >
                            <SelectTrigger
                                {...fieldAria(
                                    'return-next',
                                    errors.next_status,
                                )}
                                className="w-full sm:w-56"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {returnStatusOptions(canRetire).map(
                                    (option) => (
                                        <SelectItem
                                            key={option.value}
                                            value={option.value}
                                        >
                                            {option.label}
                                        </SelectItem>
                                    ),
                                )}
                            </SelectContent>
                        </Select>
                    </Field>

                    <Field
                        id="return-note"
                        label="Note"
                        error={errors.return_note}
                        hint="Optional. For example: screen scratched."
                    >
                        <Textarea
                            {...fieldAria(
                                'return-note',
                                errors.return_note,
                                true,
                            )}
                            value={data.return_note}
                            onChange={(e) =>
                                form.setData('return_note', e.target.value)
                            }
                            maxLength={500}
                            rows={2}
                        />
                    </Field>

                    <DialogFooter className="flex-col-reverse gap-2 sm:flex-row">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={onClose}
                            disabled={form.processing}
                        >
                            Cancel
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            {form.processing && <Spinner />}
                            Return asset
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
