import { Link, useForm } from '@inertiajs/react';
import { Clock } from 'lucide-react';
import { CheckField, NumberField } from '@/components/settings/fields';
import {
    type Editable,
    SettingsCard,
    useSettingsSubmit,
} from '@/components/settings/settings-form';
import { Button } from '@/components/ui/button';
import { useCan } from '@/hooks/use-can';
import { index as shiftsIndex } from '@/routes/attendance/shifts';
import type { AttendanceValues } from '@/types/hrm-settings';

/**
 * Company-wide attendance rules. Shift times, grace, the half-day threshold and weekly offs are set per shift
 * (Attendance > Shifts); their settings keys are kept (the default shift was created from them) but are no longer
 * edited here.
 */
export function AttendancePanel({ values }: { values: AttendanceValues }) {
    const form = useForm<Editable<AttendanceValues>>(values);
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
                description="Pay for time worked after the shift ends."
                {...card(['overtime_enabled', 'overtime_min_minutes'])}
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
