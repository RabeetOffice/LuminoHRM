import { useForm } from '@inertiajs/react';
import SettlementController from '@/actions/App/Http/Controllers/Exits/SettlementController';
import { DialogFrame } from '@/components/exit/dialog-frame';
import { Field, fieldAria } from '@/components/settings/settings-form';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { generalErrors } from '@/lib/exit';
import { formatMoney } from '@/lib/payroll';

type Props = {
    settlementId: number;
    /** Lines already on the draft (the server allows 20). */
    count: number;
    /** What one paid day of the final month came to (paisa), a hint for a notice-shortfall line. */
    dayPay: number | null;
    onClose: () => void;
};

const FIELDS = ['kind', 'type', 'label', 'amount', 'is_taxable'];

const KINDS = [
    { value: 'bonus', label: 'Bonus (added to pay)' },
    { value: 'notice_shortfall', label: 'Notice shortfall (deducted)' },
    { value: 'other', label: 'Other' },
];

/**
 * Add one HR line to a draft settlement: a bonus (added), a notice shortfall (deducted) or another line either way. The
 * amount is whole rupees. The server's limits (20 lines, 1 to 10,000,000 rupees, a frozen settlement) show in the dialog,
 * which stays open. Mount it only while open.
 */
export function SettlementLineDialog({
    settlementId,
    count,
    dayPay,
    onClose,
}: Props) {
    const form = useForm({
        kind: 'bonus',
        type: 'addition',
        label: '',
        amount: '',
        is_taxable: true,
    });
    const { data, errors } = form;
    const general = generalErrors(
        errors as Record<string, string | undefined>,
        FIELDS,
    );
    const addition =
        data.kind === 'bonus' ||
        (data.kind === 'other' && data.type === 'addition');

    const submit = () => {
        form.submit(SettlementController.addLine(settlementId), {
            preserveScroll: true,
            onSuccess: () => onClose(),
        });
    };

    return (
        <DialogFrame
            title="Add a line"
            description={`A bonus or a deduction for the final month. The settlement is recalculated. ${count} of 20 lines used.`}
            submitLabel="Add line"
            processing={form.processing}
            onClose={onClose}
            onSubmit={submit}
            general={general}
        >
            <div className="grid gap-5 sm:grid-cols-2">
                <Field id="line-kind" label="Kind" error={errors.kind}>
                    <Select
                        value={data.kind}
                        onValueChange={(value) => {
                            form.setData((d) => ({
                                ...d,
                                kind: value,
                                type:
                                    value === 'notice_shortfall'
                                        ? 'deduction'
                                        : value === 'bonus'
                                          ? 'addition'
                                          : d.type,
                            }));
                        }}
                    >
                        <SelectTrigger
                            {...fieldAria('line-kind', errors.kind)}
                            className="w-full"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {KINDS.map((kind) => (
                                <SelectItem key={kind.value} value={kind.value}>
                                    {kind.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </Field>
                {data.kind === 'other' && (
                    <Field
                        id="line-type"
                        label="Added or deducted"
                        error={errors.type}
                    >
                        <Select
                            value={data.type}
                            onValueChange={(value) =>
                                form.setData('type', value)
                            }
                        >
                            <SelectTrigger
                                {...fieldAria('line-type', errors.type)}
                                className="w-full"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="addition">
                                    Added to pay
                                </SelectItem>
                                <SelectItem value="deduction">
                                    Deducted from pay
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    </Field>
                )}
            </div>

            <Field
                id="line-label"
                label="Label"
                error={errors.label}
                hint="Shown on the statement. At most 100 characters."
            >
                <Input
                    {...fieldAria('line-label', errors.label, true)}
                    value={data.label}
                    maxLength={100}
                    onChange={(e) => form.setData('label', e.target.value)}
                />
            </Field>

            <Field
                id="line-amount"
                label="Amount (rupees)"
                error={errors.amount}
                hint={
                    data.kind === 'notice_shortfall' && dayPay !== null
                        ? `One day's pay came to about ${formatMoney(dayPay)}. Whole rupees, 1 to 10,000,000.`
                        : 'Whole rupees, 1 to 10,000,000.'
                }
            >
                <Input
                    {...fieldAria('line-amount', errors.amount, true)}
                    inputMode="numeric"
                    value={data.amount}
                    onChange={(e) =>
                        form.setData(
                            'amount',
                            e.target.value.replace(/[^\d]/g, ''),
                        )
                    }
                    className="sm:w-56"
                />
            </Field>

            {addition && (
                <div className="flex items-start gap-3">
                    <Checkbox
                        id="line-taxable"
                        checked={data.is_taxable}
                        onCheckedChange={(checked) =>
                            form.setData('is_taxable', checked === true)
                        }
                        className="mt-0.5"
                    />
                    <Label htmlFor="line-taxable" className="font-normal">
                        Taxable (counted in the pay the tax is worked out on)
                    </Label>
                </div>
            )}
        </DialogFrame>
    );
}
