<?php

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\LoanRepayment;
use App\Models\LoanSkip;
use App\Support\Payroll\LoanDue;
use App\Support\Payroll\PayAdjustment;
use App\Support\Payroll\PayCalculator;
use App\Support\Payroll\PayInput;
use App\Support\Payroll\PayInputLoader;
use App\Support\Payroll\WorkingDays;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/*
 * PayInputLoader on seeded rows. November 2026 (30 days, starts on a Sunday); "now" is 2026-12-05 in Karachi, so the
 * whole month is past; attendance started on 2026-11-01 unless a test says otherwise.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-12-05 12:00:00', 'Asia/Karachi'));
    Settings::set('attendance', ['start_date' => '2026-11-01']);
});

/** @param  array<string, mixed>  $attrs */
function pilPerson(array $attrs = []): Employee
{
    return Employee::factory()->create(['joining_date' => '2025-01-01', ...$attrs]);
}

/** @param  array<string, mixed>  $attrs */
function pilDay(Employee $e, string $date, ?string $status, array $attrs = []): Attendance
{
    return Attendance::factory()->create([
        'employee_id' => $e->id,
        'date' => $date,
        'status' => $status,
        'closed' => true,
        ...$attrs,
    ]);
}

/** The one person's input for November 2026. */
function pilLoad(Employee $e, array $itemInputs = []): PayInput
{
    return PayInputLoader::load('2026-11', collect([$e->fresh()]), $itemInputs)[$e->id];
}

// ---------------------------------------------------------------- attendance and leave

it('turns attendance cells into halves, off dates, manual worked dates, lates and overtime', function () {
    $e = pilPerson();
    salaryProfile($e);
    pilDay($e, '2026-11-02', 'present');
    pilDay($e, '2026-11-03', 'absent');
    pilDay($e, '2026-11-04', 'half_day');
    pilDay($e, '2026-11-05', 'absent', ['leave_half' => 'am']);       // half-day leave, other half absent
    pilDay($e, '2026-11-06', 'on_leave');
    pilDay($e, '2026-11-07', 'weekend');
    pilDay($e, '2026-11-09', 'holiday');
    pilDay($e, '2026-11-10', 'late', ['late_minutes' => 20]);
    pilDay($e, '2026-11-11', 'late', ['late_minutes' => 20, 'overtime_minutes' => 45]);
    pilDay($e, '2026-11-12', null);                                     // pending: never deducted here
    pilDay($e, '2026-11-13', 'present', ['is_manual' => true]);
    pilDay($e, '2026-11-16', 'absent', ['is_manual' => true]);
    approvedLeave($e, '2026-11-06', null, null, 'Unpaid');

    $in = pilLoad($e);

    expect($in->attendanceHalves)->toBe(['2026-11-03' => 2, '2026-11-04' => 1, '2026-11-05' => 1, '2026-11-16' => 2])
        ->and($in->unpaidLeaveHalves)->toBe(['2026-11-06' => 2])
        // the two off cells (7th, 9th) plus the default shift's weekends without a row
        ->and($in->offDates)->toBe(['2026-11-01', '2026-11-07', '2026-11-08', '2026-11-09', '2026-11-14', '2026-11-15',
            '2026-11-21', '2026-11-22', '2026-11-28', '2026-11-29'])
        ->and($in->manualWorkedDates)->toBe(['2026-11-13'])
        ->and($in->lateDays)->toBe(2)
        ->and($in->overtimeMinutes)->toBe(45)
        ->and($in->countedFrom)->toBe('2026-11-01')
        ->and($in->attendanceTotals['absent'])->toBe(2.5)
        ->and($in->attendanceTotals['on_leave'])->toBe(1.5)
        // the 6th is wholly unpaid; the half-day leave on the 5th is the one paid half
        ->and($in->attendanceTotals['paid_leave'])->toBe(0.5);
});

