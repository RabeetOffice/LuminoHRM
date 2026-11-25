<?php

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\Shift;
use App\Support\Leave\LeaveDays;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/*
 * LeaveDays::plan: weekly offs per date (closed-row snapshot, else the shift in force), holidays and the employment
 * window, in a fixed number of queries. Calendar: Fri 2026-11-20, Mon 23 … Fri 27, Sat 28, Sun 29, Mon 30.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-25 12:00:00', 'Asia/Karachi'));
});

function ldlEmployee(array $attrs = []): Employee
{
    return Employee::factory()->create(['first_name' => 'Sara', 'last_name' => 'Khan', 'joining_date' => '2024-03-01', ...$attrs]);
}

/** A closed attendance row on $date whose snapshot says $offs were the weekly offs (or $rules as given). */
function ldlClosedDay(Employee $e, string $date, ?array $offs, ?array $rules = null, bool $closed = true): Attendance
{
    return Attendance::factory()->create([
        'employee_id' => $e->id,
        'date' => $date,
        'closed' => $closed,
        'shift_rules' => $rules ?? ($offs === null ? null : [
            'shift_id' => null, 'name' => 'Old', 'start_time' => '09:00', 'end_time' => '18:00', 'grace_minutes' => 15,
            'half_day_hours' => 4.0, 'weekly_offs' => $offs, 'overtime_min_minutes' => 0, 'duplicate_punch_minutes' => 5,
            'attendance_exempt' => false,
        ]),
    ]);
}

it('counts the working days of the employee\'s own shift', function () {
    $e = ldlEmployee();
    shiftFor($e, ['weekly_offs' => ['friday']]);

    $plan = LeaveDays::plan($e->fresh(), '2026-11-23', '2026-11-29', null);

    expect($plan->total)->toBe(6.0)
        ->and($plan->dates())->not->toContain('2026-11-27');
});

it('does not refuse a weekend before the joining date (only counted days must be inside employment)', function () {
    $e = ldlEmployee(['joining_date' => '2026-11-23']);
    shiftFor($e, ['weekly_offs' => ['saturday', 'sunday']]);

    $plan = LeaveDays::plan($e->fresh(), '2026-11-21', '2026-11-27', null);

    expect($plan->refusal)->toBeNull()
        ->and($plan->total)->toBe(5.0)
        ->and($plan->dates())->toBe(['2026-11-23', '2026-11-24', '2026-11-25', '2026-11-26', '2026-11-27']);
});

it('uses the default shift for an employee without one', function () {
    Shift::default()->update(['weekly_offs' => ['sunday']]);
    $e = ldlEmployee(['shift_id' => null]);

    $plan = LeaveDays::plan($e, '2026-11-23', '2026-11-29', null);

    expect($plan->total)->toBe(6.0)
        ->and($plan->dates())->toContain('2026-11-28')
        ->and($plan->dates())->not->toContain('2026-11-29');
});

it('lets a past closed day\'s snapshot weekly offs win over a since-changed shift', function () {
    $e = ldlEmployee();
    shiftFor($e, ['weekly_offs' => ['friday', 'saturday']]); // the shift now
    // Fri 20 Nov was decided with a Sat/Sun-off shift: a working day then.
    ldlClosedDay($e, '2026-11-20', ['saturday', 'sunday']);
    // Sun 22 Nov too: an off day then, although Sunday is a working day on today's shift.
    ldlClosedDay($e, '2026-11-22', ['saturday', 'sunday']);

    $plan = LeaveDays::plan($e->fresh(), '2026-11-20', '2026-11-27', null);

    // Fri 20 (snapshot: working), Sat 21 (today's shift: off), Sun 22 (snapshot: off), Mon-Thu 23-26, Fri 27 (off).
    expect($plan->dates())->toBe(['2026-11-20', '2026-11-23', '2026-11-24', '2026-11-25', '2026-11-26'])
        ->and($plan->total)->toBe(5.0);
});

it('ignores a snapshot that is unusable, missing, or on a day not closed', function () {
    $e = ldlEmployee();
    shiftFor($e, ['weekly_offs' => ['friday', 'saturday']]);
    ldlClosedDay($e, '2026-11-20', null, ['weekly_offs' => ['sunday']]);   // no times: unusable
    ldlClosedDay($e, '2026-11-13', null);                                  // no snapshot at all
    ldlClosedDay($e, '2026-11-06', ['sunday'], null, closed: false);       // open row

    expect(LeaveDays::plan($e->fresh(), '2026-11-20', '2026-11-20', null)->refusal)->toBe(LeaveDays::REFUSAL_NOTHING_TO_COUNT)
        ->and(LeaveDays::plan($e->fresh(), '2026-11-13', '2026-11-13', null)->refusal)->toBe(LeaveDays::REFUSAL_NOTHING_TO_COUNT)
        ->and(LeaveDays::plan($e->fresh(), '2026-11-06', '2026-11-06', null)->refusal)->toBe(LeaveDays::REFUSAL_NOTHING_TO_COUNT);
});

