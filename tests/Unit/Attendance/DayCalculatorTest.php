<?php

use App\Support\Attendance\DayCalculator;
use App\Support\Attendance\DayInput;
use App\Support\Attendance\DayResult;

/*
| The day rules as a table. Shift 09:00-18:00, 15 minutes grace, 4 h half day, Saturday and Sunday off, overtime
| recorded from 30 minutes. 2026-11-24 is a Tuesday, 2026-11-28 a Saturday.
*/

const DC_TUESDAY = '2026-11-24';
const DC_SATURDAY = '2026-11-28';

/**
 * @param  list<string>  $times  "H:i[:s]" on $date, or a full "Y-m-d H:i:s"
 * @param  array<string, mixed>  $overrides
 */
function dcInput(array $times, array $overrides = []): DayInput
{
    $date = $overrides['date'] ?? DC_TUESDAY;

    $punches = array_map(function (string $t) use ($date): string {
        if (strlen($t) > 8) {
            return $t;
        }

        return $date.' '.(strlen($t) === 5 ? "{$t}:00" : $t);
    }, $times);

    return new DayInput(...array_merge([
        'date' => $date,
        'shiftStart' => '09:00',
        'shiftEnd' => '18:00',
        'graceMinutes' => 15,
        'halfDayHours' => 4.0,
        'weeklyOffs' => ['saturday', 'sunday'],
        'isHoliday' => false,
        'isExempt' => false,
        'punches' => $punches,
        'closed' => true,
        'overtimeMinMinutes' => 30,
        'onLeave' => false,
        'duplicatePunchMinutes' => 5,
        'dayCutoff' => '04:00',
    ], $overrides));
}

/** status, in "H:i:s"|null, out "H:i:s"|null, worked, late, early, overtime, needsAttention */
function dcRow(DayResult $r): array
{
    return [
        $r->status,
        $r->clockIn === null ? null : substr($r->clockIn, 11),
        $r->clockOut === null ? null : substr($r->clockOut, 11),
        $r->workedMinutes,
        $r->lateMinutes,
        $r->earlyMinutes,
        $r->overtimeMinutes,
        $r->needsAttention,
    ];
}

