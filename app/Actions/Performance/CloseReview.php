<?php

namespace App\Actions\Performance;

use App\Models\Review;
use App\Models\ReviewCycle;
use App\Models\User;
use App\Support\Performance\ReviewAccess;
use App\Support\Performance\ReviewLock;
use App\Support\Performance\ReviewRatings;
use App\Support\Performance\ReviewScoring;
use App\Support\Performance\ReviewStates;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * HR closes a review (ready_to_close -> closed; ReviewAccess::mayManage): the score freezes as final_score
 * (ReviewScoring::managerScore of the rows read under the lock), with closed_at and closed_by. Audit `Review closed`
 * (final_score, label, own_review). Lock order: ReviewLock.
 */
class CloseReview
{
    public function handle(User $by, Review $review): Review
    {
        return ReviewLock::run($review, fn (ReviewCycle $cycle, Review $locked) => $this->within($by, $cycle, $locked));
    }

    public function within(User $by, ReviewCycle $cycle, Review $locked): Review
    {
        ReviewLock::assertCycleOpen($cycle);

        if (! ReviewAccess::mayManage($by, $locked)) {
            throw new AuthorizationException('Only HR can close a review, and never their own.');
        }

        ReviewStates::assertCan(ReviewStates::CLOSE, $locked->status);
        ReviewRatings::assertAllRated($locked, 'manager');

        $score = (int) ReviewScoring::managerScore($locked);

        $locked->forceFill([
            'status' => ReviewStates::target(ReviewStates::CLOSE, $locked->status),
            'final_score' => $score,
            'closed_at' => now(),
            'closed_by' => $by->id,
        ])->save();

        ReviewRatings::audit('Review closed', $locked, $cycle, [
            'final_score' => $score,
            'label' => ReviewScoring::label($score),
            'own_review' => ReviewAccess::isReviewee($by, $locked),
        ], $by);

        return $locked;
    }
}
