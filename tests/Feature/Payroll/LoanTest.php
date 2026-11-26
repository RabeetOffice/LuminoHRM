<?php

use App\Actions\Payroll\SaveLoan;
use App\Models\Employee;
use App\Models\Loan;
use App\Models\LoanRepayment;
use App\Models\LoanSkip;
use App\Models\PayrollItem;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

/*
 * Payroll > Loans & Advances: create / edit / skip / unskip / manual repayment / cancel, with the money rules in
 * paisa (exact rupee figures), the payroll lock, and who may touch whose loan. Today is 2026-11-25.
 */

beforeEach(function () {
    $this->withoutVite();
    $this->travelTo(CarbonImmutable::parse('2026-11-25 12:00:00', 'Asia/Karachi'));
});

function lnUser(string $role = 'hr'): User
{
    $user = userWithRole($role);
    employeeFor($user, ['joining_date' => '2024-03-01']);

    return $user->fresh();
}

/** An employee whose login (if any) holds $role. */
function lnPerson(?string $role = 'employee'): Employee
{
    if ($role === null) {
        return Employee::factory()->create(['joining_date' => '2024-03-01']);
    }

    return lnUser($role)->employee;
}

function lnAudit(string $description): ?Activity
{
    return Activity::query()->where('log_name', 'payroll')->where('description', $description)->latest('id')->first();
}

// --- access ----------------------------------------------------------------------------------------------------

test('managers and employees get 403 on every loan route', function (string $role) {
    $user = lnUser($role);
    $loan = loanFor(lnPerson(null), 12000, 5000, '2026-12');

    $this->actingAs($user)->get('/payroll/loans')->assertForbidden();
    $this->actingAs($user)->get("/payroll/loans/{$loan->id}")->assertForbidden();
    $this->actingAs($user)->post('/payroll/loans', [])->assertForbidden();
    $this->actingAs($user)->put("/payroll/loans/{$loan->id}", [])->assertForbidden();
    $this->actingAs($user)->post("/payroll/loans/{$loan->id}/skip", [])->assertForbidden();
    $this->actingAs($user)->delete("/payroll/loans/{$loan->id}/skip/2026-12")->assertForbidden();
    $this->actingAs($user)->post("/payroll/loans/{$loan->id}/repayments", [])->assertForbidden();
    $this->actingAs($user)->post("/payroll/loans/{$loan->id}/cancel", [])->assertForbidden();
})->with(['manager', 'employee']);

it('lets HR create a loan for an employee, in paisa, audited', function () {
    $hr = lnUser('hr');
    $person = lnPerson('employee');

    $this->actingAs($hr)->post('/payroll/loans', [
        'employee_id' => $person->id,
        'type' => 'loan',
        'amount' => '50,000',
        'installment' => 5000,
        'start_month' => '2026-12',
        'note' => 'Car repair',
    ])->assertSessionHasNoErrors()->assertRedirect();

    $loan = Loan::query()->sole();
    expect($loan->employee_id)->toBe($person->id)
        ->and($loan->amount)->toBe(5000000)
        ->and($loan->installment)->toBe(500000)
        ->and($loan->opening_repaid)->toBe(0)
        ->and($loan->start_month)->toBe('2026-12')
        ->and($loan->created_by)->toBe($hr->id)
        ->and($loan->remaining())->toBe(5000000);

    $audit = lnAudit('Loan created');
    expect($audit->subject_id)->toBe($person->id)
        ->and($audit->properties['attributes']['amount'])->toBe(5000000)
        ->and($audit->properties['attributes']['amount_formatted'])->toBe('Rs 50,000');
});

it('defaults an advance installment to its whole amount', function () {
    $hr = lnUser('hr');
    $person = lnPerson('employee');

    $this->actingAs($hr)->post('/payroll/loans', [
        'employee_id' => $person->id, 'type' => 'advance', 'amount' => 25000, 'start_month' => '2026-12',
    ])->assertSessionHasNoErrors();

    expect(Loan::query()->sole()->installment)->toBe(2500000);
});

