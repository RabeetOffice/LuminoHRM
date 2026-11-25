<?php

use App\Support\Payroll\Money;

// ---------------------------------------------------------------- div: half up to the whole rupee

it('divides and rounds half up to a whole rupee', function (int $numerator, int $denominator, int $expected) {
    expect(Money::div($numerator, $denominator))->toBe($expected);
})->with([
    'Rs 1,500.50 -> 1,501 (exactly half goes up)' => [150050, 1, 150100],
    'Rs 1,500.49 -> 1,500' => [150049, 1, 150000],
    'Rs 42,580.645 -> 42,581 (22 of 31 days of 60,000)' => [6000000 * 22, 31, 4258100],
    'Rs 4,258.06 -> 4,258 (22 of 31 days of 6,000)' => [600000 * 22, 31, 425800],
    'Rs 75.005 -> 75 (half a paisa above half a rupee is not reached)' => [750050, 100, 7500],
    'overtime 600 min at 150% of 90,000 -> 5,625' => [9000000 * 600 * 150, 30 * 8 * 60 * 100, 562500],
    'attendance 9 halves of 90,000 -> 13,500' => [9000000 * 9, 60, 1350000],
    'tax 5% of 71,400 -> 3,570' => [7140000 * 500, 10000, 357000],
    'allowance 15,001 for 15 of 30 days -> 7,501' => [1500100 * 15, 30, 750100],
    'zero' => [0, 7, 0],
    'below half a rupee -> 0' => [49, 1, 0],
    'exactly half a rupee -> 1' => [50, 1, 100],
]);

it('refuses a negative numerator and a denominator that is not positive', function (int $numerator, int $denominator) {
    Money::div($numerator, $denominator);
})->throws(InvalidArgumentException::class)->with([
    'negative numerator' => [-1, 1],
    'zero denominator' => [100, 0],
    'negative denominator' => [100, -3],
    'denominator above the ceiling' => [100, Money::MAX_DENOMINATOR + 1],
]);

it('stays exact at the documented ceiling (amount 10^12 paisa x multiplier 10^6)', function () {
    $numerator = Money::MAX_AMOUNT * Money::MAX_MULTIPLIER; // 10^18, still an int

    expect($numerator)->toBeInt()
        ->and(Money::div($numerator, Money::MAX_MULTIPLIER))->toBe(Money::MAX_AMOUNT)
        ->and(Money::div($numerator, 3))->toBe(333_333_333_333_333_300)
        ->and(Money::div(PHP_INT_MAX, 1))->toBe(9_223_372_036_854_775_800) // ...807 paisa: 7 is below half a rupee
        ->and(Money::div(PHP_INT_MAX, Money::MAX_DENOMINATOR))->toBe(922_300); // 922,337.2 paisa = Rs 9,223.37 -> Rs 9,223 (no overflow inside div)
});

it('refuses a product that overflowed into a float instead of rounding it', function () {
    Money::div(Money::MAX_AMOUNT * Money::MAX_MULTIPLIER * 100, 1);
})->throws(TypeError::class);

// ---------------------------------------------------------------- rupees, format, bp

it('turns entered whole rupees into paisa', function () {
    expect(Money::rupees(60000))->toBe(6000000)->and(Money::rupees(0))->toBe(0);
});

it('formats paisa as rupees', function (int $paisa, string $text) {
    expect(Money::format($paisa))->toBe($text);
})->with([
    [6493000, 'Rs 64,930'],
    [0, 'Rs 0'],
    [100, 'Rs 1'],
    [100000000, 'Rs 1,000,000'],
    [6493050, 'Rs 64,930.50'],
    [6493005, 'Rs 64,930.05'],
    [-50000, '-Rs 500'],
]);

it('reads a percentage in basis points', function (string $percent, int $bp) {
    expect(Money::bp($percent))->toBe($bp);
})->with([
    ['5', 500],
    ['5.25', 525],
    ['5.5', 550],
    ['0', 0],
    ['0.01', 1],
    ['100', 10000],
    ['100.00', 10000],
]);

it('refuses a malformed or out-of-range percentage', function (string $percent) {
    Money::bp($percent);
})->throws(InvalidArgumentException::class)->with(['5.255', '101', '100.01', '-5', '', ' 5', '5.', '.5', 'abc', '1e2', '1000']);
