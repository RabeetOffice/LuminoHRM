import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import PayrollRunController from '@/actions/App/Http/Controllers/Payroll/PayrollRunController';
import { Field, fieldAria } from '@/components/settings/settings-form';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';

export type GenerateMonth = {
    value: string;
    label: string;
    has_draft: boolean;
};

export type GenerateOptions = {
    months: GenerateMonth[];
    default: string | null;
};

type Props = {
    options: GenerateOptions;
    onClose: () => void;
};

/**
 * Generates the payroll of one month (or recalculates that month's draft) and
 * opens it. Only months after the latest approved one, up to the current month,
 * are offered. Mount it only while open.
 */
export function GenerateRunDialog({ options, onClose }: Props) {
    const form = useForm({ month: options.default ?? '' });
    const { data, errors } = form;
    const chosen = options.months.find((m) => m.value === data.month);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.submit(PayrollRunController.store(), { preserveScroll: true });
    };

    return (
        <Dialog
            open
            onOpenChange={(open) => !open && !form.processing && onClose()}
        >
            <DialogContent className="sm:max-w-md">
                <form onSubmit={submit} className="grid gap-5" noValidate>
                    <DialogHeader>
                        <DialogTitle>Generate payroll</DialogTitle>
                        <DialogDescription>
                            Calculates everyone's pay for the month from
                            attendance, leave, salary profiles and loans.
                            Nothing is final until it is approved.
                        </DialogDescription>
                    </DialogHeader>
                    {options.months.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            Every month up to the current one is already
                            approved.
                        </p>
                    ) : (
                        <Field
                            id="generate-month"
                            label="Month"
                            hint={
                                chosen?.has_draft
                                    ? 'A draft already exists: it is recalculated, keeping your adjustments.'
                                    : undefined
                            }
                            error={errors.month}
                        >
                            <Select
                                value={data.month}
                                onValueChange={(v) => form.setData('month', v)}
                            >
                                <SelectTrigger
                                    {...fieldAria(
                                        'generate-month',
                                        errors.month,
                                        chosen?.has_draft === true,
                                    )}
                                >
                                    <SelectValue placeholder="Choose a month" />
                                </SelectTrigger>
                                <SelectContent>
                                    {options.months.map((month) => (
                                        <SelectItem
                                            key={month.value}
                                            value={month.value}
                                        >
                                            {month.label}
                                            {month.has_draft ? ' (draft)' : ''}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </Field>
                    )}
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={onClose}
                            disabled={form.processing}
                        >
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            disabled={
                                form.processing ||
                                options.months.length === 0 ||
                                data.month === ''
                            }
                        >
                            {form.processing && <Spinner />}
                            {chosen?.has_draft ? 'Recalculate' : 'Generate'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
