import { MoneyInput } from '@/components/payroll/money-input';
import { Field, fieldAria } from '@/components/settings/settings-form';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type { TaxBase, TaxMode } from '@/lib/payroll';

export type TaxDraft = {
    tax_mode: TaxMode;
    /** Fixed tax, whole rupees as typed. */
    tax_value_rupees: string;
    /** Percentage as typed ("5.25"); at most two decimals. */
    tax_percent: string;
    tax_base: TaxBase;
};

type Props = {
    idPrefix: string;
    value: TaxDraft;
    errors: Partial<Record<keyof TaxDraft, string>>;
    onChange: <K extends keyof TaxDraft>(key: K, value: TaxDraft[K]) => void;
};

const MODES: { value: TaxMode; label: string }[] = [
    { value: 'none', label: 'No tax' },
    { value: 'fixed', label: 'Fixed amount a month' },
    { value: 'percentage', label: 'Percentage' },
];

const BASES: { value: TaxBase; label: string }[] = [
    { value: 'gross_taxable', label: 'of gross taxable pay' },
    { value: 'basic', label: 'of basic salary' },
];

/**
 * The tax part of a salary: none, a fixed amount a month (whole rupees), or a
 * percentage (up to two decimals) of gross taxable pay or of basic.
 */
export function TaxFields({ idPrefix, value, errors, onChange }: Props) {
    const modeId = `${idPrefix}-tax-mode`;
    const amountId = `${idPrefix}-tax-amount`;
    const percentId = `${idPrefix}-tax-percent`;
    const baseId = `${idPrefix}-tax-base`;

    return (
        <div className="grid gap-4 sm:grid-cols-3">
            <Field id={modeId} label="Tax" error={errors.tax_mode}>
                <Select
                    value={value.tax_mode}
                    onValueChange={(mode) =>
                        onChange('tax_mode', mode as TaxMode)
                    }
                >
                    <SelectTrigger {...fieldAria(modeId, errors.tax_mode)}>
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        {MODES.map((mode) => (
                            <SelectItem key={mode.value} value={mode.value}>
                                {mode.label}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            </Field>

            {value.tax_mode === 'fixed' && (
                <Field
                    id={amountId}
                    label="Tax a month"
                    error={errors.tax_value_rupees}
                >
                    <MoneyInput
                        {...fieldAria(amountId, errors.tax_value_rupees)}
                        value={value.tax_value_rupees}
                        onChange={(v) => onChange('tax_value_rupees', v)}
                    />
                </Field>
            )}

            {value.tax_mode === 'percentage' && (
                <>
                    <Field
                        id={percentId}
                        label="Rate"
                        error={errors.tax_percent}
                    >
                        <div className="relative">
                            <Input
                                {...fieldAria(percentId, errors.tax_percent)}
                                type="text"
                                inputMode="decimal"
                                autoComplete="off"
                                className="pr-8 text-right tabular-nums"
                                value={value.tax_percent}
                                onChange={(e) =>
                                    onChange(
                                        'tax_percent',
                                        e.target.value.replace(/[^\d.]/g, ''),
                                    )
                                }
                            />
                            <span
                                aria-hidden="true"
                                className="pointer-events-none absolute inset-y-0 right-3 flex items-center text-sm text-muted-foreground"
                            >
                                %
                            </span>
                        </div>
                    </Field>
                    <Field id={baseId} label="Taken" error={errors.tax_base}>
                        <Select
                            value={value.tax_base}
                            onValueChange={(base) =>
                                onChange('tax_base', base as TaxBase)
                            }
                        >
                            <SelectTrigger
                                {...fieldAria(baseId, errors.tax_base)}
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {BASES.map((base) => (
                                    <SelectItem
                                        key={base.value}
                                        value={base.value}
                                    >
                                        {base.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </Field>
                </>
            )}
        </div>
    );
}