it('requires an installment for a loan, at most the amount, and repaid-before below the amount', function () {
    $hr = lnUser('hr');
    $person = lnPerson('employee');
    $base = ['employee_id' => $person->id, 'type' => 'loan', 'amount' => 10000, 'start_month' => '2026-12'];

    $this->actingAs($hr)->post('/payroll/loans', $base)->assertSessionHasErrors('installment');
    $this->actingAs($hr)->post('/payroll/loans', [...$base, 'installment' => 10001])->assertSessionHasErrors('installment');
    $this->actingAs($hr)->post('/payroll/loans', [...$base, 'installment' => 0])->assertSessionHasErrors('installment');
    $this->actingAs($hr)->post('/payroll/loans', [...$base, 'installment' => 1000, 'opening_repaid' => 10000])->assertSessionHasErrors('opening_repaid');
    $this->actingAs($hr)->post('/payroll/loans', [...$base, 'amount' => 100000000, 'installment' => 1000])->assertSessionHasErrors('amount');
    $this->actingAs($hr)->post('/payroll/loans', [...$base, 'amount' => '1000.5', 'installment' => 1000])->assertSessionHasErrors('amount');

    expect(Loan::query()->count())->toBe(0);
});

it('refuses a first deduction month in or before an approved payroll month', function () {
    $hr = lnUser('hr');
    $person = lnPerson('employee');
    payrollRun('2026-11', 'approved');
    $base = ['employee_id' => $person->id, 'type' => 'loan', 'amount' => 10000, 'installment' => 1000];

    foreach (['2026-11', '2026-10'] as $month) {
        $this->actingAs($hr)->post('/payroll/loans', [...$base, 'start_month' => $month])
            ->assertSessionHasErrors(['start_month' => 'Payroll for November 2026 is approved; start deductions after it.']);
    }

    $this->actingAs($hr)->post('/payroll/loans', [...$base, 'start_month' => '2026-12'])->assertSessionHasNoErrors();
    expect(Loan::query()->count())->toBe(1);
});

test('HR cannot touch their own, an Admin\'s or another HR person\'s loan; Admin can', function () {
    $hr = lnUser('hr');
    $admin = lnUser('admin');
    $otherHr = lnPerson('hr');
    $payload = fn (Employee $e) => ['employee_id' => $e->id, 'type' => 'loan', 'amount' => 10000, 'installment' => 1000, 'start_month' => '2026-12'];

    $this->actingAs($hr)->post('/payroll/loans', $payload($hr->employee))->assertForbidden();
    $this->actingAs($hr)->post('/payroll/loans', $payload($admin->employee))->assertForbidden();
    $this->actingAs($hr)->post('/payroll/loans', $payload($otherHr))->assertForbidden();

    $own = loanFor($hr->employee, 12000, 5000, '2026-12');
    $this->actingAs($hr)->put("/payroll/loans/{$own->id}", ['amount' => 12000, 'installment' => 4000, 'start_month' => '2026-12'])->assertForbidden();
    $this->actingAs($hr)->post("/payroll/loans/{$own->id}/repayments", ['amount' => 100, 'month' => '2026-12', 'note' => 'Cash'])->assertForbidden();
    $this->actingAs($hr)->post("/payroll/loans/{$own->id}/skip", ['month' => '2026-12', 'reason' => 'Eid'])->assertForbidden();

    $this->actingAs($admin)->post('/payroll/loans', $payload($otherHr))->assertSessionHasNoErrors();
    $this->actingAs($admin)->post('/payroll/loans', $payload($admin->employee))->assertSessionHasNoErrors();
    expect(Loan::query()->count())->toBe(3);
});

// --- edits -----------------------------------------------------------------------------------------------------

it('refuses an amount edit after a repayment but allows the installment and note', function () {
    $hr = lnUser('hr');
    $loan = loanFor(lnPerson('employee'), 12000, 5000, '2026-12');
    LoanRepayment::query()->create(['loan_id' => $loan->id, 'amount' => 100000, 'month' => '2026-12', 'note' => 'Cash']);

    $this->actingAs($hr)->put("/payroll/loans/{$loan->id}", ['amount' => 15000, 'installment' => 5000, 'start_month' => '2026-12'])
        ->assertSessionHasErrors(['amount' => SaveLoan::HAS_REPAYMENTS]);

    $this->actingAs($hr)->put("/payroll/loans/{$loan->id}", ['amount' => 12000, 'installment' => 3000, 'start_month' => '2026-12', 'note' => 'Lower'])
        ->assertSessionHasNoErrors();

    $loan->refresh();
    expect($loan->amount)->toBe(1200000)
        ->and($loan->installment)->toBe(300000)
        ->and($loan->note)->toBe('Lower');

    $audit = lnAudit('Loan updated');
    expect($audit->properties['old']['installment'])->toBe(500000)
        ->and($audit->properties['attributes']['installment'])->toBe(300000);
});

