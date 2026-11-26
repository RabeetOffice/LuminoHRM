<?php

namespace App\Support\Performance;

use App\Models\Review;
use App\Models\ReviewCycle;
use App\Models\ReviewScore;
use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Writing one side's ratings (self or manager) onto a locked review's score rows, and the audit entry every review action
 * writes. Ratings arrive as [score row id => ['rating' => ?int 1-5, 'comment' => ?string up to 1,000]]; a row left out, or a
 * key left out of a row, is left as it is (drafts may be partial).
 */
final class ReviewRatings
{
    public const NOT_THIS_REVIEW = 'Those ratings do not belong to this review.';

    public const RATE_EVERY = 'Rate every KPI from 1 to 5 before submitting.';

    public const BAD_RATING = 'A rating must be a whole number from 1 to 5.';

    public const COMMENT_TOO_LONG = 'A comment can be at most 1,000 characters.';

    public const OVERALL_TOO_LONG = 'The overall comment can be at most 2,000 characters.';

    /**
     * @param  'self'|'manager'  $side
     * @param  array<int|string, mixed>  $ratings
     */
    public static function apply(Review $locked, array $ratings, string $side): void
    {
        $scores = ReviewLock::scores($locked)->keyBy('id');
        $ratingColumn = $side.'_rating';
        $commentColumn = $side.'_comment';

        foreach (array_keys($ratings) as $id) {
            if (! is_numeric($id) || ! $scores->has((int) $id) || ! is_array($ratings[$id])) {
                throw ValidationException::withMessages(['ratings' => self::NOT_THIS_REVIEW]);
            }
        }

        foreach ($ratings as $id => $row) {
            /** @var ReviewScore $score */
            $score = $scores->get((int) $id);

            if (array_key_exists('rating', $row)) {
                $rating = $row['rating'];

                if ($rating !== null && (filter_var($rating, FILTER_VALIDATE_INT) === false || (int) $rating < 1 || (int) $rating > 5)) {
                    throw ValidationException::withMessages(['ratings' => self::BAD_RATING]);
                }

                $score->{$ratingColumn} = $rating === null ? null : (int) $rating;
            }

            if (array_key_exists('comment', $row)) {
                $score->{$commentColumn} = self::cleanText($row['comment'], 1000, 'ratings', self::COMMENT_TOO_LONG);
            }

            if ($score->isDirty()) {
                $score->save();
            }
        }
    }

    /**
     * Whether every row of $scores has a rating on $side (and there is at least one row).
     *
     * @param  Collection<int, ReviewScore>  $scores
     * @param  'self'|'manager'  $side
     */
    public static function allRated(Collection $scores, string $side): bool
    {
        $column = $side.'_rating';

        return $scores->isNotEmpty() && $scores->every(fn (ReviewScore $s) => $s->{$column} !== null);
    }

    /**
     * @param  'self'|'manager'  $side
     *
     * @throws ValidationException when a row of the locked review's scores is unrated on $side.
     */
    public static function assertAllRated(Review $locked, string $side): void
    {
        $scores = $locked->relationLoaded('scores') ? $locked->scores : ReviewLock::scores($locked);

        if (! self::allRated($scores, $side)) {
            throw ValidationException::withMessages(['ratings' => self::RATE_EVERY]);
        }
    }

    /** Trimmed text, null when empty; refused (ValidationException on $key) when longer than $max characters. */
    public static function cleanText(mixed $text, int $max, string $key, string $tooLong): ?string
    {
        if ($text === null) {
            return null;
        }

        $text = trim((string) $text);

        if ($text === '') {
            return null;
        }

        if (mb_strlen($text) > $max) {
            throw ValidationException::withMessages([$key => $tooLong]);
        }

        return $text;
    }

    /**
     * Audit an action on a review (area performance, subject the review): the employee and the cycle, then $extra. Never
     * rating or comment text.
     *
     * @param  array<string, mixed>  $extra
     */
    public static function audit(string $description, Review $review, ReviewCycle $cycle, array $extra, User $by): void
    {
        $employee = $review->employee()->first(['id', 'first_name', 'last_name']);

        Audit::record('performance', $description, $review, array_merge([
            'employee_id' => $review->employee_id,
            'employee' => $employee?->full_name,
            'cycle_id' => $cycle->id,
            'cycle' => $cycle->name,
        ], $extra), $by);
    }
}
