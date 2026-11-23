import { useForm } from '@inertiajs/react';
import { CheckField, NumberField } from '@/components/settings/fields';
import {
    type Editable,
    Field,
    fieldAria,
    SettingsCard,
    useSettingsSubmit,
} from '@/components/settings/settings-form';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { AttendanceValues } from '@/types/hrm-settings';

const DAYS = [
    'monday',
    'tuesday',
    'wednesday',
    'thursday',
    'friday',
    'saturday',
    'sunday',
] as const;

const label = (day: string) => day.charAt(0).toUpperCase() + day.slice(1);

export function AttendancePanel({ values }: { values: AttendanceValues }) {
    const form = useForm<Editable<AttendanceValues>>(values);
    const { submit, state } = useSettingsSubmit('attendance', form);
    const { data, errors, setData } = form;

    // Errors on the list items come back as weekly_offs.0, weekly_offs.1 ...
    const offsError =
        errors.weekly_offs ??
        Object.entries(errors).find(([key]) =>
            key.startsWith('weekly_offs.'),
        )?.[1];

    const toggleDay = (day: string, on: boolean) => {
        const next = on
            ? [...data.weekly_offs, day]
            : data.weekly_offs.filter((d: string) => d !== day);

        setData(
            'weekly_offs',
            DAYS.filter((d) => next.includes(d)),
        );
    };

    return (
        <form onSubmit={submit} className="grid gap-6" noValidate>
            <SettingsCard
                title="Working hours"
                description="The standard shift and the days the office is closed."
                state={state}
            >
                <div className="grid gap-5 sm:grid-cols-3">
                    <Field
                        id="att-shift-start"
                        label="Shift starts"
                        error={errors.shift_start}
                    >
                        <Input
                            {...fieldAria(
                                'att-shift-start',
                                errors.shift_start,
                            )}
                            type="time"
                            value={data.shift_start}
                            onChange={(e) =>
                                setData('shift_start', e.target.value)
                            }
                        />
                    </Field>
                    <Field
                        id="att-shift-end"
                        label="Shift ends"
                        error={errors.shift_end}
                    >
                        <Input
                            {...fieldAria('att-shift-end', errors.shift_end)}
                            type="time"
                            value={data.shift_end}
                            onChange={(e) =>
                                setData('shift_end', e.target.value)
                            }
                        />
                    </Field>
                    <NumberField
                        id="att-grace"
                        label="Grace period"
                        suffix="minutes"
                        min={0}
                        max={120}
                        value={data.grace_minutes}
                        error={errors.grace_minutes}
                        onChange={(v) => setData('grace_minutes', v)}
                    />
                </div>

                <div
                    role="group"
                    aria-labelledby="att-offs-label"
                    className="grid gap-3"
                >
                    <span
                        id="att-offs-label"
                        className="text-sm leading-none font-medium"
                    >
                        Weekly offs
                    </span>
                    <div className="flex flex-wrap gap-x-5 gap-y-2">
                        {DAYS.map((day) => (
                            <div key={day} className="flex items-center gap-2">
                                <Checkbox
                                    id={`att-off-${day}`}
                                    checked={data.weekly_offs.includes(day)}
                                    onCheckedChange={(v) =>
                                        toggleDay(day, v === true)
                                    }
                                />
                                <Label
                                    htmlFor={`att-off-${day}`}
                                    className="font-normal"
                                >
                                    {label(day)}
                                </Label>
                            </div>
                        ))}
                    </div>
                    {offsError && (
                        <p
                            role="alert"
                            className="text-sm text-red-600 dark:text-red-400"
                        >
                            {offsError}
                        </p>
                    )}
                </div>
            </SettingsCard>

            <SettingsCard
                title="Late and half-day rules"
                description="How lateness and short days turn into deductions."
                state={state}
            >
                <div className="grid gap-5 sm:grid-cols-2">
                    <NumberField
                        id="att-late-count"
                        label="Late arrivals that count as one day"
                        hint="Every time this many lates add up, one day is deducted."
                        suffix="lates"
                        min={1}
                        max={31}
                        value={data.late_rule_count}
                        error={errors.late_rule_count}
                        onChange={(v) => setData('late_rule_count', v)}
                    />
                    <NumberField
                        id="att-half-day"
                        label="Half-day threshold"
                        hint="Fewer hours than this on a day counts as a half day."
                        suffix="hours"
                        min={1}
                        max={12}
                        value={data.half_day_hours}
                        error={errors.half_day_hours}
                        onChange={(v) => setData('half_day_hours', v)}
                    />
                </div>
            </SettingsCard>

            <SettingsCard
                title="Overtime"
                description="Pay for time worked after the shift ends."
                state={state}
            >
                <CheckField
                    id="att-overtime"
                    label="Overtime is paid"
                    checked={data.overtime_enabled}
                    error={errors.overtime_enabled}
                    onChange={(v) => setData('overtime_enabled', v)}
                />
                <NumberField
                    id="att-overtime-min"
                    label="Minimum overtime to count"
                    hint="Shorter stretches after the shift are ignored."
                    suffix="minutes"
                    min={0}
                    max={480}
                    value={data.overtime_min_minutes}
                    error={errors.overtime_min_minutes}
                    onChange={(v) => setData('overtime_min_minutes', v)}
                />
            </SettingsCard>
        </form>
    );
}