it('decides a day', function (array $punches, array $overrides, array $expected) {
    $result = (new DayCalculator)->calculate(dcInput($punches, $overrides));

    expect($result)->not->toBeNull()
        ->and(dcRow($result))->toBe($expected);
})->with([
    // Working day, two or more punches
    'on time, full day' => [['08:55', '18:00'], [], ['present', '08:55:00', '18:00:00', 545, 0, 0, 0, false]],
    'clock-in exactly at start + grace is on time' => [['09:15', '18:00'], [], ['present', '09:15:00', '18:00:00', 525, 0, 0, 0, false]],
    'seconds inside the last grace minute are on time' => [['09:15:59', '18:00'], [], ['present', '09:15:59', '18:00:00', 524, 0, 0, 0, false]],
    'one minute past grace is late, counted from shift start' => [['09:16', '18:00'], [], ['late', '09:16:00', '18:00:00', 524, 16, 0, 0, false]],
    'late and leaves early' => [['09:30', '17:00'], [], ['late', '09:30:00', '17:00:00', 450, 30, 60, 0, false]],
    'early departure in whole minutes' => [['09:00', '17:30:30'], [], ['present', '09:00:00', '17:30:30', 510, 0, 29, 0, false]],
    'clock-in before the start is not late' => [['07:00', '18:00'], [], ['present', '07:00:00', '18:00:00', 660, 0, 0, 0, false]],
    'half day: worked below the threshold' => [['09:00', '12:59'], [], ['half_day', '09:00:00', '12:59:00', 239, 0, 301, 0, false]],
    'exactly the half-day threshold is not a half day' => [['09:00', '13:00'], [], ['present', '09:00:00', '13:00:00', 240, 0, 300, 0, false]],
    'half day beats late and stores no late minutes' => [['11:00', '14:00'], [], ['half_day', '11:00:00', '14:00:00', 180, 0, 240, 0, false]],
    'two punches less than an hour apart: the single-punch rule on the first, flagged' => [['09:00', '09:30'], [], ['present', '09:00:00', null, 0, 0, 0, 0, true]],
    'two punches 40 minutes apart (no real clock-out): present and flagged, not half day' => [['09:00', '09:40'], [], ['present', '09:00:00', null, 0, 0, 0, 0, true]],
    'two punches less than an hour apart, late first: late and flagged' => [['09:45', '10:30'], [], ['late', '09:45:00', null, 0, 45, 0, 0, true]],
    'two punches 59:59 apart: still the single-punch rule' => [['09:00', '09:59:59'], [], ['present', '09:00:00', null, 0, 0, 0, 0, true]],
    'entered times (close-pair rule off): 09:00-09:40 is 40 minutes worked, a half day' => [['09:00', '09:40'], ['closePairRule' => false], ['half_day', '09:00:00', '09:40:00', 40, 0, 500, 0, false]],
    'entered times on the open day: present, 40 minutes, no half day yet' => [['09:00', '09:40'], ['closePairRule' => false, 'closed' => false], ['present', '09:00:00', '09:40:00', 40, 0, 0, 0, false]],
    'two punches exactly an hour apart: not flagged' => [['09:00', '10:00'], [], ['half_day', '09:00:00', '10:00:00', 60, 0, 480, 0, false]],
    'three punches close together: only exactly two are flagged' => [['09:00', '09:10', '09:20'], [], ['half_day', '09:00:00', '09:20:00', 20, 0, 520, 0, false]],
    'punches between first and last are ignored' => [['09:00', '13:00', '14:00', '18:00'], [], ['present', '09:00:00', '18:00:00', 540, 0, 0, 0, false]],
    'punches out of order are sorted' => [['18:00', '13:00', '09:05'], [], ['present', '09:05:00', '18:00:00', 535, 0, 0, 0, false]],
    'duplicate punches count once' => [['09:00', '09:00', '18:00', '18:00'], [], ['present', '09:00:00', '18:00:00', 540, 0, 0, 0, false]],
    'clock-in after the shift end: overtime counted from the clock-in' => [['19:00', '21:00'], [], ['half_day', '19:00:00', '21:00:00', 120, 0, 0, 120, false]],
    'clock-in after the shift end, worked below the overtime minimum' => [['23:58', '2026-11-25 00:05:00', '2026-11-25 00:15:00'], [], ['half_day', '23:58:00', '00:15:00', 17, 0, 0, 0, false]],
    'clock-in 23:58, punch 00:03: one late-night punch, no overtime' => [['23:58', '2026-11-25 00:03:00'], [], ['present', null, '23:58:00', 0, 0, 0, 0, true]],
    'overtime after shift end' => [['09:00', '18:45'], [], ['present', '09:00:00', '18:45:00', 585, 0, 0, 45, false]],
    'overtime below the minimum is not recorded' => [['09:00', '18:29:59'], [], ['present', '09:00:00', '18:29:59', 569, 0, 0, 0, false]],
    'overtime exactly at the minimum is recorded' => [['09:00', '18:30'], [], ['present', '09:00:00', '18:30:00', 570, 0, 0, 30, false]],
    'late with overtime' => [['10:00', '19:00'], [], ['late', '10:00:00', '19:00:00', 540, 60, 0, 60, false]],
    'zero grace: seconds in the first minute are on time' => [['09:00:30', '18:00'], ['graceMinutes' => 0], ['present', '09:00:30', '18:00:00', 539, 0, 0, 0, false]],
    'zero grace: one minute is late' => [['09:01', '18:00'], ['graceMinutes' => 0], ['late', '09:01:00', '18:00:00', 539, 1, 0, 0, false]],
    'fractional half-day hours' => [['09:00', '13:29'], ['halfDayHours' => 4.5], ['half_day', '09:00:00', '13:29:00', 269, 0, 271, 0, false]],
    // Open day (not closed yet): never half day, never early minutes; present or late from the first punch only
    'open day, two punches: late by the first punch, no half day, no early' => [['09:20', '12:00'], ['closed' => false], ['late', '09:20:00', '12:00:00', 160, 20, 0, 0, false]],
    'open day, two punches under an hour: single-punch rule, not flagged yet' => [['09:00', '09:30'], ['closed' => false], ['present', '09:00:00', null, 0, 0, 0, 0, false]],
    'open day, overtime is still recorded' => [['09:00', '18:45'], ['closed' => false], ['present', '09:00:00', '18:45:00', 585, 0, 0, 45, false]],

    // Working day, one punch (after double-tap reduction)
    'one punch, open day: present, not flagged yet' => [['09:00'], ['closed' => false], ['present', '09:00:00', null, 0, 0, 0, 0, false]],
    'one punch, closed day: present and flagged' => [['09:00'], [], ['present', '09:00:00', null, 0, 0, 0, 0, true]],
    'one late punch, closed day: late and flagged' => [['09:45'], [], ['late', '09:45:00', null, 0, 45, 0, 0, true]],
    'one late punch, open day: late, not flagged' => [['09:45'], ['closed' => false], ['late', '09:45:00', null, 0, 45, 0, 0, false]],
    'the same punch twice is still one punch' => [['09:00', '09:00'], [], ['present', '09:00:00', null, 0, 0, 0, 0, true]],
    'one punch just before the shift midpoint is a late clock-in' => [['13:29:59'], [], ['late', '13:29:59', null, 0, 269, 0, 0, true]],
    'one punch at the shift midpoint is the clock-out, never late' => [['13:30'], [], ['present', null, '13:30:00', 0, 0, 0, 0, true]],
    'one punch after the midpoint is the clock-out' => [['18:05'], [], ['present', null, '18:05:00', 0, 0, 0, 0, true]],
    'one clock-out punch on an open day: present, not flagged yet' => [['18:05'], ['closed' => false], ['present', null, '18:05:00', 0, 0, 0, 0, false]],

    // Double tap: a punch within the duplicate window of the previously kept punch is dropped
    'double tap at clock-in is dropped' => [['09:00', '09:04:59', '18:00'], [], ['present', '09:00:00', '18:00:00', 540, 0, 0, 0, false]],
    'double tap at clock-out keeps the last tap as the clock-out' => [['09:00', '18:00', '18:02'], [], ['present', '09:00:00', '18:02:00', 542, 0, 0, 0, false]],
    'a clock-out cluster ending after the shift end: no early minutes' => [['09:00', '17:58', '18:01'], [], ['present', '09:00:00', '18:01:00', 541, 0, 0, 0, false]],
    'a chain of taps collapses to its first punch' => [['09:00', '09:03', '09:06'], [], ['present', '09:00:00', null, 0, 0, 0, 0, true]],
    'a chain of taps at clock-in, then a real clock-out' => [['09:00', '09:03', '09:06', '18:00'], [], ['present', '09:00:00', '18:00:00', 540, 0, 0, 0, false]],
    'a double tap alone is one punch' => [['09:00', '09:01'], [], ['present', '09:00:00', null, 0, 0, 0, 0, true]],
    'a punch exactly the window later is kept' => [['09:00', '09:05', '13:30'], [], ['present', '09:00:00', '13:30:00', 270, 0, 270, 0, false]],
    'double taps are measured from the previous raw punch' => [['09:00', '09:04', '09:08', '18:00'], [], ['present', '09:00:00', '18:00:00', 540, 0, 0, 0, false]],
    'window 0 keeps every distinct punch' => [['09:00', '09:01', '09:02'], ['duplicatePunchMinutes' => 0], ['half_day', '09:00:00', '09:02:00', 2, 0, 538, 0, false]],
    'double tap on a weekend: one punch, not flagged' => [['10:00', '10:02'], ['date' => DC_SATURDAY], ['weekend', '10:00:00', null, 0, 0, 0, 0, false]],

    // Working day, no punches
    'no punches on a closed working day: absent' => [[], [], ['absent', null, null, 0, 0, 0, 0, false]],

    // Weekly off and holiday
    'weekend, no punches' => [[], ['date' => DC_SATURDAY], ['weekend', null, null, 0, 0, 0, 0, false]],
    'weekend, no punches, day still open' => [[], ['date' => DC_SATURDAY, 'closed' => false], ['weekend', null, null, 0, 0, 0, 0, false]],
    'weekend worked: all worked minutes are overtime' => [['10:00', '14:00'], ['date' => DC_SATURDAY], ['weekend', '10:00:00', '14:00:00', 240, 0, 0, 240, false]],
    'weekend worked below the overtime minimum' => [['10:00', '10:20'], ['date' => DC_SATURDAY], ['weekend', '10:00:00', '10:20:00', 20, 0, 0, 0, false]],
    'weekend, one punch, closed: not flagged, no overtime' => [['10:00'], ['date' => DC_SATURDAY], ['weekend', '10:00:00', null, 0, 0, 0, 0, false]],
    'holiday, one punch, closed: not flagged' => [['10:00'], ['isHoliday' => true], ['holiday', '10:00:00', null, 0, 0, 0, 0, false]],
    'weekend, one punch, open: not flagged' => [['10:00'], ['date' => DC_SATURDAY, 'closed' => false], ['weekend', '10:00:00', null, 0, 0, 0, 0, false]],
    'weekly off days come from the shift' => [[], ['weeklyOffs' => ['tuesday']], ['weekend', null, null, 0, 0, 0, 0, false]],
    'no weekly offs: Saturday is a working day' => [[], ['date' => DC_SATURDAY, 'weeklyOffs' => []], ['absent', null, null, 0, 0, 0, 0, false]],
    'holiday on a working day' => [[], ['isHoliday' => true], ['holiday', null, null, 0, 0, 0, 0, false]],
    'holiday on an open day' => [[], ['isHoliday' => true, 'closed' => false], ['holiday', null, null, 0, 0, 0, 0, false]],
    'holiday beats weekend' => [[], ['date' => DC_SATURDAY, 'isHoliday' => true], ['holiday', null, null, 0, 0, 0, 0, false]],
    'holiday worked: all worked minutes are overtime, no late or early' => [['11:00', '15:00'], ['isHoliday' => true], ['holiday', '11:00:00', '15:00:00', 240, 0, 0, 240, false]],

    // Attendance-exempt
    'exempt, no punches, closed working day: present' => [[], ['isExempt' => true], ['present', null, null, 0, 0, 0, 0, false]],
    'exempt, late and early: present with no markers' => [['11:00', '15:00'], ['isExempt' => true], ['present', '11:00:00', '15:00:00', 240, 0, 0, 0, false]],
    'exempt, stays late: no overtime' => [['09:00', '20:00'], ['isExempt' => true], ['present', '09:00:00', '20:00:00', 660, 0, 0, 0, false]],
    'exempt, one punch, closed: present, not flagged' => [['09:40'], ['isExempt' => true], ['present', '09:40:00', null, 0, 0, 0, 0, false]],
    'exempt on a weekend: weekend' => [[], ['isExempt' => true, 'date' => DC_SATURDAY], ['weekend', null, null, 0, 0, 0, 0, false]],
    'exempt on a holiday: holiday' => [[], ['isExempt' => true, 'isHoliday' => true], ['holiday', null, null, 0, 0, 0, 0, false]],

    // Leave hook (the Leave phase supplies onLeave; never true in Phase 3)
    'on leave, no punches' => [[], ['onLeave' => true], ['on_leave', null, null, 0, 0, 0, 0, false]],
    'on leave, open day' => [[], ['onLeave' => true, 'closed' => false], ['on_leave', null, null, 0, 0, 0, 0, false]],
    'on leave with punches: times kept, no markers' => [['09:30', '12:00'], ['onLeave' => true], ['on_leave', '09:30:00', '12:00:00', 150, 0, 0, 0, false]],
    'holiday beats leave' => [[], ['onLeave' => true, 'isHoliday' => true], ['holiday', null, null, 0, 0, 0, 0, false]],
    'weekend beats leave' => [[], ['onLeave' => true, 'date' => DC_SATURDAY], ['weekend', null, null, 0, 0, 0, 0, false]],

    // Day boundaries: the day runs from the cutoff (04:00) to the cutoff of the next calendar day
    'a punch at 00:05 the next day is this day\'s clock-out' => [['09:00', '2026-11-25 00:05:00'], [], ['present', '09:00:00', '00:05:00', 905, 0, 0, 365, false]],
    'a punch just before the next cutoff is this day\'s clock-out' => [['09:00', '2026-11-25 03:59:59'], [], ['present', '09:00:00', '03:59:59', 1139, 0, 0, 599, false]],
    // The punches given are trusted: their day was decided at ingestion, the cutoff no longer filters them here
    'a punch after the next cutoff is still used when given for this day' => [['09:00', '2026-11-25 05:00:00'], [], ['present', '09:00:00', '05:00:00', 1200, 0, 0, 660, false]],
    'the cutoff given does not filter: cutoff 06:00 with a 05:00 clock-out' => [['09:00', '2026-11-25 05:00:00'], ['dayCutoff' => '06:00'], ['present', '09:00:00', '05:00:00', 1200, 0, 0, 660, false]],
    'a punch at 23:59:59 is still this day' => [['09:00', '23:59:59'], [], ['present', '09:00:00', '23:59:59', 899, 0, 0, 359, false]],
]);

