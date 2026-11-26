import { Head, usePage } from '@inertiajs/react';
import { Hourglass, MessageSquareWarning, TriangleAlert } from 'lucide-react';
import { PageHeader } from '@/components/hrm/page-header';
import { StatusPill } from '@/components/hrm/status-pill';
import { ManagerForm } from '@/components/performance/manager-form';
import { RatingTable } from '@/components/performance/rating-table';
import type { ReviewRow } from '@/components/performance/rating-table';
import { ReviewActions } from '@/components/performance/review-actions';
import type { ReviewerChoice } from '@/components/performance/reviewer-dialog';
import { ScoreSummary } from '@/components/performance/score-summary';
import type { ReviewScoreProps } from '@/components/performance/score-summary';
import { SelfForm } from '@/components/performance/self-form';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { formatDate } from '@/lib/format-date';
import { reviewStateLabel, reviewStateTone } from '@/lib/performance';
import { reviewColumns } from '@/lib/review-view';
import type { ReviewCan, ReviewRole } from '@/lib/review-view';
import { reviews as myReviews } from '@/routes/my';
import { index as reviewsIndex, show } from '@/routes/performance/reviews';

type Props = {
    review: {
        id: number;
        employee: {
            id: number;
            code: string;
            name: string;
            designation: string | null;
            department: string | null;
        };
        cycle: {
            id: number;
            name: string;
            due_on: string;
            status: string;
            self_review_enabled: boolean;
        };
        state: string;
        state_label: string;
        reviewer: string;
    };
    role: ReviewRole;
    can: ReviewCan;
    rows: ReviewRow[];
    score: ReviewScoreProps;
    /** Every key below is absent when the viewer may not see it. */
    notice?: string;
    manager_comment?: string | null;
    send_back_note?: string | null;
    skip_reason?: string | null;
    reviewer_options?: ReviewerChoice[];
    reviewer_id?: number | null;
    reviewer_problem?: string | null;
};

export default function ReviewPage({
    review,
    role,
    can,
    rows,
    score,
    notice,
    manager_comment,
    send_back_note,
    skip_reason,
    reviewer_options,
    reviewer_id,
    reviewer_problem,
}: Props) {
    const { props } = usePage();
    const dateFormat = props.app.dateFormat;
    const { employee, cycle } = review;

    const first = rows[0];
    const columns = reviewColumns({
        role,
        state: review.state,
        can,
        selfEnabled: cycle.self_review_enabled,
        hasSelfData: first !== undefined && 'self_rating' in first,
        hasManagerData: first !== undefined && 'manager_rating' in first,
    });
    const editing = columns.self === 'edit' || columns.manager === 'edit';
    const skipped = review.state === 'skipped';
    const cycleClosed = cycle.status === 'closed';
    const subtitle = [cycle.name, employee.designation, employee.department]
        .filter(Boolean)
        .join(' · ');
    const due = formatDate(cycle.due_on, dateFormat);

    return (
        <>
            <Head title={`${employee.name} · ${cycle.name}`} />

            <div className="grid gap-6 p-4 sm:p-6">
                <PageHeader
                    title={employee.name}
                    subtitle={subtitle}
                    actions={
                        <>
                            <StatusPill tone={reviewStateTone(review.state)}>
                                {review.state_label ||
                                    reviewStateLabel(review.state)}
                            </StatusPill>
                            <span className="text-sm text-muted-foreground">
                                {cycleClosed ? 'Cycle closed' : `Due ${due}`}
                            </span>
                        </>
                    }
                />

                <dl className="flex flex-wrap gap-x-8 gap-y-2 text-sm">
                    <div>
                        <dt className="text-xs font-medium text-muted-foreground">
                            Employee code
                        </dt>
                        <dd>{employee.code}</dd>
                    </div>
                    <div>
                        <dt className="text-xs font-medium text-muted-foreground">
                            Reviewer
                        </dt>
                        <dd>{review.reviewer}</dd>
                    </div>
                </dl>

                {send_back_note ? (
                    <Alert role="status">
                        <MessageSquareWarning />
                        <AlertTitle>Sent back by HR</AlertTitle>
                        <AlertDescription>
                            <span className="break-words whitespace-pre-wrap">
                                {send_back_note}
                            </span>
                        </AlertDescription>
                    </Alert>
                ) : null}

                {reviewer_problem ? (
                    <Alert variant="destructive" role="alert">
                        <TriangleAlert />
                        <AlertDescription>{reviewer_problem}</AlertDescription>
                    </Alert>
                ) : null}

                {notice ? (
                    <Alert role="status">
                        <Hourglass />
                        <AlertDescription>{notice}</AlertDescription>
                    </Alert>
                ) : null}

                {skipped && role === 'reviewee' ? (
                    <p className="rounded-lg border bg-card p-4 text-sm text-muted-foreground">
                        This review was skipped.
                    </p>
                ) : null}

                {skipped && skip_reason ? (
                    <Alert role="status">
                        <AlertTitle>Skipped</AlertTitle>
                        <AlertDescription>
                            <span className="break-words whitespace-pre-wrap">
                                {skip_reason}
                            </span>
                        </AlertDescription>
                    </Alert>
                ) : null}

                <ScoreSummary score={score} state={review.state} />

                {editing && columns.self === 'edit' && (
                    <SelfForm
                        reviewId={review.id}
                        rows={rows}
                        columns={columns}
                        canSubmit={can.submit_self}
                    />
                )}

                {editing && columns.manager === 'edit' && (
                    <ManagerForm
                        reviewId={review.id}
                        rows={rows}
                        columns={columns}
                        overallComment={manager_comment ?? null}
                        canSubmit={can.submit_manager}
                    />
                )}

                {!editing && rows.length > 0 && (
                    <RatingTable rows={rows} columns={columns} />
                )}

                {!editing && manager_comment ? (
                    <section className="grid gap-2 rounded-lg border bg-card p-4">
                        <h2 className="text-sm font-semibold">
                            Overall comment
                        </h2>
                        <p className="text-sm break-words whitespace-pre-wrap">
                            {manager_comment}
                        </p>
                    </section>
                ) : null}

                <ReviewActions
                    reviewId={review.id}
                    employeeName={employee.name}
                    can={can}
                    previewScore={score.manager_score}
                    previewLabel={score.label}
                    reviewerId={reviewer_id ?? null}
                    reviewerOptions={reviewer_options ?? []}
                />
            </div>
        </>
    );
}

ReviewPage.layout = (props: {
    role: ReviewRole;
    review: { id: number; employee: { name: string } };
}) => ({
    breadcrumbs: [
        props.role === 'reviewee'
            ? { title: 'My Reviews', href: myReviews() }
            : { title: 'Reviews', href: reviewsIndex() },
        { title: props.review.employee.name, href: show(props.review.id) },
    ],
});
