import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import LoanController from '@/actions/App/Http/Controllers/Payroll/LoanController';
import { MoneyInput } from '@/components/payroll/money-input';
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
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { formatMoney, monthLabel } from '@/lib/payroll';

type Props = {
    loanId: number;
    /** What is left to recover (paisa): the most a repayment may be. */
    remaining: number;
    /** The default and earliest month (the first month after approved payroll). */
    month: string;
    minMonth: string | null;
    onClose: () => void;
};

/**
 * Records a repayment made outside payroll (cash, bank transfer), whole
 * rupees, no more than what is left. Mount it only while open.
 */
export function RepaymentDialog({
    loanId,
    remaining,
    month,
    minMonth,
    onClose,
}: Props) {
    const form = useForm({ amount: '', month, note: '' });
    const { data, errors } = form;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.submit(LoanController.repay(loanId), {
            preserveScroll: true,
            onSuccess: () => onClose(),
        });
    };

    return (
        <Dialog
            open
            onOpenChange={(open) => !open && !form.processing && onClose()}
        >
            <DialogContent className="sm:max-w-md">
                <form onSubmit={submit} className="grid gap-5" noValidate>
                    <DialogHeader>
                        <DialogTitle>Record a repayment</DialogTitle>
                        <DialogDescription>
                            Money repaid outside payroll. Up to{' '}
                            {formatMoney(remaining)} is left.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field
                            id="repay-amount"
                            label="Amount"
                            error={errors.amount}
                        >
                            <MoneyInput
                                {...fieldAria('repay-amount', errors.amount)}
                                value={data.amount}
                                onChange={(v) => form.setData('amount', v)}
                            />
                        </Field>
                        <Field
                            id="repay-month"
                            label="Month"
                            hint={
                                minMonth
                                    ? `${monthLabel(minMonth)} or later.`
                                    : undefined
                            }
                            error={errors.month}
                        >
                            <Input
                                {...fieldAria(
                                    'repay-month',
                                    errors.month,
                                    minMonth !== null,
                                )}
                                type="month"
                                min={minMonth ?? undefined}
                                value={data.month}
                                onChange={(e) =>
                                    form.setData('month', e.target.value)
                                }
                            />
                        </Field>
                    </div>
                    <Field
                        id="repay-note"
                        label="How it was repaid"
                        error={errors.note}
                    >
                        <Textarea
                            {...fieldAria('repay-note', errors.note)}
                            value={data.note}
                            maxLength={255}
                            rows={2}
                            placeholder="Cash, receipt 41"
                            onChange={(e) =>
                                form.setData('note', e.target.value)
                            }
                        />
                    </Field>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={onClose}
                            disabled={form.processing}
                        >
                            Cancel
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            {form.processing && <Spinner />}
                            Record repayment
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
