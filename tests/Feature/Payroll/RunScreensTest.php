<?php

use App\Actions\Payroll\GeneratePayrollRun;
use App\Actions\Payroll\PayrollLineInputs;
use App\Events\PayrollRunApproved;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\Loan;
use App\Models\LoanSkip;
use App\Models\PayrollAdjustment;
use App\Models\PayrollItem;
use App\Models\PayrollRun;
use App\Models\User;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/Support/run-helpers.php';

/*
 * Payroll > Payroll Runs screens: the list, generating, the run page, the line drawer and every action behind them.
 * "Now" is 2026-12-05 12:00 in Karachi, attendance started 2026-11-01, late rule 3. The main person is design example 1
 * on real rows (net 64,930, loan 12,000 with installment 5,000). Only Admin / HR reach any route.
 */

beforeEach(function () {
    $this->withoutVite();
    $this->travelTo(CarbonImmutable::parse('2026-12-05 12:00:00', 'Asia/Karachi'));
    Settings::set('attendance', ['start_date' => '2026-11-01', 'late_rule_count' => 3]);
    Event::fake([PayrollRunApproved::class]);
    Mail::fake();
    $this->hr = userWithRole('hr');
    $this->admin = userWithRole('admin');
});

/** @return array{0: Employee, 1: PayrollRun} */
function rsSetUp(): array
{
    $e = prEmployee();
    prExample1($e);
    prBank($e);

    return [$e, prGenerate(test()->hr)];
}

function rsItemUrl(PayrollRun $run, PayrollItem $item, string $tail = ''): string
{
    return "/payroll/runs/{$run->id}/items/{$item->id}{$tail}";
}

// --- access ----------------------------------------------------------------------------------------------------

test('managers and employees get 403 on every run route, ids included', function (string $role) {
    [$e, $run] = rsSetUp();
    $item = prItem($run, $e);
    $loan = Loan::query()->sole();
    $adjustment = PayrollAdjustment::query()->create(['payroll_item_id' => $item->id, 'label' => 'Bonus', 'type' => 'addition', 'amount' => 100]);
    $user = userWithRole($role);
    employeeFor($user, ['joining_date' => '2025-01-01']);
    $before = PayrollRun::count();

    $this->actingAs($user)->get('/payroll/runs')->assertForbidden();
    $this->actingAs($user)->post('/payroll/runs', ['month' => '2026-11'])->assertForbidden();
    $this->actingAs($user)->get("/payroll/runs/{$run->id}")->assertForbidden();
    $this->actingAs($user)->post("/payroll/runs/{$run->id}/recalculate")->assertForbidden();
    $this->actingAs($user)->post("/payroll/runs/{$run->id}/approve", ['fingerprint' => $run->fingerprint()])->assertForbidden();
    $this->actingAs($user)->post("/payroll/runs/{$run->id}/paid", ['paid_on' => '2026-12-01'])->assertForbidden();
    $this->actingAs($user)->delete("/payroll/runs/{$run->id}")->assertForbidden();
    $this->actingAs($user)->get(rsItemUrl($run, $item))->assertForbidden();
    $this->actingAs($user)->post(rsItemUrl($run, $item, '/adjustments'), ['label' => 'X', 'type' => 'addition', 'amount' => 1])->assertForbidden();
    $this->actingAs($user)->delete(rsItemUrl($run, $item, "/adjustments/{$adjustment->id}"))->assertForbidden();
    $this->actingAs($user)->put(rsItemUrl($run, $item, '/tax'), ['amount' => 0])->assertForbidden();
    $this->actingAs($user)->put(rsItemUrl($run, $item, '/exclude'), ['excluded' => true])->assertForbidden();
    $this->actingAs($user)->put(rsItemUrl($run, $item, "/loans/{$loan->id}"), ['skipped' => true])->assertForbidden();

    expect(PayrollRun::count())->toBe($before)
        ->and(PayrollAdjustment::count())->toBe(1)
        ->and($run->fresh()->status)->toBe('draft');
})->with(['manager', 'employee']);

