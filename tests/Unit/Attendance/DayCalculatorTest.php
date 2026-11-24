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
    'half day beats late, late minutes still recorded' => [['11:00', '14:00'], [], ['half_day', '11:00:00', '14:00:00', 180, 120, 240, 0, false]],
    'two punches a minute apart are a (tiny) half day' => [['09:00', '09:01'], [], ['half_day', '09:00:00', '09:01:00', 1, 0, 539, 0, false]],
    'punches between first and last are ignored' => [['09:00', '13:00', '14:00', '18:00'], [], ['present', '09:00:00', '18:00:00', 540, 0, 0, 0, false]],
    'punches out of order are sorted' => [['18:00', '13:00', '09:05'], [], ['present', '09:05:00', '18:00:00', 535, 0, 0, 0, false]],
    'duplicate punches count once' => [['09:00', '09:00', '18:00', '18:00'], [], ['present', '09:00:00', '18:00:00', 540, 0, 0, 0, false]],
    'overtime after shift end' => [['09:00', '18:45'], [], ['present', '09:00:00', '18:45:00', 585, 0, 0, 45, false]],
    'overtime below the minimum is not recorded' => [['09:00', '18:29:59'], [], ['present', '09:00:00', '18:29:59', 569, 0, 0, 0, false]],
    'overtime exactly at the minimum is recorded' => [['09:00', '18:30'], [], ['present', '09:00:00', '18:30:00', 570, 0, 0, 30, false]],
    'late with overtime' => [['10:00', '19:00'], [], ['late', '10:00:00', '19:00:00', 540, 60, 0, 60, false]],
    'zero grace: seconds in the first minute are on time' => [['09:00:30', '18:00'], ['graceMinutes' => 0], ['present', '09:00:30', '18:00:00', 539, 0, 0, 0, false]],
    'zero grace: one minute is late' => [['09:01', '18:00'], ['graceMinutes' => 0], ['late', '09:01:00', '18:00:00', 539, 1, 0, 0, false]],
    'fractional half-day hours' => [['09:00', '13:29'], ['halfDayHours' => 4.5], ['half_day', '09:00:00', '13:29:00', 269, 0, 271, 0, false]],
    'an open day with two punches is decided the same way' => [['09:20', '12:00'], ['closed' => false], ['half_day', '09:20:00', '12:00:00', 160, 20, 360, 0, false]],

    // Working day, one punch (clock-out missing)
    'one punch, open day: present, not flagged yet' => [['09:00'], ['closed' => false], ['present', '09:00:00', null, 0, 0, 0, 0, false]],
    'one punch, closed day: present and flagged' => [['09:00'], [], ['present', '09:00:00', null, 0, 0, 0, 0, true]],
    'one late punch, closed day: late and flagged' => [['09:45'], [], ['late', '09:45:00', null, 0, 45, 0, 0, true]],
    'one late punch, open day: late, not flagged' => [['09:45'], ['closed' => false], ['late', '09:45:00', null, 0, 45, 0, 0, false]],
    'the same punch twice is still one punch' => [['09:00', '09:00'], [], ['present', '09:00:00', null, 0, 0, 0, 0, true]],

    // Working day, no punches
    'no punches on a closed working day: absent' => [[], [], ['absent', null, null, 0, 0, 0, 0, false]],

    // Weekly off and holiday
    'weekend, no punches' => [[], ['date' => DC_SATURDAY], ['weekend', null, null, 0, 0, 0, 0, false]],
    'weekend, no punches, day still open' => [[], ['date' => DC_SATURDAY, 'closed' => false], ['weekend', null, null, 0, 0, 0, 0, false]],
    'weekend worked: all worked minutes are overtime' => [['10:00', '14:00'], ['date' => DC_SATURDAY], ['weekend', '10:00:00', '14:00:00', 240, 0, 0, 240, false]],
    'weekend worked below the overtime minimum' => [['10:00', '10:20'], ['date' => DC_SATURDAY], ['weekend', '10:00:00', '10:20:00', 20, 0, 0, 0, false]],
    'weekend, one punch, closed: flagged, no overtime' => [['10:00'], ['date' => DC_SATURDAY], ['weekend', '10:00:00', null, 0, 0, 0, 0, true]],
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

    // Day boundaries: no shift crosses midnight, so a punch after midnight belongs to the next day
    'a punch at 00:01 the next day is not this day\'s clock-out' => [['21:00', '2026-11-25 00:01:00'], [], ['late', '21:00:00', null, 0, 720, 0, 0, true]],
    'a punch at 23:59 the day before is ignored' => [['2026-11-23 23:59:00', '09:00', '18:00'], [], ['present', '09:00:00', '18:00:00', 540, 0, 0, 0, false]],
    'a punch at 23:59:59 is still this day' => [['09:00', '23:59:59'], [], ['present', '09:00:00', '23:59:59', 899, 0, 0, 359, false]],
]);

it('returns null for an open working day with no punches (nothing to record yet)', function (array $overrides) {
    expect((new DayCalculator)->calculate(dcInput([], ['closed' => false, ...$overrides])))->toBeNull();
})->with([
    'ordinary employee' => [[]],
    'exempt employee' => [['isExempt' => true]],
    'only punches on other days' => [['punches' => ['2026-11-23 09:00:00', '2026-11-25 09:00:00']]],
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
]);

it('never reads the clock or the database', function () {
    $source = (string) file_get_contents(app_path('Support/Attendance/DayCalculator.php'));

    expect($source)->not->toMatch('/\b(now|today|Date::|Carbon|DB::|settings\(|AttendanceClock|Model|time\(\))/');
});
