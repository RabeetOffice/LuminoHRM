<?php

use App\Models\Employee;
use App\Models\FinalSettlement;
use App\Models\PayrollItem;
use App\Support\Payroll\PayrollRoster;
use App\Support\Payroll\TaxReport;
use Carbon\CarbonImmutable;

/*
| Approved / paid final settlements are counted by the tax report in their month and tax year, from the stored figures;
| a draft is not. The run page's settled-separately list shows the settlement status. "Now" is 5 Dec 2026 (tax year
| 2026-27, July to June).
*/

beforeEach(function () {
    $this->withoutVite();
    $this->travelTo(CarbonImmutable::parse('2026-12-05 12:00:00', 'Asia/Karachi'));
});

/** A settlement of $employee for $month with the given taxable pay and tax (rupees). */
function tsSettlement(Employee $employee, string $month, string $status, int $taxableRupees, int $taxRupees, ?int $grossTaxableRupees = null): FinalSettlement
{
    $exit = approvedExit($employee, "{$month}-20");

    return settlementRow($exit, [
        'status' => $status,
        'taxable_pay' => $taxableRupees * 100,
        'tax' => $taxRupees * 100,
        'breakdown' => [
            'tax' => ['gross_taxable_pay' => ($grossTaxableRupees ?? $taxableRupees) * 100],
            'settlement' => ['last_working_day' => "{$month}-20", 'exit_type' => 'resignation', 'encashment' => null, 'lines' => [], 'loans' => [], 'loan_shortfall' => 0, 'owed_by_employee' => 0],
        ],
    ]);
}

it('counts an approved settlement in its month and tax year with the gross taxable figure', function () {
    $e = Employee::factory()->create(['employee_code' => 'LD-0001']);
    tsSettlement($e, '2026-11', 'approved', 40_000, 2_000, 55_000);

    $report = TaxReport::for(2026);
    $row = collect($report['rows'])->firstWhere('code', 'LD-0001');

    expect($row['months']['2026-11'])->toBe(['taxable' => 5_500_000, 'tax' => 200_000])
        ->and($row['total_taxable'])->toBe(5_500_000)
        ->and($row['total_tax'])->toBe(200_000)
        ->and($report['totals']['months']['2026-11'])->toBe(['taxable' => 5_500_000, 'tax' => 200_000]);
});

it('counts a paid settlement and falls back to taxable_pay without the breakdown key', function () {
    $e = Employee::factory()->create(['employee_code' => 'LD-0002']);
    $s = tsSettlement($e, '2026-10', 'paid', 30_000, 1_500);
    $s->forceFill(['breakdown' => ['settlement' => $s->breakdown['settlement']]])->save();

    $row = collect(TaxReport::for(2026)['rows'])->firstWhere('code', 'LD-0002');

    expect($row['months']['2026-10'])->toBe(['taxable' => 3_000_000, 'tax' => 150_000]);
});

it('does not count a draft settlement', function () {
    $e = Employee::factory()->create(['employee_code' => 'LD-0003']);
    tsSettlement($e, '2026-11', 'draft', 40_000, 2_000);

    expect(collect(TaxReport::for(2026)['rows'])->firstWhere('code', 'LD-0003'))->toBeNull();
});

it('keeps a settlement of another tax year out and offers its year', function () {
    $e = Employee::factory()->create(['employee_code' => 'LD-0004']);
    tsSettlement($e, '2025-09', 'approved', 40_000, 2_000);

    expect(collect(TaxReport::for(2026)['rows'])->firstWhere('code', 'LD-0004'))->toBeNull()
        ->and(collect(TaxReport::for(2025)['rows'])->firstWhere('code', 'LD-0004')['months']['2025-09']['tax'])->toBe(200_000)
        ->and(TaxReport::years(2026))->toBe([2026, 2025]);
});

it('adds a settlement to a payroll line of the same month', function () {
    $e = Employee::factory()->create(['employee_code' => 'LD-0005']);
    $run = payrollRun('2026-11', 'approved');
    PayrollItem::factory()->create(['payroll_run_id' => $run->id, 'employee_id' => $e->id, 'taxable_pay' => 7_000_000, 'tax' => 300_000]);
    tsSettlement($e, '2026-11', 'approved', 10_000, 500);

    $row = collect(TaxReport::for(2026)['rows'])->firstWhere('code', 'LD-0005');

    expect($row['months']['2026-11'])->toBe(['taxable' => 8_000_000, 'tax' => 350_000]);
});

it('gives the roster each settled person with the status of their settlement', function () {
    $none = Employee::factory()->create(['joining_date' => '2025-01-01', 'last_working_day' => '2026-11-10', 'employee_code' => 'LD-0010']);
    $draft = Employee::factory()->create(['joining_date' => '2025-01-01', 'employee_code' => 'LD-0011']);
    $paid = Employee::factory()->create(['joining_date' => '2025-01-01', 'employee_code' => 'LD-0012']);
    tsSettlement($draft, '2026-11', 'draft', 0, 0);
    tsSettlement($paid, '2026-11', 'paid', 0, 0);

    $byCode = collect(PayrollRoster::forMonth('2026-11')->settledSeparately)->pluck('settlement', 'code')->all();

    expect($byCode)->toBe(['LD-0010' => null, 'LD-0011' => 'draft', 'LD-0012' => 'paid']);
});

it('shows the live settlement status in the run page list', function () {
    $this->actingAs(userWithRole('hr'));
    $e = Employee::factory()->create(['joining_date' => '2025-01-01', 'employee_code' => 'LD-0020']);
    $settlement = tsSettlement($e, '2026-11', 'draft', 0, 0);
    $run = payrollRun('2026-11', 'draft', ['summary' => ['settled_separately' => [
        ['id' => $e->id, 'name' => $e->full_name, 'code' => 'LD-0020', 'last_working_day' => '2026-11-20', 'settlement' => null],
    ]]]);

    $settlement->forceFill(['status' => 'approved'])->save();

    $this->get(route('payroll.runs.show', $run))
        ->assertInertia(fn ($page) => $page->where('settled_separately.0.settlement', 'approved'));
});
