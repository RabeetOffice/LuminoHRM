<?php

use App\Support\Payroll\TaxYear;

it('puts a month in the tax year that started the previous July', function (string $month, int $year) {
    expect(TaxYear::of($month))->toBe($year);
})->with([
    '2026-11' => ['2026-11', 2026],
    '2027-03' => ['2027-03', 2026],
    '2026-06' => ['2026-06', 2025],
    '2026-07' => ['2026-07', 2026],
    '2027-06' => ['2027-06', 2026],
    '2027-01' => ['2027-01', 2026],
    '2026-12' => ['2026-12', 2026],
]);

it('follows another start month', function () {
    expect(TaxYear::of('2026-03', 4))->toBe(2025)
        ->and(TaxYear::of('2026-04', 4))->toBe(2026)
        ->and(TaxYear::of('2026-12', 1))->toBe(2026)
        ->and(TaxYear::of('2026-01', 1))->toBe(2026);
});

it('lists the twelve months of a tax year', function () {
    expect(TaxYear::months(2026))->toBe([
        '2026-07', '2026-08', '2026-09', '2026-10', '2026-11', '2026-12',
        '2027-01', '2027-02', '2027-03', '2027-04', '2027-05', '2027-06',
    ])->and(TaxYear::months(2026, 1))->toBe([
        '2026-01', '2026-02', '2026-03', '2026-04', '2026-05', '2026-06',
        '2026-07', '2026-08', '2026-09', '2026-10', '2026-11', '2026-12',
    ]);
});

it('labels and parses a tax year', function () {
    expect(TaxYear::label(2026))->toBe('2026-27')
        ->and(TaxYear::label(2099))->toBe('2099-00')
        ->and(TaxYear::label(2026, 1))->toBe('2026')
        ->and(TaxYear::parse('2026-27'))->toBe(2026)
        ->and(TaxYear::parse(' 2025-26 '))->toBe(2025)
        ->and(TaxYear::parse('2026-28'))->toBeNull()
        ->and(TaxYear::parse('2026'))->toBeNull()
        ->and(TaxYear::parse('26-27'))->toBeNull()
        ->and(TaxYear::parse('=1+1'))->toBeNull()
        ->and(TaxYear::parse('2026', 1))->toBe(2026);
});

it('refuses a malformed month or start month', function (string $month, int $start) {
    TaxYear::of($month, $start);
})->throws(InvalidArgumentException::class)->with([
    'month 13' => ['2026-13', 7],
    'date' => ['2026-11-01', 7],
    'start 0' => ['2026-11', 0],
    'start 13' => ['2026-11', 13],
]);
