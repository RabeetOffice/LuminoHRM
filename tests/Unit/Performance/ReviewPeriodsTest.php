<?php

use App\Support\Performance\ReviewPeriods;

it('builds quarter keys, labels with an en dash, and end dates', function (string $key, string $label, string $start, string $end) {
    expect(ReviewPeriods::parse('quarterly', $key))->toBe(['key' => $key, 'label' => $label, 'start' => $start, 'end' => $end]);
})->with([
    ['2026-Q1', 'Q1 2026 (Jan–Mar)', '2026-01-01', '2026-03-31'],
    ['2026-Q2', 'Q2 2026 (Apr–Jun)', '2026-04-01', '2026-06-30'],
    ['2026-Q3', 'Q3 2026 (Jul–Sep)', '2026-07-01', '2026-09-30'],
    ['2026-Q4', 'Q4 2026 (Oct–Dec)', '2026-10-01', '2026-12-31'],
]);

it('builds month keys and labels with 28, 29, 30 and 31 day ends', function (string $key, string $label, string $end) {
    expect(ReviewPeriods::parse('monthly', $key))->toBe(['key' => $key, 'label' => $label, 'start' => substr($key, 0, 7).'-01', 'end' => $end]);
})->with([
    ['2026-02', 'February 2026', '2026-02-28'],
    ['2028-02', 'February 2028', '2028-02-29'],
    ['2026-11', 'November 2026', '2026-11-30'],
    ['2026-12', 'December 2026', '2026-12-31'],
]);

it('rejects keys that are not of the type or not well formed', function (string $type, string $key) {
    expect(ReviewPeriods::parse($type, $key))->toBeNull()
        ->and(ReviewPeriods::find($type, $key, '2026-11-26'))->toBeNull();
})->with([
    ['quarterly', '2026-11'],
    ['monthly', '2026-Q3'],
    ['quarterly', '2026-Q5'],
    ['quarterly', '2026-Q0'],
    ['monthly', '2026-13'],
    ['monthly', '2026-1'],
    ['monthly', ' 2026-11'],
    ['weekly', '2026-11'],
    ['quarterly', ''],
]);

it('finds the current period, one that has ended, and refuses a future one', function () {
    expect(ReviewPeriods::find('quarterly', '2026-Q4', '2026-11-26')['end'])->toBe('2026-12-31')
        ->and(ReviewPeriods::find('quarterly', '2026-Q3', '2026-11-26')['start'])->toBe('2026-07-01')
        ->and(ReviewPeriods::find('quarterly', '2027-Q1', '2026-11-26'))->toBeNull()
        ->and(ReviewPeriods::isFuture('quarterly', '2027-Q1', '2026-11-26'))->toBeTrue()
        ->and(ReviewPeriods::find('monthly', '2026-11', '2026-11-01')['key'])->toBe('2026-11')
        ->and(ReviewPeriods::find('monthly', '2026-12', '2026-11-30'))->toBeNull()
        ->and(ReviewPeriods::isFuture('monthly', '2026-12', '2026-11-30'))->toBeTrue()
        ->and(ReviewPeriods::isFuture('monthly', '2026-11', '2026-11-30'))->toBeFalse();
});

it('refuses a key older than the offered window', function () {
    // Nine quarters back from Q4 2026 is Q4 2024; Q3 2024 is the tenth.
    expect(ReviewPeriods::find('quarterly', '2024-Q4', '2026-11-26'))->not->toBeNull()
        ->and(ReviewPeriods::find('quarterly', '2024-Q3', '2026-11-26'))->toBeNull()
        ->and(ReviewPeriods::find('monthly', '2026-03', '2026-11-26'))->not->toBeNull()
        ->and(ReviewPeriods::find('monthly', '2026-02', '2026-11-26'))->toBeNull()
        ->and(ReviewPeriods::isFuture('quarterly', '2024-Q3', '2026-11-26'))->toBeFalse();
});

it('offers the current and the eight earlier periods, newest first, across a year end', function () {
    $quarters = array_column(ReviewPeriods::offered('quarterly', '2026-02-10', []), 'key');
    $months = array_column(ReviewPeriods::offered('monthly', '2026-02-10', []), 'key');

    expect($quarters)->toBe(['2026-Q1', '2025-Q4', '2025-Q3', '2025-Q2', '2025-Q1', '2024-Q4', '2024-Q3', '2024-Q2', '2024-Q1'])
        ->and($months)->toBe(['2026-02', '2026-01', '2025-12', '2025-11', '2025-10', '2025-09', '2025-08', '2025-07', '2025-06']);
});

it('drops offered periods a cycle overlaps, both ways', function () {
    // A monthly cycle inside Q3 hides Q3.
    $quarters = array_column(ReviewPeriods::offered('quarterly', '2026-11-26', [['start' => '2026-08-01', 'end' => '2026-08-31']]), 'key');
    // A quarterly cycle hides its three months.
    $months = array_column(ReviewPeriods::offered('monthly', '2026-11-26', [['start' => '2026-07-01', 'end' => '2026-09-30']]), 'key');

    expect($quarters)->not->toContain('2026-Q3')->toContain('2026-Q4')->toContain('2026-Q2')
        ->and($months)->not->toContain('2026-07')->not->toContain('2026-08')->not->toContain('2026-09')
        ->toContain('2026-10')->toContain('2026-06');
});

it('defaults the due date to the period end plus 14 days', function () {
    expect(ReviewPeriods::dueDefault('2026-09-30'))->toBe('2026-10-14')
        ->and(ReviewPeriods::dueDefault('2026-12-31'))->toBe('2027-01-14')
        ->and(ReviewPeriods::dueDefault('2028-02-29'))->toBe('2028-03-14');
});

it('tells overlapping ranges apart from touching ones', function () {
    expect(ReviewPeriods::overlaps('2026-07-01', '2026-09-30', '2026-09-30', '2026-10-31'))->toBeTrue()
        ->and(ReviewPeriods::overlaps('2026-07-01', '2026-09-30', '2026-10-01', '2026-10-31'))->toBeFalse();
});