it('keeps unpaid half days as one half and an am plus pm as a whole day', function () {
    $e = pilPerson();
    salaryProfile($e);
    approvedLeave($e, '2026-11-17', null, 'am', 'Unpaid');
    approvedLeave($e, '2026-11-18', null, 'am', 'Unpaid');
    approvedLeave($e, '2026-11-18', null, 'pm', 'Unpaid');
    approvedLeave($e, '2026-11-19', null, 'pm');                       // Annual: paid, not here

    expect(pilLoad($e)->unpaidLeaveHalves)->toBe(['2026-11-17' => 1, '2026-11-18' => 2]);
});

it('deducts unpaid leave before the attendance start date but not the absences there', function () {
    Settings::set('attendance', ['start_date' => '2026-11-16']);
    $e = pilPerson();
    salaryProfile($e);
    pilDay($e, '2026-11-12', 'absent');                                // stored before the start: not counted
    pilDay($e, '2026-11-16', 'present');
    pilDay($e, '2026-11-17', 'absent');
    approvedLeave($e, '2026-11-10', null, null, 'Unpaid');

    $in = pilLoad($e);

    expect($in->unpaidLeaveHalves)->toBe(['2026-11-10' => 2])
        ->and($in->attendanceHalves)->toBe(['2026-11-17' => 2])
        ->and($in->countedFrom)->toBe('2026-11-16');
});

it('lists unpaid leave on a manual present day as a manual worked date', function () {
    $e = pilPerson();
    salaryProfile($e);
    pilDay($e, '2026-11-20', 'present', ['is_manual' => true]);
    approvedLeave($e, '2026-11-20', null, null, 'Unpaid');

    $in = pilLoad($e);

    expect($in->manualWorkedDates)->toBe(['2026-11-20'])
        ->and($in->unpaidLeaveHalves)->toBe(['2026-11-20' => 2]);
});

it('lists a weekend cell with unpaid leave as an off date', function () {
    $e = pilPerson();
    salaryProfile($e);
    pilDay($e, '2026-11-14', 'weekend');
    approvedLeave($e, '2026-11-14', null, null, 'Unpaid');

    $in = pilLoad($e);

    expect($in->offDates)->toContain('2026-11-14')
        ->and($in->unpaidLeaveHalves)->toBe(['2026-11-14' => 2]);
});

it('starts a mid-month joiner\'s window, attendance and unpaid leave on the joining date', function () {
    $e = pilPerson(['joining_date' => '2026-11-16']);
    salaryProfile($e, from: '2026-11-16');
    pilDay($e, '2026-11-10', 'absent');                                // before joining: outside the window
    pilDay($e, '2026-11-17', 'absent');
    approvedLeave($e, '2026-11-12', null, null, 'Unpaid');             // before joining: ignored
    approvedLeave($e, '2026-11-18', null, null, 'Unpaid');

    $in = pilLoad($e);

    expect($in->windowFrom)->toBe('2026-11-16')
        ->and($in->windowTo)->toBe('2026-11-30')
        ->and($in->attendanceHalves)->toBe(['2026-11-17' => 2])
        ->and($in->unpaidLeaveHalves)->toBe(['2026-11-18' => 2])
        ->and($in->countedFrom)->toBe('2026-11-16')
        ->and(array_map(fn ($s) => [$s->from, $s->to, $s->days], $in->segments))->toBe([['2026-11-16', '2026-11-30', 15]]);
});

it('counts paid leave from the cells: unpaid leave before the start date and paid leave after it (probe A)', function () {
    Settings::set('attendance', ['start_date' => '2026-11-16']);
    $e = pilPerson();
    salaryProfile($e);
    pilDay($e, '2026-11-20', 'on_leave');
    approvedLeave($e, '2026-11-10', null, null, 'Unpaid');            // no cell: before the start date
    approvedLeave($e, '2026-11-20');                                  // Annual

    $in = pilLoad($e);

    expect($in->attendanceTotals['paid_leave'])->toBe(1)
        ->and($in->unpaidLeaveHalves)->toBe(['2026-11-10' => 2]);
});