it('allows an amount edit before any repayment and keeps a loan due in an approved month fixed', function () {
    $hr = lnUser('hr');
    $person = lnPerson('employee');
    $open = loanFor($person, 12000, 5000, '2026-12');

    $this->actingAs($hr)->put("/payroll/loans/{$open->id}", ['amount' => 20000, 'installment' => 5000, 'start_month' => '2027-01', 'opening_repaid' => 1000])
        ->assertSessionHasNoErrors();
    expect($open->refresh()->amount)->toBe(2000000)
        ->and($open->start_month)->toBe('2027-01')
        ->and($open->remaining())->toBe(1900000);

    $due = loanFor($person, 12000, 5000, '2026-11');
    payrollRun('2026-11', 'approved');

    $this->actingAs($hr)->put("/payroll/loans/{$due->id}", ['amount' => 15000, 'installment' => 5000, 'start_month' => '2026-11'])
        ->assertSessionHasErrors(['start_month' => SaveLoan::DUE_IN_LOCKED]);
    $this->actingAs($hr)->put("/payroll/loans/{$open->id}", ['amount' => 20000, 'installment' => 5000, 'start_month' => '2026-11', 'opening_repaid' => 1000])
        ->assertSessionHasErrors(['start_month' => 'Payroll for November 2026 is approved; start deductions after it.']);
    $this->actingAs($hr)->put("/payroll/loans/{$due->id}", ['amount' => 12000, 'installment' => 2000, 'start_month' => '2026-11'])
        ->assertSessionHasNoErrors();
    expect($due->refresh()->installment)->toBe(200000)->and($due->amount)->toBe(1200000);
});

// --- skips -----------------------------------------------------------------------------------------------------

it('skips a month once, refuses the same month twice, before the start or in a locked month', function () {
    $hr = lnUser('hr');
    $loan = loanFor(lnPerson('employee'), 12000, 5000, '2026-12');

    $this->actingAs($hr)->post("/payroll/loans/{$loan->id}/skip", ['month' => '2027-01', 'reason' => 'Wedding'])->assertSessionHasNoErrors();
    $this->actingAs($hr)->post("/payroll/loans/{$loan->id}/skip", ['month' => '2027-01', 'reason' => 'Again'])
        ->assertSessionHasErrors(['month' => 'This month is already skipped.']);
    $this->actingAs($hr)->post("/payroll/loans/{$loan->id}/skip", ['month' => '2026-11', 'reason' => 'Early'])
        ->assertSessionHasErrors(['month' => 'Deductions start in December 2026; skip that month or a later one.']);

    expect(LoanSkip::query()->where('loan_id', $loan->id)->pluck('month')->all())->toBe(['2027-01'])
        ->and(lnAudit('Loan skipped')->properties['month'])->toBe('2027-01');

    payrollRun('2026-12', 'approved');
    $this->actingAs($hr)->post("/payroll/loans/{$loan->id}/skip", ['month' => '2026-12', 'reason' => 'Late'])
        ->assertSessionHasErrors(['month' => 'Payroll for December 2026 is approved, so this can no longer change.']);
});

it('removes a skip, but never one in a locked month', function () {
    $hr = lnUser('hr');
    $loan = loanFor(lnPerson('employee'), 12000, 5000, '2026-11');
    LoanSkip::query()->create(['loan_id' => $loan->id, 'month' => '2026-11', 'reason' => 'Eid']);
    LoanSkip::query()->create(['loan_id' => $loan->id, 'month' => '2027-01', 'reason' => 'Wedding']);
    payrollRun('2026-11', 'approved');

    $this->actingAs($hr)->delete("/payroll/loans/{$loan->id}/skip/2026-11")
        ->assertSessionHasErrors(['month' => 'Payroll for November 2026 is approved, so this can no longer change.']);
    $this->actingAs($hr)->delete("/payroll/loans/{$loan->id}/skip/2027-01")->assertSessionHasNoErrors();
    $this->actingAs($hr)->delete("/payroll/loans/{$loan->id}/skip/2027-02")->assertNotFound();

    expect(LoanSkip::query()->pluck('month')->all())->toBe(['2026-11'])
        ->and(lnAudit('Loan unskipped'))->not->toBeNull();
});

