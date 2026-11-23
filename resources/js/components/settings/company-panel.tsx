import { useForm } from '@inertiajs/react';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Field,
    fieldAria,
    SettingsCard,
    useSettingsSubmit,
} from '@/components/settings/settings-form';
import type { CompanyValues, SettingsOptions } from '@/types/hrm-settings';

type Props = { values: CompanyValues; options: SettingsOptions };

export function CompanyPanel({ values, options }: Props) {
    const form = useForm(values);
    const { submit, state } = useSettingsSubmit('company', form);
    const { data, errors, setData } = form;

    return (
        <form onSubmit={submit} className="grid gap-6" noValidate>
            <SettingsCard
                title="Company details"
                description="Shown on payslips, letters and emails."
                state={state}
            >
                <Field
                    id="company-name"
                    label="Company name"
                    error={errors.name}
                >
                    <Input
                        {...fieldAria('company-name', errors.name)}
                        value={data.name}
                        maxLength={100}
                        onChange={(e) => setData('name', e.target.value)}
                    />
                </Field>
                <Field
                    id="company-address"
                    label="Address"
                    error={errors.address}
                >
                    <Textarea
                        {...fieldAria('company-address', errors.address)}
                        value={data.address ?? ''}
                        maxLength={500}
                        rows={3}
                        onChange={(e) => setData('address', e.target.value)}
                    />
                </Field>
            </SettingsCard>

            <SettingsCard
                title="Regional"
                description="Currency, timezone and how dates are written."
                state={state}
            >
                <div className="grid gap-5 sm:grid-cols-2">
                    <Field
                        id="company-currency"
                        label="Currency code"
                        hint="Three letters, for example PKR or USD."
                        error={errors.currency}
                    >
                        <Input
                            {...fieldAria(
                                'company-currency',
                                errors.currency,
                                true,
                            )}
                            value={data.currency}
                            maxLength={3}
                            className="uppercase"
                            onChange={(e) =>
                                setData(
                                    'currency',
                                    e.target.value.toUpperCase(),
                                )
                            }
                        />
                    </Field>
                    <Field
                        id="company-date-format"
                        label="Date format"
                        error={errors.date_format}
                    >
                        <Select
                            value={data.date_format}
                            onValueChange={(v) => setData('date_format', v)}
                        >
                            <SelectTrigger
                                {...fieldAria(
                                    'company-date-format',
                                    errors.date_format,
                                )}
                                className="w-full"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {options.dateFormats.map((format) => (
                                    <SelectItem
                                        key={format.value}
                                        value={format.value}
                                    >
                                        {format.example}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </Field>
                </div>
                <Field
                    id="company-timezone"
                    label="Timezone"
                    hint="Used to show times and to decide which day a punch belongs to."
                    error={errors.timezone}
                >
                    <Select
                        value={data.timezone}
                        onValueChange={(v) => setData('timezone', v)}
                    >
                        <SelectTrigger
                            {...fieldAria(
                                'company-timezone',
                                errors.timezone,
                                true,
                            )}
                            className="w-full"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent className="max-h-72">
                            {options.timezones.map((zone) => (
                                <SelectItem key={zone} value={zone}>
                                    {zone}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </Field>
            </SettingsCard>
        </form>
    );
}
