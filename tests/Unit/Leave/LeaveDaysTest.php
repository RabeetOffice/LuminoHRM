<?php

use App\Support\Leave\LeaveDays;
use App\Support\Leave\LeavePlan;

/*
 * The pure day counter (LeaveDays::count), table-driven. Calendar used throughout:
 *   Mon 2026-11-23 … Fri 27, Sat 28, Sun 29, Mon 30, Tue 2026-12-01
 *   Thu 2026-12-31, Fri 2027-01-01, Sat 2, Sun 3, Mon 4
 */

/**
 * The same weekly offs for every date from $from to $to.
 *
 * @param  list<string>  $offs
 * @return array<string, list<string>>
 */
function ldOffs(string $from, string $to, array $offs = ['saturday', 'sunday']): array
{
    $map = [];

    for ($d = new DateTimeImmutable($from); $d->format('Y-m-d') <= $to; $d = $d->modify('+1 day')) {
        $map[$d->format('Y-m-d')] = $offs;
    }

    return $map;
}

const LD_WEEKEND = ['saturday', 'sunday'];

dataset('counter table', [
    'Mon-Fri week, Sat/Sun off = 5' => [
        '2026-11-23', '2026-11-27', null, LD_WEEKEND, [], '2020-01-01', null,
        5.0, ['2026-11-23', '2026-11-24', '2026-11-25', '2026-11-26', '2026-11-27'], null,
    ],
    'range across a weekend counts working days only' => [
        '2026-11-27', '2026-12-01', null, LD_WEEKEND, [], '2020-01-01', null,
        3.0, ['2026-11-27', '2026-11-30', '2026-12-01'], null,
    ],
    'a holiday inside the range is skipped' => [
        '2026-11-23', '2026-11-27', null, LD_WEEKEND, ['2026-11-25'], '2020-01-01', null,
        4.0, ['2026-11-23', '2026-11-24', '2026-11-26', '2026-11-27'], null,
    ],
    'holidays outside the range are ignored' => [
        '2026-11-23', '2026-11-24', null, LD_WEEKEND, ['2026-11-20', '2026-12-25'], '2020-01-01', null,
        2.0, ['2026-11-23', '2026-11-24'], null,
    ],
    'Friday-only off shift: Sat and Sun count' => [
        '2026-11-23', '2026-11-29', null, ['friday'], [], '2020-01-01', null,
        6.0, ['2026-11-23', '2026-11-24', '2026-11-25', '2026-11-26', '2026-11-28', '2026-11-29'], null,
    ],
    'no weekly offs at all: every date counts' => [
        '2026-11-27', '2026-11-29', null, [], [], '2020-01-01', null,
        3.0, ['2026-11-27', '2026-11-28', '2026-11-29'], null,
    ],
    'single working day' => [
        '2026-11-25', '2026-11-25', null, LD_WEEKEND, [], '2020-01-01', null,
        1.0, ['2026-11-25'], null,
    ],
    'half day am on a working day = 0.5' => [
        '2026-11-25', '2026-11-25', 'am', LD_WEEKEND, [], '2020-01-01', null,
        0.5, ['2026-11-25'], null,
    ],
    'half day pm on a working day = 0.5' => [
        '2026-11-25', '2026-11-25', 'pm', LD_WEEKEND, [], '2020-01-01', null,
        0.5, ['2026-11-25'], null,
    ],
    'half day on an off day is refused' => [
        '2026-11-28', '2026-11-28', 'am', LD_WEEKEND, [], '2020-01-01', null,
        0.0, [], LeaveDays::REFUSAL_NOTHING_TO_COUNT,
    ],
    'half day on a holiday is refused' => [
        '2026-11-25', '2026-11-25', 'pm', LD_WEEKEND, ['2026-11-25'], '2020-01-01', null,
        0.0, [], LeaveDays::REFUSAL_NOTHING_TO_COUNT,
    ],
    'half day over two dates is refused' => [
        '2026-11-25', '2026-11-26', 'am', LD_WEEKEND, [], '2020-01-01', null,
        0.0, [], LeaveDays::REFUSAL_HALF_DAY_RANGE,
    ],
    'a range of only off days is refused' => [
        '2026-11-28', '2026-11-29', null, LD_WEEKEND, [], '2020-01-01', null,
        0.0, [], LeaveDays::REFUSAL_NOTHING_TO_COUNT,
    ],
    'only offs and holidays is refused' => [
        '2026-11-27', '2026-11-29', null, LD_WEEKEND, ['2026-11-27'], '2020-01-01', null,
        0.0, [], LeaveDays::REFUSAL_NOTHING_TO_COUNT,
    ],
    'end before start is refused' => [
        '2026-11-27', '2026-11-23', null, LD_WEEKEND, [], '2020-01-01', null,
        0.0, [], LeaveDays::REFUSAL_END_BEFORE_START,
    ],
    'end before start wins over the window' => [
        '2026-11-27', '2026-11-23', null, LD_WEEKEND, [], '2027-01-01', null,
        0.0, [], LeaveDays::REFUSAL_END_BEFORE_START,
    ],
    'starts before joining is refused' => [
        '2026-11-23', '2026-11-25', null, LD_WEEKEND, [], '2026-11-24', null,
        0.0, [], LeavePlan::OUTSIDE_EMPLOYMENT,
    ],
    'starting on the joining date is inside' => [
        '2026-11-24', '2026-11-25', null, LD_WEEKEND, [], '2026-11-24', null,
        2.0, ['2026-11-24', '2026-11-25'], null,
    ],
    'ends after the last working day is refused' => [
        '2026-11-25', '2026-11-27', null, LD_WEEKEND, [], '2020-01-01', '2026-11-26',
        0.0, [], LeavePlan::OUTSIDE_EMPLOYMENT,
    ],
    'ending on the last working day is inside' => [
        '2026-11-25', '2026-11-26', null, LD_WEEKEND, [], '2020-01-01', '2026-11-26',
        2.0, ['2026-11-25', '2026-11-26'], null,
    ],
    'an off day after the last working day does not refuse' => [
        '2026-11-27', '2026-11-28', null, LD_WEEKEND, [], '2020-01-01', '2026-11-27',
        1.0, ['2026-11-27'], null,
    ],
    'joined Mon 23 Nov, Sat 21 - Fri 27 = 5 (weekend before joining is not counted)' => [
        '2026-11-21', '2026-11-27', null, LD_WEEKEND, [], '2026-11-23', null,
        5.0, ['2026-11-23', '2026-11-24', '2026-11-25', '2026-11-26', '2026-11-27'], null,
    ],
    'a holiday before joining does not refuse' => [
        '2026-11-20', '2026-11-24', null, LD_WEEKEND, ['2026-11-20'], '2026-11-23', null,
        2.0, ['2026-11-23', '2026-11-24'], null,
    ],
    'a counted day after the last working day refuses even with offs between' => [
        '2026-11-27', '2026-11-30', null, LD_WEEKEND, [], '2020-01-01', '2026-11-27',
        0.0, [], LeavePlan::OUTSIDE_EMPLOYMENT,
    ],
    'only offs outside the window: nothing to count' => [
        '2026-11-28', '2026-11-29', null, LD_WEEKEND, [], '2020-01-01', '2026-11-27',
        0.0, [], LeaveDays::REFUSAL_NOTHING_TO_COUNT,
    ],
    'across 31 Dec → 4 Jan: Thu, Fri, Mon' => [
        '2026-12-31', '2027-01-04', null, LD_WEEKEND, [], '2020-01-01', null,
        3.0, ['2026-12-31', '2027-01-01', '2027-01-04'], null,
    ],
    'across the year with 1 Jan a holiday' => [
        '2026-12-31', '2027-01-04', null, LD_WEEKEND, ['2027-01-01'], '2020-01-01', null,
        2.0, ['2026-12-31', '2027-01-04'], null,
    ],
]);

