import { useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';
import CycleController from '@/actions/App/Http/Controllers/Performance/CycleController';
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
import { formatDate } from '@/lib/format-date';

type Props = {
    cycleId: number;
    /** "Y-m-d". */
    dueOn: string;
    /** "Y-m-d": the due date cannot be before it. */
    periodEnd: string;
    onClose: () => void;
};

/**
 * HR moves an open cycle's due date (performance.cycles.due-date). It must be a real date on or after the period end. The
 * server's refusals (before the period ends, the same date, a closed cycle) show in the dialog, which stays open. Mount it
 * only while open.
 */
export function ChangeDueDateDialog({
    cycleId,
    dueOn,
    periodEnd,
    onClose,
}: Props) {
    const dateFormat = usePage().props.app.dateFormat;
    const form = useForm({ due_on: dueOn });
    const { data } = form;
    const errors: Record<string, string | undefined> = form.errors;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.submit(CycleController.dueDate(cycleId), {
            preserveScroll: true,
            onSuccess: () => onClose(),
        });
    };

    return (
        <Dialog
            open
            onOpenChange={(open) => !open && !form.processing && onClose()}
        >
            <DialogContent className="sm:max-w-sm">
                <form onSubmit={submit} className="grid gap-5" noValidate>
                    <DialogHeader>
                        <DialogTitle>Change the due date</DialogTitle>
                        <DialogDescription>
                            The period ends on{' '}
                            {formatDate(periodEnd, dateFormat)}. The due date
                            cannot be earlier.
                        </DialogDescription>
                    </DialogHeader>

                    <Field
                        id="cycle-due-date"
                        label="Due date"
                        error={errors.due_on}
                    >
                        <Input
                            {...fieldAria('cycle-due-date', errors.due_on)}
                            type="date"
                            className="w-full sm:w-48"
                            value={data.due_on}
                            onChange={(e) =>
                                form.setData('due_on', e.target.value)
                            }
                        />
                    </Field>

                    {errors.cycle && (
                        <p
                            role="alert"
                            className="text-sm text-red-600 dark:text-red-400"
                        >
                            {errors.cycle}
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
                            Change date
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