test('HR cannot approve, Admin can', function () {
    [, $run] = rsSetUp();
    $fingerprint = $run->fingerprint();

    $this->actingAs($this->hr)->post("/payroll/runs/{$run->id}/approve", ['fingerprint' => $fingerprint])->assertForbidden();
    expect($run->fresh()->status)->toBe('draft');

    $this->actingAs($this->admin)->post("/payroll/runs/{$run->id}/approve", ['fingerprint' => $fingerprint])
        ->assertRedirect()->assertSessionHas('success');
    expect($run->fresh()->status)->toBe('approved');
});

// --- the list and generating ---------------------------------------------------------------------------------------

test('the list shows runs newest first for HR, with the months Generate offers', function () {
    [, $run] = rsSetUp();
    $older = payrollRun('2026-10', 'approved', ['headcount' => 4, 'gross' => 40000000, 'net' => 36000000]);

    $this->actingAs($this->hr)->get('/payroll/runs')->assertInertia(fn (Assert $page) => $page
        ->component('payroll/runs/index')
        ->has('runs', 2)
        ->where('runs.0.month', '2026-11')
        ->where('runs.0.label', 'November 2026')
        ->where('runs.0.status', 'draft')
        ->where('runs.0.headcount', 1)
        ->where('runs.0.net', prRs(64930))
        ->where('runs.0.generated_by', $this->hr->name)
        ->where('runs.0.generated_on', '2026-12-05')
        ->where('runs.1.month', '2026-10')
        ->where('runs.1.status', 'approved')
        ->where('runs.1.gross', 40000000)
        // October is approved: the window starts at November, ends at the current month, default last month.
        ->where('generate.default', '2026-11')
        ->where('generate.months.0.value', '2026-12')
        ->where('generate.months.1.value', '2026-11')
        ->where('generate.months.1.has_draft', true)
        ->has('generate.months', 2));
});

test('Generate defaults to last month and offers twelve months when nothing is approved', function () {
    $this->actingAs($this->hr)->get('/payroll/runs')->assertInertia(fn (Assert $page) => $page
        ->where('runs', [])
        ->where('generate.default', '2026-11')
        ->has('generate.months', 12)
        ->where('generate.months.11.value', '2026-01'));
});

test('generating creates the run and redirects to it; a future or malformed month is refused', function () {
    $e = prEmployee();
    prExample1($e);

    $this->actingAs($this->hr)->post('/payroll/runs', ['month' => '2026-11'])
        ->assertRedirect('/payroll/runs/'.PayrollRun::query()->where('month', '2026-11')->firstOrFail()->id)
        ->assertSessionHas('success');
    expect(PayrollRun::count())->toBe(1)->and(PayrollItem::count())->toBe(1);

    $this->actingAs($this->hr)->post('/payroll/runs', ['month' => '2027-01'])->assertSessionHasErrors('month');
    $this->actingAs($this->hr)->post('/payroll/runs', ['month' => 'soon'])->assertSessionHasErrors('month');
    $this->actingAs($this->hr)->post('/payroll/runs', [])->assertSessionHasErrors('month');
    expect(PayrollRun::count())->toBe(1);
});

// --- the run page ----------------------------------------------------------------------------------------------

test('the run page shows blocking checks first with their people, totals and lines', function () {
    [$e, $run] = rsSetUp();
    $noProfile = prEmployee(['first_name' => 'Zed', 'last_name' => 'Nobody']);
    app(GeneratePayrollRun::class)->recalculate($this->hr, $run);
    $item = prItem($run, $e);

    $this->actingAs($this->admin)->get("/payroll/runs/{$run->id}")->assertInertia(fn (Assert $page) => $page
        ->component('payroll/runs/show')
        ->where('run.month', '2026-11')
        ->where('run.label', 'November 2026')
        ->where('run.status', 'draft')
        ->where('run.headcount', 2)
        ->where('run.gross', $run->fresh()->gross)
        ->where('run.fingerprint', $run->fresh()->fingerprint())
        ->where('run.can_edit', true)
        ->where('run.can_approve', true)
        ->where('run.can_pay', false)
        ->where('run.approves_own', false)
        ->where('run.payslip_count', 2)
        ->where('checks.0.severity', 'block')
        ->where('checks.0.key', 'no_profile')
        ->where('checks.0.people.0.name', $noProfile->full_name)
        ->has('lines', 2)
        ->where('lines.0.id', $item->id)
        ->where('lines.0.employee.name', $e->full_name)
        ->where('lines.0.employee.code', $e->employee_code)
        ->where('lines.0.days_paid', $item->days_paid)
        ->where('lines.0.gross', prRs(90000))
        ->where('lines.0.attendance_deduction', prRs(13500))
        ->where('lines.0.other_deductions', prRs(3000))
        ->where('lines.0.loan_deduction', prRs(5000))
        ->where('lines.0.tax', prRs(3570))
        ->where('lines.0.net', prRs(64930))
        ->where('lines.0.excluded', false)
        ->where('lines.0.can_edit', true)
        ->where('lines.0.payslip_url', null)
        ->where('lines.1.blocking', ['no_profile'])
        ->where('downloads.bank_sheet', null)
        ->where('downloads.summary', null));
});

