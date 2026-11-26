import { router } from '@inertiajs/react';
import {
    CircleCheck,
    CornerUpLeft,
    HandHelping,
    Redo2,
    RotateCcw,
    SkipForward,
    UserCog,
} from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import ReviewController from '@/actions/App/Http/Controllers/Performance/ReviewController';
import { ConfirmDialog } from '@/components/hrm/confirm-dialog';
import { ReviewerDialog } from '@/components/performance/reviewer-dialog';
import type { ReviewerChoice } from '@/components/performance/reviewer-dialog';
import { SendBackDialog } from '@/components/performance/send-back-dialog';
import { SkipDialog } from '@/components/performance/skip-dialog';
import { Button } from '@/components/ui/button';
import { formatScore } from '@/lib/performance';
import type { ReviewCan } from '@/lib/review-view';

type Props = {
    reviewId: number;
    employeeName: string;
    can: ReviewCan;
    /** What closing will record, in hundredths (HR's preview at ready to close). */
    previewScore?: number;
    previewLabel?: string;
    reviewerId: number | null;
    reviewerOptions: ReviewerChoice[];
};

type Confirm = 'waive' | 'close' | 'reopen' | 'restore';
type Dialog = 'send_back' | 'skip' | 'reviewer';

type ConfirmCopy = {
    title: string;
    description: ReactNode;
    label: string;
    destructive: boolean;
    action: (id: number) => { url: string; method: string };
};

/**
 * The HR actions of a review: only those the server said the viewer may do now (`can`). A confirm dialog stays open when
 * the server refuses and shows the message in it; the dialogs with a text field show it under the field.
 */
export function ReviewActions({
    reviewId,
    employeeName,
    can,
    previewScore,
    previewLabel,
    reviewerId,
    reviewerOptions,
}: Props) {
    const [confirm, setConfirm] = useState<Confirm | null>(null);
    const [dialog, setDialog] = useState<Dialog | null>(null);
    const [processing, setProcessing] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const copy: Record<Confirm, ConfirmCopy> = {
        close: {
            title: `Close ${employeeName}'s review?`,
            description:
                previewScore === undefined
                    ? 'The score is recorded as the final score. You can reopen the review while the cycle is open.'
                    : `The final score will be ${formatScore(previewScore)}${previewLabel ? ` (${previewLabel})` : ''}. The employee will then see the manager's ratings and comments. You can reopen the review while the cycle is open.`,
            label: 'Close review',
            destructive: false,
            action: ReviewController.close,
        },
        reopen: {
            title: 'Reopen this review?',
            description:
                'The final score is cleared and the review goes back to ready to close. The employee stops seeing the manager side until it is closed again.',
            label: 'Reopen review',
            destructive: false,
            action: ReviewController.reopen,
        },
        waive: {
            title: 'Waive the self-review?',
            description:
                'The review moves on to the manager without the employee’s self-review. Any draft they saved is not shown to the manager.',
            label: 'Waive self-review',
            destructive: false,
            action: ReviewController.waive,
        },
        restore: {
            title: 'Restore this review?',
            description:
                'The review returns to where it was before it was skipped.',
            label: 'Restore review',
            destructive: false,
            action: ReviewController.restore,
        },
    };

    const open = (next: Confirm) => {
        setError(null);
        setConfirm(next);
    };

    const run = () => {
        if (confirm === null) {
            return;
        }

        const target = copy[confirm].action(reviewId);

        router.visit(target.url, {
            method: target.method as 'post',
            preserveScroll: true,
            onStart: () => {
                setProcessing(true);
                setError(null);
            },
            onSuccess: () => setConfirm(null),
            onError: (errors) =>
                setError(
                    Object.values(errors).join(' ') ||
                        'That did not work. Try again.',
                ),
            onFinish: () => setProcessing(false),
        });
    };

    const any = Object.values(can).some(Boolean);
    const hrActions =
        can.close ||
        can.send_back ||
        can.reopen ||
        can.waive ||
        can.skip ||
        can.restore ||
        can.change_reviewer;

    if (!any || !hrActions) {
        return null;
    }

    return (
        <section aria-label="Review actions" className="grid gap-3">
            <div className="flex flex-wrap items-center gap-2">
                {can.close && (
                    <Button type="button" onClick={() => open('close')}>
                        <CircleCheck /> Close review
                    </Button>
                )}
                {can.send_back && (
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => setDialog('send_back')}
                    >
                        <CornerUpLeft /> Send back
                    </Button>
                )}
                {can.reopen && (
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => open('reopen')}
                    >
                        <Redo2 /> Reopen
                    </Button>
                )}
                {can.waive && (
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => open('waive')}
                    >
                        <HandHelping /> Waive self-review
                    </Button>
                )}
                {can.restore && (
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => open('restore')}
                    >
                        <RotateCcw /> Restore
                    </Button>
                )}
                {can.change_reviewer && (
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => setDialog('reviewer')}
                    >
                        <UserCog /> Change reviewer
                    </Button>
                )}
                {can.skip && (
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => setDialog('skip')}
                    >
                        <SkipForward /> Skip
                    </Button>
                )}
            </div>

            {confirm !== null && (
                <ConfirmDialog
                    open
                    onOpenChange={(next) => !next && setConfirm(null)}
                    title={copy[confirm].title}
                    description={
                        <>
                            {copy[confirm].description}
                            {error && (
                                <span
                                    role="alert"
                                    className="mt-3 block text-sm text-red-600 dark:text-red-400"
                                >
                                    {error}
                                </span>
                            )}
                        </>
                    }
                    confirmLabel={copy[confirm].label}
                    destructive={copy[confirm].destructive}
                    processing={processing}
                    onConfirm={run}
                />
            )}

            {dialog === 'send_back' && (
                <SendBackDialog
                    reviewId={reviewId}
                    onClose={() => setDialog(null)}
                />
            )}
            {dialog === 'skip' && (
                <SkipDialog
                    reviewId={reviewId}
                    employeeName={employeeName}
                    onClose={() => setDialog(null)}
                />
            )}
            {dialog === 'reviewer' && (
                <ReviewerDialog
                    reviewId={reviewId}
                    employeeName={employeeName}
                    currentId={reviewerId}
                    choices={reviewerOptions}
                    onClose={() => setDialog(null)}
                />
            )}
        </section>
    );
}
