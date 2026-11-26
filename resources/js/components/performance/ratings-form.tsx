import { CheckCircle2, TriangleAlert } from 'lucide-react';
import { useState } from 'react';
import ReviewController from '@/actions/App/Http/Controllers/Performance/ReviewController';
import { ConfirmDialog } from '@/components/hrm/confirm-dialog';
import InputError from '@/components/input-error';
import { RatingTable } from '@/components/performance/rating-table';
import type { ReviewRow } from '@/components/performance/rating-table';
import { useRatingsForm } from '@/components/performance/use-ratings-form';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import type { ReviewColumns } from '@/lib/review-view';

type Props = {
    side: 'self' | 'manager';
    reviewId: number;
    rows: ReviewRow[];
    columns: ReviewColumns;
    /** The reviewer's saved overall comment (manager side only). */
    overallComment: string | null;
    canSubmit: boolean;
};

const COPY = {
    self: {
        draft: ReviewController.selfDraft,
        submit: ReviewController.selfSubmit,
        title: 'Submit your self-review?',
        body: 'Your manager will see your ratings and comments, and you will not be able to change them.',
        confirm: 'Submit self-review',
        hint: 'Rate each KPI from 1 (lowest) to 5 (highest). Save a draft any time; nobody else sees it until you submit.',
    },
    manager: {
        draft: ReviewController.managerDraft,
        submit: ReviewController.managerSubmit,
        title: 'Submit this review?',
        body: 'HR will see your ratings and comments. Until HR closes the review, they can send it back to you.',
        confirm: 'Submit review',
        hint: 'Rate each KPI from 1 (lowest) to 5 (highest). Your draft is private until you submit.',
    },
} as const;

/**
 * The editable review: the ratings table with this person's side open for input, Save draft and Submit. Used for the
 * employee's self side (SelfForm) and the reviewer's manager side (ManagerForm). A failed save or submit leaves the page
 * as it is, with the server's message under the field it belongs to or in the alert, and the confirm dialog open with the
 * same message.
 */
export function RatingsForm({
    side,
    reviewId,
    rows,
    columns,
    overallComment,
    canSubmit,
}: Props) {
    const copy = COPY[side];
    const ratings = useRatingsForm(rows, side, overallComment);
    const [confirming, setConfirming] = useState(false);
    const { form, general } = ratings;

    return (
        <div className="grid gap-4">
            <p className="text-sm text-muted-foreground">{copy.hint}</p>

            {general.length > 0 && !confirming && (
                <Alert variant="destructive" role="alert">
                    <TriangleAlert />
                    <AlertDescription>
                        {general.map((message) => (
                            <span key={message} className="block">
                                {message}
                            </span>
                        ))}
                    </AlertDescription>
                </Alert>
            )}

            <RatingTable
                rows={rows}
                columns={columns}
                values={ratings.values}
                onChange={ratings.change}
                errors={ratings.rowErrors}
                disabled={form.processing}
            />

            {side === 'manager' && (
                <div className="grid gap-2">
                    <Label htmlFor="manager-overall">Overall comment</Label>
                    <Textarea
                        id="manager-overall"
                        value={form.data.manager_comment}
                        onChange={(e) =>
                            form.setData('manager_comment', e.target.value)
                        }
                        maxLength={2000}
                        rows={4}
                        placeholder="How did this person do overall? (optional)"
                        disabled={form.processing}
                        aria-invalid={ratings.overallError ? true : undefined}
                        aria-describedby={
                            ratings.overallError
                                ? 'manager-overall-error'
                                : undefined
                        }
                    />
                    <InputError
                        id="manager-overall-error"
                        role="alert"
                        message={ratings.overallError}
                    />
                </div>
            )}

            <div className="flex flex-wrap items-center gap-2">
                <Button
                    type="button"
                    variant="outline"
                    disabled={form.processing}
                    onClick={() => ratings.send(copy.draft(reviewId))}
                >
                    {form.processing && !confirming && <Spinner />}
                    Save draft
                </Button>
                {canSubmit && (
                    <Button
                        type="button"
                        disabled={form.processing}
                        onClick={() => setConfirming(true)}
                    >
                        Submit
                    </Button>
                )}
                {ratings.saved && !form.hasErrors && (
                    <span
                        role="status"
                        className="inline-flex items-center gap-1 text-sm text-emerald-700 dark:text-emerald-300"
                    >
                        <CheckCircle2 className="size-4" /> Saved
                    </span>
                )}
            </div>

            <ConfirmDialog
                open={confirming}
                onOpenChange={setConfirming}
                title={copy.title}
                description={
                    <>
                        {copy.body}
                        {general.map((message) => (
                            <span
                                key={message}
                                role="alert"
                                className="mt-3 block text-sm text-red-600 dark:text-red-400"
                            >
                                {message}
                            </span>
                        ))}
                    </>
                }
                confirmLabel={copy.confirm}
                destructive={false}
                processing={form.processing}
                onConfirm={() =>
                    ratings.send(copy.submit(reviewId), () =>
                        setConfirming(false),
                    )
                }
            />
        </div>
    );
}
