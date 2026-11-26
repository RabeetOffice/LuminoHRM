<?php

use App\Actions\Attendance\ApplyManualCorrection;
use App\Actions\Attendance\CorrectionRequests;
use App\Actions\Employees\UpdateEmployee;
use App\Actions\Leave\ApplyForLeave;
use App\Actions\Leave\LeaveDecisions;
use App\Actions\Payroll\LoanActions;
use App\Actions\Payroll\SaveSalaryProfile;
use App\Models\Attendance;
use App\Models\AttendanceCorrection;
use App\Models\Device;
use App\Models\Employee;
use App\Models\LeaveDay;
use App\Support\Attendance\AttendanceRebuilder;
use App\Support\Attendance\CorrectionEligibility;
use App\Support\Attendance\PunchIngestor;
use App\Support\Attendance\RebuildMarks;
use App\Support\PayrollLock;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/Support/settlement-helpers.php';

/*
 * An approved final settlement freezes that person's November 2026 (design 5.6): every switched writer refuses or
 * skips it with "The final settlement for November 2026 is approved, so this can no longer change.", while another
 * person in the same month is unaffected and a DRAFT settlement locks nothing. "Now" is 5 Dec 2026.
 */

const SL_MESSAGE = 'The final settlement for November 2026 is approved, so this can no longer change.';

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-12-05 12:00:00', 'Asia/Karachi'));
    Settings::set('attendance', ['start_date' => '2026-11-01']);
    $this->admin = userWithRole('admin');
    $this->hr = userWithRole('hr');
    $this->settled = stPerson();
    stDays($this->settled, '2026-11-01', '2026-11-28');
    lockSettlement($this->settled, '2026-11');
    $this->other = stPerson();
    stDays($this->other, '2026-11-01', '2026-11-30');
});

it('answers the per-employee lock API', function () {
    $id = (int) $this->settled->id;

    expect(PayrollLock::isLockedFor($id, '2026-11'))->toBeTrue()
        ->and(PayrollLock::isLockedFor((int) $this->other->id, '2026-11'))->toBeFalse()
        ->and(PayrollLock::isLocked('2026-11'))->toBeFalse()
        ->and(PayrollLock::lockedMonthsFor($id, ['2026-10', '2026-11']))->toBe(['2026-11'])
        ->and(PayrollLock::firstLockedFor($id, ['2026-10-05', '2026-11-10']))->toBe('2026-11')
        ->and(PayrollLock::rangeTouchesLockedFor($id, '2026-12-20', '2026-10-01'))->toBe('2026-11')
        ->and(PayrollLock::settledMonths([$id, (int) $this->other->id], ['2026-11']))->toBe([$id => ['2026-11']])
        ->and(PayrollLock::messageForEmployee($id, '2026-11'))->toBe(SL_MESSAGE);

    payrollRun('2026-10', 'approved');
    expect(PayrollLock::rangeTouchesLockedFor($id, '2026-10-01', '2026-11-30'))->toBe('2026-10');
});

it('refuses a manual correction of the settled month, not of another person', function () {
    $correct = app(ApplyManualCorrection::class);

    expect(fn () => $correct->handle($this->settled, '2026-11-10', '09:00', '18:00', null, 'Forgot to punch', $this->hr))
        ->toThrow(ValidationException::class, SL_MESSAGE);

    expect($correct->handle($this->other, '2026-11-10', '09:00', '18:00', null, 'Forgot to punch', $this->hr)->is_manual)->toBeTrue();
});

it('refuses to apply a correction request and lists the reason on My Attendance', function () {
    $request = AttendanceCorrection::factory()->create(['employee_id' => $this->settled->id, 'date' => '2026-11-10', 'status' => 'manager_approved']);

    expect(fn () => app(CorrectionRequests::class)->decideAsHr($this->hr, $request, true, null))
        ->toThrow(ValidationException::class, SL_MESSAGE)
        ->and(app(CorrectionEligibility::class)->reasonRefused($this->settled, '2026-11-10'))->toBe(SL_MESSAGE)
        ->and(app(CorrectionEligibility::class)->reasonRefused($this->other, '2026-11-10'))->not->toBe(SL_MESSAGE);
});

