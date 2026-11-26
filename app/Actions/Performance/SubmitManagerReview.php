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

/**
 * The reviewer submits the manager review (manager_review -> ready_to_close; ReviewAccess::mayRate). Saves the given ratings
 * and overall comment, needs every KPI rated (ReviewRatings::RATE_EVERY; nothing kept when refused), sets
 * manager_submitted_at and clears an earlier send-back note. Audit `Manager review submitted` (manager_score preview,
 * own_review: true when the sole performance.manage user rated their own review). Lock order: ReviewLock.
 */
class SubmitManagerReview
{
    /** @param  array<int|string, mixed>  $ratings */
    public function handle(User $by, Review $review, array $ratings, ?string $overallComment, bool $commentSent = true): Review
    {
        return ReviewLock::run($review, fn (ReviewCycle $cycle, Review $locked) => $this->within($by, $cycle, $locked, $ratings, $overallComment, $commentSent), $by);
    }

    /** @param  array<int|string, mixed>  $ratings */
    public function within(User $by, ReviewCycle $cycle, Review $locked, array $ratings, ?string $overallComment, bool $commentSent = true): Review
    {
        SaveManagerRatings::check($by, $cycle, $locked, ReviewStates::MANAGER_SUBMIT);
        SaveManagerRatings::write($locked, $ratings, $overallComment, $commentSent);
        ReviewRatings::assertAllRated($locked, 'manager');

        $locked->forceFill([
            'status' => ReviewStates::target(ReviewStates::MANAGER_SUBMIT, $locked->status),
            'manager_submitted_at' => now(),
            'send_back_note' => null,
        ])->save();

        ReviewRatings::audit('Manager review submitted', $locked, $cycle, [
            'manager_score' => ReviewScoring::managerScore($locked),
            'own_review' => ReviewAccess::isReviewee($by, $locked),
        ], $by);

        return $locked;
    }
}
