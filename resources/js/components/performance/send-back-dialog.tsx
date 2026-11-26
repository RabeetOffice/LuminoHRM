import { useForm } from '@inertiajs/react';
import { TriangleAlert } from 'lucide-react';
import type { FormEvent } from 'react';
import ReviewController from '@/actions/App/Http/Controllers/Performance/ReviewController';
import { Field, fieldAria } from '@/components/settings/settings-form';
import { Alert, AlertDescription } from '@/components/ui/alert';
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
    onClose: () => void;
};

type NoteProps = Props & {
    title: string;
    description: string;
    label: string;
    hint: string;
    /** The request field the text goes in (`note` or `reason`). */
    field: 'note' | 'reason';
    submitLabel: string;
    destructive?: boolean;
    action: typeof ReviewController.sendBack;
};

/**
 * A dialog with one required text (the send-back note, the skip reason). It stays open when the server refuses, with the
 * message under the text (or above it, when the refusal is about the review itself), and closes on success. Mount it only
 * while open so it starts empty.
 */
function NoteDialog({
    reviewId,
    onClose,
    title,
    description,
    label,
    hint,
    field,
    submitLabel,
    destructive,
    action,
}: NoteProps) {
    const form = useForm({ text: '' });
    const errors = form.errors as Record<string, string>;
    const fieldError = errors[field];
    const others = Object.entries(errors)
        .filter(([key]) => key !== field && key !== 'text')
        .map(([, message]) => message);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({ [field]: data.text }));
        form.submit(action(reviewId), {
            preserveScroll: true,
            onSuccess: () => onClose(),
        });
    };

    const id = `review-${field}`;

    return (
        <Dialog
            open
            onOpenChange={(open) => !open && !form.processing && onClose()}
        >
            <DialogContent className="max-h-[90dvh] overflow-y-auto sm:max-w-lg">
                <form onSubmit={submit} className="grid gap-5" noValidate>
                    <DialogHeader>
                        <DialogTitle>{title}</DialogTitle>
                        <DialogDescription>{description}</DialogDescription>
                    </DialogHeader>

                    {others.length > 0 && (
                        <Alert variant="destructive" role="alert">
                            <TriangleAlert />
                            <AlertDescription>
                                {others.map((message) => (
                                    <span key={message} className="block">
                                        {message}
                                    </span>
                                ))}
                            </AlertDescription>
                        </Alert>
                    )}

                    <Field id={id} label={label} error={fieldError} hint={hint}>
                        <Textarea
                            {...fieldAria(id, fieldError, true)}
                            value={form.data.text}
                            onChange={(e) =>
                                form.setData('text', e.target.value)
                            }
                            maxLength={500}
                            rows={4}
                        />
                    </Field>

                    <DialogFooter className="flex-col-reverse gap-2 sm:flex-row">
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
                            variant={destructive ? 'destructive' : 'default'}
                            disabled={form.processing}
                        >
                            {form.processing && <Spinner />}
                            {submitLabel}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

/** HR sends a review that is ready to close back to the manager with a note. */
export function SendBackDialog({ reviewId, onClose }: Props) {
    return (
        <NoteDialog
            reviewId={reviewId}
            onClose={onClose}
            title="Send back to the manager"
            description="The review returns to the manager, who sees your note and can change the ratings and submit again. The employee does not see the note."
            label="Note for the manager"
            hint="Required. At most 500 characters."
            field="note"
            submitLabel="Send back"
            action={ReviewController.sendBack}
        />
    );
}