// --- repayments ------------------------------------------------------------------------------------------------

it('records manual repayments up to what is left, never more', function () {
    $hr = lnUser('hr');
    $loan = loanFor(lnPerson('employee'), 12000, 5000, '2026-12');

    $this->actingAs($hr)->post("/payroll/loans/{$loan->id}/repayments", ['amount' => 12001, 'month' => '2026-12', 'note' => 'Cash'])
        ->assertSessionHasErrors(['amount' => 'Enter an amount above zero and no more than what is left (Rs 12,000).']);

    $this->actingAs($hr)->post("/payroll/loans/{$loan->id}/repayments", ['amount' => '5,000', 'month' => '2026-12', 'note' => 'Cash, receipt 41'])
        ->assertSessionHasNoErrors();
    expect($loan->fresh()->remaining())->toBe(700000);

    $this->actingAs($hr)->post("/payroll/loans/{$loan->id}/repayments", ['amount' => 7001, 'month' => '2026-12', 'note' => 'Cash'])
        ->assertSessionHasErrors('amount');
    $this->actingAs($hr)->post("/payroll/loans/{$loan->id}/repayments", ['amount' => 7000, 'month' => '2026-12', 'note' => 'Cash'])
        ->assertSessionHasNoErrors();

    expect($loan->fresh()->remaining())->toBe(0)
        ->and($loan->fresh()->isCleared())->toBeTrue()
        ->and(LoanRepayment::query()->where('loan_id', $loan->id)->sum('amount'))->toBe(1200000)
        ->and(LoanRepayment::query()->whereNotNull('payroll_item_id')->count())->toBe(0);

    $this->actingAs($hr)->post("/payroll/loans/{$loan->id}/repayments", ['amount' => 1, 'month' => '2026-12', 'note' => 'Cash'])
        ->assertSessionHasErrors(['amount' => 'Nothing is left to recover on this loan.']);

    $audit = lnAudit('Loan repaid');
    expect($audit->properties['amount'])->toBe(700000)->and($audit->properties['remaining_after'])->toBe(0);
});

it('counts repaid-before-go-live in remaining and refuses a repayment in a locked month', function () {
    $hr = lnUser('hr');
    $loan = loanFor(lnPerson('employee'), 50000, 5000, '2026-12', 38000);
    expect($loan->remaining())->toBe(1200000);

    payrollRun('2026-11', 'approved');
    $this->actingAs($hr)->post("/payroll/loans/{$loan->id}/repayments", ['amount' => 1000, 'month' => '2026-11', 'note' => 'Cash'])
        ->assertSessionHasErrors(['month' => 'Payroll for November 2026 is approved, so this can no longer change.']);
    expect($loan->fresh()->remaining())->toBe(1200000);
});

// --- cancel ----------------------------------------------------------------------------------------------------

it('lets only an Admin cancel a loan; a cancelled loan no longer changes', function () {
    $hr = lnUser('hr');
    $admin = lnUser('admin');
    $loan = loanFor(lnPerson('employee'), 12000, 5000, '2026-12');

    $this->actingAs($hr)->post("/payroll/loans/{$loan->id}/cancel", ['reason' => 'Written off'])->assertForbidden();
    expect($loan->fresh()->status)->toBe('active');

    $this->actingAs($admin)->post("/payroll/loans/{$loan->id}/cancel", ['reason' => 'Written off by the owner'])->assertSessionHasNoErrors();
    $loan->refresh();
    expect($loan->status)->toBe('cancelled')
        ->and($loan->cancelled_by)->toBe($admin->id)
        ->and($loan->cancel_reason)->toBe('Written off by the owner')
        ->and(lnAudit('Loan cancelled')->properties['written_off'])->toBe(1200000);

    $this->actingAs($hr)->post("/payroll/loans/{$loan->id}/repayments", ['amount' => 100, 'month' => '2026-12', 'note' => 'Cash'])
        ->assertSessionHasErrors('amount');
    $this->actingAs($hr)->put("/payroll/loans/{$loan->id}", ['amount' => 12000, 'installment' => 1000, 'start_month' => '2026-12'])
        ->assertSessionHasErrors('loan');
});