it('returns null for an open working day with no punches (nothing to record yet)', function (array $overrides) {
    expect((new DayCalculator)->calculate(dcInput([], ['closed' => false, ...$overrides])))->toBeNull();
})->with([
    'ordinary employee' => [[]],
    'exempt employee' => [['isExempt' => true]],
]);

it('keeps the punch strings exactly as given', function () {
    $result = (new DayCalculator)->calculate(dcInput(['08:59:07', '18:03:41']));

    expect($result?->clockIn)->toBe('2026-11-24 08:59:07')
        ->and($result?->clockOut)->toBe('2026-11-24 18:03:41');
});

it('accepts shift times with seconds', function () {
    $result = (new DayCalculator)->calculate(dcInput(['09:20', '18:00'], ['shiftStart' => '09:00:00', 'shiftEnd' => '18:00:00']));

    expect($result?->status)->toBe('late')->and($result?->lateMinutes)->toBe(20);
});

it('rejects input it cannot decide', function (array $overrides) {
    (new DayCalculator)->calculate(dcInput(['09:00'], $overrides));
})->throws(InvalidArgumentException::class)->with([
    'shift ending before it starts (night shift)' => [['shiftStart' => '22:00', 'shiftEnd' => '06:00']],
    'shift ending when it starts' => [['shiftStart' => '09:00', 'shiftEnd' => '09:00']],
    'bad date' => [['date' => '2026-02-30']],
    'bad shift time' => [['shiftStart' => '9am']],
    'bad punch' => [['punches' => ['yesterday at nine']]],
    'negative grace' => [['graceMinutes' => -1]],
    'zero half-day hours' => [['halfDayHours' => 0.0]],
    'negative duplicate window' => [['duplicatePunchMinutes' => -1]],
    'bad day cutoff' => [['dayCutoff' => '4am']],
]);

