import { useForm } from '@inertiajs/react';
import { CheckField, NumberField } from '@/components/settings/fields';
import {
    type Editable,
    SettingsCard,
    useSettingsSubmit,
} from '@/components/settings/settings-form';
import type { LeaveValues } from '@/types/hrm-settings';

export function LeavePanel({ values }: { values: LeaveValues }) {
    const form = useForm<Editable<LeaveValues>>(values);
    const { submit, state } = useSettingsSubmit('leave', form);
    const { data, errors, setData } = form;

    return (
        <form onSubmit={submit} className="grid gap-6" noValidate>
            <SettingsCard
                title="Approval"
                description="Who signs off a leave request."
                state={state}
            >
                <CheckField
                    id="leave-hr-step"
                    label="HR approves after the manager"
                    hint="When off, the manager's approval is final."
                    checked={data.hr_step_enabled}
                    error={errors.hr_step_enabled}
                    onChange={(v) => setData('hr_step_enabled', v)}
                />
            </SettingsCard>

            <SettingsCard
                title="Balances"
                description="What happens to leave at the turn of the year and for new joiners."
                state={state}
            >
                <NumberField
                    id="leave-carry"
                    label="Carry-forward cap"
                    hint="The most days that move into next year. 0 means nothing carries over."
                    suffix="days"
                    min={0}
                    max={365}
                    value={data.carry_forward_cap}
                    error={errors.carry_forward_cap}
                    onChange={(v) => setData('carry_forward_cap', v)}
                />
                <CheckField
                    id="leave-prorate"
                    label="Pro-rate leave for new joiners"
                    hint="A person who joins mid-year gets a share of the yearly allowance."
                    checked={data.prorate_new_joiners}
                    error={errors.prorate_new_joiners}
                    onChange={(v) => setData('prorate_new_joiners', v)}
                />
            </SettingsCard>
        </form>
    );
}
