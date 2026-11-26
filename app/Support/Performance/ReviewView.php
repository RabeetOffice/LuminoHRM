<?php

namespace App\Support\Performance;

use App\Models\Employee;
use App\Models\Review;
use App\Models\ReviewScore;
use App\Models\User;

/**
 * The only builder of review props (spec 5.7: the matrix is the contract). A page, a list or a card that carries a review
 * field takes it from here, so what a viewer may see is decided in one place. What a viewer may not see is left out of the
 * array: the key is missing, it is not null and not hidden by the page.
 *
 *   ReviewView::for($user, $review); // review, role, can, rows, score, notice?, manager_comment?, send_back_note?, ...
 *
 * By role (ReviewAccess::role, so the person themselves is always "reviewee", whatever else they hold):
 *   reviewee  their own ratings; the manager side, the overall comment and the scores only once the review is closed; never
 *             the send-back note or the skip reason; a skipped review is just "Skipped".
 *   reviewer  their own manager ratings (drafts too); the employee's ratings only once the self-review was submitted; the
 *             send-back note; while the employee is still rating, only a waiting notice.
 *   hr        everything, drafts included, the live score at ready_to_close, the skip reason and the reviewer problem.
 * An HR person who is the only performance.manage user and works on their own review (ReviewAccess::soleManager) has
 * their own work in front of them (the manager side and the HR actions), as spec 5.7 says; anyone else on their own
 * review gets the reviewee shape.
 */
final class ReviewView
{
    public const WAITING = "Waiting for the employee's self-review.";