it('counts paid leave from the cells: unpaid leave on worked, absent and half days is no paid leave (probe B)', function () {
    $e = pilPerson();
    salaryProfile($e);
    pilDay($e, '2026-11-05', 'half_day', ['is_manual' => true, 'status_overridden' => true]);
    pilDay($e, '2026-11-06', 'present', ['is_manual' => true]);
    pilDay($e, '2026-11-09', 'absent', ['is_manual' => true]);
    pilDay($e, '2026-11-10', 'on_leave');
    approvedLeave($e, '2026-11-05', null, 'am', 'Unpaid');
    approvedLeave($e, '2026-11-06', null, null, 'Unpaid');
    approvedLeave($e, '2026-11-09', null, null, 'Unpaid');
    approvedLeave($e, '2026-11-10');                                  // Annual

    expect(pilLoad($e)->attendanceTotals['paid_leave'])->toBe(1);
});

it('gives a half-day leave absent cell with an unpaid half 1 + 1 = 2 halves and no paid leave', function () {
    $e = pilPerson();
    salaryProfile($e);
    pilDay($e, '2026-11-11', 'absent', ['leave_half' => 'am']);
    approvedLeave($e, '2026-11-11', null, 'am', 'Unpaid');

    $in = pilLoad($e);

    expect($in->attendanceHalves)->toBe(['2026-11-11' => 1])
        ->and($in->unpaidLeaveHalves)->toBe(['2026-11-11' => 1])
        ->and($in->attendanceTotals['paid_leave'])->toBe(0)
        ->and(PayCalculator::calculate($in)->breakdown['attendance']['deducted_halves'])->toBe(2);
});

it('treats a weekly off or holiday without an attendance row as a day off: unpaid leave there is not deducted', function () {
    Settings::set('attendance', ['start_date' => '2026-11-16']);
    $e = pilPerson();
    salaryProfile($e);
    shiftFor($e, ['weekly_offs' => ['friday']]);
    Holiday::query()->create(['name' => 'Iqbal Day', 'date' => '2026-11-09']);
    approvedLeave($e, '2026-11-06', null, null, 'Unpaid');            // Friday, no row: off
    approvedLeave($e, '2026-11-09', null, null, 'Unpaid');            // holiday, no row: off
    approvedLeave($e, '2026-11-10', null, null, 'Unpaid');            // Tuesday, no row: deducted
    approvedLeave($e, '2026-11-14', null, null, 'Unpaid');            // Saturday is a working day on this shift

    $in = pilLoad($e);

    expect($in->offDates)->toBe(['2026-11-06', '2026-11-09', '2026-11-13', '2026-11-20', '2026-11-27'])
        ->and($in->unpaidLeaveHalves)->toBe(['2026-11-06' => 2, '2026-11-09' => 2, '2026-11-10' => 2, '2026-11-14' => 2]);

    $result = PayCalculator::calculate($in);

    expect($result->breakdown['attendance']['deducted_halves'])->toBe(4)
        ->and(array_column($result->warnings, 'key'))->toContain('unpaid_leave_on_off_day');
});

it('counts from max(joining date, attendance start date), not from the first stored day', function () {
    Settings::set('attendance', ['start_date' => '2026-11-10']);
    $a = pilPerson();
    pilDay($a, '2026-11-20', 'present');
    $b = pilPerson(['joining_date' => '2026-11-18']);
    $c = pilPerson(['joining_date' => '2026-12-03']);

    $inputs = PayInputLoader::load('2026-11', collect([$a->fresh(), $b->fresh(), $c->fresh()]));

    expect($inputs[$a->id]->countedFrom)->toBe('2026-11-10')
        ->and($inputs[$b->id]->countedFrom)->toBe('2026-11-18')
        ->and($inputs[$c->id]->countedFrom)->toBeNull();

    Settings::set('attendance', ['start_date' => null]);
    expect(pilLoad($a)->countedFrom)->toBe('2026-11-01');

    Settings::set('attendance', ['start_date' => '2026-12-01']);
    expect(pilLoad($a)->countedFrom)->toBeNull();
});