test('the page of an HR user does not offer approval, and their own line is not editable', function () {
    $hrEmployee = employeeFor($this->hr, ['joining_date' => '2025-01-01']);
    salaryProfile($hrEmployee);
    prFill($hrEmployee);
    [$e, $run] = rsSetUp();
    app(GeneratePayrollRun::class)->recalculate($this->hr, $run);

    $this->actingAs($this->hr->fresh())->get("/payroll/runs/{$run->id}")->assertInertia(fn (Assert $page) => $page
        ->where('run.can_edit', true)
        ->where('run.can_approve', false)
        ->where('run.approves_own', true)
        ->has('lines', 2)
        ->where('lines', fn ($lines) => collect($lines)->firstWhere('employee.id', $hrEmployee->id)['can_edit'] === false
            && collect($lines)->firstWhere('employee.id', $e->id)['can_edit'] === true));
});

test('a draft offers no downloads, and counts the payslips that will be mailed', function () {
    [$e, $run] = rsSetUp();

    $this->actingAs($this->hr)->get("/payroll/runs/{$run->id}")->assertInertia(fn (Assert $page) => $page
        ->where('downloads.summary', null)
        ->where('downloads.bank_sheet', null)
        ->where('downloads.payslips', null)
        ->where('run.payslip_count', 1)
        ->where('run.emailed_count', 0));

    // An active login with an email counts; an inactive one does not.
    $login = User::factory()->create(['employee_id' => $e->id, 'is_active' => true]);
    $this->actingAs($this->hr)->get("/payroll/runs/{$run->id}")->assertInertia(fn (Assert $page) => $page->where('run.emailed_count', 1));
    $login->forceFill(['is_active' => false])->save();
    $this->actingAs($this->hr)->get("/payroll/runs/{$run->id}")->assertInertia(fn (Assert $page) => $page->where('run.emailed_count', 0));
});

test('an approved run shows downloads and payslip links, no edit controls, and the warnings it was approved with', function () {
    [$e, $run] = rsSetUp();
    $this->actingAs($this->admin)->post("/payroll/runs/{$run->id}/approve", ['fingerprint' => $run->fingerprint()])->assertRedirect();
    $item = prItem($run, $e);

    $this->actingAs($this->hr)->get("/payroll/runs/{$run->id}")->assertInertia(fn (Assert $page) => $page
        ->where('run.status', 'approved')
        ->where('run.approved_by', $this->admin->name)
        ->where('run.can_edit', false)
        ->where('run.can_approve', false)
        ->where('run.can_pay', true)
        ->where('lines.0.can_edit', false)
        ->where('lines.0.payslip_url', url("/payroll/payslips/{$item->id}"))
        ->where('downloads.bank_sheet', url("/payroll/runs/{$run->id}/bank-sheet"))
        ->where('downloads.summary', url("/payroll/runs/{$run->id}/summary"))
        ->where('downloads.payslips', url('/payroll/payslips?month=2026-11'))
        ->where('checks', $run->fresh()->summary['checks']));
});

test('the run page reads a fixed number of queries whatever the headcount', function (string $viewer) {
    [, $run] = rsSetUp();
    $count = function () use ($run, $viewer): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($this->{$viewer})->get("/payroll/runs/{$run->id}")->assertOk();
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        return count($log);
    };

    // The first request of a user also loads their roles and permissions; measure after that is cached.
    $count();

    $few = $count();

    foreach (range(1, 5) as $n) {
        $person = prEmployee();
        salaryProfile($person, 40000 + $n * 1000);
        prFill($person);
    }
    app(GeneratePayrollRun::class)->recalculate($this->hr, $run);

    expect(PayrollItem::count())->toBe(6)->and($count())->toBe($few);
})->with(['admin', 'hr']);