// --- screens ---------------------------------------------------------------------------------------------------

it('lists loans with remaining, next deduction month and status counts', function () {
    $hr = lnUser('hr');
    $person = lnPerson('employee');
    $active = loanFor($person, 12000, 5000, '2026-11');
    LoanSkip::query()->create(['loan_id' => $active->id, 'month' => '2026-12', 'reason' => 'Eid']);
    $cleared = loanFor($person, 1000, 1000, '2026-12');
    LoanRepayment::query()->create(['loan_id' => $cleared->id, 'amount' => 100000, 'month' => '2026-12', 'note' => 'Cash']);
    payrollRun('2026-11', 'approved');

    $this->actingAs($hr)->get('/payroll/loans')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('payroll/loans/index')
        ->where('counts', ['all' => 2, 'active' => 1, 'cleared' => 1, 'cancelled' => 0])
        ->has('loans', 1)
        ->where('loans.0.id', $active->id)
        ->where('loans.0.remaining', 1200000)
        ->where('loans.0.repaid', 0)
        ->where('loans.0.next_month', '2027-01')
        ->where('loans.0.status', 'active')
        ->where('months.first_open', '2026-12'));

    $this->actingAs($hr)->get('/payroll/loans?status=cleared')->assertInertia(fn (Assert $page) => $page
        ->has('loans', 1)->where('loans.0.id', $cleared->id)->where('loans.0.next_month', null));
});

it('reads the loans list with a fixed number of queries', function () {
    $hr = lnUser('hr');

    $count = function () use ($hr): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($hr)->get('/payroll/loans?status=all')->assertOk();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    };

    foreach (range(1, 2) as $i) {
        $l = loanFor(lnPerson(null), 12000, 5000, '2026-12');
        LoanSkip::query()->create(['loan_id' => $l->id, 'month' => '2027-01', 'reason' => 'x']);
        LoanRepayment::query()->create(['loan_id' => $l->id, 'amount' => 1000, 'month' => '2026-12']);
    }
    $count(); // warm-up: the first request also loads the permission cache and settings
    $few = $count();

    foreach (range(1, 6) as $i) {
        $l = loanFor(lnPerson(null), 12000, 5000, '2026-12');
        LoanSkip::query()->create(['loan_id' => $l->id, 'month' => '2027-01', 'reason' => 'x']);
        LoanRepayment::query()->create(['loan_id' => $l->id, 'amount' => 1000, 'month' => '2026-12']);
    }

    expect($count())->toBe($few);
});

it('shows a loan with repayments, their source, skips and the projected schedule', function () {
    $hr = lnUser('hr');
    $person = lnPerson('employee');
    $loan = loanFor($person, 20000, 5000, '2026-10');
    $run = payrollRun('2026-10', 'approved');
    $item = PayrollItem::query()->create(['payroll_run_id' => $run->id, 'employee_id' => $person->id]);
    LoanRepayment::query()->create(['loan_id' => $loan->id, 'payroll_item_id' => $item->id, 'amount' => 500000, 'month' => '2026-10']);
    LoanRepayment::query()->create(['loan_id' => $loan->id, 'amount' => 300000, 'month' => '2026-11', 'note' => 'Cash']);
    LoanSkip::query()->create(['loan_id' => $loan->id, 'month' => '2026-12', 'reason' => 'Eid']);

    $this->actingAs($hr)->get("/payroll/loans/{$loan->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('payroll/loans/show')
        ->where('loan.remaining', 1200000)
        ->where('loan.repaid', 800000)
        ->where('loan.has_repayments', true)
        ->where('loan.core_editable', false)
        ->where('repayments.0.source', 'Payroll October 2026')
        ->where('repayments.1.source', 'Cash')
        ->where('skips.0.month', '2026-12')
        ->where('skips.0.locked', false)
        ->where('schedule', [
            ['month' => '2026-11', 'amount' => 500000],
            ['month' => '2027-01', 'amount' => 500000],
            ['month' => '2027-02', 'amount' => 200000],
        ])
        ->where('can', ['edit' => true, 'cancel' => false]));
});
