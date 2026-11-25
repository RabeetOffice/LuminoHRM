<?php

use App\Support\Payroll\LoanSchedule;

it('projects installments from the start month, the last one taking the remainder', function () {
    // Rs 12,000 at Rs 5,000 a month from November 2026
    expect(LoanSchedule::project(1200000, 500000, '2026-11', []))->toBe([
        ['month' => '2026-11', 'amount' => 500000],
        ['month' => '2026-12', 'amount' => 500000],
        ['month' => '2027-01', 'amount' => 200000],
    ]);
});

it('pushes the schedule one month later for a skipped month', function () {
    expect(LoanSchedule::project(1200000, 500000, '2026-11', ['2026-12']))->toBe([
        ['month' => '2026-11', 'amount' => 500000],
        ['month' => '2027-01', 'amount' => 500000],
        ['month' => '2027-02', 'amount' => 200000],
    ]);
});

it('skips several months, including the first', function () {
    expect(LoanSchedule::project(1000000, 500000, '2026-11', ['2026-11', '2027-01']))->toBe([
        ['month' => '2026-12', 'amount' => 500000],
        ['month' => '2027-02', 'amount' => 500000],
    ]);
});

it('ignores skips before the start month or after the last installment', function () {
    expect(LoanSchedule::project(1000000, 500000, '2026-11', ['2026-10', '2027-06']))->toBe([
        ['month' => '2026-11', 'amount' => 500000],
        ['month' => '2026-12', 'amount' => 500000],
    ]);
});

it('takes an advance in one month when the installment is the whole amount', function () {
    expect(LoanSchedule::project(2500000, 2500000, '2026-12', []))->toBe([
        ['month' => '2026-12', 'amount' => 2500000],
    ]);
});

it('never recovers more than the remainder when the installment is larger', function () {
    expect(LoanSchedule::project(300000, 500000, '2027-03', []))->toBe([
        ['month' => '2027-03', 'amount' => 300000],
    ]);
});

it('crosses the year boundary', function () {
    $months = array_column(LoanSchedule::project(400000, 100000, '2026-11', []), 'month');

    expect($months)->toBe(['2026-11', '2026-12', '2027-01', '2027-02']);
});

it('sums exactly to the remainder', function () {
    $schedule = LoanSchedule::project(5000001, 333333, '2026-07', ['2026-09']);

    expect(array_sum(array_column($schedule, 'amount')))->toBe(5000001)
        ->and(end($schedule)['amount'])->toBe(5000001 - 15 * 333333);
});

it('gives nothing when nothing remains', function (int $remaining) {
    expect(LoanSchedule::project($remaining, 500000, '2026-11', []))->toBe([])
        ->and(LoanSchedule::nextMonth($remaining, 500000, '2026-11', []))->toBeNull();
})->with([0, -100]);

it('stops after the month limit, counting skipped months', function () {
    $schedule = LoanSchedule::project(10000000, 100000, '2026-01', ['2026-02'], 3);

    expect($schedule)->toBe([
        ['month' => '2026-01', 'amount' => 100000],
        ['month' => '2026-03', 'amount' => 100000],
    ]);
});

it('names the next deduction month', function () {
    expect(LoanSchedule::nextMonth(1200000, 500000, '2026-11', ['2026-11', '2026-12']))->toBe('2027-01')
        ->and(LoanSchedule::addMonth('2026-12'))->toBe('2027-01')
        ->and(LoanSchedule::addMonth('2026-07'))->toBe('2026-08');
});

it('refuses a non-positive installment and a malformed month', function (int $installment, string $month) {
    LoanSchedule::project(100000, $installment, $month, []);
})->throws(InvalidArgumentException::class)->with([
    'zero installment' => [0, '2026-11'],
    'negative installment' => [-1, '2026-11'],
    'month 13' => [100, '2026-13'],
    'date, not month' => [100, '2026-11-01'],
]);