// --- run actions ---------------------------------------------------------------------------------------------------

test('recalculate refreshes all lines or one person', function () {
    [$e, $run] = rsSetUp();
    approvedLeave($e, '2026-11-19', null, null, 'Unpaid');
    $before = prItem($run, $e)->net;

    $this->actingAs($this->hr)->post("/payroll/runs/{$run->id}/recalculate", ['employee_id' => $e->id])
        ->assertRedirect()->assertSessionHas('success', 'Line recalculated.');
    expect(prItem($run, $e)->net)->toBeLessThan($before)->and(prItem($run, $e)->net)->toBe(prRs(62070));

    $this->actingAs($this->hr)->post("/payroll/runs/{$run->id}/recalculate")->assertSessionHas('success', 'Payroll recalculated.');
    $this->actingAs($this->hr)->post("/payroll/runs/{$run->id}/recalculate", ['employee_id' => 'x'])->assertSessionHasErrors('employee_id');
});

test('approve with a stale fingerprint reloads with the changed message and approves nothing', function () {
    [$e, $run] = rsSetUp();
    $reviewed = $run->fresh()->fingerprint();
    approvedLeave($e, '2026-11-19', null, null, 'Unpaid');

    $this->actingAs($this->admin)->from("/payroll/runs/{$run->id}")->post("/payroll/runs/{$run->id}/approve", ['fingerprint' => $reviewed])
        ->assertRedirect("/payroll/runs/{$run->id}")
        ->assertSessionHas('error', 'Figures changed for 1 person — review and approve again.');
    expect($run->fresh()->status)->toBe('draft');

    // With the page reloaded the new fingerprint approves.
    $this->actingAs($this->admin)->post("/payroll/runs/{$run->id}/approve", ['fingerprint' => $run->fresh()->fingerprint()])
        ->assertSessionHas('success');
    expect($run->fresh()->status)->toBe('approved');
});

test('a changed fingerprint with no changed line has its own message', function () {
    [, $run] = rsSetUp();

    $this->actingAs($this->admin)->from("/payroll/runs/{$run->id}")->post("/payroll/runs/{$run->id}/approve", ['fingerprint' => str_repeat('a', 64)])
        ->assertRedirect("/payroll/runs/{$run->id}")
        ->assertSessionHas('error', 'The figures changed since you opened this page — review and approve again.');
    expect($run->fresh()->status)->toBe('draft');
});

test('approve needs a fingerprint and is refused while a blocking check exists', function () {
    [$e, $run] = rsSetUp();

    $this->actingAs($this->admin)->post("/payroll/runs/{$run->id}/approve", [])->assertSessionHasErrors('fingerprint');

    Attendance::query()->where('employee_id', $e->id)->onDate('2026-11-12')->update(['needs_attention' => true]);
    $this->actingAs($this->admin)->post("/payroll/runs/{$run->id}/approve", ['fingerprint' => $run->fresh()->fingerprint()])
        ->assertSessionHasErrors('run');
    expect($run->fresh()->status)->toBe('draft');
});

test('mark paid sets the date once and only on an approved run', function () {
    [, $run] = rsSetUp();

    $this->actingAs($this->hr)->post("/payroll/runs/{$run->id}/paid", ['paid_on' => '2026-12-02'])->assertSessionHasErrors('run');

    $this->actingAs($this->admin)->post("/payroll/runs/{$run->id}/approve", ['fingerprint' => $run->fingerprint()]);

    $this->actingAs($this->hr)->post("/payroll/runs/{$run->id}/paid", ['paid_on' => '2026-10-31'])->assertSessionHasErrors('paid_on');
    $this->actingAs($this->hr)->post("/payroll/runs/{$run->id}/paid", [])->assertSessionHasErrors('paid_on');
    $this->actingAs($this->hr)->post("/payroll/runs/{$run->id}/paid", ['paid_on' => '2026-12-02'])
        ->assertRedirect()->assertSessionHas('success');

    expect($run->fresh()->status)->toBe('paid')->and($run->fresh()->paid_on->format('Y-m-d'))->toBe('2026-12-02');

    $this->actingAs($this->hr)->get("/payroll/runs/{$run->id}")->assertInertia(fn (Assert $page) => $page
        ->where('run.status', 'paid')->where('run.paid_on', '2026-12-02')->where('run.can_pay', false));
});

