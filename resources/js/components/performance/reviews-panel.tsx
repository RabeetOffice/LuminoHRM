import { Link } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';
import { StatusPill } from '@/components/hrm/status-pill';
import type { StatusTone } from '@/components/hrm/status-pill';

/** One closed review as the profile's Reviews tab and My Reviews receive it. */
export type ClosedReviewRow = {
    id: number;
    cycle: string;
    /** Only on the profile tab: the review period, "Y-m-d". */
    period?: { from: string; to: string };
    /** "4.12"; null only if a closed review has no score. */
    final_score: string | null;
    /** "Outstanding" ... "Unsatisfactory". */
    label: string | null;
    /** Only on the profile tab: the reviewer's name, or "HR". */
    reviewer?: string;
    /** "Y-m-d". */
    closed_on: string | null;
    /** Absent when the viewer may not open the review. */
    url?: string;
};

/** The profile's Reviews tab data: closed reviews, newest first. */
export type ProfileReviews = { closed: ClosedReviewRow[] };

/** The colour of a score label (the labels come from the server's settings). */
export function scoreTone(label: string | null): StatusTone {
    switch (label) {
        case 'Outstanding':
        case 'Exceeds':
            return 'green';
        case 'Meets':
            return 'blue';
        case 'Below':
            return 'amber';
        case 'Unsatisfactory':
            return 'red';
        default:
            return 'grey';
    }
}

/** "4.12 · Exceeds" in a coloured pill. */
export function ScorePill({
    score,
    label,
}: {
    score: string | null;
    label: string | null;
}) {
    if (score === null) {
        return <StatusPill tone="grey">No score</StatusPill>;
    }

    return (
        <StatusPill tone={scoreTone(label)}>
            <span className="tabular-nums">{score}</span>
            {label && <> · {label}</>}
        </StatusPill>
    );
}

/** The Reviews tab: the person's closed reviews, newest first. */
export function ReviewsPanel({
    reviews,
    date,
}: {
    reviews: ProfileReviews;
    date: (value: string | null | undefined) => string | null;
}) {
    if (reviews.closed.length === 0) {
        return (
            <p className="text-sm text-muted-foreground">
                No closed reviews yet.
            </p>
        );
    }

    return (
        <ul role="list" className="divide-y rounded-lg border">
            {reviews.closed.map((review) => (
                <li
                    key={review.id}
                    className="flex flex-wrap items-center gap-x-3 gap-y-2 px-3 py-2.5"
                >
                    <div className="min-w-0 flex-1">
                        <div className="truncate text-sm font-medium">
                            {review.cycle}
                        </div>
                        <div className="mt-0.5 text-xs text-muted-foreground">
                            {review.period &&
                                `${date(review.period.from)} – ${date(review.period.to)} · `}
                            Reviewed by {review.reviewer ?? 'HR'}
                            {review.closed_on &&
                                ` · Closed ${date(review.closed_on)}`}
                        </div>
                    </div>
                    <ScorePill
                        score={review.final_score}
                        label={review.label}
                    />
                    {review.url && (
                        <Link
                            href={review.url}
                            className="inline-flex items-center gap-1 text-sm font-medium text-brand-ink hover:underline"
                        >
                            Open
                            <ArrowRight
                                aria-hidden="true"
                                className="size-3.5"
                            />
                        </Link>
                    )}
                </li>
            ))}
        </ul>
    );
}
