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
 * HR waives the self-review (self_review -> manager_review; ReviewAccess::mayManage). Any self-rating drafts stay as they
 * are but count as never submitted (self_submitted_at stays null). Audit `Self-review waived`. Lock order: ReviewLock.
 */
class WaiveSelfReview
{
    public function handle(User $by, Review $review): Review
    {
        return ReviewLock::run($review, fn (ReviewCycle $cycle, Review $locked) => $this->within($by, $cycle, $locked), $by);
    }

    public function within(User $by, ReviewCycle $cycle, Review $locked): Review
    {
        ReviewLock::assertCycleOpen($cycle);

        if (! ReviewAccess::mayManage($by, $locked)) {
            throw new AuthorizationException('Only HR can waive a self-review.');
        }

        ReviewStates::assertCan(ReviewStates::WAIVE, $locked->status);

        $locked->forceFill(['status' => ReviewStates::target(ReviewStates::WAIVE, $locked->status)])->save();

        ReviewRatings::audit('Self-review waived', $locked, $cycle, ['own_review' => ReviewAccess::isReviewee($by, $locked)], $by);

        return $locked;
    }
}