    /** @return array<string, mixed> */
    public static function for(User $viewer, Review $review): array
    {
        $role = ReviewAccess::role($viewer, $review) ?? abort(403);

        $review->loadMissing(['employee.designation', 'employee.department', 'reviewer', 'cycle', 'scores']);

        $can = self::can($viewer, $review);
        $closed = $review->status === ReviewStates::CLOSED;
        $skipped = $review->status === ReviewStates::SKIPPED;
        $reviewee = $role === 'reviewee';
        $ownWork = $reviewee && ($can['save_manager'] || $can['submit_manager'] || ReviewAccess::mayManage($viewer, $review));

        // Which sides and notes this viewer may see.
        $managerSide = ! $reviewee || $closed || $ownWork;
        $selfSide = $reviewee || $role === 'hr' || $review->self_submitted_at !== null;
        $notes = ! $reviewee || $ownWork;

        $out = [
            'review' => self::header($review),
            'role' => $role,
            'can' => $can,
            'rows' => [],
            'score' => [],
        ];

        if ($reviewee && $skipped && ! $ownWork) {
            return $out;
        }

        if ($role === 'reviewer' && $review->status === ReviewStates::SELF_REVIEW) {
            $out['rows'] = $review->scores->map(fn (ReviewScore $s) => self::line($s))->all();
            $out['notice'] = self::WAITING;

            return $out;
        }

        $out['rows'] = $review->scores->map(fn (ReviewScore $s) => self::line($s) + ($selfSide ? [
            'self_rating' => $s->self_rating,
            'self_comment' => $s->self_comment,
        ] : []) + ($managerSide ? [
            'manager_rating' => $s->manager_rating,
            'manager_comment' => $s->manager_comment,
        ] : []))->all();

        if ($managerSide) {
            $out['manager_comment'] = $review->manager_comment;
        }

        if ($notes) {
            $out['send_back_note'] = $review->send_back_note;
        }

        if ($role === 'hr' || $ownWork) {
            $out['skip_reason'] = $review->skip_reason;
        }

        $out['score'] = self::score($review, $role === 'hr' ? 'live' : ($selfSide ? 'stored' : 'none'), $managerSide);

        if ($can['change_reviewer']) {
            $out['reviewer_options'] = self::reviewerOptions($review);
            $out['reviewer_id'] = $review->reviewer_id;
        }

        if ($role === 'hr' || $ownWork) {
            $out['reviewer_problem'] = self::reviewerProblem($review);
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private static function header(Review $review): array
    {
        $employee = $review->employee;
        $cycle = $review->cycle;

        return [
            'id' => $review->id,
            'employee' => [
                'id' => $employee->id,
                'code' => $employee->employee_code,
                'name' => $employee->full_name,
                'designation' => $employee->designation?->name,
                'department' => $employee->department?->name,
            ],
            'cycle' => [
                'id' => $cycle->id,
                'name' => $cycle->name,
                'due_on' => $cycle->due_on->format('Y-m-d'),
                'status' => $cycle->status,
                'self_review_enabled' => $cycle->self_review_enabled,
            ],
            'state' => $review->status,
            'state_label' => ReviewStates::label($review->status),
            'reviewer' => $review->reviewer_id === null ? 'HR' : $review->reviewer->full_name,
        ];
    }

    /** @return array{id: int, name: string, description: string|null, weight: int} */
    private static function line(ReviewScore $score): array
    {
        return ['id' => $score->id, 'name' => $score->name, 'description' => $score->description, 'weight' => $score->weight];
    }

    /**
     * The scores this viewer may see, in hundredths, and the label of the manager score. Self score: the stored one once
     * submitted (HR: live). Manager score: live, at ready_to_close and once closed. Final score: closed only.
     *
     * @param  'live'|'stored'|'none'  $selfMode
     * @return array<string, int|string>
     */
    private static function score(Review $review, string $selfMode, bool $managerSide): array
    {
        $score = [];

        if ($selfMode === 'live') {
            $self = ReviewScoring::selfScore($review);
        } elseif ($selfMode === 'stored') {
            $self = $review->self_submitted_at !== null ? $review->self_score : null;
        } else {
            $self = null;
        }

        if ($self !== null) {
            $score['self_score'] = $self;
        }

        if (! $managerSide) {
            return $score;
        }

        if (in_array($review->status, [ReviewStates::READY_TO_CLOSE, ReviewStates::CLOSED], true)) {
            $manager = ReviewScoring::managerScore($review);

            if ($manager !== null) {
                $score['manager_score'] = $manager;
                $score['label'] = ReviewScoring::label($manager);
            }
        }

        if ($review->status === ReviewStates::CLOSED && $review->final_score !== null) {
            $score['final_score'] = $review->final_score;
            $score['label'] = ReviewScoring::label($review->final_score);
        }

        return $score;
    }

    /**
     * The viewer's own actions, each true only when the action would be accepted now (cycle open, state, actor).
     *
     * @return array<string, bool>
     */
    private static function can(User $viewer, Review $review): array
    {
        $open = $review->cycle()->value('status') === 'open';
        $state = $review->status;
        $reviewee = ReviewAccess::isReviewee($viewer, $review);
        $rate = $open && ReviewAccess::mayRate($viewer, $review);
        $manage = $open && ReviewAccess::mayManage($viewer, $review);

        return [
            'save_self' => $open && $reviewee && ReviewStates::canMove(ReviewStates::SELF_DRAFT, $state),
            'submit_self' => $open && $reviewee && ReviewStates::canMove(ReviewStates::SELF_SUBMIT, $state),
            'save_manager' => $rate && ReviewStates::canMove(ReviewStates::MANAGER_DRAFT, $state),
            'submit_manager' => $rate && ReviewStates::canMove(ReviewStates::MANAGER_SUBMIT, $state),
            'send_back' => $manage && ReviewStates::canMove(ReviewStates::SEND_BACK, $state),
            'close' => $manage && ReviewStates::canMove(ReviewStates::CLOSE, $state),
            'reopen' => $manage && ReviewStates::canMove(ReviewStates::REOPEN, $state),
            'skip' => $manage && ReviewStates::canMove(ReviewStates::SKIP, $state),
            'restore' => $manage && ReviewStates::canMove(ReviewStates::RESTORE, $state),
            'waive' => $manage && ReviewStates::canMove(ReviewStates::WAIVE, $state),
            'change_reviewer' => $manage && ReviewStates::canMove(ReviewStates::CHANGE_REVIEWER, $state),
        ];
    }

    /**
     * People who may be chosen as reviewer: an active login holding performance.review-team, other than the reviewee.
     *
     * @return array<int, array{id: int, code: string, name: string}>
     */
    private static function reviewerOptions(Review $review): array
    {
        $ids = User::permission('performance.review-team')
            ->where('users.is_active', true)
            ->whereNotNull('users.employee_id')
            ->pluck('users.employee_id');

        return Employee::query()->whereIn('id', $ids)->whereKeyNot($review->employee_id)
            ->orderBy('first_name')->orderBy('last_name')->orderBy('id')->get()
            ->map(fn (Employee $e) => ['id' => $e->id, 'code' => $e->employee_code, 'name' => $e->full_name])
            ->values()
            ->all();
    }

    /** HR's warning when the stored reviewer can no longer review (no active login or no review access); null otherwise. */
    private static function reviewerProblem(Review $review): ?string
    {
        if ($review->reviewer_id === null || $review->status === ReviewStates::CLOSED) {
            return null;
        }

        $login = User::query()->where('employee_id', $review->reviewer_id)->first();

        if ($login === null || ! $login->is_active || ! $login->can('performance.review-team')) {
            $name = $review->reviewer->full_name;

            return "{$name} has no active login with review access. Choose another reviewer.";
        }

        return null;
    }
}