it('keeps lateness in whole minutes: 09:15:59 is on time with 15 minutes grace, 09:16:00 is late', function () {
    $calc = new DayCalculator;

    expect($calc->calculate(dcInput(['09:15:59', '18:00']))?->lateMinutes)->toBe(0)
        ->and($calc->calculate(dcInput(['09:16:00', '18:00']))?->lateMinutes)->toBe(16);
});

it('does not let a late night hide the next day\'s lateness', function () {
    $calc = new DayCalculator;
    // As the rebuild groups them by punch_date: 00:05 on the 25th belongs to the 24th (before the 04:00 cutoff).
    $late = $calc->calculate(dcInput(['2026-11-24 09:00:00', '2026-11-25 00:05:00']));
    $next = $calc->calculate(dcInput(['2026-11-25 09:40:00', '2026-11-25 18:00:00'], ['date' => '2026-11-25']));

    expect($late?->clockOut)->toBe('2026-11-25 00:05:00')
        ->and($next?->clockIn)->toBe('2026-11-25 09:40:00')
        ->and($next?->status)->toBe('late')
        ->and($next?->lateMinutes)->toBe(40);
});

it('pins the single-punch midpoint deliberately: 13:29:59 is a late clock-in, 13:30:00 a clock-out', function () {
    $calc = new DayCalculator;
    $before = $calc->calculate(dcInput(['13:29:59']));
    $at = $calc->calculate(dcInput(['13:30:00']));

    expect(dcRow($before))->toBe(['late', '13:29:59', null, 0, 269, 0, 0, true])
        ->and(dcRow($at))->toBe(['present', null, '13:30:00', 0, 0, 0, 0, true]);
});

