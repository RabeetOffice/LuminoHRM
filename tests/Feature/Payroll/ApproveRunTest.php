<?php

use App\Actions\Payroll\ApprovePayrollRun;
use App\Actions\Payroll\GeneratePayrollRun;
use App\Actions\Payroll\MarkRunPaid;
use App\Actions\Payroll\PayrollLineInputs;
use App\Events\PayrollRunApproved;
use App\Models\ActivityEntry;
use App\Models\Attendance;
use App\Models\LeaveDay;
use App\Models\Loan;
use App\Models\LoanRepayment;
use App\Models\PayrollItem;
use App\Models\PayrollRun;
use App\Models\SalaryProfile;
use App\Models\User;
use App\Support\Leave\LeaveDays;
use App\Support\PayrollLock;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;

require_once __DIR__.'/Support/run-helpers.php';

/*
 * Approving a run, marking it paid. "Now" is 2026-12-05 12:00 in Karachi, attendance started 2026-11-01, late rule 3.
 * The main person is design example 1 on real rows (net 64,930, loan 12,000 with installment 5,000) with a bank account.
 */

beforeEach(function () {
    $this->withoutVite();
    $this->travelTo(CarbonImmutable::parse('2026-12-05 12:00:00', 'Asia/Karachi'));
    Settings::set('attendance', ['start_date' => '2026-11-01', 'late_rule_count' => 3]);
    Settings::set('company', ['name' => 'Lumino Digital', 'address' => '12 Main Boulevard, Lahore']);
    Settings::set('payroll', ['payslip_footer' => 'Computer generated; no signature needed.']);
    Event::fake([PayrollRunApproved::class]);
    Mail::fake();
    $this->hr = userWithRole('hr');
    $this->admin = userWithRole('admin');
});

function arSetUp(): array
{
    $e = prEmployee();
    prExample1($e);
    prBank($e, '1111222233334444');
    $run = prGenerate(test()->hr);

    return [$e, $run];
}

function arApprove(User $by, PayrollRun $run, ?string $fingerprint = null): string
{
    return app(ApprovePayrollRun::class)->approve($by, $run, $fingerprint ?? $run->fresh()->fingerprint());
}

function arErrors(Closure $fn): array
{
    try {
        $fn();
    } catch (ValidationException $e) {
        return $e->errors();
    }

    throw new RuntimeException('No validation error.');
}

it('approves: repayments, bank details, company snapshot, lock, audit and one event', function () {
    [$e, $run] = arSetUp();
    $loan = Loan::query()->sole();

    expect(arApprove($this->admin, $run))->toBe('approved');

    $run->refresh();
    $item = prItem($run, $e);
    expect($run->status)->toBe('approved')
        ->and($run->approved_by)->toBe($this->admin->id)
        ->and($run->approved_at)->not->toBeNull()
        ->and(PayrollLock::isLocked('2026-11'))->toBeTrue()
        ->and($item->net)->toBe(prRs(64930))
        ->and($item->bank_name)->toBe('Meezan Bank')
        ->and($item->account_title)->toBe($e->full_name)
        ->and($item->account_number)->toBe('1111222233334444')
        ->and($run->summary['company'])->toBe([
            'name' => 'Lumino Digital',
            'address' => '12 Main Boulevard, Lahore',
            'logo_path' => null,
            'footer' => 'Computer generated; no signature needed.',
        ])
        ->and($run->summary['checks'])->toBe([]);

    $repayment = LoanRepayment::query()->sole();
    expect($repayment->loan_id)->toBe($loan->id)
        ->and($repayment->payroll_item_id)->toBe($item->id)
        ->and($repayment->amount)->toBe(prRs(5000))
        ->and($repayment->month)->toBe('2026-11')
        ->and($loan->fresh()->remaining())->toBe(prRs(7000));

    $audit = Activity::where('description', 'Payroll approved')->sole();
    expect($audit->subject_id)->toBe($run->id)
        ->and($audit->causer_id)->toBe($this->admin->id)
        ->and($audit->properties['headcount'])->toBe(1)
        ->and($audit->properties['net'])->toBe(prRs(64930))
        ->and($audit->properties['totals_formatted']['net'])->toBe('Rs 64,930')
        ->and($audit->properties['own_payslip'])->toBeFalse()
        ->and($audit->properties['repaid'])->toBe(prRs(5000));

    Event::assertDispatchedTimes(PayrollRunApproved::class, 1);
    Event::assertDispatched(PayrollRunApproved::class, fn (PayrollRunApproved $ev) => $ev->runId === $run->id);
});