it('skips holidays in the range', function () {
    $e = ldlEmployee();
    shiftFor($e);
    Holiday::query()->create(['name' => 'Iqbal Day', 'date' => '2026-11-25']);
    Holiday::query()->create(['name' => 'Outside', 'date' => '2026-12-25']);

    $plan = LeaveDays::plan($e->fresh(), '2026-11-23', '2026-11-27', null);

    expect($plan->total)->toBe(4.0)
        ->and($plan->dates())->not->toContain('2026-11-25');
});

it('counts a half day and refuses one on a holiday', function () {
    $e = ldlEmployee();
    shiftFor($e);
    Holiday::query()->create(['name' => 'Iqbal Day', 'date' => '2026-11-25']);

    expect(LeaveDays::plan($e->fresh(), '2026-11-24', '2026-11-24', 'am')->days)
        ->toBe([['date' => '2026-11-24', 'amount' => 0.5, 'half' => 'am']])
        ->and(LeaveDays::plan($e->fresh(), '2026-11-25', '2026-11-25', 'pm')->refusal)
        ->toBe(LeaveDays::REFUSAL_NOTHING_TO_COUNT);
});

it('refuses dates before joining with the person\'s name', function () {
    $e = ldlEmployee(['joining_date' => '2026-11-24']);

    expect(LeaveDays::plan($e, '2026-11-23', '2026-11-25', null)->refusal)
        ->toBe("These dates are outside Sara Khan's employment.")
        ->and(LeaveDays::plan($e, '2026-11-24', '2026-11-25', null)->total)->toBe(2.0);
});

it('refuses dates after the last working day', function () {
    $e = ldlEmployee(['status' => 'resigned', 'last_working_day' => '2026-12-04']);

    expect(LeaveDays::plan($e, '2026-12-03', '2026-12-07', null)->refusal)
        ->toBe("These dates are outside Sara Khan's employment.")
        ->and(LeaveDays::plan($e, '2026-12-03', '2026-12-04', null)->total)->toBe(2.0);
});

it('ends a leaver without a last working day the day before the attendance day', function () {
    $e = ldlEmployee(['status' => 'resigned', 'last_working_day' => null]);

    expect(LeaveDays::plan($e, '2026-11-24', '2026-11-24', null)->total)->toBe(1.0)
        ->and(LeaveDays::plan($e, '2026-11-24', '2026-11-25', null)->refusal)
        ->toBe("These dates are outside Sara Khan's employment.");
});

it('counts a range across 31 Dec per year', function () {
    $e = ldlEmployee();
    shiftFor($e);
    Holiday::query()->create(['name' => 'New Year', 'date' => '2027-01-01']);

    $plan = LeaveDays::plan($e->fresh(), '2026-12-30', '2027-01-05', null);

    expect($plan->byYear())->toBe([2026 => 2.0, 2027 => 2.0]);
});

it('runs a fixed number of queries whatever the range', function () {
    $e = ldlEmployee();
    shiftFor($e);
    foreach (['2026-11-02', '2026-11-03', '2026-11-04', '2026-11-05', '2026-11-06'] as $date) {
        ldlClosedDay($e, $date, ['saturday', 'sunday']);
    }
    Holiday::query()->create(['name' => 'A', 'date' => '2026-11-09']);
    Holiday::query()->create(['name' => 'B', 'date' => '2026-12-25']);
    $e = $e->fresh();

    $count = function (string $from, string $to) use ($e): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        LeaveDays::plan($e, $from, $to, null);
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    };

    $count('2026-11-02', '2026-11-02'); // warm settings caches
    $short = $count('2026-11-05', '2026-11-10');
    $long = $count('2026-10-01', '2027-03-31');
    $snapshotsOnly = $count('2026-11-02', '2026-11-06'); // every date has a snapshot: the shift is not even read

    expect($long)->toBe($short)
        ->and($short)->toBeLessThanOrEqual(3) // attendance rows, holidays, the shift
        ->and($snapshotsOnly)->toBe($short - 1);
});

it('plans many requests of many people in a fixed number of queries', function () {
    $make = function (int $n): array {
        $asks = [];

        for ($i = 0; $i < $n; $i++) {
            $e = ldlEmployee();
            shiftFor($e);
            ldlClosedDay($e, '2026-11-23', ['saturday', 'sunday']);
            $asks[] = [$e->fresh(), '2026-12-30', '2027-01-05', null];
        }

        return $asks;
    };

    $count = function (array $asks): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $plans = LeaveDays::planMany($asks);
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        expect($plans)->toHaveCount(count($asks))->and($plans[0]->total)->toBe(5.0);

        return $n;
    };

    $count($make(1));
    $two = $count($make(2));
    $six = $count($make(6));

    expect($six)->toBe($two);
});