it('never lets overtime exceed the worked time', function (array $punches) {
    $result = (new DayCalculator)->calculate(dcInput($punches, ['overtimeMinMinutes' => 0]));

    expect($result?->overtimeMinutes)->toBeLessThanOrEqual($result?->workedMinutes ?? 0);
})->with([
    'in and out after the end' => [['19:00', '21:00']],
    'late night across midnight' => [['23:58', '2026-11-25 00:30:00', '2026-11-25 01:00:00']],
    'normal day with overtime' => [['09:00', '20:00']],
    'weekend-like hours on a working day' => [['18:30', '22:00']],
]);

it('trusts the punches it is given for the day, whatever the cutoff', function () {
    // Stored under an earlier 06:00 cutoff: 05:30 belongs to the 24th. A later cutoff of 04:00 must not drop it.
    $result = (new DayCalculator)->calculate(dcInput(['09:00', '2026-11-25 05:30:00'], ['dayCutoff' => '04:00']));

    expect($result?->clockOut)->toBe('2026-11-25 05:30:00')
        ->and($result?->workedMinutes)->toBe(1230);
});

it('never reads the clock or the database', function () {
    $source = (string) file_get_contents(app_path('Support/Attendance/DayCalculator.php'));

    expect($source)->not->toMatch('/\b(now|today|Date::|Carbon|DB::|settings\(|AttendanceClock|Model|time\(\))/');
});

