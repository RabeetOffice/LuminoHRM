import { useForm } from '@inertiajs/react';
import { CheckField } from '@/components/settings/fields';
import {
    type Editable,
    SettingsCard,
    useSettingsSubmit,
} from '@/components/settings/settings-form';
import type { LeaveValues } from '@/types/hrm-settings';

export function LeavePanel({ values }: { values: LeaveValues }) {
    const form = useForm<Editable<LeaveValues>>(values);
    const { card } = useSettingsSubmit('leave', form);
    const { data, errors, setData } = form;

    return (
        <form
            onSubmit={(e) => e.preventDefault()}
            className="grid gap-6"
            noValidate
        >
            <SettingsCard
                title="Approval"
                description="Who signs off a leave request."
                {...card(['hr_step_enabled'])}
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
                description="How much leave new joiners get. The carry-forward cap is set on each leave type."
                {...card(['prorate_new_joiners'])}
            >
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
