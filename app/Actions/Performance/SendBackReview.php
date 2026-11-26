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
use Illuminate\Validation\ValidationException;

/**
 * HR sends a submitted manager review back (ready_to_close -> manager_review; ReviewAccess::mayManage) with a note for the
 * manager (required, up to 500). The ratings stay so the reviewer can edit them; manager_submitted_at is cleared. Audit
 * `Review sent back` (note). Lock order: ReviewLock.
 */
class SendBackReview
{
    public const NOTE_REQUIRED = 'Write a note for the manager.';

    public const NOTE_TOO_LONG = 'The note can be at most 500 characters.';

    public function handle(User $by, Review $review, string $note): Review
    {
        return ReviewLock::run($review, fn (ReviewCycle $cycle, Review $locked) => $this->within($by, $cycle, $locked, $note), $by);
    }

    public function within(User $by, ReviewCycle $cycle, Review $locked, string $note): Review
    {
        ReviewLock::assertCycleOpen($cycle);

        if (! ReviewAccess::mayManage($by, $locked)) {
            throw new AuthorizationException('Only HR can send a review back.');
        }

        ReviewStates::assertCan(ReviewStates::SEND_BACK, $locked->status);

        $note = ReviewRatings::cleanText($note, 500, 'note', self::NOTE_TOO_LONG)
            ?? throw ValidationException::withMessages(['note' => self::NOTE_REQUIRED]);

        $locked->forceFill([
            'status' => ReviewStates::target(ReviewStates::SEND_BACK, $locked->status),
            'send_back_note' => $note,
            'manager_submitted_at' => null,
        ])->save();

        ReviewRatings::audit('Review sent back', $locked, $cycle, ['note' => $note, 'own_review' => ReviewAccess::isReviewee($by, $locked)], $by);

        return $locked;
    }
}