test('delete removes a draft and refuses an approved run', function () {
    [, $run] = rsSetUp();
    $approved = payrollRun('2026-10', 'approved');

    $this->actingAs($this->hr)->delete("/payroll/runs/{$approved->id}")->assertSessionHasErrors('run');
    expect(PayrollRun::query()->whereKey($approved->id)->exists())->toBeTrue();

    $this->actingAs($this->hr)->delete("/payroll/runs/{$run->id}")->assertRedirect('/payroll/runs')->assertSessionHas('success');
    expect(PayrollRun::query()->whereKey($run->id)->exists())->toBeFalse()->and(PayrollItem::count())->toBe(0);
});

// --- the line drawer and its inputs -------------------------------------------------------------------------------

test('the drawer JSON carries the stored breakdown, inputs and loans', function () {
    [$e, $run] = rsSetUp();
    $item = prItem($run, $e);
    $adjustment = PayrollAdjustment::query()->create(['payroll_item_id' => $item->id, 'label' => 'Bonus', 'type' => 'addition', 'amount' => prRs(1000), 'is_taxable' => true]);
    $loan = Loan::query()->sole();

    $json = $this->actingAs($this->hr)->getJson(rsItemUrl($run, $item))->assertOk()->json();

    expect($json['breakdown'])->toBe($item->fresh()->breakdown)
        ->and($json['figures']['net'])->toBe($item->net)
        ->and($json['employee']['name'])->toBe($e->full_name)
        ->and($json['adjustments'])->toBe([['id' => $adjustment->id, 'label' => 'Bonus', 'type' => 'addition', 'amount' => prRs(1000), 'is_taxable' => true]])
        ->and($json['can_edit'])->toBeTrue()
        ->and($json['loans'])->toBe([[
            'id' => $loan->id, 'type' => 'loan', 'installment' => prRs(5000), 'remaining' => prRs(12000),
            'deduction' => prRs(5000), 'skipped' => false, 'skip_reason' => null,
        ]]);
});

test('the drawer of an approved run is read-only and lists no loans to skip', function () {
    [$e, $run] = rsSetUp();
    $this->actingAs($this->admin)->post("/payroll/runs/{$run->id}/approve", ['fingerprint' => $run->fingerprint()]);
    $item = prItem($run, $e);

    $json = $this->actingAs($this->admin)->getJson(rsItemUrl($run, $item))->assertOk()->json();

    expect($json['can_edit'])->toBeFalse()->and($json['loans'])->toBe([])->and($json['status'])->toBe('approved');
});

test('a line, adjustment or loan of another run or person is 404', function () {
    [$e, $run] = rsSetUp();
    $item = prItem($run, $e);
    $other = prEmployee();
    salaryProfile($other);
    prFill($other);
    $otherRun = payrollRun('2026-10');
    $otherItem = PayrollItem::factory()->create(['payroll_run_id' => $otherRun->id, 'employee_id' => $other->id]);
    $otherAdjustment = PayrollAdjustment::query()->create(['payroll_item_id' => $otherItem->id, 'label' => 'Bonus', 'type' => 'addition', 'amount' => 100]);
    $otherLoan = loanFor($other, 6000, 1000, '2026-11');

    $this->actingAs($this->admin)->getJson(rsItemUrl($run, $otherItem))->assertNotFound();
    $this->actingAs($this->admin)->post(rsItemUrl($run, $otherItem, '/adjustments'), ['label' => 'Bonus', 'type' => 'addition', 'amount' => 1])->assertNotFound();
    $this->actingAs($this->admin)->delete(rsItemUrl($run, $item, "/adjustments/{$otherAdjustment->id}"))->assertNotFound();
    $this->actingAs($this->admin)->put(rsItemUrl($run, $otherItem, '/tax'), ['amount' => 0, 'reason' => 'Exemption.'])->assertNotFound();
    $this->actingAs($this->admin)->put(rsItemUrl($run, $otherItem, '/exclude'), ['excluded' => true, 'reason' => 'Paid elsewhere.'])->assertNotFound();
    $this->actingAs($this->admin)->put(rsItemUrl($run, $item, "/loans/{$otherLoan->id}"), ['skipped' => true, 'reason' => 'Short this month.'])->assertNotFound();
    // The loan is right for the line's person, but the line belongs to another run (the route has no scoped bindings).
    $ownLoan = loanFor($other, 7000, 1000, '2026-11');
    $this->actingAs($this->admin)->put(rsItemUrl($run, $otherItem, "/loans/{$ownLoan->id}"), ['skipped' => true, 'reason' => 'Short this month.'])->assertNotFound();

    expect(PayrollAdjustment::count())->toBe(1)->and(LoanSkip::count())->toBe(0);
});

