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
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';

type Props = {
    runId: number;
    label: string;
    /** "Y-m" of the run: the date cannot be before its first day. */
    month: string;
    /** Today in the company's time zone, "Y-m-d" (from the server). */
    today: string;
    onClose: () => void;
};

/**
 * Records the day the salaries were paid out. It does not move money; it only
 * closes the run's story. Dates are plain "Y-m-d" text, never run through the
 * browser's time zone. Mount it only while open.
 */
export function MarkPaidDialog({ runId, label, month, today, onClose }: Props) {
    const first = `${month}-01`;
    const form = useForm({ paid_on: today >= first ? today : first });
    const { data, errors } = form;
    // The refusal of the rules (not tied to one field) comes back under `run`.
    const runError = (errors as Record<string, string | undefined>).run;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.submit(PayrollRunController.paid(runId), {
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
                        <DialogTitle>Mark {label} as paid</DialogTitle>
                        <DialogDescription>
                            Record the day the salaries were paid out. This
                            cannot be undone.
                        </DialogDescription>
                    </DialogHeader>
                    <Field
                        id="paid-on"
                        label="Paid on"
                        hint="From the first day of the month up to today."
                        error={errors.paid_on}
                    >
                        <Input
                            {...fieldAria('paid-on', errors.paid_on, true)}
                            type="date"
                            min={first}
                            max={today}
                            value={data.paid_on}
                            onChange={(e) =>
                                form.setData('paid_on', e.target.value)
                            }
                        />
                    </Field>
                    {runError && (
                        <p role="alert" className="text-sm text-destructive">
                            {runError}
                        </p>
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
                        <Button type="submit" disabled={form.processing}>
                            {form.processing && <Spinner />}
                            Mark as paid
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
