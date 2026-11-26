<?php

use App\Actions\Payroll\GeneratePayrollRun;
use App\Actions\Payroll\PayrollLineInputs;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\PayrollRun;
use App\Support\Payroll\PayrollChecks;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/Support/run-helpers.php';

/*
 * The approval checks of a run. "Now" is 2026-12-05 12:00 in Karachi unless a test travels; attendance started
 * 2026-11-01. A "clean" person has profile P, a bank account and a closed present row on every November day.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-12-05 12:00:00', 'Asia/Karachi'));
    Settings::set('attendance', ['start_date' => '2026-11-01', 'late_rule_count' => 3]);
    $this->hr = userWithRole('hr');
    $this->admin = userWithRole('admin');
});

function ckClean(array $attrs = []): Employee
{
    $e = prEmployee($attrs);
    salaryProfile($e);
    prBank($e);
    prFill($e);

    return $e;
}

/** @return array<string, array{key: string, severity: string, message: string, people: list<array{id: int, name: string}>, count: int}> */
function ckChecks(PayrollRun $run): array
{
    return collect(PayrollChecks::for($run->fresh()))->keyBy('key')->all();
}

function ckRecalc(PayrollRun $run): PayrollRun
{
    return app(GeneratePayrollRun::class)->recalculate(userWithRole('hr'), $run);
}

it('lists nothing for a clean run of an ended month', function () {
    ckClean();
    $run = prGenerate($this->hr);

    expect(PayrollChecks::for($run))->toBe([])
        ->and(PayrollChecks::blocking($run))->toBe([]);
});

it('blocks a month that has not ended', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-25 12:00:00', 'Asia/Karachi'));
    ckClean();
    Attendance::query()->where('date', '>=', '2026-11-25')->delete();
    $run = prGenerate($this->hr);

    $c = ckChecks($run);
    expect($c['month_not_ended']['severity'])->toBe('block')
        ->and($c['month_not_ended']['message'])->toBe('November 2026 has not ended yet. It can be approved from 1 Dec 2026.')
        ->and(PayrollChecks::blocking($run))->toBe(['month_not_ended']);

    $this->travelTo(CarbonImmutable::parse('2026-12-01 12:00:00', 'Asia/Karachi'));
    prFill(Employee::query()->firstOrFail(), '2026-11-25', '2026-11-30');
    expect(PayrollChecks::blocking($run))->toBe([]);
});

it('blocks while an earlier month is still a draft', function () {
    ckClean();
    $october = payrollRun('2026-10');
    $run = prGenerate($this->hr);

    expect(ckChecks($run)['earlier_draft']['message'])->toBe('Payroll for October 2026 is still a draft. Approve the months in order.');

    $october->update(['status' => 'approved', 'approved_at' => now()]);
    expect(PayrollChecks::blocking($run))->toBe([]);
});

it('blocks a person with no salary profile until one is added or the line is excluded', function () {
    ckClean();
    $e = prEmployee();
    prBank($e);
    prFill($e);
    $run = prGenerate($this->hr);

    $c = ckChecks($run);
    expect($c['no_profile']['people'])->toBe([['id' => $e->id, 'name' => $e->full_name]])
        ->and($c['no_profile']['count'])->toBe(1);

    app(PayrollLineInputs::class)->exclude($this->admin, prItem($run, $e), 'Paid by the sister company.');
    $c = ckChecks($run);
    expect(PayrollChecks::blocking($run))->toBe([])
        ->and($c['excluded']['people'][0]['id'])->toBe($e->id)
        ->and($c['excluded']['severity'])->toBe('warn');
});

it('blocks net pay below zero', function () {
    $e = ckClean();
    $run = prGenerate($this->hr);
    app(PayrollLineInputs::class)->addAdjustment($this->admin, prItem($run, $e), 'Damage recovery', 'deduction', 90000, false);

    expect(PayrollChecks::blocking($run->fresh()))->toBe(['negative_net']);
});

it('blocks a missing clock-out on a counted day and clears when resolved', function () {
    $e = ckClean();
    $row = Attendance::query()->where('employee_id', $e->id)->onDate('2026-11-12')->sole();
    $row->forceFill(['needs_attention' => true])->save();
    $run = prGenerate($this->hr);

    $c = ckChecks($run);
    expect($c['missing_clock_out']['message'])->toBe('1 day has a missing clock-out to resolve on the Daily screen.')
        ->and($c['missing_clock_out']['people'][0]['id'])->toBe($e->id);

    $row->forceFill(['needs_attention' => false])->save();
    expect(PayrollChecks::blocking($run))->toBe([]);
});

it('ignores a missing clock-out before the attendance start date and of an excluded person', function () {
    Settings::set('attendance', ['start_date' => '2026-11-15']);
    $e = ckClean();
    Attendance::query()->where('employee_id', $e->id)->onDate('2026-11-12')->update(['needs_attention' => true]);
    $other = ckClean();
    Attendance::query()->where('employee_id', $other->id)->onDate('2026-11-20')->update(['needs_attention' => true]);
    $run = prGenerate($this->hr);

    expect(ckChecks($run)['missing_clock_out']['people'])->toBe([['id' => $other->id, 'name' => $other->full_name]]);

    app(PayrollLineInputs::class)->exclude($this->admin, prItem($run, $other), 'Paid by the sister company.');
    expect(PayrollChecks::blocking($run))->toBe([]);
});