test('a malformed body on a foreign line is 404, an unknown run is 404, and guests go to login', function () {
    [$e, $run] = rsSetUp();
    $item = prItem($run, $e);
    $otherRun = payrollRun('2026-10');
    $otherItem = PayrollItem::factory()->create(['payroll_run_id' => $otherRun->id, 'employee_id' => $e->id]);

    $this->actingAs($this->admin)->post(rsItemUrl($run, $otherItem, '/adjustments'), [])->assertNotFound();
    $this->actingAs($this->admin)->put(rsItemUrl($run, $otherItem, '/tax'), ['amount' => 'abc'])->assertNotFound();
    $this->actingAs($this->admin)->put(rsItemUrl($run, $otherItem, '/exclude'), [])->assertNotFound();
    $this->actingAs($this->admin)->get('/payroll/runs/999999')->assertNotFound();
    $this->actingAs($this->admin)->getJson('/payroll/runs/999999/items/'.$item->id)->assertNotFound();
    $this->actingAs($this->admin)->post('/payroll/runs/999999/recalculate')->assertNotFound();

    auth()->logout();
    $this->get('/payroll/runs')->assertRedirect('/login');
    $this->get("/payroll/runs/{$run->id}")->assertRedirect('/login');
    $this->post(rsItemUrl($run, $item, '/adjustments'), [])->assertRedirect('/login');
});

test('HR adds and removes a one-off item; tax follows an addition', function () {
    [$e, $run] = rsSetUp();
    $item = prItem($run, $e);
    $netBefore = $item->net;

    $this->actingAs($this->hr)->post(rsItemUrl($run, $item, '/adjustments'), ['label' => 'Bonus', 'type' => 'addition', 'amount' => '10,000', 'taxable' => true])
        ->assertRedirect()->assertSessionHas('success');
    $adjustment = PayrollAdjustment::query()->sole();
    // 10,000 taxable: tax +500 at 5%.
    expect($adjustment->amount)->toBe(prRs(10000))->and($adjustment->is_taxable)->toBeTrue()
        ->and(prItem($run, $e)->net)->toBe($netBefore + prRs(10000) - prRs(500))
        ->and($run->fresh()->net)->toBe($netBefore + prRs(10000) - prRs(500));

    $this->actingAs($this->hr)->delete(rsItemUrl($run, $item, "/adjustments/{$adjustment->id}"))->assertSessionHas('success');
    expect(PayrollAdjustment::count())->toBe(0)->and(prItem($run, $e)->net)->toBe($netBefore);

    $this->actingAs($this->hr)->post(rsItemUrl($run, $item, '/adjustments'), ['label' => 'Canteen', 'type' => 'deduction', 'amount' => 500, 'taxable' => true])->assertSessionHas('success');
    expect(PayrollAdjustment::query()->sole()->is_taxable)->toBeFalse()
        ->and(prItem($run, $e)->net)->toBe($netBefore - prRs(500));
});

test('adjustment input is validated', function () {
    [$e, $run] = rsSetUp();
    $item = prItem($run, $e);

    $this->actingAs($this->hr)->post(rsItemUrl($run, $item, '/adjustments'), ['label' => '', 'type' => 'gift', 'amount' => '1.5'])
        ->assertSessionHasErrors(['label', 'type', 'amount']);
    $this->actingAs($this->hr)->post(rsItemUrl($run, $item, '/adjustments'), ['label' => 'Bonus', 'type' => 'addition', 'amount' => 0])
        ->assertSessionHasErrors('amount');
    expect(PayrollAdjustment::count())->toBe(0);
});