it('counts days by the table', function (string $from, string $to, ?string $half, array $offs, array $holidays, string $windowFrom, ?string $windowTo, float $total, array $dates, ?string $refusal) {
    $plan = LeaveDays::count($from, $to, $half, ldOffs(min($from, $to), max($from, $to), $offs), $holidays, $windowFrom, $windowTo);

    expect($plan->total)->toBe($total)
        ->and($plan->dates())->toBe($dates)
        ->and($plan->refusal)->toBe($refusal)
        ->and($plan->isRefused())->toBe($refusal !== null);
})->with('counter table');

it('marks full days 1 / full and half days 0.5 / am|pm', function () {
    $full = LeaveDays::count('2026-11-26', '2026-11-27', null, ldOffs('2026-11-26', '2026-11-27'), [], '2020-01-01', null);
    $am = LeaveDays::count('2026-11-26', '2026-11-26', 'am', ldOffs('2026-11-26', '2026-11-26'), [], '2020-01-01', null);
    $pm = LeaveDays::count('2026-11-26', '2026-11-26', 'pm', ldOffs('2026-11-26', '2026-11-26'), [], '2020-01-01', null);

    expect($full->days)->toBe([
        ['date' => '2026-11-26', 'amount' => 1.0, 'half' => 'full'],
        ['date' => '2026-11-27', 'amount' => 1.0, 'half' => 'full'],
    ])
        ->and($am->days)->toBe([['date' => '2026-11-26', 'amount' => 0.5, 'half' => 'am']])
        ->and($pm->days)->toBe([['date' => '2026-11-26', 'amount' => 0.5, 'half' => 'pm']]);
});

