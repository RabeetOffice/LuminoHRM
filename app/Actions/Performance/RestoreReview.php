<?php

namespace App\Actions\Performance;

use App\Models\Review;
use App\Models\ReviewCycle;
use App\Models\User;
use App\Support\Performance\ReviewAccess;
use App\Support\Performance\ReviewLock;
use App\Support\Performance\ReviewRatings;
use App\Support\Performance\ReviewStates;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * HR restores a skipped review while its cycle is open (skipped -> the state it was skipped from; ReviewAccess::mayManage).
 * skip_reason and skipped_from are cleared. Audit `Review restored` (to). Lock order: ReviewLock.
 */
class RestoreReview
{
    public function handle(User $by, Review $review): Review
    {
        return ReviewLock::run($review, fn (ReviewCycle $cycle, Review $locked) => $this->within($by, $cycle, $locked), $by);
    }

    public function within(User $by, ReviewCycle $cycle, Review $locked): Review
    {
        ReviewLock::assertCycleOpen($cycle);

        if (! ReviewAccess::mayManage($by, $locked)) {
            throw new AuthorizationException('Only HR can restore a review.');
        }

        ReviewStates::assertCan(ReviewStates::RESTORE, $locked->status);

        $to = in_array($locked->skipped_from, [ReviewStates::SELF_REVIEW, ReviewStates::MANAGER_REVIEW, ReviewStates::READY_TO_CLOSE], true)
            ? $locked->skipped_from
            : ReviewStates::initial($cycle);

        $locked->forceFill(['status' => $to, 'skip_reason' => null, 'skipped_from' => null])->save();

        ReviewRatings::audit('Review restored', $locked, $cycle, ['to' => $to, 'own_review' => ReviewAccess::isReviewee($by, $locked)], $by);

        return $locked;
    }
}
