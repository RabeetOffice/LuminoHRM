import { RatingsForm } from '@/components/performance/ratings-form';
import type { ReviewRow } from '@/components/performance/rating-table';
import type { ReviewColumns } from '@/lib/review-view';

type Props = {
    reviewId: number;
    rows: ReviewRow[];
    columns: ReviewColumns;
    overallComment: string | null;
    canSubmit: boolean;
};

/** The reviewer's ratings and overall comment (manager_review): their saved draft, Save draft and Submit. */
export function ManagerForm({
    reviewId,
    rows,
    columns,
    overallComment,
    canSubmit,
}: Props) {
    return (
        <RatingsForm
            side="manager"
            reviewId={reviewId}
            rows={rows}
            columns={columns}
            overallComment={overallComment}
            canSubmit={canSubmit}
        />
    );
}
