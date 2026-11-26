<?php

namespace App\Support\Performance;

use App\Models\Review;
use App\Models\ReviewScore;

/**
 * Scores in integer hundredths (412 = 4.12). The only place a score or its label is computed; no floats, no rounding function.
 *
 *   ReviewScoring::weighted([['rating' => 4, 'weight' => 20], ['rating' => 5, 'weight' => 30], ['rating' => 3, 'weight' => 50]]); // 380
 *   ReviewScoring::label(380);  // "Exceeds"
 *   ReviewScoring::format(380); // "3.80"
 *
 * weighted(): sum(rating * weight) / sum(weight), rounded half up to hundredths, done as
 * intdiv(2 * 100 * Σ(rating × weight) + Σweight, 2 * Σweight). Rows without a rating are left out of both sums (a preview);
 * the actions refuse to submit or close while any row is unrated. Null when no row has a rating and a weight.
 */
final class ReviewScoring
{
    /** @param  iterable<array{rating: int|null, weight: int}>  $rows */
    public static function weighted(iterable $rows): ?int
    {
        $sum = 0;
        $weights = 0;

        foreach ($rows as $row) {
            $rating = $row['rating'];
            $weight = (int) $row['weight'];

            if ($rating === null || $weight <= 0) {
                continue;
            }

            $sum += (int) $rating * $weight;
            $weights += $weight;
        }

        if ($weights === 0) {
            return null;
        }

        return intdiv(2 * 100 * $sum + $weights, 2 * $weights);
    }

    public static function selfScore(Review $review): ?int
    {
        return self::weighted($review->scores->map(fn (ReviewScore $s) => ['rating' => $s->self_rating, 'weight' => $s->weight]));
    }

    public static function managerScore(Review $review): ?int
    {
        return self::weighted($review->scores->map(fn (ReviewScore $s) => ['rating' => $s->manager_rating, 'weight' => $s->weight]));
    }

    /** The label of a score, from config('hrm.performance.labels') (lowest score of each label, highest first). */
    public static function label(int $hundredths): string
    {
        foreach (config('hrm.performance.labels') as $band) {
            if ($hundredths >= (int) $band['min']) {
                return $band['label'];
            }
        }

        return (string) last(config('hrm.performance.labels'))['label'];
    }

    public static function format(int $hundredths): string
    {
        return sprintf('%d.%02d', intdiv($hundredths, 100), $hundredths % 100);
    }
}