it('caps the repayment at what is left on the loan', function () {
    $e = prEmployee();
    salaryProfile($e);
    prFill($e);
    $loan = loanFor($e, 10000, 5000, '2026-10', 8000);
    $run = prGenerate($this->hr);

    expect(arApprove($this->admin, $run))->toBe('approved')
        ->and(LoanRepayment::query()->sole()->amount)->toBe(prRs(2000))
        ->and($loan->fresh()->remaining())->toBe(0)
        ->and(prItem($run, $e)->loan_deduction)->toBe(prRs(2000));
});

it('copies no bank details and writes no repayment for an excluded line, and keeps the warnings', function () {
    [$e, $run] = arSetUp();
    $other = prEmployee();
    salaryProfile($other);
    prFill($other);
    app(GeneratePayrollRun::class)->recalculate($this->hr, $run);
    app(PayrollLineInputs::class)->exclude($this->admin, prItem($run, $e), 'Paid by the sister company.');

    expect(arApprove($this->admin, $run))->toBe('approved')
        ->and(prItem($run, $e)->account_number)->toBeNull()
        ->and(LoanRepayment::count())->toBe(0)
        ->and(collect($run->fresh()->summary['checks'])->pluck('key')->all())->toBe(['no_bank_account', 'excluded']);
});

it('refuses HR', function () {
    [, $run] = arSetUp();

    expect(fn () => arApprove($this->hr, $run))->toThrow(AuthorizationException::class)
        ->and($run->fresh()->status)->toBe('draft');
    Event::assertNotDispatched(PayrollRunApproved::class);
});

it('returns changed with fresh figures when a leave day was approved after the review', function () {
    [$e, $run] = arSetUp();
    $reviewed = $run->fresh()->fingerprint();

    approvedLeave($e, '2026-11-19', null, null, 'Unpaid');

    $approve = app(ApprovePayrollRun::class);
    expect($approve->approve($this->admin, $run, $reviewed))->toBe('changed')
        ->and($approve->changedLines)->toBe(1);

    $run->refresh();
    // One more unpaid day: 11 halves -> R(90,000 x 11, 60) = 16,500; taxable share 15,400; tax R(68,600 x 500, 10,000) = 3,430.
    expect($run->status)->toBe('draft')
        ->and(prItem($run, $e)->attendance_deduction)->toBe(prRs(16500))
        ->and(prItem($run, $e)->net)->toBe(prRs(90000 - 16500 - 3000 - 3430 - 5000))
        ->and($run->net)->toBe(prRs(62070))
        ->and(LoanRepayment::count())->toBe(0)
        ->and(Activity::where('description', 'Payroll recalculated at approval')->sole()->properties['changed'])->toBe(1);
    Event::assertNotDispatched(PayrollRunApproved::class);

    // Reviewing the new figures and approving again works.
    expect(arApprove($this->admin, $run))->toBe('approved');
});

it('returns changed without writing when the reviewed fingerprint is stale', function () {
    [, $run] = arSetUp();

    $approve = app(ApprovePayrollRun::class);
    expect($approve->approve($this->admin, $run, 'stale'))->toBe('changed')
        ->and($approve->changedLines)->toBe(0)
        ->and($run->fresh()->status)->toBe('draft')
        ->and(Activity::where('description', 'like', 'Payroll recalculated at approval')->count())->toBe(0);
});

it('refuses when the people changed since the last calculation, writing nothing', function () {
    [, $run] = arSetUp();
    $joiner = prEmployee(['joining_date' => '2026-11-20']);
    salaryProfile($joiner, from: '2026-11-20');
    prFill($joiner, '2026-11-20');

    expect(arErrors(fn () => arApprove($this->admin, $run)))
        ->toBe(['run' => [ApprovePayrollRun::ROSTER_CHANGED_MESSAGE]])
        ->and(PayrollItem::count())->toBe(1)
        ->and($run->fresh()->status)->toBe('draft');
});

