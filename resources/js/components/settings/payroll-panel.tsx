import { useForm } from '@inertiajs/react';
import { CheckField, NumberField } from '@/components/settings/fields';
import {
    type Editable,
    Field,
    fieldAria,
    SettingsCard,
    useSettingsSubmit,
} from '@/components/settings/settings-form';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import type { PayrollValues } from '@/types/hrm-settings';

const DAY_PAY_BASES = [
    { value: 'calendar_days', label: 'Days in the calendar month' },
    { value: 'thirty', label: 'Thirty days' },
    { value: 'working_days', label: 'Working days in the month' },
];

const TAX_MODES = [
    { value: 'none', label: 'No tax' },
    { value: 'fixed', label: 'Fixed amount each month' },
    { value: 'percentage', label: 'Percentage of salary' },
];

export function PayrollPanel({ values }: { values: PayrollValues }) {
    const form = useForm<Editable<PayrollValues>>(values);
    const { submit, state } = useSettingsSubmit('payroll', form);
    const { data, errors, setData } = form;

    return (
        <form onSubmit={submit} className="grid gap-6" noValidate>
            <SettingsCard
                title="Pay calculation"
                description="How one day's pay is worked out and how tax starts for a new salary profile."
                state={state}
            >
                <div className="grid gap-5 sm:grid-cols-2">
                    <Field
                        id="pay-basis"
                        label="One day's pay is monthly pay divided by"
                        error={errors.day_pay_basis}
                    >
                        <Select
                            value={data.day_pay_basis}
                            onValueChange={(v) => setData('day_pay_basis', v)}
                        >
                            <SelectTrigger
                                {...fieldAria(
                                    'pay-basis',
                                    errors.day_pay_basis,
                                )}
                                className="w-full"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {DAY_PAY_BASES.map((o) => (
                                    <SelectItem key={o.value} value={o.value}>
                                        {o.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </Field>
                    <Field
                        id="pay-tax-mode"
                        label="Default tax mode"
                        error={errors.default_tax_mode}
                    >
                        <Select
                            value={data.default_tax_mode}
                            onValueChange={(v) =>
                                setData('default_tax_mode', v)
                            }
                        >
                            <SelectTrigger
                                {...fieldAria(
                                    'pay-tax-mode',
                                    errors.default_tax_mode,
                                )}
                                className="w-full"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {TAX_MODES.map((o) => (
                                    <SelectItem key={o.value} value={o.value}>
                                        {o.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </Field>
                </div>
            </SettingsCard>

            <SettingsCard
                title="Leave and notice"
                description="Settlement rules for leavers."
                state={state}
            >
                <CheckField
                    id="pay-encashment"
                    label="Pay out unused leave when someone leaves"
                    checked={data.leave_encashment}
                    error={errors.leave_encashment}
                    onChange={(v) => setData('leave_encashment', v)}
                />
                <NumberField
                    id="pay-notice"
                    label="Notice period"
                    suffix="days"
                    min={0}
                    max={365}
                    value={data.notice_period_days}
                    error={errors.notice_period_days}
                    onChange={(v) => setData('notice_period_days', v)}
                />
            </SettingsCard>

            <SettingsCard
                title="Payslip"
                description="Printed at the bottom of every payslip."
                state={state}
            >
                <Field
                    id="pay-footer"
                    label="Footer text"
                    hint="Optional. Up to 500 characters."
                    error={errors.payslip_footer}
                >
                    <Textarea
                        {...fieldAria(
                            'pay-footer',
                            errors.payslip_footer,
                            true,
                        )}
                        value={data.payslip_footer ?? ''}
                        maxLength={500}
                        rows={3}
                        onChange={(e) =>
                            setData('payslip_footer', e.target.value)
                        }
                    />
                </Field>
            </SettingsCard>
        </form>
    );
}
