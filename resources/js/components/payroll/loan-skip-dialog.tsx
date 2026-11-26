import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import LoanController from '@/actions/App/Http/Controllers/Payroll/LoanController';
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
import { monthLabel } from '@/lib/payroll';

type Props = {
    loanId: number;
    /** The first month that may be skipped (start month or first open month). */
    minMonth: string;
    onClose: () => void;
};

/**
 * Skips one month of a loan's installments, with a reason. The rest of the
 * schedule moves one month later. Mount it only while open.
 */
export function LoanSkipDialog({ loanId, minMonth, onClose }: Props) {
    const form = useForm({ month: minMonth, reason: '' });
    const { data, errors } = form;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.submit(LoanController.skip(loanId), {
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
                        <DialogTitle>Skip a month</DialogTitle>
                        <DialogDescription>
                            Nothing is deducted that month; the remaining
                            installments move one month later.
                        </DialogDescription>
                    </DialogHeader>
                    <Field
                        id="skip-month"
                        label="Month"
                        hint={`${monthLabel(minMonth)} or later.`}
                        error={errors.month}
                    >
                        <Input
                            {...fieldAria('skip-month', errors.month, true)}
                            type="month"
                            min={minMonth}
                            value={data.month}
                            onChange={(e) =>
                                form.setData('month', e.target.value)
                            }
                        />
                    </Field>
                    <Field
                        id="skip-reason"
                        label="Reason"
                        error={errors.reason}
                    >
                        <Textarea
                            {...fieldAria('skip-reason', errors.reason)}
                            value={data.reason}
                            maxLength={255}
                            rows={2}
                            onChange={(e) =>
                                form.setData('reason', e.target.value)
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
                            Skip month
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
