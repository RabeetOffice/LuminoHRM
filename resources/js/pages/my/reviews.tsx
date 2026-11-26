import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowRight, Star } from 'lucide-react';
import { PageHeader } from '@/components/hrm/page-header';
import { StatusPill } from '@/components/hrm/status-pill';
import { ScorePill } from '@/components/performance/reviews-panel';
import type { ClosedReviewRow } from '@/components/performance/reviews-panel';
import { formatDate } from '@/lib/format-date';
import { reviews as myReviews } from '@/routes/my';

type OpenReview = {
    id: number;
    cycle: string;
    /** "Y-m-d". */
    due_on: string;
    /** "Self-review", "With manager" or "Ready to close". */
    state: string;
    /** "Write your self-review" | "With your manager" | "Ready to close". */
    action: string;
    url: string;
};

type SkippedReview = {
    id: number;
    cycle: string;
    state: string;
};

type Props = {
    /** The viewer's own record; null when the account is not linked to an employee. */
    employee: { name: string } | null;
    open: OpenReview[];
    closed: ClosedReviewRow[];
    skipped: SkippedReview[];
};

const linkClass =
    'inline-flex items-center gap-1 text-sm font-medium text-brand-ink hover:underline';

function OpenLink({ url, label }: { url: string; label: string }) {
    return (
        <Link href={url} className={linkClass}>
            {label}
            <ArrowRight aria-hidden="true" className="size-3.5" />
        </Link>
    );
}

/** The signed-in person's own reviews: open, closed and skipped. Never a manager rating or score before close. */
export default function MyReviews({ employee, open, closed, skipped }: Props) {
    const { props } = usePage();
    const dateFormat = props.app.dateFormat;

    return (
        <>
            <Head title="My Reviews" />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title="My Reviews"
                    subtitle={
                        employee
                            ? 'Your performance reviews: what is open and your closed history.'
                            : undefined
                    }
                />

                {employee === null ? (
                    <NotLinked />
                ) : (
                    <>
                        <section className="grid gap-3">
                            <h2 className="text-base font-semibold">Open</h2>
                            {open.length === 0 ? (
                                <p className="rounded-xl border bg-card px-4 py-6 text-sm text-muted-foreground shadow-xs">
                                    You have no open reviews.
                                </p>
                            ) : (
                                <ul
                                    role="list"
                                    className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3"
                                >
                                    {open.map((review) => (
                                        <li
                                            key={review.id}
                                            className="grid gap-2 rounded-xl border bg-card p-4 shadow-xs"
                                        >
                                            <div className="flex flex-wrap items-center justify-between gap-2">
                                                <span className="font-medium">
                                                    {review.cycle}
                                                </span>
                                                <StatusPill tone="amber">
                                                    {review.state}
                                                </StatusPill>
                                            </div>
                                            <p className="text-sm text-muted-foreground">
                                                Due{' '}
                                                {formatDate(
                                                    review.due_on,
                                                    dateFormat,
                                                )}
                                            </p>
                                            <OpenLink
                                                url={review.url}
                                                label={review.action}
                                            />
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </section>

                        <section className="grid gap-3">
                            <h2 className="text-base font-semibold">Closed</h2>
                            {closed.length === 0 ? (
                                <p className="rounded-xl border bg-card px-4 py-6 text-sm text-muted-foreground shadow-xs">
                                    No closed reviews yet.
                                </p>
                            ) : (
                                <ul
                                    role="list"
                                    className="divide-y rounded-xl border bg-card shadow-xs"
                                >
                                    {closed.map((review) => (
                                        <li
                                            key={review.id}
                                            className="flex flex-wrap items-center gap-x-3 gap-y-2 px-4 py-3"
                                        >
                                            <div className="min-w-0 flex-1">
                                                <div className="truncate text-sm font-medium">
                                                    {review.cycle}
                                                </div>
                                                {review.closed_on && (
                                                    <div className="mt-0.5 text-xs text-muted-foreground">
                                                        Closed{' '}
                                                        {formatDate(
                                                            review.closed_on,
                                                            dateFormat,
                                                        )}
                                                    </div>
                                                )}
                                            </div>
                                            <ScorePill
                                                score={review.final_score}
                                                label={review.label}
                                            />
                                            {review.url && (
                                                <OpenLink
                                                    url={review.url}
                                                    label="Open"
                                                />
                                            )}
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </section>

                        {skipped.length > 0 && (
                            <section className="grid gap-3">
                                <h2 className="text-base font-semibold">
                                    Skipped
                                </h2>
                                <ul
                                    role="list"
                                    className="divide-y rounded-xl border bg-card shadow-xs"
                                >
                                    {skipped.map((review) => (
                                        <li
                                            key={review.id}
                                            className="flex flex-wrap items-center justify-between gap-2 px-4 py-3"
                                        >
                                            <span className="text-sm font-medium">
                                                {review.cycle}
                                            </span>
                                            <StatusPill tone="grey">
                                                {review.state}
                                            </StatusPill>
                                        </li>
                                    ))}
                                </ul>
                            </section>
                        )}
                    </>
                )}
            </div>
        </>
    );
}

function NotLinked() {
    return (
        <section className="flex flex-col items-center rounded-xl border bg-card px-6 py-16 text-center shadow-xs">
            <div className="flex size-12 items-center justify-center rounded-full bg-muted text-muted-foreground">
                <Star aria-hidden="true" className="size-6" />
            </div>
            <p className="mt-4 text-base font-medium">
                Your account is not linked to an employee record yet.
            </p>
            <p className="mt-1 text-sm text-muted-foreground">
                Your reviews appear here once HR links it.
            </p>
        </section>
    );
}

MyReviews.layout = {
    breadcrumbs: [{ title: 'My Reviews', href: myReviews() }],
};