test('HR overrides the tax with a reason and clears it', function () {
    [$e, $run] = rsSetUp();
    $item = prItem($run, $e);

    $this->actingAs($this->hr)->put(rsItemUrl($run, $item, '/tax'), ['amount' => '1,500', 'reason' => 'Exemption certificate on file.'])
        ->assertSessionHas('success', 'Tax set for this month.');
    expect(prItem($run, $e)->tax)->toBe(prRs(1500))->and(prItem($run, $e)->tax_overridden)->toBeTrue();

    $this->actingAs($this->hr)->put(rsItemUrl($run, $item, '/tax'), ['amount' => 500, 'reason' => 'no'])->assertSessionHasErrors('reason');

    $this->actingAs($this->hr)->put(rsItemUrl($run, $item, '/tax'), ['amount' => ''])->assertSessionHas('success', 'Tax override cleared.');
    expect(prItem($run, $e)->tax)->toBe(prRs(3570))->and(prItem($run, $e)->tax_overridden)->toBeFalse();
});

test('HR excludes a person with a reason and includes them again', function () {
    [$e, $run] = rsSetUp();
    $item = prItem($run, $e);

    $this->actingAs($this->hr)->put(rsItemUrl($run, $item, '/exclude'), ['excluded' => true, 'reason' => 'x'])->assertSessionHasErrors('reason');
    $this->actingAs($this->hr)->put(rsItemUrl($run, $item, '/exclude'), ['excluded' => true, 'reason' => 'On unpaid sabbatical.'])->assertSessionHas('success');
    expect(prItem($run, $e)->excluded)->toBeTrue()->and($run->fresh()->headcount)->toBe(0)->and($run->fresh()->net)->toBe(0);

    $this->actingAs($this->hr)->put(rsItemUrl($run, $item, '/exclude'), ['excluded' => false])->assertSessionHas('success');
    expect(prItem($run, $e)->excluded)->toBeFalse()->and($run->fresh()->net)->toBe(prRs(64930));
});

test('HR cannot edit their own line or an Admin\'s line; Admin can', function () {
    $hrEmployee = employeeFor($this->hr, ['joining_date' => '2025-01-01']);
    $adminEmployee = employeeFor($this->admin, ['joining_date' => '2025-01-01']);
    foreach ([$hrEmployee, $adminEmployee] as $person) {
        salaryProfile($person);
        prFill($person);
    }
    $run = prGenerate($this->admin);
    $hr = $this->hr->fresh();

    foreach ([$hrEmployee, $adminEmployee] as $person) {
        $item = prItem($run, $person);
        $this->actingAs($hr)->post(rsItemUrl($run, $item, '/adjustments'), ['label' => 'Bonus', 'type' => 'addition', 'amount' => 100])->assertForbidden();
        $this->actingAs($hr)->put(rsItemUrl($run, $item, '/tax'), ['amount' => 0, 'reason' => 'Exemption.'])->assertForbidden();
        $this->actingAs($hr)->put(rsItemUrl($run, $item, '/exclude'), ['excluded' => true, 'reason' => 'Paid elsewhere.'])->assertForbidden();
        $this->actingAs($hr)->getJson(rsItemUrl($run, $item))->assertOk()->assertJsonPath('can_edit', false);
    }
    expect(PayrollAdjustment::count())->toBe(0);

    $this->actingAs($this->admin->fresh())->post(rsItemUrl($run, prItem($run, $hrEmployee), '/adjustments'), ['label' => 'Bonus', 'type' => 'addition', 'amount' => 100])->assertSessionHas('success');
    expect(PayrollAdjustment::count())->toBe(1);
});