it('takes a single entered time in the role it was entered as, whatever the midpoint', function (array $times, array $overrides, array $expected) {
    $result = (new DayCalculator)->calculate(dcInput($times, ['duplicatePunchMinutes' => 0, 'closePairRule' => false, ...$overrides]));

    expect(dcRow($result))->toBe($expected);
})->with([
    'clock-in 14:00 only is a late clock-in (closed)' => [['14:00'], ['singleRole' => 'in'], ['late', '14:00:00', null, 0, 300, 0, 0, true]],
    'clock-in 14:00 only, open day: late, not flagged yet' => [['14:00'], ['singleRole' => 'in', 'closed' => false], ['late', '14:00:00', null, 0, 300, 0, 0, false]],
    'clock-in 09:05 only is on time' => [['09:05'], ['singleRole' => 'in'], ['present', '09:05:00', null, 0, 0, 0, 0, true]],
    'clock-out 12:00 only is never late' => [['12:00'], ['singleRole' => 'out'], ['present', null, '12:00:00', 0, 0, 0, 0, true]],
    'clock-out 08:30 only is never late' => [['08:30'], ['singleRole' => 'out'], ['present', null, '08:30:00', 0, 0, 0, 0, true]],
    'clock-in on a weekend is recorded as the clock-in, not flagged' => [['14:00'], ['singleRole' => 'in', 'date' => DC_SATURDAY], ['weekend', '14:00:00', null, 0, 0, 0, 0, false]],
    'clock-out on a weekend is recorded as the clock-out' => [['10:00'], ['singleRole' => 'out', 'date' => DC_SATURDAY], ['weekend', null, '10:00:00', 0, 0, 0, 0, false]],
    'without a role a machine punch keeps the midpoint rule' => [['14:00'], [], ['present', null, '14:00:00', 0, 0, 0, 0, true]],
]);

it('ignores the single role when there are two times', function () {
    $result = (new DayCalculator)->calculate(dcInput(['09:00', '18:00'], ['singleRole' => 'in']));

    expect(dcRow($result))->toBe(['present', '09:00:00', '18:00:00', 540, 0, 0, 0, false]);
});

it('rejects an unknown single role', function () {
    (new DayCalculator)->calculate(dcInput(['14:00'], ['singleRole' => 'middle']));
})->throws(InvalidArgumentException::class);

/*
| Phase 4: approved leave. leaveHalf 'am' / 'pm' = half-day leave (the worked half needs the 4 h half-day threshold,
| never late or early); onLeave = a full leave day. Rows: status, in, out, worked, late, early, overtime, attention,
| leaveHalf.
*/