it('splits a request across 31 Dec by year', function () {
    $plan = LeaveDays::count('2026-12-28', '2027-01-08', null, ldOffs('2026-12-28', '2027-01-08'), ['2027-01-01'], '2020-01-01', null);

    // 2026: Mon 28 - Thu 31 = 4; 2027: Mon 4 - Fri 8 = 5 (Fri 1 is a holiday).
    expect($plan->byYear())->toBe([2026 => 4.0, 2027 => 5.0])
        ->and($plan->total)->toBe(9.0);
});

it('byYear of a half day and of a refusal', function () {
    $half = LeaveDays::count('2027-01-04', '2027-01-04', 'pm', ldOffs('2027-01-04', '2027-01-04'), [], '2020-01-01', null);
    $refused = LeaveDays::count('2026-11-28', '2026-11-29', null, ldOffs('2026-11-28', '2026-11-29'), [], '2020-01-01', null);

    expect($half->byYear())->toBe([2027 => 0.5])
        ->and($refused->byYear())->toBe([])
        ->and($refused->days)->toBe([]);
});

it('uses the weekly offs given for each date (a shift that changed mid-range)', function () {
    // Week 1 worked a Friday-off shift, week 2 a Sat/Sun-off shift.
    $offs = ldOffs('2026-11-23', '2026-11-29', ['friday']) + ldOffs('2026-11-30', '2026-12-06', LD_WEEKEND);

    $plan = LeaveDays::count('2026-11-23', '2026-12-06', null, $offs, [], '2020-01-01', null);

    expect($plan->total)->toBe(11.0) // 6 (Mon-Thu, Sat, Sun) + 5 (Mon-Fri)
        ->and($plan->dates())->not->toContain('2026-11-27')
        ->and($plan->dates())->toContain('2026-11-28')
        ->and($plan->dates())->not->toContain('2026-12-05');
});

it('treats a date without an entry as having no weekly off', function () {
    $plan = LeaveDays::count('2026-11-28', '2026-11-29', null, [], [], '2020-01-01', null);

    expect($plan->total)->toBe(2.0);
});

it('sums many halves exactly (no float drift)', function () {
    // 0.1 + 0.2 style drift cannot happen: amounts are added as whole half days.
    $days = [];
    $d = new DateTimeImmutable('2020-01-01');

    for ($i = 0; $i < 1001; $i++) {
        $days[] = ['date' => $d->format('Y-m-d'), 'amount' => 0.5, 'half' => 'am'];
        $d = $d->modify('+1 day');
    }

    $plan = new LeavePlan($days);

    expect($plan->total)->toBe(500.5)
        ->and(array_sum($plan->byYear()))->toBe(500.5)
        ->and($plan->byYear())->toBe([2020 => 183.0, 2021 => 182.5, 2022 => 135.0]);
});

it('converts amounts to and from whole half days', function (float|int|string $in, int $halves) {
    expect(LeaveDays::halves($in))->toBe($halves)
        ->and(LeaveDays::fromHalves($halves))->toBe((float) ($halves / 2));
})->with([
    [0.5, 1], [1, 2], ['1.5', 3], ['14.0', 28], [-2.5, -5], [1.4999999, 3], [0, 0],
]);

it('rejects malformed dates and unknown halves', function (string $from, string $to, ?string $half) {
    LeaveDays::count($from, $to, $half, [], [], '2020-01-01', null);
})->with([
    'bad from' => ['2026-13-01', '2026-12-01', null],
    'bad to' => ['2026-11-01', '2026-11-31', null],
    'not Y-m-d' => ['2026/11/01', '2026-11-02', null],
    'unknown half' => ['2026-11-25', '2026-11-25', 'full'],
])->throws(InvalidArgumentException::class);
