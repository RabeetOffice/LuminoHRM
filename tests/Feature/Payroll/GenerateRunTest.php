<?php

use App\Actions\Payroll\DeleteDraftRun;
use App\Actions\Payroll\GeneratePayrollRun;
use App\Actions\Payroll\PayrollLineInputs;
use App\Models\Attendance;
use App\Models\LoanSkip;
use App\Models\PayrollAdjustment;
use App\Models\PayrollItem;
use App\Models\PayrollRun;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

require_once __DIR__.'/Support/run-helpers.php';

/*
 * Generating and recalculating a draft run. "Now" is 2026-12-05 12:00 in Karachi (current attendance month December),
 * attendance started 2026-11-01, late rule 3, calendar-days basis. Profile P = salaryProfile() defaults.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-12-05 12:00:00', 'Asia/Karachi'));
    Settings::set('attendance', ['start_date' => '2026-11-01', 'late_rule_count' => 3]);
    $this->hr = userWithRole('hr');
});

function grError(Closure $fn, string $field): string
{
    try {
        $fn();
    } catch (ValidationException $e) {
        return (string) ($e->errors()[$field][0] ?? '');
    }

    throw new RuntimeException('No validation error.');
}

it('generates November for a joiner, a leaver and a person with no profile', function () {
    $joiner = prEmployee(['joining_date' => '2026-11-16']);
    salaryProfile($joiner, from: '2026-11-16');
    $leaver = prEmployee(['last_working_day' => '2026-11-20', 'status' => 'resigned']);
    salaryProfile($leaver);
    $noProfile = prEmployee();

    $run = prGenerate($this->hr);

    expect($run->status)->toBe('draft')
        ->and($run->generated_by)->toBe($this->hr->id)
        ->and($run->items()->count())->toBe(2)
        ->and(prItem($run, $leaver))->toBeNull();

    $j = prItem($run, $joiner);
    expect($j->days_paid)->toBe(15)
        ->and($j->gross)->toBe(prRs(45000))
        ->and($j->net)->toBe(prRs(41400))
        ->and($j->blocking)->toBe([])
        ->and($j->breakdown['version'])->toBe(1);

    expect(prItem($run, $noProfile)->blocking)->toBe(['no_profile'])
        ->and(prItem($run, $noProfile)->net)->toBe(0);

    expect($run->summary['settled_separately'])->toHaveCount(1)
        ->and($run->summary['settled_separately'][0]['id'])->toBe($leaver->id)
        ->and($run->summary['left_without_last_day'])->toBe([])
        ->and($run->headcount)->toBe(2)
        ->and($run->net)->toBe(prRs(41400))
        ->and($run->calculated_at)->not->toBeNull();

    $audit = Activity::query()->where('log_name', 'payroll')->where('description', 'Payroll generated')->sole();
    expect($audit->subject_id)->toBe($run->id)
        ->and($audit->causer_id)->toBe($this->hr->id)
        ->and($audit->properties['headcount'])->toBe(2)
        ->and($audit->properties['net'])->toBe(prRs(41400))
        ->and($audit->properties['totals_formatted']['net'])->toBe('Rs 41,400');
});

it('nets 64,930 for design example 1 and keeps the run totals', function () {
    $e = prEmployee();
    prExample1($e);
    $joiner = prEmployee(['joining_date' => '2026-11-16']);
    salaryProfile($joiner, from: '2026-11-16');

    $run = prGenerate($this->hr);
    $line = prItem($run, $e);

    expect($line->gross)->toBe(prRs(90000))
        ->and($line->attendance_deduction)->toBe(prRs(13500))
        ->and($line->fixed_deductions)->toBe(prRs(3000))
        ->and($line->tax)->toBe(prRs(3570))
        ->and($line->loan_deduction)->toBe(prRs(5000))
        ->and($line->net)->toBe(prRs(64930));

    // Totals: gross 90,000 + 45,000; deductions 13,500 + 3,000 + 5,000 + 1,500; tax 3,570 + 2,100; net 64,930 + 41,400.
    expect($run->headcount)->toBe(2)
        ->and($run->gross)->toBe(prRs(135000))
        ->and($run->deductions)->toBe(prRs(23000))
        ->and($run->tax)->toBe(prRs(5670))
        ->and($run->net)->toBe(prRs(106330));
});

it('recalculates in place on a second generate and keeps an adjustment and a tax override', function () {
    $admin = userWithRole('admin');
    $e = prEmployee();
    salaryProfile($e);

    $run = prGenerate($this->hr);
    $item = prItem($run, $e);
    $inputs = app(PayrollLineInputs::class);
    $inputs->addAdjustment($admin, $item, 'Bonus', 'addition', 10000, true);
    $inputs->overrideTax($admin, $item, 1234, 'Certificate on file.');

    $again = prGenerate($this->hr);
    $after = prItem($again, $e);

    expect($again->id)->toBe($run->id)
        ->and($after->id)->toBe($item->id)
        ->and(PayrollItem::count())->toBe(1)
        ->and(PayrollAdjustment::count())->toBe(1)
        ->and($after->additions)->toBe(prRs(10000))
        ->and($after->tax_overridden)->toBeTrue()
        ->and($after->tax)->toBe(prRs(1234))
        // 100,000 - 3,000 provident fund - 1,234 tax.
        ->and($after->net)->toBe(prRs(95766));

    expect(Activity::where('description', 'Payroll generated')->count())->toBe(1)
        ->and(Activity::where('description', 'Payroll recalculated')->count())->toBe(1);
});

it('changes the line when an attendance row is corrected and the run is recalculated', function () {
    $e = prEmployee();
    salaryProfile($e);
    $absent = prDay($e, '2026-11-03', 'absent');
    prFill($e);

    $run = prGenerate($this->hr);
    // R(90,000 x 2, 60) = 3,000; taxable share 2,800; tax R(81,200 x 500, 10,000) = 4,060.
    expect(prItem($run, $e)->net)->toBe(prRs(90000 - 3000 - 3000 - 4060));

    $absent->forceFill(['status' => 'present'])->save();
    $run = app(GeneratePayrollRun::class)->recalculate($this->hr, $run);

    expect(prItem($run, $e)->attendance_deduction)->toBe(0)
        ->and(prItem($run, $e)->net)->toBe(prRs(90000 - 3000 - 4200))
        ->and($run->net)->toBe(prRs(82800));
});

it('recalculates one line only', function () {
    $a = prEmployee();
    salaryProfile($a);
    $b = prEmployee();
    salaryProfile($b);
    $run = prGenerate($this->hr);

    $loan = loanFor($a, 10000, 2000, '2026-11');
    $loanB = loanFor($b, 10000, 2000, '2026-11');
    app(GeneratePayrollRun::class)->recalculate($this->hr, $run, $a->id);

    expect(prItem($run, $a)->loan_deduction)->toBe(prRs(2000))
        ->and(prItem($run, $b)->loan_deduction)->toBe(0)
        ->and($run->fresh()->deductions)->toBe(prRs(3000 + 3000 + 2000));

    LoanSkip::query()->create(['loan_id' => $loan->id, 'month' => '2026-11', 'reason' => 'Family wedding.']);
    app(GeneratePayrollRun::class)->recalculate($this->hr, $run, $a->id);

    expect(prItem($run, $a)->loan_deduction)->toBe(0)
        ->and($loanB->id)->toBeInt();
});

it('generates December while November is approved and refuses October then', function () {
    $e = prEmployee();
    salaryProfile($e);
    payrollRun('2026-11', 'approved');

    $dec = prGenerate($this->hr, '2026-12');
    expect($dec->month)->toBe('2026-12')->and($dec->items()->count())->toBe(1);

    expect(grError(fn () => prGenerate($this->hr, '2026-10'), 'month'))->toBe('Payroll for November 2026 is already approved.')
        ->and(grError(fn () => prGenerate($this->hr, '2026-11'), 'month'))->toBe('Payroll for November 2026 is already approved.')
        ->and(PayrollRun::where('month', '2026-10')->exists())->toBeFalse();
});

it('refuses a month after the current one and a malformed month', function () {
    expect(grError(fn () => prGenerate($this->hr, '2027-01'), 'month'))->toBe('You can generate payroll up to December 2026.')
        ->and(grError(fn () => prGenerate($this->hr, '2026-13'), 'month'))->toBe('Choose a month.')
        ->and(grError(fn () => prGenerate($this->hr, 'November'), 'month'))->toBe('Choose a month.')
        ->and(PayrollRun::count())->toBe(0);
});

it('allows a preview of the current month', function () {
    $e = prEmployee();
    salaryProfile($e);

    expect(prGenerate($this->hr, '2026-12')->items()->count())->toBe(1);
});

it('removes a person who became a leaver without a last working day, with their adjustments', function () {
    $admin = userWithRole('admin');
    $stay = prEmployee();
    salaryProfile($stay);
    $go = prEmployee();
    salaryProfile($go);
    $run = prGenerate($this->hr);
    app(PayrollLineInputs::class)->addAdjustment($admin, prItem($run, $go), 'Bonus', 'addition', 500, true);

    $go->forceFill(['status' => 'resigned'])->save();
    $run = app(GeneratePayrollRun::class)->recalculate($this->hr, $run);

    expect(prItem($run, $go))->toBeNull()
        ->and(PayrollAdjustment::count())->toBe(0)
        ->and($run->headcount)->toBe(1)
        ->and($run->summary['left_without_last_day'][0]['id'])->toBe($go->id);

    $audit = Activity::where('description', 'Payroll recalculated')->latest('id')->first();
    expect($audit->properties['removed'])->toBe([['id' => $go->id, 'name' => $go->full_name]]);
});

it('adds a joiner on recalculation', function () {
    $a = prEmployee();
    salaryProfile($a);
    $run = prGenerate($this->hr);

    $b = prEmployee(['joining_date' => '2026-11-16']);
    salaryProfile($b, from: '2026-11-16');
    $run = app(GeneratePayrollRun::class)->recalculate($this->hr, $run);

    expect($run->headcount)->toBe(2)->and(prItem($run, $b)->net)->toBe(prRs(41400));
});

it('refuses recalculating an approved run', function () {
    $e = prEmployee();
    salaryProfile($e);
    $run = prGenerate($this->hr);
    $run->update(['status' => 'approved', 'approved_at' => now()]);

    expect(grError(fn () => app(GeneratePayrollRun::class)->recalculate($this->hr, $run), 'run'))
        ->toBe('This payroll is approved and cannot change.')
        ->and(grError(fn () => app(GeneratePayrollRun::class)->recalculate($this->hr, $run, $e->id), 'run'))
        ->toBe('This payroll is approved and cannot change.');
});

it('refuses someone without payroll.run', function () {
    $employee = userWithRole('employee');

    expect(fn () => prGenerate($employee))->toThrow(AuthorizationException::class)
        ->and(fn () => app(GeneratePayrollRun::class)->recalculate($employee, payrollRun('2026-11')))->toThrow(AuthorizationException::class);
});

it('deletes a draft with its lines and adjustments and refuses an approved run', function () {
    $admin = userWithRole('admin');
    $e = prEmployee();
    salaryProfile($e);
    $run = prGenerate($this->hr);
    app(PayrollLineInputs::class)->addAdjustment($admin, prItem($run, $e), 'Bonus', 'addition', 500, true);

    app(DeleteDraftRun::class)->handle($this->hr, $run);

    expect(PayrollRun::count())->toBe(0)
        ->and(PayrollItem::count())->toBe(0)
        ->and(PayrollAdjustment::count())->toBe(0);
    $audit = Activity::where('description', 'Payroll draft deleted')->sole();
    expect($audit->properties['month'])->toBe('2026-11')
        ->and($audit->properties['headcount'])->toBe(1);

    $approved = payrollRun('2026-10', 'approved');
    expect(grError(fn () => app(DeleteDraftRun::class)->handle($this->hr, $approved), 'run'))
        ->toBe('This payroll is approved and cannot be deleted.')
        ->and(PayrollRun::whereKey($approved->id)->exists())->toBeTrue()
        ->and(fn () => app(DeleteDraftRun::class)->handle(userWithRole('employee'), $approved))->toThrow(AuthorizationException::class);
});

it('stores the calculation of an excluded line but pays nothing', function () {
    $admin = userWithRole('admin');
    $e = prEmployee();
    salaryProfile($e);
    $run = prGenerate($this->hr);

    app(PayrollLineInputs::class)->exclude($admin, prItem($run, $e), 'Paid by the sister company.');
    $run = prGenerate($this->hr);
    $line = prItem($run, $e);

    expect($line->excluded)->toBeTrue()
        ->and($line->net)->toBe(0)
        ->and($line->gross)->toBe(0)
        ->and($line->blocking)->toBe([])
        ->and($line->breakdown['totals']['net'])->toBe(prRs(90000 - 3000 - 4200))
        ->and($run->headcount)->toBe(0)
        ->and($run->net)->toBe(0);
});

it('keeps attendance rows untouched', function () {
    $e = prEmployee();
    salaryProfile($e);
    prFill($e);
    $before = Attendance::query()->orderBy('id')->get()->toArray();

    prGenerate($this->hr);

    expect(Attendance::query()->orderBy('id')->get()->toArray())->toBe($before);
});

it('locks an existing draft without inserting and inserts a month that has no run, the later months share-locked after', function () {
    $e = prEmployee();
    salaryProfile($e);
    payrollRun('2026-12');

    $sql = [];
    DB::listen(function ($query) use (&$sql) {
        $sql[] = $query->sql;
    });

    // The transaction's statements: from the employee share lock on (the permission and pre-checks come before it).
    $inTransaction = fn (array $sql): array => array_values(array_slice($sql, (int) collect($sql)
        ->search(fn (string $q): bool => str_starts_with($q, 'select "id" from "employees"'))));

    // First generation of November: no row yet, so it is inserted and then locked.
    $run = prGenerate($this->hr);
    $first = $inTransaction($sql);
    expect($first[0])->toStartWith('select "id" from "employees"')
        ->and($first[1])->toStartWith('select * from "payroll_runs" where "month" = ?')
        ->and($first[2])->toStartWith('insert or ignore into "payroll_runs"')
        ->and($first[3])->toStartWith('select * from "payroll_runs" where "month" = ?')
        ->and($first[4])->toStartWith('select "status", "month" from "payroll_runs" where "month" > ?');

    // Generating it again: the existing row is locked straight away, no INSERT IGNORE on a duplicate.
    $sql = [];
    $again = prGenerate($this->hr);
    $sql = $inTransaction($sql);
    expect($sql[0])->toStartWith('select "id" from "employees"')
        ->and($sql[1])->toStartWith('select * from "payroll_runs" where "month" = ?')
        ->and($sql[2])->toStartWith('select "status", "month" from "payroll_runs" where "month" > ?')
        ->and(collect($sql)->filter(fn (string $q): bool => str_starts_with($q, 'insert or ignore'))->all())->toBe([])
        ->and($again->id)->toBe($run->id)
        ->and(PayrollRun::query()->where('month', '2026-11')->count())->toBe(1)
        ->and(Activity::where('description', 'Payroll generated')->count())->toBe(1)
        ->and(Activity::where('description', 'Payroll recalculated')->count())->toBe(1);
});

it('writes nothing when one person who is not in the roster and has no line is recalculated', function () {
    $e = prEmployee();
    salaryProfile($e);
    $gone = prEmployee(['last_working_day' => '2026-10-20', 'status' => 'resigned']);
    $run = prGenerate($this->hr);
    $calculatedAt = $run->fresh()->calculated_at?->toDateTimeString();
    $entries = Activity::count();

    $this->travel(5)->minutes();
    $result = app(GeneratePayrollRun::class)->recalculate($this->hr, $run, $gone->id);

    expect($result->id)->toBe($run->id)
        ->and(Activity::count())->toBe($entries)
        ->and($run->fresh()->calculated_at?->toDateTimeString())->toBe($calculatedAt)
        ->and($run->items()->count())->toBe(1);
});
