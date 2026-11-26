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
 * The employee submits their self-review (self_review -> manager_review; the reviewee only). Saves the given ratings, then
 * needs every KPI rated (ReviewRatings::RATE_EVERY; nothing is kept when refused), stores self_score and
 * self_submitted_at. Audit `Self-review submitted` (self_score). Lock order: ReviewLock.
 */
class SubmitSelfReview
{
    /** @param  array<int|string, mixed>  $ratings */
    public function handle(User $by, Review $review, array $ratings): Review
    {
        return ReviewLock::run($review, fn (ReviewCycle $cycle, Review $locked) => $this->within($by, $cycle, $locked, $ratings));
    }

    /** @param  array<int|string, mixed>  $ratings */
    public function within(User $by, ReviewCycle $cycle, Review $locked, array $ratings): Review
    {
        ReviewLock::assertCycleOpen($cycle);

        if (! ReviewAccess::isReviewee($by, $locked)) {
            throw new AuthorizationException('Only the person being reviewed submits the self-review.');
        }

        ReviewStates::assertCan(ReviewStates::SELF_SUBMIT, $locked->status);
        ReviewRatings::apply($locked, $ratings, 'self');
        ReviewRatings::assertAllRated($locked, 'self');

        $locked->forceFill([
            'self_score' => ReviewScoring::selfScore($locked),
            'self_submitted_at' => now(),
            'status' => ReviewStates::target(ReviewStates::SELF_SUBMIT, $locked->status),
        ])->save();

        ReviewRatings::audit('Self-review submitted', $locked, $cycle, ['self_score' => $locked->self_score], $by);

        return $locked;
    }
}
