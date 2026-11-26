import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import ReviewController from '@/actions/App/Http/Controllers/Performance/ReviewController';
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
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';

type Props = {
    reviewId: number;
    /** Who the review is about, for the title. */
    employeeName: string;
    onClose: () => void;
};

/**
 * HR skips a review (performance.reviews.skip) with a reason. A skipped review is set aside, with its ratings kept, and can
 * be restored. The reason is required (up to 500 characters); the server's refusals (no reason, wrong state, closed cycle)
 * show in the dialog, which stays open. Mount it only while open.
 */
export function SkipDialog({ reviewId, employeeName, onClose }: Props) {
    const form = useForm({ reason: '' });
    const { data } = form;
    const errors: Record<string, string | undefined> = form.errors;
    const other = errors.review ?? errors.cycle;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.submit(ReviewController.skip(reviewId), {
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
                        <DialogTitle>Skip {employeeName}'s review</DialogTitle>
                        <DialogDescription>
                            A skipped review does not hold up closing the cycle.
                            What has been written is kept, and you can restore
                            the review later.
                        </DialogDescription>
                    </DialogHeader>

                    <Field
                        id="skip-reason"
                        label="Reason"
                        error={errors.reason}
                        hint="For example: on long leave for the whole period."
                    >
                        <Textarea
                            {...fieldAria('skip-reason', errors.reason, true)}
                            value={data.reason}
                            onChange={(e) =>
                                form.setData('reason', e.target.value)
                            }
                            maxLength={500}
                            rows={3}
                        />
                    </Field>

                    {other && (
                        <p
                            role="alert"
                            className="text-sm text-red-600 dark:text-red-400"
                        >
                            {other}
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
                            Skip review
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
