import { useForm } from '@inertiajs/react';
import { CheckField } from '@/components/settings/fields';
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
import type { PerformanceValues } from '@/types/hrm-settings';

const PERIODS = [
    { value: 'quarterly', label: 'Quarterly (every three months)' },
    { value: 'monthly', label: 'Monthly' },
];

export function PerformancePanel({ values }: { values: PerformanceValues }) {
    const form = useForm<Editable<PerformanceValues>>(values);
    const { card } = useSettingsSubmit('performance', form);
    const { data, errors, setData } = form;

    return (
        <form
            onSubmit={(e) => e.preventDefault()}
            className="grid gap-6"
            noValidate
        >
            <SettingsCard
                title="Review rounds"
                description="These choices are used when a review cycle is opened. A change affects only cycles opened later; a cycle already open keeps the rules it started with."
                {...card(['review_period', 'self_review_enabled'])}
            >
                <Field
                    id="performance-period"
                    label="Review period"
                    hint="Quarterly cycles cover a calendar quarter (Jan–Mar, Apr–Jun, Jul–Sep, Oct–Dec); monthly cycles cover one calendar month."
                    error={errors.review_period}
                >
                    <Select
                        value={data.review_period}
                        onValueChange={(value) =>
                            setData('review_period', value)
                        }
                    >
                        <SelectTrigger
                            {...fieldAria(
                                'performance-period',
                                errors.review_period,
                                true,
                            )}
                            className="w-full sm:w-72"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {PERIODS.map((period) => (
                                <SelectItem
                                    key={period.value}
                                    value={period.value}
                                >
                                    {period.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </Field>

                <CheckField
                    id="performance-self-review"
                    label="People rate themselves first"
                    hint="When on, a new cycle starts with each person's self-review, then the manager reviews. When off, it goes straight to the manager."
                    checked={data.self_review_enabled}
                    error={errors.self_review_enabled}
                    onChange={(v) => setData('self_review_enabled', v)}
                />
            </SettingsCard>
        </form>
    );
}
