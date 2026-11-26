<?php

use App\Models\Review;
use App\Models\ReviewScore;
use App\Support\Performance\ReviewScoring;

/** @param  list<array{0: int|null, 1: int}>  $pairs  [rating, weight] */
function rsRows(array $pairs): array
{
    return array_map(fn (array $p) => ['rating' => $p[0], 'weight' => $p[1]], $pairs);
}

it('computes the weighted average in hundredths, half up', function (array $pairs, ?int $expected) {
    expect(ReviewScoring::weighted(rsRows($pairs)))->toBe($expected);
})->with([
    'equal weights' => [[[4, 20], [5, 20], [3, 20]], 400],
    'equal weights, uneven' => [[[4, 10], [5, 10]], 450],
    'unequal weights (4, 5, 3 at 20, 30, 50 = 3.80)' => [[[4, 20], [5, 30], [3, 50]], 380],
    'exactly half rounds up (3.145 -> 3.15)' => [[[3, 100], [3, 71], [4, 29]], 315],
    'below half rounds down (3.1428 -> 3.14)' => [[[3, 3], [3, 3], [4, 1]], 314],
    'above half rounds up (3.2857 -> 3.29)' => [[[3, 3], [3, 2], [4, 2]], 329],
    'a third rounds down (3.333 -> 3.33)' => [[[3, 1], [3, 1], [4, 1]], 333],
    'two thirds rounds up (3.666 -> 3.67)' => [[[3, 1], [4, 1], [4, 1]], 367],
    'only one KPI' => [[[2, 40]], 200],
    'a missing rating is left out of both sums' => [[[4, 20], [null, 30], [2, 20]], 300],
    'no rating at all' => [[[null, 20], [null, 30]], null],
    'no rows' => [[], null],
    'weight sum zero' => [[[4, 0], [5, 0]], null],
    'all fives' => [[[5, 1], [5, 100]], 500],
    'all ones' => [[[1, 7], [1, 93]], 100],
]);

it('labels a score at each threshold', function (int $score, string $label) {
    expect(ReviewScoring::label($score))->toBe($label);
})->with([
    [500, 'Outstanding'], [450, 'Outstanding'], [449, 'Exceeds'], [350, 'Exceeds'], [349, 'Meets'], [250, 'Meets'],
    [249, 'Below'], [150, 'Below'], [149, 'Unsatisfactory'], [100, 'Unsatisfactory'],
]);

it('formats hundredths for people', function (int $score, string $text) {
    expect(ReviewScoring::format($score))->toBe($text);
})->with([[412, '4.12'], [380, '3.80'], [500, '5.00'], [100, '1.00'], [305, '3.05'], [0, '0.00']]);

it('scores the self side and the manager side of a review separately', function () {
    $review = new Review;
    $review->setRelation('scores', collect([
        new ReviewScore(['weight' => 20, 'self_rating' => 5, 'manager_rating' => 4]),
        new ReviewScore(['weight' => 30, 'self_rating' => 5, 'manager_rating' => 5]),
        new ReviewScore(['weight' => 50, 'self_rating' => 5, 'manager_rating' => 3]),
    ]));

    expect(ReviewScoring::selfScore($review))->toBe(500)
        ->and(ReviewScoring::managerScore($review))->toBe(380);
});

it('never uses a float', function () {
    $tokens = PhpToken::tokenize(file_get_contents(dirname(__DIR__, 3).'/app/Support/Performance/ReviewScoring.php'));
    $code = collect($tokens)->reject(fn (PhpToken $t) => $t->is([T_COMMENT, T_DOC_COMMENT]))->map(fn (PhpToken $t) => $t->text)->implode('');

    expect($code)->not->toContain('round(')->not->toContain('(float)')->not->toContain('floor(')->not->toMatch('/[^\/*]\/[^\/*]/');
});
