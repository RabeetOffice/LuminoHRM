import { RatingsForm } from '@/components/performance/ratings-form';
import type { ReviewRow } from '@/components/performance/rating-table';
import type { ReviewColumns } from '@/lib/review-view';

type Props = {
    reviewId: number;
    rows: ReviewRow[];
    columns: ReviewColumns;
    canSubmit: boolean;
};

/** The employee's own ratings (self_review): their saved draft, Save draft and Submit. */
export function SelfForm({ reviewId, rows, columns, canSubmit }: Props) {
    return (
        <RatingsForm
            side="self"
            reviewId={reviewId}
            rows={rows}
            columns={columns}
            overallComment={null}
            canSubmit={canSubmit}
        />
    );
}