it('lists the past counted days without an attendance row per person, in one query', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-06 12:00:00', 'Asia/Karachi')); // today is the 6th: up to the 5th
    Settings::set('attendance', ['start_date' => '2026-11-02']);
    $a = pilPerson();
    pilDay($a, '2026-11-02', 'present');
    pilDay($a, '2026-11-04', null);                                   // pending: a row exists
    pilDay($a, '2026-11-06', 'present');                              // today: not past
    $b = pilPerson(['joining_date' => '2026-11-04']);
    pilDay($b, '2026-11-04', 'present');
    $c = pilPerson(['joining_date' => '2026-11-20']);
    $people = Employee::query()->whereKey([$a->id, $b->id, $c->id])->get();
    settings('attendance.start_date'); // warms the settings cache

    DB::enableQueryLog();
    DB::flushQueryLog();
    $missing = PayInputLoader::missingDays('2026-11', $people);
    $queries = count(DB::getQueryLog());

    expect($missing)->toBe([$a->id => ['2026-11-03', '2026-11-05'], $b->id => ['2026-11-05'], $c->id => []])
        ->and($queries)->toBe(1)
        ->and(PayInputLoader::missingDays('2026-11', collect()))->toBe([]);

    $this->travelTo(CarbonImmutable::parse('2026-12-05 12:00:00', 'Asia/Karachi'));
    expect(PayInputLoader::missingDays('2026-11', collect([$b->fresh()]))[$b->id])->toHaveCount(26); // 5..30 Nov
});

// ---------------------------------------------------------------- salary profiles

it('splits the month at a profile effective on 16 Nov into 15 and 15 days', function () {
    $e = pilPerson();
    $old = salaryProfile($e, from: '2026-01-01');
    salaryProfile($e, basicRupees: 10000, from: '2025-06-01');          // older history: not used
    $new = salaryProfile($e, basicRupees: 66000, allowances: [['House rent', 26400, true], ['Medical', 6000, false]], from: '2026-11-16');
    salaryProfile($e, basicRupees: 99000, from: '2026-12-01');          // after the month: not used

    $segments = pilLoad($e)->segments;

    expect($segments)->toHaveCount(2)
        ->and([$segments[0]->profileId, $segments[0]->from, $segments[0]->to, $segments[0]->days])->toBe([$old->id, '2026-11-01', '2026-11-15', 15])
        ->and([$segments[1]->profileId, $segments[1]->from, $segments[1]->to, $segments[1]->days])->toBe([$new->id, '2026-11-16', '2026-11-30', 15])
        ->and($segments[1]->basic)->toBe(6600000)
        ->and($segments[1]->gross())->toBe(9840000)
        ->and($segments[1]->taxableGross())->toBe(9240000)
        ->and(array_map(fn ($c) => $c->toArray(), $segments[0]->allowances))->toBe([
            ['name' => 'House rent', 'amount' => 2400000, 'taxable' => true],
            ['name' => 'Medical', 'amount' => 600000, 'taxable' => false],
        ])
        ->and(array_map(fn ($c) => $c->toArray(), $segments[0]->deductions))->toBe([
            ['name' => 'Provident fund', 'amount' => 300000, 'taxable' => false],
        ])
        ->and([$segments[0]->taxMode, $segments[0]->taxValue, $segments[0]->taxBase])->toBe(['percentage', 500, 'gross_taxable']);
});

it('drops the days before the first profile: a profile from 20 Nov gives one 11-day segment', function () {
    $e = pilPerson(['joining_date' => '2026-11-01']);
    $profile = salaryProfile($e, from: '2026-11-20');

    $in = pilLoad($e);

    expect($in->windowFrom)->toBe('2026-11-01')
        ->and(array_map(fn ($s) => [$s->profileId, $s->from, $s->to, $s->days], $in->segments))->toBe([[$profile->id, '2026-11-20', '2026-11-30', 11]]);
});

it('splits the month at several profile changes in one month', function () {
    $e = pilPerson();
    $a = salaryProfile($e, from: '2026-01-01');
    $b = salaryProfile($e, basicRupees: 62000, from: '2026-11-08');
    $c = salaryProfile($e, basicRupees: 64000, from: '2026-11-21');

    expect(array_map(fn ($s) => [$s->profileId, $s->from, $s->to, $s->days, $s->basic], pilLoad($e)->segments))->toBe([
        [$a->id, '2026-11-01', '2026-11-07', 7, 6000000],
        [$b->id, '2026-11-08', '2026-11-20', 13, 6200000],
        [$c->id, '2026-11-21', '2026-11-30', 10, 6400000],
    ]);
});