it('decides a day with approved leave', function (array $punches, array $overrides, array $expected) {
    $result = (new DayCalculator)->calculate(dcInput($punches, $overrides));

    expect($result)->not->toBeNull()
        ->and([...dcRow($result), $result->leaveHalf])->toBe($expected);
})->with([
    // Half-day leave, closed working day
    'half leave, two punches at the threshold: present, late 0 though the clock-in is late' => [['13:30', '17:30'], ['leaveHalf' => 'am'], ['present', '13:30:00', '17:30:00', 240, 0, 0, 0, false, 'am']],
    'half leave, first half worked, leaves at 13:00: present, no early minutes' => [['09:00', '13:00'], ['leaveHalf' => 'pm'], ['present', '09:00:00', '13:00:00', 240, 0, 0, 0, false, 'pm']],
    'half leave, two punches below the threshold: absent' => [['09:00', '12:59'], ['leaveHalf' => 'pm'], ['absent', '09:00:00', '12:59:00', 239, 0, 0, 0, false, 'pm']],
    'half leave, one punch: absent and flagged' => [['14:00'], ['leaveHalf' => 'am'], ['absent', null, '14:00:00', 0, 0, 0, 0, true, 'am']],
    'half leave, one late clock-in punch: absent and flagged, never late' => [['10:00'], ['leaveHalf' => 'pm'], ['absent', '10:00:00', null, 0, 0, 0, 0, true, 'pm']],
    'half leave, two punches under an hour apart: the close-pair rule makes it one punch' => [['13:30', '14:00'], ['leaveHalf' => 'am'], ['absent', null, '13:30:00', 0, 0, 0, 0, true, 'am']],
    'half leave, a double tap is one punch (the midpoint rule gives its role)' => [['13:30', '13:32'], ['leaveHalf' => 'am'], ['absent', null, '13:30:00', 0, 0, 0, 0, true, 'am']],
    'half leave, no punches: absent' => [[], ['leaveHalf' => 'am'], ['absent', null, null, 0, 0, 0, 0, false, 'am']],
    'half leave, overtime by the normal rule' => [['14:00', '19:00'], ['leaveHalf' => 'am'], ['present', '14:00:00', '19:00:00', 300, 0, 0, 60, false, 'am']],
    'half leave, entered times taken literally' => [['13:30', '14:00'], ['leaveHalf' => 'am', 'closePairRule' => false], ['absent', '13:30:00', '14:00:00', 30, 0, 0, 0, false, 'am']],
    // Open day
    'half leave, open day with punches: present, never late' => [['12:45'], ['leaveHalf' => 'am', 'closed' => false], ['present', '12:45:00', null, 0, 0, 0, 0, false, 'am']],
    'half leave, open day, short pair: present (decided at the close)' => [['09:00', '11:00'], ['leaveHalf' => 'pm', 'closed' => false], ['present', '09:00:00', '11:00:00', 120, 0, 0, 0, false, 'pm']],
    // Holiday, weekly off, exempt, full leave
    'half leave on a holiday: holiday, no leave half' => [[], ['leaveHalf' => 'am', 'isHoliday' => true], ['holiday', null, null, 0, 0, 0, 0, false, null]],
    'half leave on a weekly off: weekend, no leave half' => [[], ['leaveHalf' => 'pm', 'date' => DC_SATURDAY], ['weekend', null, null, 0, 0, 0, 0, false, null]],
    'exempt with half leave: present, leave half kept' => [[], ['leaveHalf' => 'am', 'isExempt' => true], ['present', null, null, 0, 0, 0, 0, false, 'am']],
    'full leave and a half both set: full leave' => [[], ['onLeave' => true, 'leaveHalf' => 'am'], ['on_leave', null, null, 0, 0, 0, 0, false, null]],
    'full leave with punches: on leave, times recorded, not flagged' => [['09:30', '18:00'], ['onLeave' => true], ['on_leave', '09:30:00', '18:00:00', 510, 0, 0, 0, false, null]],
    'full leave on an open day without punches: on leave' => [[], ['onLeave' => true, 'closed' => false], ['on_leave', null, null, 0, 0, 0, 0, false, null]],
    'full leave, single punch on a closed day: on leave, not flagged' => [['09:00'], ['onLeave' => true], ['on_leave', '09:00:00', null, 0, 0, 0, 0, false, null]],
    'no leave: leave half null' => [['09:00', '18:00'], [], ['present', '09:00:00', '18:00:00', 540, 0, 0, 0, false, null]],
]);

it('waits on an open half-leave day without punches', function () {
    expect((new DayCalculator)->calculate(dcInput([], ['closed' => false, 'leaveHalf' => 'am'])))->toBeNull()
        ->and((new DayCalculator)->calculate(dcInput([], ['closed' => false, 'leaveHalf' => 'pm', 'isExempt' => true])))->toBeNull();
});

it('rejects an unknown leave half', function () {
    (new DayCalculator)->calculate(dcInput([], ['leaveHalf' => 'full']));
})->throws(InvalidArgumentException::class);