it('refuses leave applied, approved or cancelled in the settled month', function () {
    $annual = leaveType('Annual', ['is_paid' => true]);

    expect(fn () => app(ApplyForLeave::class)->handle($this->hr, $this->settled, $annual, '2026-11-10', '2026-11-10', null, 'Back-dated day off.', null))
        ->toThrow(ValidationException::class, SL_MESSAGE);

    $pending = leaveRequest($this->settled, ['from_date' => '2026-11-11', 'to_date' => '2026-11-11', 'status' => 'manager_approved']);
    expect(fn () => app(LeaveDecisions::class)->decideFinal($this->hr, $pending, true, null))
        ->toThrow(ValidationException::class, SL_MESSAGE);

    $approved = approvedLeave($this->settled, '2026-11-12');
    expect(fn () => app(LeaveDecisions::class)->cancelApproved($this->hr, $approved, '2026-11-12', 'Cancelled after the settlement.'))
        ->toThrow(ValidationException::class);
    expect(LeaveDay::query()->where('leave_request_id', $approved->id)->count())->toBe(1);
});

it('leaves the settled person\'s days untouched in a rebuild and in rebuild marks', function () {
    $row = Attendance::query()->where('employee_id', $this->settled->id)->onDate('2026-11-10')->firstOrFail();
    $row->forceFill(['needs_rebuild' => true])->save();

    app(AttendanceRebuilder::class)->rebuild($this->settled, '2026-11-10');

    $row->refresh();
    expect($row->status)->toBe('present')->and($row->needs_rebuild)->toBeFalse()
        ->and(RebuildMarks::forLeaveDays((int) $this->settled->id, ['2026-11-11']))->toBe(0)
        ->and(RebuildMarks::forLeaveDays((int) $this->other->id, ['2026-11-11']))->toBe(1);
});

it('marks nothing for the settled person when punches arrive, and still marks others', function () {
    $this->settled->forceFill(['device_user_id' => '5001'])->save();
    $this->other->forceFill(['device_user_id' => '5002'])->save();
    $device = Device::factory()->create();

    app(PunchIngestor::class)->ingest($device, "5001\t2026-11-10 09:05:00\t0\t1\n5002\t2026-11-10 09:05:00\t0\t1\n", null);

    expect(Attendance::query()->where('employee_id', $this->settled->id)->onDate('2026-11-10')->value('needs_rebuild'))->toBeFalsy()
        ->and(Attendance::query()->where('employee_id', $this->other->id)->onDate('2026-11-10')->value('needs_rebuild'))->toBeTruthy();
});

it('refuses a salary profile starting in the settled month', function () {
    $data = ['effective_from' => '2026-11-15', 'basic' => 100, 'tax_mode' => 'none', 'tax_value' => 0, 'tax_base' => null, 'note' => null, 'components' => []];

    expect(fn () => app(SaveSalaryProfile::class)->create($this->admin, Employee::query()->findOrFail($this->settled->id), $data))
        ->toThrow(ValidationException::class, SL_MESSAGE);

    expect(app(SaveSalaryProfile::class)->create($this->admin, Employee::query()->findOrFail($this->other->id), $data)->id)->toBeInt();
});

it('refuses a loan skip or a repayment in the settled month', function () {
    $loan = loanFor($this->settled, 10000, 1000, '2026-06');

    expect(fn () => app(LoanActions::class)->skip($this->hr, $loan, '2026-11', 'Festival month'))
        ->toThrow(ValidationException::class, SL_MESSAGE)
        ->and(fn () => app(LoanActions::class)->repay($this->hr, $loan, 100_000, '2026-11', 'Cash'))
        ->toThrow(ValidationException::class, SL_MESSAGE);

    $otherLoan = loanFor($this->other, 10000, 1000, '2026-06');
    expect(app(LoanActions::class)->skip($this->hr, $otherLoan, '2026-11', 'Festival month')->month)->toBe('2026-11');
});

it('refuses a joining date move across the settled month', function () {
    expect(fn () => app(UpdateEmployee::class)->handle($this->settled, ['joining_date' => '2026-11-05'], $this->admin, false))
        ->toThrow(ValidationException::class, SL_MESSAGE);
});

it('locks nothing while the settlement is a draft', function () {
    $draftPerson = stPerson();
    stDays($draftPerson, '2026-11-01', '2026-11-20');
    settlementRow(approvedExit($draftPerson, '2026-11-20'));

    expect(PayrollLock::isLockedFor((int) $draftPerson->id, '2026-11'))->toBeFalse()
        ->and(app(ApplyManualCorrection::class)->handle($draftPerson, '2026-11-10', '09:00', '18:00', null, 'Forgot to punch', $this->hr)->is_manual)->toBeTrue();
});