it('gives no segment to someone without a profile in force in the month', function () {
    $e = pilPerson();
    salaryProfile($e, from: '2026-12-01');

    expect(pilLoad($e)->segments)->toBe([]);
});

// ---------------------------------------------------------------- loans

it('loads the active loans due this month, oldest first, with a skip and the remaining amount', function () {
    $e = pilPerson();
    salaryProfile($e);
    $newer = loanFor($e, 20000, 4000, '2026-11');
    $older = loanFor($e, 50000, 5000, '2026-06', openingRepaidRupees: 38000);
    $skipped = loanFor($e, 9000, 3000, '2026-10', type: 'advance');
    LoanSkip::query()->create(['loan_id' => $skipped->id, 'month' => '2026-11', 'reason' => 'Wedding']);
    LoanSkip::query()->create(['loan_id' => $older->id, 'month' => '2026-12', 'reason' => 'Other month']);
    LoanRepayment::query()->create(['loan_id' => $older->id, 'amount' => 500000, 'month' => '2026-10']);
    loanFor($e, 10000, 5000, '2026-12');                                  // starts in December: not loaded
    $cleared = loanFor($e, 10000, 5000, '2026-08', openingRepaidRupees: 5000);
    LoanRepayment::query()->create(['loan_id' => $cleared->id, 'amount' => 500000, 'month' => '2026-10']);
    loanFor($e, 10000, 5000, '2026-08')->forceFill(['status' => 'cancelled'])->save();

    $loans = array_map(fn (LoanDue $l) => $l->toArray(), pilLoad($e)->loans);

    expect($loans)->toBe([
        ['loan_id' => $older->id, 'type' => 'loan', 'remaining' => 700000, 'installment' => 500000, 'skipped' => false],
        ['loan_id' => $skipped->id, 'type' => 'advance', 'remaining' => 900000, 'installment' => 300000, 'skipped' => true],
        ['loan_id' => $newer->id, 'type' => 'loan', 'remaining' => 2000000, 'installment' => 400000, 'skipped' => false],
    ]);
});

// ---------------------------------------------------------------- basis, settings, line inputs

it('uses the day-pay basis: calendar days, thirty, or working days', function () {
    $e = pilPerson();
    salaryProfile($e);
    shiftFor($e, ['weekly_offs' => ['saturday', 'sunday']]);
    Holiday::query()->create(['name' => 'Iqbal Day', 'date' => '2026-11-09']);

    expect(pilLoad($e)->basisDays)->toBe(30)
        ->and(pilLoad($e)->basis)->toBe('calendar_days');

    Settings::set('payroll', ['day_pay_basis' => 'thirty']);
    expect(pilLoad($e)->basisDays)->toBe(30);

    Settings::set('payroll', ['day_pay_basis' => 'working_days']);
    $in = pilLoad($e);
    expect($in->basisDays)->toBe(20)
        ->and($in->basis)->toBe('working_days')
        ->and($in->daysInMonth)->toBe(30);
});

it('falls back to the default shift for the working-days basis of someone without a shift', function () {
    Settings::set('payroll', ['day_pay_basis' => 'working_days']);
    $e = pilPerson(); // no shift: the default (Saturday and Sunday off)
    salaryProfile($e);

    expect($e->fresh()->shift_id)->toBeNull()
        ->and(pilLoad($e)->basisDays)->toBe(21);
});

