import { Link, useForm } from '@inertiajs/react';
import { Clock } from 'lucide-react';
import { CheckField, NumberField } from '@/components/settings/fields';
import {
    type Editable,
    Field,
    SettingsCard,
    fieldAria,
    useSettingsSubmit,
} from '@/components/settings/settings-form';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useCan } from '@/hooks/use-can';
import { index as shiftsIndex } from '@/routes/attendance/shifts';
import type { AttendanceValues } from '@/types/hrm-settings';

/**
 * Company-wide attendance rules. Shift times, grace, the half-day threshold and weekly offs are set per shift
 * (Attendance > Shifts); their settings keys are kept (the default shift was created from them) but are no longer
 * edited here.
 */
export function AttendancePanel({ values }: { values: AttendanceValues }) {
    // An empty start date is sent as null ("not started"); the date input holds '' meanwhile.
    const form = useForm<Editable<AttendanceValues>>({
        ...values,
        start_date: values.start_date ?? '',
    });
    const { card, state } = useSettingsSubmit('attendance', form);
    const { data, errors, setData } = form;
    const canEditShifts = useCan()('attendance.edit');

    return (
        <form
            onSubmit={(e) => e.preventDefault()}
            className="grid gap-6"
            noValidate
        >
            <SettingsCard
                title="Working hours"
                description="Shift times, grace period, half-day threshold and weekly offs."
                state={state}
                footer={
                    canEditShifts ? (
                        <Button asChild variant="outline">
                            <Link href={shiftsIndex()}>
                                <Clock />
                                Open Shifts
                            </Link>
                        </Button>
                    ) : (
                        // A footer of its own: this card has nothing to save.
                        <></>
                    )
                }
            >
                <p className="text-sm text-muted-foreground">
                    Working hours are set per shift. Each person works their own
                    shift, or the default shift when they have none.
                </p>
            </SettingsCard>

            <SettingsCard
                title="Attendance rules"
                description="When attendance starts counting, repeat punches and where one day ends."
                {...card([
                    'start_date',
                    'duplicate_punch_minutes',
                    'day_cutoff_time',
                ])}
            >
                <div className="grid gap-5 sm:grid-cols-2">
                    <Field
                        id="att-start-date"
                        label="Attendance starts on"
                        hint="No absences are recorded before this date. Leave empty until the machine is switched over to this system."
                        error={errors.start_date}
                    >
                        <Input
                            {...fieldAria(
                                'att-start-date',
                                errors.start_date,
                                true,
                            )}
                            type="date"
                            value={data.start_date ?? ''}
                            className="w-44"
                            onChange={(e) =>
                                setData('start_date', e.target.value || null)
                            }
                        />
                    </Field>
                    <Field
                        id="att-day-cutoff"
                        label="Day ends at"
                        hint="A punch before this time counts for the previous day. Changing it affects new punches only."
                        error={errors.day_cutoff_time}
                    >
                        <Input
                            {...fieldAria(
                                'att-day-cutoff',
                                errors.day_cutoff_time,
                                true,
                            )}
                            type="time"
                            min="00:00"
                            max="06:00"
                            step={60}
                            value={data.day_cutoff_time}
                            className="w-36"
                            onChange={(e) =>
                                setData('day_cutoff_time', e.target.value)
                            }
                        />
                    </Field>
                    <NumberField
                        id="att-duplicate"
                        label="Ignore repeat punches within"
                        hint="A second punch this soon after the last one is a double tap and is ignored. 0 turns this off."
                        suffix="minutes"
                        min={0}
                        max={60}
                        value={data.duplicate_punch_minutes}
                        error={errors.duplicate_punch_minutes}
                        onChange={(v) => setData('duplicate_punch_minutes', v)}
                    />
                </div>
            </SettingsCard>

            <SettingsCard
                title="Late rule"
                description="How lateness turns into deductions."
                {...card(['late_rule_count'])}
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
                </div>
            </SettingsCard>

            <SettingsCard
                title="Overtime"
                description="Overtime minutes are always recorded in attendance. This setting only decides whether payroll pays them."
                {...card([
                    'overtime_enabled',
                    'overtime_min_minutes',
                    'overtime_rate_mode',
                    'overtime_multiplier_percent',
                    'overtime_fixed_rate',
                    'overtime_hours_per_day',
                ])}
            >
                <CheckField
                    id="att-overtime"
                    label="Overtime is paid"
                    hint="Controls payment in payroll only. Attendance records overtime minutes either way."
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
                <div className="grid gap-5 sm:grid-cols-2">
                    <Field
                        id="att-overtime-mode"
                        label="Overtime rate"
                        hint="Used only while overtime is paid."
                        error={errors.overtime_rate_mode}
                    >
                        <Select
                            value={data.overtime_rate_mode}
                            onValueChange={(v) =>
                                setData(
                                    'overtime_rate_mode',
                                    v as AttendanceValues['overtime_rate_mode'],
                                )
                            }
                        >
                            <SelectTrigger
                                {...fieldAria(
                                    'att-overtime-mode',
                                    errors.overtime_rate_mode,
                                    true,
                                )}
                                className="w-full"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="multiple">
                                    A multiple of hourly pay
                                </SelectItem>
                                <SelectItem value="fixed">
                                    A fixed amount an hour
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    </Field>
                    {data.overtime_rate_mode === 'fixed' ? (
                        <NumberField
                            id="att-overtime-fixed"
                            label="Fixed rate"
                            hint="Whole rupees for each hour of overtime."
                            suffix="rupees an hour"
                            min={0}
                            max={100000}
                            value={data.overtime_fixed_rate}
                            error={errors.overtime_fixed_rate}
                            onChange={(v) => setData('overtime_fixed_rate', v)}
                        />
                    ) : (
                        <NumberField
                            id="att-overtime-multiplier"
                            label="Multiple of hourly pay"
                            hint="150% pays one and a half times the hourly pay."
                            suffix="%"
                            min={100}
                            max={300}
                            value={data.overtime_multiplier_percent}
                            error={errors.overtime_multiplier_percent}
                            onChange={(v) =>
                                setData('overtime_multiplier_percent', v)
                            }
                        />
                    )}
                    <NumberField
                        id="att-overtime-hours"
                        label="Hours in a working day"
                        hint="Hourly pay is one day's pay divided by this."
                        suffix="hours"
                        min={1}
                        max={24}
                        value={data.overtime_hours_per_day}
                        error={errors.overtime_hours_per_day}
                        onChange={(v) => setData('overtime_hours_per_day', v)}
                    />
                </div>
            </SettingsCard>
        </form>
    );
}