it('refuses a run with a blocking check and writes nothing', function () {
    [$e, $run] = arSetUp();
    Attendance::query()->where('employee_id', $e->id)->onDate('2026-11-12')->update(['needs_attention' => true]);

    expect(arErrors(fn () => arApprove($this->admin, $run)))
        ->toBe(['run' => ['1 day has a missing clock-out to resolve on the Daily screen.']])
        ->and($run->fresh()->status)->toBe('draft')
        ->and(prItem($run, $e)->account_number)->toBeNull()
        ->and(LoanRepayment::count())->toBe(0)
        ->and(Activity::where('description', 'Payroll approved')->count())->toBe(0);
    Event::assertNotDispatched(PayrollRunApproved::class);
});

it('refuses a second approval', function () {
    [, $run] = arSetUp();
    arApprove($this->admin, $run);

    expect(arErrors(fn () => arApprove($this->admin, $run)))->toBe(['run' => ['This payroll is already approved.']])
        ->and(LoanRepayment::count())->toBe(1);
    Event::assertDispatchedTimes(PayrollRunApproved::class, 1);
});

it('lets the sole active Admin approve their own line, audited, and refuses it with a second Admin', function () {
    [, $run] = arSetUp();
    $this->admin->forceFill(['is_active' => false])->save();
    [$admin, $adminEmployee] = prUser('admin');
    salaryProfile($adminEmployee);
    prFill($adminEmployee);
    app(GeneratePayrollRun::class)->recalculate($this->hr, $run);

    $second = userWithRole('admin');
    expect(fn () => arApprove($admin, $run))->toThrow(AuthorizationException::class);

    $second->forceFill(['is_active' => false])->save();
    expect(arApprove($admin, $run))->toBe('approved')
        ->and(Activity::where('description', 'Payroll approved')->sole()->properties['own_payslip'])->toBeTrue();
});

it('approves months in order', function () {
    [$e, $run] = arSetUp();
    $october = payrollRun('2026-10');

    expect(arErrors(fn () => arApprove($this->admin, $run))['run'])
        ->toBe(['Payroll for October 2026 is still a draft. Approve the months in order.']);

    $october->delete();
    $december = payrollRun('2026-12');
    $december->update(['status' => 'approved', 'approved_at' => now()]);
    expect(arErrors(fn () => arApprove($this->admin, $run))['run'])
        ->toBe(['Payroll for December 2026 is already approved, so an earlier month cannot be approved.'])
        ->and(prItem($run, $e)->account_number)->toBeNull();
});

it('freezes the approved lines against later profile and company changes', function () {
    [$e, $run] = arSetUp();
    arApprove($this->admin, $run);
    $before = prItem($run, $e)->toArray();

    SalaryProfile::query()->where('employee_id', $e->id)->firstOrFail()->update(['basic' => prRs(99000)]);
    Settings::set('company', ['name' => 'Renamed Ltd']);

    expect(arErrors(fn () => app(GeneratePayrollRun::class)->recalculate($this->hr, $run)))->toHaveKey('run')
        ->and(arErrors(fn () => prGenerate($this->hr)))->toHaveKey('month')
        ->and(prItem($run, $e)->toArray())->toBe($before)
        ->and($run->fresh()->summary['company']['name'])->toBe('Lumino Digital');
});

it('refuses a leave approval in November once the run is approved, after one approved before it was seen', function () {
    [$e, $run] = arSetUp();
    $reviewed = $run->fresh()->fingerprint();

    // A leave approval that committed before the payroll approval is picked up by it: figures changed.
    approvedLeave($e, '2026-11-18', null, null, 'Unpaid');
    expect(arApprove($this->admin, $run, $reviewed))->toBe('changed')
        ->and(arApprove($this->admin, $run))->toBe('approved');

    // One that runs after it reads the lock and is refused.
    $hr = userWithRole('hr');
    employeeFor($hr, ['joining_date' => '2025-01-01']);
    $request = leaveRequest($e, [
        'from_date' => '2026-11-23',
        'to_date' => '2026-11-23',
        'days' => LeaveDays::plan($e, '2026-11-23', '2026-11-23', null)->total,
        'reason' => 'Family wedding out of town.',
    ]);

    $this->actingAs($hr)->from('/leave/requests')
        ->put("/leave/requests/{$request->id}/final-decide", ['decision' => 'approve', 'note' => null])
        ->assertSessionHas('error', PayrollLock::message('2026-11'));
    expect(LeaveDay::query()->where('leave_request_id', $request->id)->exists())->toBeFalse();
});

