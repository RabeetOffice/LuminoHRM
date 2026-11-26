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
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';

export type ReviewerChoice = { id: number; code: string; name: string };

type Props = {
    reviewId: number;
    /** Who the review is about, for the title. */
    employeeName: string;
    /** The current reviewer's employee id; null when it is "HR". */
    currentId: number | null;
    choices: ReviewerChoice[];
    onClose: () => void;
};

const HR = 'hr';

/**
 * HR changes who reviews: a person with review access, or "HR" (any HR user rates). The server's refusals (no access, the
 * same person again) show under the field and the dialog stays open. Mount it only while open.
 */
export function ReviewerDialog({
    reviewId,
    employeeName,
    currentId,
    choices,
    onClose,
}: Props) {
    const form = useForm({
        reviewer: currentId === null ? HR : String(currentId),
    });
    const errors = form.errors as Record<string, string>;
    const fieldError = errors.reviewer_id;
    const others = Object.entries(errors)
        .filter(([key]) => key !== 'reviewer_id' && key !== 'reviewer')
        .map(([, message]) => message);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({
            reviewer_id: data.reviewer === HR ? null : Number(data.reviewer),
        }));
        form.submit(ReviewController.reviewer(reviewId), {
            preserveScroll: true,
            onSuccess: () => onClose(),
        });
    };

    return (
        <Dialog
            open
            onOpenChange={(open) => !open && !form.processing && onClose()}
        >
            <DialogContent className="max-h-[90dvh] overflow-y-auto sm:max-w-lg">
                <form onSubmit={submit} className="grid gap-5" noValidate>
                    <DialogHeader>
                        <DialogTitle>
                            Change reviewer for {employeeName}
                        </DialogTitle>
                        <DialogDescription>
                            The new reviewer takes over at once. The previous
                            reviewer loses access to this review; saved drafts
                            stay.
                        </DialogDescription>
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

                    <Field
                        id="review-reviewer"
                        label="Reviewer"
                        error={fieldError}
                    >
                        <Select
                            value={form.data.reviewer}
                            onValueChange={(value) =>
                                form.setData('reviewer', value)
                            }
                        >
                            <SelectTrigger
                                {...fieldAria('review-reviewer', fieldError)}
                                className="w-full"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value={HR}>HR</SelectItem>
                                {choices.map((option) => (
                                    <SelectItem
                                        key={option.id}
                                        value={String(option.id)}
                                    >
                                        {option.name} ({option.code})
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
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
                        <Button type="submit" disabled={form.processing}>
                            {form.processing && <Spinner />}
                            Change reviewer
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