test('skipping a loan installment writes a loan skip and the line deduction becomes 0; undo restores it', function () {
    [$e, $run] = rsSetUp();
    $item = prItem($run, $e);
    $loan = Loan::query()->sole();
    expect($item->loan_deduction)->toBe(prRs(5000));

    $this->actingAs($this->hr)->put(rsItemUrl($run, $item, "/loans/{$loan->id}"), ['skipped' => true, 'reason' => ''])->assertSessionHasErrors('reason');

    $this->actingAs($this->hr)->put(rsItemUrl($run, $item, "/loans/{$loan->id}"), ['skipped' => true, 'reason' => 'Short this month.'])
        ->assertSessionHas('success');
    $skip = LoanSkip::query()->sole();
    expect($skip->loan_id)->toBe($loan->id)->and($skip->month)->toBe('2026-11')->and($skip->reason)->toBe('Short this month.')
        ->and(prItem($run, $e)->loan_deduction)->toBe(0)
        ->and(prItem($run, $e)->net)->toBe(prRs(69930))
        ->and($run->fresh()->net)->toBe(prRs(69930));

    $json = $this->actingAs($this->hr)->getJson(rsItemUrl($run, $item))->json('loans');
    expect($json[0]['skipped'])->toBeTrue()->and($json[0]['skip_reason'])->toBe('Short this month.')->and($json[0]['deduction'])->toBe(0);

    $this->actingAs($this->hr)->put(rsItemUrl($run, $item, "/loans/{$loan->id}"), ['skipped' => false])->assertSessionHas('success');
    expect(LoanSkip::count())->toBe(0)->and(prItem($run, $e)->loan_deduction)->toBe(prRs(5000))->and(prItem($run, $e)->net)->toBe(prRs(64930));
});

test('every write route is refused on an approved run and changes nothing', function () {
    [$e, $run] = rsSetUp();
    $item = prItem($run, $e);
    $adjustment = app(PayrollLineInputs::class)->addAdjustment($this->hr, $item, 'Bonus', 'addition', 1, true);
    $loan = Loan::query()->sole();
    $this->actingAs($this->admin)->post("/payroll/runs/{$run->id}/approve", ['fingerprint' => $run->fresh()->fingerprint()])->assertSessionHas('success');
    $before = PayrollItem::query()->orderBy('id')->get()->toArray();
    $net = $run->fresh()->net;

    $this->actingAs($this->admin)->post("/payroll/runs/{$run->id}/recalculate")->assertSessionHasErrors('run');
    $this->actingAs($this->admin)->post("/payroll/runs/{$run->id}/approve", ['fingerprint' => $run->fresh()->fingerprint()])->assertSessionHasErrors('run');
    $this->actingAs($this->admin)->delete("/payroll/runs/{$run->id}")->assertSessionHasErrors('run');
    $this->actingAs($this->admin)->post(rsItemUrl($run, $item, '/adjustments'), ['label' => 'More', 'type' => 'addition', 'amount' => 100])->assertSessionHasErrors('run');
    $this->actingAs($this->admin)->delete(rsItemUrl($run, $item, "/adjustments/{$adjustment->id}"))->assertSessionHasErrors('run');
    $this->actingAs($this->admin)->put(rsItemUrl($run, $item, '/tax'), ['amount' => 0, 'reason' => 'Exemption.'])->assertSessionHasErrors('run');
    $this->actingAs($this->admin)->put(rsItemUrl($run, $item, '/exclude'), ['excluded' => true, 'reason' => 'Paid elsewhere.'])->assertSessionHasErrors('run');
    $this->actingAs($this->admin)->put(rsItemUrl($run, $item, "/loans/{$loan->id}"), ['skipped' => true, 'reason' => 'Short this month.'])->assertSessionHasErrors('run');

    expect(PayrollItem::query()->orderBy('id')->get()->toArray())->toBe($before)
        ->and($run->fresh()->net)->toBe($net)
        ->and(PayrollAdjustment::count())->toBe(1)
        ->and(LoanSkip::count())->toBe(0)
        ->and(PayrollRun::query()->whereKey($run->id)->exists())->toBeTrue();
});

test('the pages carry no salary figure for anyone but Admin and HR', function () {
    [$e, $run] = rsSetUp();
    $manager = userWithRole('manager');
    employeeFor($manager, ['joining_date' => '2025-01-01']);

    $response = $this->actingAs($manager)->get("/payroll/runs/{$run->id}");

    $response->assertForbidden();
    expect($response->getContent())->not->toContain((string) prItem($run, $e)->net);
});
