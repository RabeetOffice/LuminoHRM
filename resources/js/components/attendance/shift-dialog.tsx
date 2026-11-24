import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import ShiftController from '@/actions/App/Http/Controllers/Attendance/ShiftController';
import { NumberField } from '@/components/settings/fields';
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

export type ShiftRow = {
    id: number;
    name: string;
    /** "H:i", 24-hour, as stored. */
    start_time: string;
    end_time: string;
    grace_minutes: number;
    half_day_hours: number;
    /** Lowercase day names, Monday first. */
    weekly_offs: string[];
    is_default: boolean;
    employees_count: number;
};

type Props = {
    /** The shift being edited, or null to add a new one. */
    shift: ShiftRow | null;
    /** Lowercase day names, Monday first. */
    weekdays: string[];
    onClose: () => void;
};

/** Closed days are not flagged by a shift change, but other changes may still recalculate them. */
export const CLOSED_DAYS_NOTE =
    'Days already closed keep their values unless they are recalculated later, for example by a late punch or a holiday change.';

export const dayLabel = (day: string) =>
    day.charAt(0).toUpperCase() + day.slice(1);

/** Add / edit dialog. Mount it only while open (with a key) so the form starts fresh. */
export function ShiftDialog({ shift, weekdays, onClose }: Props) {
    const form = useForm({
        name: shift?.name ?? '',
        start_time: shift?.start_time ?? '09:00',
        end_time: shift?.end_time ?? '18:00',
        grace_minutes: String(shift?.grace_minutes ?? 15),
        half_day_hours: String(shift?.half_day_hours ?? 4),
        weekly_offs: shift?.weekly_offs ?? ['saturday', 'sunday'],
    });
    const { data, errors } = form;

    // Errors on the list items come back as weekly_offs.0, weekly_offs.1 ...
    const offsError =
        errors.weekly_offs ??
        Object.entries(errors).find(([key]) =>
            key.startsWith('weekly_offs.'),
        )?.[1];

    const toggleDay = (day: string, on: boolean) => {
        const next = on
            ? [...data.weekly_offs, day]
            : data.weekly_offs.filter((d) => d !== day);

        form.setData(
            'weekly_offs',
            weekdays.filter((d) => next.includes(d)),
        );
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();

        const options = { preserveScroll: true, onSuccess: () => onClose() };

        if (shift) {
            form.submit(ShiftController.update(shift.id), options);
        } else {
            form.submit(ShiftController.store(), options);
        }
    };

    return (
        <Dialog
            open
            onOpenChange={(open) => !open && !form.processing && onClose()}
        >
            <DialogContent className="sm:max-w-lg">
                <form onSubmit={submit} className="grid gap-5" noValidate>
                    <DialogHeader>
                        <DialogTitle>
                            {shift ? 'Edit shift' : 'Add shift'}
                        </DialogTitle>
                        <DialogDescription>
                            Working hours and the late, half-day and weekly off
                            rules for the people on this shift. A shift starts
                            and ends on the same day.
                        </DialogDescription>
                    </DialogHeader>

                    <Field id="shift-name" label="Name" error={errors.name}>
                        <Input
                            {...fieldAria('shift-name', errors.name)}
                            value={data.name}
                            onChange={(e) =>
                                form.setData('name', e.target.value)
                            }
                            maxLength={100}
                            autoComplete="off"
                        />
                    </Field>

                    <div className="grid gap-5 sm:grid-cols-2">
                        <Field
                            id="shift-start"
                            label="Starts"
                            error={errors.start_time}
                        >
                            <Input
                                {...fieldAria('shift-start', errors.start_time)}
                                type="time"
                                value={data.start_time}
                                onChange={(e) =>
                                    form.setData('start_time', e.target.value)
                                }
                            />
                        </Field>
                        <Field
                            id="shift-end"
                            label="Ends"
                            error={errors.end_time}
                        >
                            <Input
                                {...fieldAria('shift-end', errors.end_time)}
                                type="time"
                                value={data.end_time}
                                onChange={(e) =>
                                    form.setData('end_time', e.target.value)
                                }
                            />
                        </Field>
                        <NumberField
                            id="shift-grace"
                            label="Grace period"
                            suffix="minutes"
                            min={0}
                            max={120}
                            value={data.grace_minutes}
                            error={errors.grace_minutes}
                            hint="Late only after the start plus this."
                            onChange={(v) => form.setData('grace_minutes', v)}
                        />
                        <Field
                            id="shift-half-day"
                            label="Half day under"
                            error={errors.half_day_hours}
                            hint="Fewer hours worked than this is a half day."
                        >
                            <div className="flex items-center gap-2">
                                <Input
                                    {...fieldAria(
                                        'shift-half-day',
                                        errors.half_day_hours,
                                        true,
                                    )}
                                    type="number"
                                    inputMode="decimal"
                                    min={1}
                                    max={12}
                                    step={0.5}
                                    value={data.half_day_hours}
                                    className="w-28"
                                    onChange={(e) =>
                                        form.setData(
                                            'half_day_hours',
                                            e.target.value,
                                        )
                                    }
                                />
                                <span className="text-sm text-muted-foreground">
                                    hours
                                </span>
                            </div>
                        </Field>
                    </div>

                    <div
                        role="group"
                        aria-labelledby="shift-offs-label"
                        className="grid gap-3"
                    >
                        <span
                            id="shift-offs-label"
                            className="text-sm leading-none font-medium"
                        >
                            Weekly offs
                        </span>
                        <div className="flex flex-wrap gap-x-5 gap-y-2">
                            {weekdays.map((day) => (
                                <div
                                    key={day}
                                    className="flex items-center gap-2"
                                >
                                    <Checkbox
                                        id={`shift-off-${day}`}
                                        checked={data.weekly_offs.includes(day)}
                                        onCheckedChange={(v) =>
                                            toggleDay(day, v === true)
                                        }
                                    />
                                    <Label
                                        htmlFor={`shift-off-${day}`}
                                        className="font-normal"
                                    >
                                        {dayLabel(day)}
                                    </Label>
                                </div>
                            ))}
                        </div>
                        {offsError && (
                            <p
                                role="alert"
                                className="text-sm text-destructive"
                            >
                                {offsError}
                            </p>
                        )}
                    </div>

                    {shift && shift.employees_count > 0 && (
                        <p className="rounded-lg bg-muted px-3 py-2 text-xs text-muted-foreground">
                            Changing the hours or rules recalculates today and
                            later days for the people on this shift.{' '}
                            {CLOSED_DAYS_NOTE}
                        </p>
                    )}

                    <DialogFooter>
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
                            {shift ? 'Save changes' : 'Add shift'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
