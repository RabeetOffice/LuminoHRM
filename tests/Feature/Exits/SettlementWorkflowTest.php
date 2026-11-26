<?php

use App\Actions\Exits\ApproveSettlement;
use App\Actions\Exits\MarkSettlementPaid;
use App\Actions\Exits\OverrideSettlementTax;
use App\Actions\Exits\PrepareSettlement;
use App\Actions\Exits\RecalculateSettlement;
use App\Actions\Exits\RemoveSettlementLine;
use App\Actions\Exits\SaveSettlementLine;
use App\Models\Attendance;
use App\Models\Clearance;
use App\Models\FinalSettlement;
use App\Models\FinalSettlementLine;
use App\Models\Loan;
use App\Models\LoanRepayment;
use App\Models\SalaryProfile;
use App\Support\Exits\SettlementAccess;
use App\Support\Payroll\PayrollRoster;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

require_once __DIR__.'/Support/settlement-helpers.php';

/*
 * Prepare, lines, tax override, recalculate, approve and mark paid; every refusal. The person is the worked example
 * (last working day 18 Nov 2026, one absent day; net 46,820 with nothing else). "Now" is 5 Dec 2026; no payroll run
 * exists unless a test adds one (payroll not live).
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-12-05 12:00:00', 'Asia/Karachi'));
    Settings::set('attendance', ['start_date' => '2026-11-01', 'late_rule_count' => 3]);
    Settings::set('company', ['name' => 'Lumino Digital', 'address' => '12 Main Boulevard, Lahore']);
    $this->hr = userWithRole('hr');
    $this->admin = userWithRole('admin');
});

function swPrepared(): array
{
    $e = stPerson();
    $exit = approvedExit($e, '2026-11-18');
    stDays($e, '2026-11-01', '2026-11-18', ['2026-11-03' => 'absent']);
    $s = app(PrepareSettlement::class)->handle(test()->hr, $exit);

    return [$e->refresh(), $exit->refresh(), $s];
}

function swLast(string $description): ?Activity
{
    return Activity::query()->where('description', $description)->latest('id')->first();
}

it('prepares a draft for the month of the last day, calculated at once and audited', function () {
    [, $exit, $s] = swPrepared();

    expect($s->status)->toBe('draft')
        ->and($s->month)->toBe('2026-11')
        ->and($s->net)->toBe(stRs(46820))
        ->and($s->prepared_by)->toBe($this->hr->id)
        ->and($s->calculated_at)->not->toBeNull()
        ->and($s->breakdown['settlement']['last_working_day'])->toBe('2026-11-18')
        ->and(swLast('Settlement prepared')?->properties['attributes']['net_formatted'])->toBe('Rs 46,820');
});

it('refuses to prepare for an exit that is not approved, twice, or for oneself', function () {
    $e = stPerson();
    $pending = resignationRow($e, ['status' => 'manager_approved', 'last_working_day' => '2026-11-18']);
    expect(fn () => app(PrepareSettlement::class)->handle($this->hr, $pending))
        ->toThrow(ValidationException::class, PrepareSettlement::NOT_APPROVED);

    [, $exit] = swPrepared();
    expect(fn () => app(PrepareSettlement::class)->handle($this->hr, $exit))
        ->toThrow(ValidationException::class, PrepareSettlement::EXISTS);

    $own = employeeFor($this->hr, ['joining_date' => '2025-01-01']);
    salaryProfile($own);
    $ownExit = approvedExit($own, '2026-11-18');
    expect(fn () => app(PrepareSettlement::class)->handle($this->hr, $ownExit))
        ->toThrow(ValidationException::class, 'You cannot prepare or edit your own settlement.');
});

it('lets only an Admin prepare the settlement of an Admin or HR person', function () {
    $other = userWithRole('hr');
    $e = employeeFor($other, ['joining_date' => '2025-01-01']);
    salaryProfile($e);
    $exit = approvedExit($e, '2026-11-18');

    expect(fn () => app(PrepareSettlement::class)->handle($this->hr, $exit))
        ->toThrow(ValidationException::class, 'Only an Admin can edit the settlement of an Admin or HR person.');

    expect(app(PrepareSettlement::class)->handle($this->admin, $exit)->status)->toBe('draft');
});

it('adds and removes lines, recalculating each time', function () {
    [, , $s] = swPrepared();

    $line = app(SaveSettlementLine::class)->handle($this->hr, $s, ['kind' => 'bonus', 'label' => 'Year-end bonus', 'amount' => 10000, 'is_taxable' => true]);
    // + 10,000, tax + 500.
    expect($s->refresh()->net)->toBe(stRs(56320))
        ->and($line->type)->toBe('addition')->and($line->amount)->toBe(stRs(10000))
        ->and(swLast('Settlement line added')?->properties['attributes']['net'])->toBe(stRs(56320));

    app(SaveSettlementLine::class)->handle($this->hr, $s, ['kind' => 'notice_shortfall', 'label' => 'Notice short by 5 days', 'amount' => 5000]);
    expect($s->refresh()->net)->toBe(stRs(51320))
        ->and(FinalSettlementLine::query()->where('kind', 'notice_shortfall')->value('type'))->toBe('deduction');

    app(RemoveSettlementLine::class)->handle($this->hr, $line);
    expect($s->refresh()->net)->toBe(stRs(41820))
        ->and(swLast('Settlement line removed'))->not->toBeNull();
});

it('refuses a bad line: no label, an amount out of range, more than 20 lines', function () {
    [, , $s] = swPrepared();
    $save = app(SaveSettlementLine::class);

    expect(fn () => $save->handle($this->hr, $s, ['kind' => 'bonus', 'label' => '  ', 'amount' => 100]))
        ->toThrow(ValidationException::class, 'Enter a label.')
        ->and(fn () => $save->handle($this->hr, $s, ['kind' => 'bonus', 'label' => 'Bonus', 'amount' => 0]))
        ->toThrow(ValidationException::class, 'Enter an amount between 1 and 10,000,000 rupees.')
        ->and(fn () => $save->handle($this->hr, $s, ['kind' => 'bonus', 'label' => 'Bonus', 'amount' => 10_000_001]))
        ->toThrow(ValidationException::class, 'Enter an amount between 1 and 10,000,000 rupees.');

    for ($i = 0; $i < 20; $i++) {
        stLine($s, 'other', 1);
    }

    expect(fn () => $save->handle($this->hr, $s, ['kind' => 'bonus', 'label' => 'One more', 'amount' => 1]))
        ->toThrow(ValidationException::class, 'At most 20 lines.');
});

it('overrides the tax with a reason and clears it again', function () {
    [, , $s] = swPrepared();
    $override = app(OverrideSettlementTax::class);

    expect(fn () => $override->handle($this->hr, $s, 1000, '  '))
        ->toThrow(ValidationException::class, 'Enter a reason for the tax override.');

    $override->handle($this->hr, $s, 1000, 'Tax certificate on file');
    expect($s->refresh()->tax)->toBe(stRs(1000))
        ->and($s->net)->toBe(stRs(48200))
        ->and(swLast('Settlement tax overridden')?->properties['attributes']['reason'])->toBe('Tax certificate on file');

    $override->handle($this->hr, $s, null, null);
    expect($s->refresh()->tax)->toBe(stRs(2380))->and($s->tax_overridden)->toBeFalse();
});

it('recalculates a stale draft and clears stale', function () {
    [, , $s] = swPrepared();
    $s->forceFill(['stale' => true])->save();

    app(RecalculateSettlement::class)->handle($this->hr, $s);

    expect($s->refresh()->stale)->toBeFalse()->and(swLast('Settlement recalculated'))->not->toBeNull();
});

it('approves: repayments linked to the settlement, the loan cleared, assets tick stored, company snapshot, audited', function () {
    [$e, $exit, $s] = swPrepared();
    $loan = loanFor($e, 20000, 2000, '2026-06');
    app(RecalculateSettlement::class)->handle($this->hr, $s);
    stClear($exit);

    $approved = app(ApproveSettlement::class)->handle($this->admin, $s->refresh());

    $repayment = LoanRepayment::query()->where('loan_id', $loan->id)->sole();
    expect($approved->status)->toBe('approved')
        ->and($approved->approved_by)->toBe($this->admin->id)
        ->and($approved->own_settlement)->toBeFalse()
        ->and($approved->net)->toBe(stRs(26820))
        ->and($approved->summary['company']['name'])->toBe('Lumino Digital')
        ->and($approved->summary['company'])->toHaveKeys(['name', 'address', 'logo_path', 'logo', 'footer'])
        ->and($repayment->final_settlement_id)->toBe($approved->id)
        ->and($repayment->payroll_item_id)->toBeNull()
        ->and($repayment->amount)->toBe(stRs(20000))
        ->and($repayment->month)->toBe('2026-11')
        ->and(Loan::query()->withRemaining()->find($loan->id)->remaining())->toBe(0)
        ->and(Clearance::query()->where('resignation_id', $exit->id)->where('kind', 'assets')->value('cleared_at'))->not->toBeNull()
        ->and(swLast('Settlement approved')?->properties['loans_recovered'][0]['amount'])->toBe(stRs(20000));

    // The December run never sees the person again, so nothing is deducted twice.
    expect(PayrollRoster::forMonth('2026-12')->included->pluck('id')->all())->not->toContain($e->id);
});

it('refuses to approve before the last day has passed', function () {
    [, $exit, $s] = swPrepared();
    stClear($exit);
    $this->travelTo(CarbonImmutable::parse('2026-11-18 12:00:00', 'Asia/Karachi'));

    expect(fn () => app(ApproveSettlement::class)->handle($this->admin, $s))
        ->toThrow(ValidationException::class, 'The last working day has not passed yet.');
});

it('refuses to approve while clearance is incomplete, naming the items', function () {
    [, , $s] = swPrepared();

    expect(fn () => app(ApproveSettlement::class)->handle($this->admin, $s))
        ->toThrow(ValidationException::class, 'Clearance is not complete: System access removed, Dues cleared.');
});

it('refuses to approve without a salary profile', function () {
    [$e, $exit, $s] = swPrepared();
    stClear($exit);
    SalaryProfile::query()->where('employee_id', $e->id)->each(fn (SalaryProfile $p) => $p->delete());

    expect(fn () => app(ApproveSettlement::class)->handle($this->admin, $s))
        ->toThrow(ValidationException::class, "{$e->full_name} has no salary profile for November 2026.");
});

it('refuses to approve while days of the final month are open', function () {
    [$e, $exit, $s] = swPrepared();
    stClear($exit);
    Attendance::query()->where('employee_id', $e->id)->onDate('2026-11-12')->delete();

    expect(fn () => app(ApproveSettlement::class)->handle($this->admin, $s))
        ->toThrow(ValidationException::class, 'Attendance for the final month is not complete: 1 days still open.');
});

it('refuses to approve while a leave request touching the month is open', function () {
    [$e, $exit, $s] = swPrepared();
    stClear($exit);
    leaveRequest($e, ['from_date' => '2026-11-10', 'to_date' => '2026-11-10', 'status' => 'pending']);

    expect(fn () => app(ApproveSettlement::class)->handle($this->admin, $s))
        ->toThrow(ValidationException::class, 'Leave requests touching November 2026 are still open.');
});

it('refuses to approve while payroll is live and the previous month has no approved run', function () {
    [, $exit, $s] = swPrepared();
    stClear($exit);
    payrollRun('2026-09', 'approved');

    expect(fn () => app(ApproveSettlement::class)->handle($this->admin, $s))
        ->toThrow(ValidationException::class, 'Approve the payroll run for October 2026 first.');

    payrollRun('2026-10', 'approved');
    expect(app(ApproveSettlement::class)->handle($this->admin, $s)->status)->toBe('approved');
});

it('refuses a stale draft', function () {
    [, $exit, $s] = swPrepared();
    stClear($exit);
    $s->forceFill(['stale' => true])->save();

    expect(fn () => app(ApproveSettlement::class)->handle($this->admin, $s))
        ->toThrow(ValidationException::class, 'The last working day changed. Recalculate the settlement first.');
});

it('saves changed figures and refuses, then approves on the second try', function () {
    [$e, $exit, $s] = swPrepared();
    stClear($exit);
    loanFor($e, 5000, 1000, '2026-06'); // added after the last calculation

    expect(fn () => app(ApproveSettlement::class)->handle($this->admin, $s))
        ->toThrow(ValidationException::class, ApproveSettlement::CHANGED);

    expect($s->refresh()->status)->toBe('draft')->and($s->net)->toBe(stRs(41820))
        ->and(LoanRepayment::query()->count())->toBe(0);

    expect(app(ApproveSettlement::class)->handle($this->admin, $s)->status)->toBe('approved')
        ->and(LoanRepayment::query()->count())->toBe(1);
});

it('lets an Admin approve their own settlement only as the sole active Admin', function () {
    $e = employeeFor($this->admin, ['joining_date' => '2025-01-01']);
    salaryProfile($e);
    $exit = approvedExit($e, '2026-11-18');
    stDays($e, '2026-11-01', '2026-11-18');
    $other = userWithRole('admin');
    $s = app(PrepareSettlement::class)->handle($other, $exit);
    stClear($exit);

    expect(fn () => app(ApproveSettlement::class)->handle($this->admin, $s))
        ->toThrow(ValidationException::class, 'You cannot approve your own settlement while another Admin can.');

    $other->forceFill(['is_active' => false])->save();
    $approved = app(ApproveSettlement::class)->handle($this->admin, $s);

    expect($approved->own_settlement)->toBeTrue();
});

it('HR cannot approve (403)', function () {
    [, $exit, $s] = swPrepared();
    stClear($exit);

    expect(fn () => app(ApproveSettlement::class)->handle($this->hr, $s))->toThrow(AuthorizationException::class);
});

it('freezes an approved settlement against every edit', function () {
    [, $exit, $s] = swPrepared();
    stClear($exit);
    $line = stLine($s, 'bonus', 100);
    app(RecalculateSettlement::class)->handle($this->hr, $s);
    app(ApproveSettlement::class)->handle($this->admin, $s->refresh());
    $frozen = 'This settlement is approved and can no longer change.';

    expect(fn () => app(SaveSettlementLine::class)->handle($this->hr, $s, ['kind' => 'bonus', 'label' => 'x', 'amount' => 1]))->toThrow(ValidationException::class, $frozen)
        ->and(fn () => app(RemoveSettlementLine::class)->handle($this->hr, $line))->toThrow(ValidationException::class, $frozen)
        ->and(fn () => app(OverrideSettlementTax::class)->handle($this->hr, $s, 0, 'x'))->toThrow(ValidationException::class, $frozen)
        ->and(fn () => app(RecalculateSettlement::class)->handle($this->hr, $s))->toThrow(ValidationException::class, $frozen)
        ->and(fn () => app(ApproveSettlement::class)->handle($this->admin, $s))->toThrow(ValidationException::class, $frozen);
});

it('marks an approved settlement paid with a date between the approval and today', function () {
    [, $exit, $s] = swPrepared();
    $paid = app(MarkSettlementPaid::class);

    expect(fn () => $paid->handle($this->hr, $s, '2026-12-05'))
        ->toThrow(ValidationException::class, 'Only an approved settlement can be marked paid.');

    stClear($exit);
    app(ApproveSettlement::class)->handle($this->admin, $s);

    expect(fn () => $paid->handle($this->hr, $s, '2026-12-06'))
        ->toThrow(ValidationException::class, 'The paid date cannot be in the future or before the approval.')
        ->and(fn () => $paid->handle($this->hr, $s, '2026-12-04'))
        ->toThrow(ValidationException::class, 'The paid date cannot be in the future or before the approval.');

    $done = $paid->handle($this->hr, $s, '2026-12-05');

    expect($done->status)->toBe('paid')
        ->and($done->paid_on->format('Y-m-d'))->toBe('2026-12-05')
        ->and($done->paid_by)->toBe($this->hr->id)
        ->and(swLast('Settlement marked paid'))->not->toBeNull()
        ->and(SettlementAccess::mayApprove($this->admin, $done))->toBeTrue();
});

it('keeps the stored breakdown totals equal to the columns', function () {
    [$e, , $s] = swPrepared();
    stEncashment($e, 6.5);
    app(SaveSettlementLine::class)->handle($this->hr, $s, ['kind' => 'bonus', 'label' => 'Bonus', 'amount' => 10000]);

    $s = FinalSettlement::query()->find($s->id);

    expect($s->breakdown['totals'])->toMatchArray([
        'gross' => $s->gross, 'attendance_deduction' => $s->attendance_deduction, 'fixed_deductions' => $s->fixed_deductions,
        'other_deductions' => $s->other_deductions, 'loan_deduction' => $s->loan_deduction, 'tax' => $s->tax, 'net' => $s->net,
    ])->and($s->encashment_amount)->toBe(stRs(19500));
});