it('counts working days of a month (pure) and per person from their shift', function () {
    expect(WorkingDays::inMonth('2026-11', ['saturday', 'sunday'], ['2026-11-09', '2026-12-25']))->toBe(20)
        ->and(WorkingDays::inMonth('2026-11', ['sunday'], []))->toBe(25)
        ->and(WorkingDays::inMonth('2027-02', [], []))->toBe(28)
        ->and(WorkingDays::inMonth('2026-11', ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'], []))->toBe(0);

    $sundayOnly = pilPerson();
    shiftFor($sundayOnly, ['weekly_offs' => ['sunday']]);
    $default = pilPerson(); // the default shift: Saturday and Sunday off

    expect(WorkingDays::forEmployees('2026-11', collect([$sundayOnly->fresh(), $default->fresh()])))
        ->toBe([$sundayOnly->id => 25, $default->id => 21]);
});

it('passes the settings, the employee facts, the exempt flag and HR\'s line inputs through', function () {
    Settings::set('attendance', ['late_rule_count' => 4, 'overtime_enabled' => true, 'overtime_rate_mode' => 'fixed', 'overtime_fixed_rate' => 300]);
    $e = pilPerson(['first_name' => 'Sara', 'last_name' => 'Ahmed', 'attendance_exempt' => true]);
    salaryProfile($e);
    $adjustment = new PayAdjustment(5, 'Bonus', 'addition', 1000000, true);

    $in = pilLoad($e, [$e->id => ['adjustments' => [$adjustment], 'tax_override' => 123400, 'tax_override_reason' => 'Agreed']]);

    expect($in->employee)->toBe([
        'code' => $e->employee_code,
        'name' => 'Sara Ahmed',
        'department' => $e->department->name,
        'designation' => $e->designation->name,
        'joining_date' => '2025-01-01',
    ])
        ->and($in->month)->toBe('2026-11')
        ->and($in->exempt)->toBeTrue()
        ->and($in->lateRuleCount)->toBe(4)
        ->and($in->overtime->toArray())->toBe(['enabled' => true, 'mode' => 'fixed', 'multiplier_percent' => 150, 'fixed_rate' => 30000, 'hours_per_day' => 8])
        ->and($in->adjustments)->toBe([$adjustment])
        ->and($in->taxOverride)->toBe(123400)
        ->and($in->taxOverrideReason)->toBe('Agreed');

    $plain = pilLoad(pilPerson());
    expect($plain->exempt)->toBeFalse()
        ->and($plain->adjustments)->toBe([])
        ->and($plain->taxOverride)->toBeNull();
});

it('returns nothing for nobody and refuses a malformed month', function () {
    expect(PayInputLoader::load('2026-11', collect()))->toBe([]);

    PayInputLoader::load('2026-1', collect([pilPerson()]));
})->throws(InvalidArgumentException::class);

// ---------------------------------------------------------------- query count

it('reads the same number of queries for 3 people as for 12, whatever their days', function () {
    Settings::set('payroll', ['day_pay_basis' => 'working_days']);

    $make = function (int $n): array {
        $ids = [];
        for ($i = 0; $i < $n; $i++) {
            $e = pilPerson();
            if ($i % 2 === 0) {
                shiftFor($e, ['weekly_offs' => ['sunday']]);
            }
            salaryProfile($e, from: '2026-01-01');
            salaryProfile($e, basicRupees: 70000, from: '2026-11-16');
            foreach (['2026-11-02', '2026-11-03', '2026-11-04'] as $d) {
                pilDay($e, $d, 'absent');
            }
            approvedLeave($e, '2026-11-10', '2026-11-11', type: 'Unpaid');
            $loan = loanFor($e, 20000, 5000, '2026-10');
            LoanSkip::query()->create(['loan_id' => $loan->id, 'month' => '2026-11', 'reason' => 'Skip']);
            loanFor($e, 10000, 2000, '2026-11');
            $ids[] = $e->id;
        }

        return $ids;
    };

    $few = Employee::query()->whereKey($make(3))->get();
    $many = Employee::query()->whereKey($make(12))->get();

    PayInputLoader::load('2026-11', $few); // warms the settings cache

    $count = function ($people): int {
        DB::enableQueryLog();
        DB::flushQueryLog();
        $inputs = PayInputLoader::load('2026-11', $people);
        expect($inputs)->toHaveCount($people->count());

        return count(DB::getQueryLog());
    };

    $fewQueries = $count(Employee::query()->whereKey($few->pluck('id'))->get());
    $manyQueries = $count(Employee::query()->whereKey($many->pluck('id'))->get());

    expect($manyQueries)->toBe($fewQueries)
        ->and($fewQueries)->toBeLessThanOrEqual(12);
});
