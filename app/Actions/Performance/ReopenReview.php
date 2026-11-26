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
 * HR reopens a closed review while its cycle is open (closed -> ready_to_close; ReviewAccess::mayManage): final_score,
 * closed_at and closed_by are cleared. Audit `Review reopened` (previous_score). Lock order: ReviewLock.
 */
class ReopenReview
{
    public const CYCLE_CLOSED = 'This cycle is closed, so its reviews cannot be reopened.';

    public function handle(User $by, Review $review): Review
    {
        return ReviewLock::run($review, fn (ReviewCycle $cycle, Review $locked) => $this->within($by, $cycle, $locked), $by);
    }

    public function within(User $by, ReviewCycle $cycle, Review $locked): Review
    {
        ReviewLock::assertCycleOpen($cycle, self::CYCLE_CLOSED);

        if (! ReviewAccess::mayManage($by, $locked)) {
            throw new AuthorizationException('Only HR can reopen a review.');
        }

        ReviewStates::assertCan(ReviewStates::REOPEN, $locked->status);

        $previous = $locked->final_score;

        $locked->forceFill([
            'status' => ReviewStates::target(ReviewStates::REOPEN, $locked->status),
            'final_score' => null,
            'closed_at' => null,
            'closed_by' => null,
        ])->save();

        ReviewRatings::audit('Review reopened', $locked, $cycle, ['previous_score' => $previous, 'own_review' => ReviewAccess::isReviewee($by, $locked)], $by);

        return $locked;
    }
}