it('marks an approved run paid with a valid date only', function () {
    [, $run] = arSetUp();
    $paid = app(MarkRunPaid::class);

    expect(arErrors(fn () => $paid->handle($this->hr, $run, '2026-12-02')))->toBe(['run' => ['Approve this payroll before marking it paid.']]);

    arApprove($this->admin, $run);

    expect(arErrors(fn () => $paid->handle($this->hr, $run, '2026-10-31')))->toBe(['paid_on' => ['The payment date cannot be before 1 Nov 2026.']])
        ->and(arErrors(fn () => $paid->handle($this->hr, $run, '2026-12-06')))->toBe(['paid_on' => ['The payment date cannot be in the future.']])
        ->and(arErrors(fn () => $paid->handle($this->hr, $run, '2026-12-32')))->toBe(['paid_on' => ['Enter the payment date.']])
        ->and(fn () => $paid->handle(userWithRole('employee'), $run, '2026-12-02'))->toThrow(AuthorizationException::class);

    $paid->handle($this->hr, $run, '2026-12-02');
    $run->refresh();

    expect($run->status)->toBe('paid')
        ->and($run->paid_on->toDateString())->toBe('2026-12-02')
        ->and($run->paid_by)->toBe($this->hr->id)
        ->and(PayrollLock::isLocked('2026-11'))->toBeTrue()
        ->and(Activity::where('description', 'Payroll marked paid')->sole()->properties['paid_on'])->toBe('2026-12-02')
        ->and(arErrors(fn () => $paid->handle($this->hr, $run, '2026-12-03')))->toBe(['run' => ['This payroll is already marked paid.']]);
});