it('blocks days still being calculated', function () {
    $e = ckClean();
    $row = Attendance::query()->where('employee_id', $e->id)->onDate('2026-11-13')->sole();
    $row->forceFill(['status' => null])->save();
    $run = prGenerate($this->hr);

    expect(ckChecks($run)['pending_days']['message'])->toBe('1 day is still being calculated. Wait for attendance to finish or rebuild it.');

    $row->forceFill(['status' => 'present', 'needs_rebuild' => true])->save();
    expect(PayrollChecks::blocking($run))->toBe(['pending_days']);

    $row->forceFill(['needs_rebuild' => false])->save();
    expect(PayrollChecks::blocking($run))->toBe([]);
});

it('blocks past counted days with no attendance row', function () {
    $e = ckClean();
    Attendance::query()->where('employee_id', $e->id)->whereIn('date', ['2026-11-14', '2026-11-15'])->delete();
    $run = prGenerate($this->hr);

    $c = ckChecks($run);
    expect($c['missing_days']['message'])->toBe('2 past days have no attendance record. Close those days or correct them.')
        ->and($c['missing_days']['people'][0]['id'])->toBe($e->id);

    prFill($e);
    expect(PayrollChecks::blocking($run))->toBe([]);
});

it('blocks an open leave request touching the month', function () {
    $e = ckClean();
    $request = leaveRequest($e, ['from_date' => '2026-11-30', 'to_date' => '2026-12-02', 'days' => 3]);
    leaveRequest($e, ['from_date' => '2026-12-10', 'to_date' => '2026-12-10']);  // outside the month
    $run = prGenerate($this->hr);

    $c = ckChecks($run);
    expect($c['open_leave']['message'])->toBe('1 leave request touching this month is still open. Approve or reject them first.')
        ->and($c['open_leave']['people'][0]['id'])->toBe($e->id);

    $request->forceFill(['status' => 'manager_approved'])->save();
    expect(PayrollChecks::blocking($run))->toBe(['open_leave']);

    $request->forceFill(['status' => 'rejected'])->save();
    expect(PayrollChecks::blocking($run))->toBe([]);
});

it('lists the warnings', function () {
    Settings::set('attendance', ['start_date' => '2026-11-16']);
    $noBank = prEmployee();
    salaryProfile($noBank);
    prFill($noBank, '2026-11-16');
    $raise = ckClean();
    salaryProfile($raise, 66000, from: '2026-11-16');
    $short = ckClean(['joining_date' => '2026-11-28']);
    salaryProfile($short, from: '2026-11-28');
    loanFor($short, 10000, 10000, '2026-11', 0, 'advance');
    $overridden = ckClean();
    prEmployee(['last_working_day' => '2026-11-20', 'status' => 'resigned']);
    $left = prEmployee(['status' => 'terminated']);
    $run = prGenerate($this->hr);
    app(PayrollLineInputs::class)->overrideTax($this->admin, prItem($run, $overridden), 0, 'Exemption certificate.');

    $c = ckChecks($run);
    expect(PayrollChecks::blocking($run))->toBe([])
        ->and($c['no_bank_account']['people'])->toBe([['id' => $noBank->id, 'name' => $noBank->full_name]])
        ->and($c['settled_separately']['count'])->toBe(1)
        ->and($c['left_without_last_day']['people'][0]['id'])->toBe($left->id)
        ->and($c['tax_overridden']['people'][0]['id'])->toBe($overridden->id)
        ->and($c['installment_reduced']['people'][0]['id'])->toBe($short->id)
        ->and($c['profile_changed_mid_month']['people'][0]['id'])->toBe($raise->id)
        ->and($c['attendance_start']['message'])->toBe('Attendance started on 16 Nov 2026; days before it were not checked.')
        ->and(collect($c)->every(fn (array $check): bool => $check['severity'] === 'warn'))->toBeTrue();
});

it('warns about unpaid leave on a day off', function () {
    $e = ckClean();
    Attendance::query()->where('employee_id', $e->id)->onDate('2026-11-14')->update(['status' => 'weekend']);
    approvedLeave($e, '2026-11-14', null, null, 'Unpaid');
    $run = prGenerate($this->hr);

    expect(ckChecks($run)['unpaid_leave_on_off_day']['people'][0]['id'])->toBe($e->id);
});

it('warns when attendance has not started and does not block on missing rows then', function () {
    Settings::set('attendance', ['start_date' => null]);
    $e = prEmployee();
    salaryProfile($e);
    prBank($e);
    $run = prGenerate($this->hr);

    $c = ckChecks($run);
    expect(PayrollChecks::blocking($run))->toBe([])
        ->and($c['attendance_start']['message'])->toBe('Attendance has not started, so absences, half days and late days were not checked.');
});

it('runs the same number of queries for 2 people as for 6', function () {
    $count = function (): int {
        $run = PayrollRun::query()->where('month', '2026-11')->firstOrFail();
        DB::flushQueryLog();
        DB::enableQueryLog();
        PayrollChecks::for($run);
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    };

    foreach (range(1, 2) as $i) {
        $e = ckClean();
        Attendance::query()->where('employee_id', $e->id)->onDate('2026-11-12')->update(['needs_attention' => true]);
        leaveRequest($e, ['from_date' => '2026-11-30', 'to_date' => '2026-11-30']);
    }
    ckRecalc(prGenerate($this->hr));
    $two = $count();

    foreach (range(1, 4) as $i) {
        $e = ckClean();
        Attendance::query()->where('employee_id', $e->id)->onDate('2026-11-13')->update(['status' => null]);
        prEmployee();
    }
    ckRecalc(PayrollRun::query()->firstOrFail());
    $six = $count();

    expect($six)->toBe($two);
});
