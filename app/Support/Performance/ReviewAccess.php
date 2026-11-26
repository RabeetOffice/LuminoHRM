<?php

namespace App\Support\Performance;

use App\Models\Review;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who may see and do what on a review (spec 5.5 and 5.7). Every screen and action asks here; nothing re-implements it.
 *
 *   ReviewAccess::role($user, $review);      // reviewee | hr | reviewer | null
 *   ReviewAccess::mayRate($user, $review);   // manager ratings (the reviewer; performance.manage when the reviewer is "HR")
 *   ReviewAccess::mayManage($user, $review); // HR actions (waive, send back, close, reopen, skip, restore, reviewer)
 *   ReviewAccess::scope(Review::query(), $user)->get();
 *
 * Own review: nobody rates or manages their own review, unless they are the only active holder of performance.manage
 * (soleManager), and then the action's audit entry says own_review: true. HR never rates on a manager's behalf: when a
 * review has a reviewer, only that reviewer rates (HR changes the reviewer instead).
 *
 * A reviewer counts only while they hold performance.review-team (a stored reviewer who lost access can do nothing; HR
 * changes the reviewer). The reviewer sees the review while the cycle is open; afterwards the closed history of their
 * current direct reports.
 */
final class ReviewAccess
{
    public static function isReviewee(User $user, Review $review): bool
    {
        return $user->employee_id !== null && (int) $user->employee_id === (int) $review->employee_id;
    }

    public static function isReviewer(User $user, Review $review): bool
    {
        return $user->employee_id !== null
            && $review->reviewer_id !== null
            && (int) $user->employee_id === (int) $review->reviewer_id
            && $user->can('performance.review-team');
    }

    /** The viewer's relation to the review: reviewee first (whatever their role), then hr, then reviewer; null = none. */
    public static function role(User $user, Review $review): ?string
    {
        if (self::isReviewee($user, $review)) {
            return 'reviewee';
        }

        if ($user->can('performance.manage')) {
            return 'hr';
        }

        if (! $user->can('performance.review-team') || $user->employee_id === null) {
            return null;
        }

        $me = (int) $user->employee_id;

        if ($review->reviewer_id !== null && (int) $review->reviewer_id === $me && $review->cycle()->value('status') === 'open') {
            return 'reviewer';
        }

        if ($review->status === ReviewStates::CLOSED && (int) $review->employee()->value('manager_id') === $me) {
            return 'reviewer';
        }

        return null;
    }

    /** HR actions: performance.manage, never on one's own review unless the sole active performance.manage user. */
    public static function mayManage(User $user, Review $review): bool
    {
        if (! $user->can('performance.manage')) {
            return false;
        }

        return ! self::isReviewee($user, $review) || self::soleManager($user);
    }

    /**
     * Manager ratings: the reviewer; when the reviewer is "HR" (reviewer_id null), any performance.manage user. Never the
     * reviewee, except the sole active performance.manage user on an "HR" review.
     */
    public static function mayRate(User $user, Review $review): bool
    {
        if (self::isReviewee($user, $review)) {
            return $review->reviewer_id === null && self::soleManager($user);
        }

        if ($review->reviewer_id !== null) {
            return self::isReviewer($user, $review);
        }

        return $user->can('performance.manage');
    }

    /** $user holds performance.manage and no other active user does. */
    public static function soleManager(User $user): bool
    {
        if (! $user->is_active || ! $user->can('performance.manage')) {
            return false;
        }

        return ! User::permission('performance.manage')
            ->where('users.is_active', true)
            ->whereKeyNot($user->getKey())
            ->exists();
    }

    /**
     * The reviews $user may list: performance.manage all; performance.review-team their open-cycle reviews as reviewer, the
     * closed reviews of their current direct reports, and their own; anyone else their own; none without an employee record.
     *
     * @param  Builder<Review>  $query
     * @return Builder<Review>
     */
    public static function scope(Builder $query, User $user): Builder
    {
        if ($user->can('performance.manage')) {
            return $query;
        }

        $me = $user->employee_id;

        if ($me === null) {
            return $query->whereRaw('1 = 0');
        }

        if (! $user->can('performance.review-team')) {
            return $query->where('reviews.employee_id', $me);
        }

        return $query->where(function (Builder $w) use ($me) {
            $w->where('reviews.employee_id', $me)
                ->orWhere(fn (Builder $r) => $r->where('reviews.reviewer_id', $me)
                    ->whereHas('cycle', fn (Builder $c) => $c->where('status', 'open')))
                ->orWhere(fn (Builder $h) => $h->where('reviews.status', ReviewStates::CLOSED)
                    ->whereHas('employee', fn (Builder $e) => $e->where('manager_id', $me)));
        });
    }

    public static function findVisible(User $user, int $id): ?Review
    {
        return self::scope(Review::query(), $user)->whereKey($id)->first();
    }
}