it('retries the approval after a deadlock and ends with one approval, one repayment, one audit entry and one event', function () {
    // Laravel retries only a top-level transaction and RefreshDatabase wraps every test in one: run on a fresh in-memory
    // database of its own (migrated here), then put the shared connection back for the teardown.
    $connection = DB::connection();
    $shared = $connection->getPdo();
    $connection->setPdo(app('db.factory')->make(config('database.connections.sqlite'), 'sqlite-deadlock')->getPdo());
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    try {
        Artisan::call('migrate', ['--force' => true]);
        Settings::set('attendance', ['start_date' => '2026-11-01', 'late_rule_count' => 3]);
        $hr = userWithRole('hr');
        $admin = userWithRole('admin');
        $e = prEmployee();
        prExample1($e);
        prBank($e);
        $run = prGenerate($hr);

        $thrown = 0;
        ActivityEntry::creating(function (ActivityEntry $entry) use (&$thrown) {
            if ($entry->description === 'Payroll approved' && $thrown++ === 0) {
                throw new QueryException('sqlite', 'insert into "activity_log"', [],
                    new PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction'));
            }
        });

        expect(arApprove($admin, $run))->toBe('approved')
            ->and($thrown)->toBe(2)
            ->and($run->fresh()->status)->toBe('approved')
            ->and(LoanRepayment::count())->toBe(1)
            ->and(LoanRepayment::query()->sole()->amount)->toBe(prRs(5000))
            ->and(Activity::where('description', 'Payroll approved')->count())->toBe(1)
            ->and(prItem($run, $e)->account_number)->toBe('0123456789012345');
        Event::assertDispatchedTimes(PayrollRunApproved::class, 1);
    } finally {
        ActivityEntry::flushEventListeners();
        $connection->setPdo($shared);
        (fn () => $this->transactions = 1)->call($connection); // the RefreshDatabase transaction, rolled back after the test
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
});

it('locks the run, its lines, the loans and the other months before any plain read', function () {
    [, $run] = arSetUp();
    payrollRun('2026-10', 'approved');
    $fingerprint = $run->fresh()->fingerprint();

    $sql = [];
    DB::listen(function ($query) use (&$sql) {
        $sql[] = $query->sql;
    });

    expect(app(ApprovePayrollRun::class)->approve($this->admin, $run, $fingerprint))->toBe('approved');

    // SQLite ignores FOR UPDATE / LOCK IN SHARE MODE, so the order of the statements is what is pinned here.
    expect($sql[0])->toStartWith('select * from "payroll_runs" where "payroll_runs"."id" = ?')
        ->and($sql[1])->toStartWith('select "employee_id" from "payroll_items" where "payroll_run_id" = ?')
        ->and($sql[2])->toStartWith('select "id" from "loans" where "employee_id" in')
        ->and($sql[3])->toStartWith('select "status", "month" from "payroll_runs" where "month" < ?')
        ->and($sql[4])->toStartWith('select "status", "month" from "payroll_runs" where "month" > ?');
});

it('recalculates without the deduction of a loan cancelled before the approval and never repays it', function () {
    [$e, $run] = arSetUp();
    $reviewed = $run->fresh()->fingerprint();
    $loan = Loan::query()->sole();
    $loan->forceFill(['status' => 'cancelled', 'cancelled_at' => now(), 'cancel_reason' => 'Written off by the owner.'])->save();

    $approve = app(ApprovePayrollRun::class);
    expect($approve->approve($this->admin, $run, $reviewed))->toBe('changed')
        ->and($approve->changedLines)->toBe(1)
        ->and(prItem($run, $e)->loan_deduction)->toBe(0)
        ->and(prItem($run, $e)->net)->toBe(prRs(64930 + 5000))
        ->and(LoanRepayment::count())->toBe(0);

    expect(arApprove($this->admin, $run))->toBe('approved')
        ->and(LoanRepayment::count())->toBe(0)
        ->and($run->fresh()->net)->toBe(prRs(69930));
});

it('recalculates a loan reduced below its installment and repays only what is left', function () {
    [$e, $run] = arSetUp();
    $reviewed = $run->fresh()->fingerprint();
    $loan = Loan::query()->sole();
    $loan->forceFill(['amount' => prRs(3000)])->save();

    expect(arApprove($this->admin, $run, $reviewed))->toBe('changed')
        ->and(prItem($run, $e)->loan_deduction)->toBe(prRs(3000))
        ->and(prItem($run, $e)->net)->toBe(prRs(64930 + 2000));

    expect(arApprove($this->admin, $run))->toBe('approved')
        ->and(LoanRepayment::query()->sole()->amount)->toBe(prRs(3000))
        ->and($loan->fresh()->remaining())->toBe(0);
});

it('rolls the approval back rather than repay less than the line deducts', function () {
    [$e, $run] = arSetUp();
    $loan = Loan::query()->sole();

    // Simulates a loan that moved under the line between the calculation and the repayment (which the loan locks rule
    // out): saving the line's bank details pays off all but 1,000 of the loan behind the action's back.
    $active = true;
    PayrollItem::saving(function (PayrollItem $item) use ($loan, &$active) {
        if ($active && $item->isDirty('account_number') && $item->account_number !== null) {
            DB::table('loans')->where('id', $loan->id)->update(['opening_repaid' => prRs(11000)]);
        }
    });

    try {
        expect(fn () => arApprove($this->admin, $run))->toThrow(LogicException::class);
    } finally {
        $active = false;
    }

    expect($run->fresh()->status)->toBe('draft')
        ->and(LoanRepayment::count())->toBe(0)
        ->and(prItem($run, $e)->account_number)->toBeNull()
        ->and($loan->fresh()->opening_repaid)->toBe(0)
        ->and(Activity::where('description', 'Payroll approved')->count())->toBe(0);
    Event::assertNotDispatched(PayrollRunApproved::class);
});

it('returns changed when a line was excluded or included between the review and the approval', function () {
    [, $run] = arSetUp();
    $noProfile = prEmployee();
    app(GeneratePayrollRun::class)->recalculate($this->hr, $run);
    $inputs = app(PayrollLineInputs::class);
    $approve = app(ApprovePayrollRun::class);

    // Excluding the no-profile line changes no money figure (it paid 0), only the excluded flag.
    $reviewed = $run->fresh()->fingerprint();
    $inputs->exclude($this->admin, prItem($run, $noProfile), 'Joins the payroll next month.');
    expect($approve->approve($this->admin, $run, $reviewed))->toBe('changed')
        ->and($approve->changedLines)->toBe(0)
        ->and($run->fresh()->status)->toBe('draft');

    $reviewed = $run->fresh()->fingerprint();
    $inputs->include($this->admin, prItem($run, $noProfile));
    expect($approve->approve($this->admin, $run, $reviewed))->toBe('changed')
        ->and($run->fresh()->status)->toBe('draft')
        ->and(LoanRepayment::count())->toBe(0);
});

it('covers excluded, days paid and every deduction column in the fingerprint', function () {
    [$e, $run] = arSetUp();
    $before = $run->fresh()->fingerprint();

    foreach (['excluded' => true, 'days_paid' => 29, 'attendance_deduction' => 1, 'fixed_deductions' => 1, 'other_deductions' => 1] as $column => $value) {
        $old = DB::table('payroll_items')->where('employee_id', $e->id)->value($column);
        DB::table('payroll_items')->where('employee_id', $e->id)->update([$column => $value]);
        expect($run->fresh()->fingerprint())->not->toBe($before, $column);
        DB::table('payroll_items')->where('employee_id', $e->id)->update([$column => $old]);
    }

    expect($run->fresh()->fingerprint())->toBe($before);
});

it('refuses a run with nobody in it', function () {
    $run = prGenerate($this->hr);

    expect($run->items()->count())->toBe(0)
        ->and(arErrors(fn () => arApprove($this->admin, $run)))->toBe(['run' => [ApprovePayrollRun::NOBODY_MESSAGE]])
        ->and($run->fresh()->status)->toBe('draft');
    Event::assertNotDispatched(PayrollRunApproved::class);
});

it('reads the bank accounts without a query per person', function () {
    [, $run] = arSetUp();
    foreach (range(1, 3) as $i) {
        $other = prEmployee();
        salaryProfile($other);
        prBank($other, "99990000111{$i}");
        prFill($other);
    }
    app(GeneratePayrollRun::class)->recalculate($this->hr, $run);

    $bankQueries = 0;
    DB::listen(function ($query) use (&$bankQueries) {
        $bankQueries += str_contains($query->sql, '"employee_bank_accounts"') ? 1 : 0;
    });

    expect(arApprove($this->admin, $run))->toBe('approved')
        ->and($bankQueries)->toBeLessThanOrEqual(2)
        ->and(PayrollItem::query()->whereNotNull('account_number')->count())->toBe(4);
});

it('retries the changed outcome after a deadlock and records it once', function () {
    // As the approval deadlock test: a fresh in-memory database so that Laravel's top-level retry runs.
    $connection = DB::connection();
    $shared = $connection->getPdo();
    $connection->setPdo(app('db.factory')->make(config('database.connections.sqlite'), 'sqlite-deadlock')->getPdo());
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    try {
        Artisan::call('migrate', ['--force' => true]);
        Settings::set('attendance', ['start_date' => '2026-11-01', 'late_rule_count' => 3]);
        $hr = userWithRole('hr');
        $admin = userWithRole('admin');
        $e = prEmployee();
        prExample1($e);
        prBank($e);
        $run = prGenerate($hr);
        $reviewed = $run->fresh()->fingerprint();
        approvedLeave($e, '2026-11-19', null, null, 'Unpaid');

        $thrown = 0;
        ActivityEntry::creating(function (ActivityEntry $entry) use (&$thrown) {
            if ($entry->description === 'Payroll recalculated at approval' && $thrown++ === 0) {
                throw new QueryException('sqlite', 'insert into "activity_log"', [],
                    new PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction'));
            }
        });

        $approve = app(ApprovePayrollRun::class);
        expect($approve->approve($admin, $run, $reviewed))->toBe('changed')
            ->and($approve->changedLines)->toBe(1)
            ->and($thrown)->toBe(2)
            ->and($run->fresh()->status)->toBe('draft')
            ->and($run->fresh()->net)->toBe(prRs(62070))
            ->and(prItem($run, $e)->attendance_deduction)->toBe(prRs(16500))
            ->and(LoanRepayment::count())->toBe(0)
            ->and(Activity::where('description', 'Payroll recalculated at approval')->count())->toBe(1)
            ->and(Activity::where('description', 'Payroll recalculated at approval')->sole()->properties['changed'])->toBe(1);
        Event::assertNotDispatched(PayrollRunApproved::class);
    } finally {
        ActivityEntry::flushEventListeners();
        $connection->setPdo($shared);
        (fn () => $this->transactions = 1)->call($connection); // the RefreshDatabase transaction, rolled back after the test
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
});
