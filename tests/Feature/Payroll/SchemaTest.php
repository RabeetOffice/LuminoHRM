<?php

use App\Models\Employee;
use App\Models\Loan;
use App\Models\LoanRepayment;
use App\Models\LoanSkip;
use App\Models\OpeningBalance;
use App\Models\PayrollAdjustment;
use App\Models\PayrollItem;
use App\Models\SalaryComponent;
use App\Models\SalaryProfile;
use App\Support\Audit\Audit;
use App\Support\Settings;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

const PS_MIGRATION = '2026_11_26_100000_create_payroll_tables.php';

const PS_TABLES = [
    'salary_profiles' => ['id', 'employee_id', 'effective_from', 'basic', 'tax_mode', 'tax_value', 'tax_base', 'note', 'created_by', 'created_at', 'updated_at'],
    'salary_components' => ['id', 'salary_profile_id', 'name', 'type', 'amount', 'is_taxable', 'sort_order', 'created_at', 'updated_at'],
    'loans' => [
        'id', 'employee_id', 'type', 'amount', 'installment', 'start_month', 'opening_repaid', 'status', 'note', 'created_by',
        'cancelled_by', 'cancelled_at', 'cancel_reason', 'created_at', 'updated_at',
    ],
    'loan_repayments' => ['id', 'loan_id', 'payroll_item_id', 'amount', 'month', 'note', 'created_by', 'created_at', 'updated_at'],
    'loan_skips' => ['id', 'loan_id', 'month', 'reason', 'created_by', 'created_at', 'updated_at'],
    'opening_balances' => ['id', 'employee_id', 'tax_year', 'tax_deducted', 'taxable_income', 'note', 'created_at', 'updated_at'],
    'payroll_runs' => [
        'id', 'month', 'status', 'headcount', 'gross', 'deductions', 'tax', 'net', 'generated_by', 'generated_at',
        'approved_by', 'approved_at', 'paid_by', 'paid_at', 'paid_on', 'summary', 'calculated_at', 'created_at', 'updated_at',
    ],
    'payroll_items' => [
        'id', 'payroll_run_id', 'employee_id', 'excluded', 'excluded_reason', 'days_paid', 'basic', 'allowances', 'overtime',
        'additions', 'gross', 'attendance_deduction', 'fixed_deductions', 'other_deductions', 'loan_deduction', 'taxable_pay',
        'tax', 'net', 'tax_overridden', 'tax_override', 'tax_override_reason', 'breakdown', 'warnings', 'blocking',
        'bank_name', 'account_title', 'account_number', 'payslip_path', 'payslip_generated_at', 'payslip_emailed_at',
        'created_at', 'updated_at',
    ],
    'payroll_adjustments' => ['id', 'payroll_item_id', 'label', 'type', 'amount', 'is_taxable', 'created_by', 'created_at', 'updated_at'],
];

// ---------------------------------------------------------------- schema

it('creates the nine payroll tables with the planned columns', function () {
    foreach (PS_TABLES as $table => $columns) {
        expect(Schema::getColumnListing($table))->toEqualCanonicalizing($columns);
    }
});

it('rolls the migration back and forward again', function () {
    $migration = require database_path('migrations/'.PS_MIGRATION);

    $migration->down();
    foreach (array_keys(PS_TABLES) as $table) {
        expect(Schema::hasTable($table))->toBeFalse();
    }

    $migration->up();
    foreach (array_keys(PS_TABLES) as $table) {
        expect(Schema::hasTable($table))->toBeTrue();
    }
});

it('keeps money as integers in paisa, months as Y-m and the profile date as Y-m-d', function () {
    $employee = Employee::factory()->create();
    $profile = salaryProfile($employee, from: '2026-11-16');
    $fresh = SalaryProfile::query()->with('components')->find($profile->id);

    expect($fresh->basic)->toBe(6_000_000)
        ->and($fresh->effective_from->format('Y-m-d'))->toBe('2026-11-16')
        ->and($fresh->toArray()['effective_from'])->toBe('2026-11-16')
        ->and(SalaryProfile::query()->onDate('2026-11-16')->pluck('id')->all())->toBe([$profile->id])
        ->and(SalaryProfile::query()->betweenDates('2026-11-01', '2026-11-30')->count())->toBe(1)
        ->and($fresh->components->pluck('amount')->all())->toBe([2_400_000, 600_000, 300_000])
        ->and($fresh->gross())->toBe(9_000_000)
        ->and($fresh->taxableGross())->toBe(8_400_000)
        ->and($fresh->allowances()->pluck('name')->all())->toBe(['House rent', 'Medical'])
        ->and($fresh->deductions()->pluck('name')->all())->toBe(['Provident fund'])
        ->and($fresh->tax_value)->toBe(500);
});

it('stores a negative net on a draft line (it blocks approval) and reads JSON columns back', function () {
    $item = PayrollItem::factory()->create(['net' => -150_000, 'breakdown' => ['version' => 1], 'warnings' => [['key' => 'x', 'message' => 'y']]]);
    $fresh = PayrollItem::query()->find($item->id);

    expect($fresh->net)->toBe(-150_000)
        ->and($fresh->breakdown)->toBe(['version' => 1])
        ->and($fresh->warnings)->toBe([['key' => 'x', 'message' => 'y']])
        ->and($fresh->blocking)->toBe([])
        ->and($fresh->excluded)->toBeFalse();
});

// ---------------------------------------------------------------- unique keys

it('allows one profile per employee and effective date', function () {
    $employee = Employee::factory()->create();
    salaryProfile($employee, from: '2026-11-01');
    salaryProfile(Employee::factory()->create(), from: '2026-11-01'); // another person: fine
    salaryProfile($employee, from: '2026-12-01'); // another date: fine

    expect(fn () => SalaryProfile::factory()->create(['employee_id' => $employee->id, 'effective_from' => '2026-11-01']))
        ->toThrow(QueryException::class);
});

it('allows one run per month', function () {
    payrollRun('2026-11');

    expect(fn () => payrollRun('2026-11', 'approved'))->toThrow(QueryException::class);
});

it('allows one line per run and employee', function () {
    $run = payrollRun('2026-11');
    $employee = Employee::factory()->create();
    PayrollItem::factory()->create(['payroll_run_id' => $run->id, 'employee_id' => $employee->id]);
    PayrollItem::factory()->create(['payroll_run_id' => payrollRun('2026-12')->id, 'employee_id' => $employee->id]);

    expect(fn () => PayrollItem::factory()->create(['payroll_run_id' => $run->id, 'employee_id' => $employee->id]))
        ->toThrow(QueryException::class);
});

it('allows one skip per loan and month', function () {
    $loan = Loan::factory()->create();
    LoanSkip::factory()->create(['loan_id' => $loan->id, 'month' => '2026-11']);
    LoanSkip::factory()->create(['loan_id' => $loan->id, 'month' => '2026-12']);

    expect(fn () => LoanSkip::factory()->create(['loan_id' => $loan->id, 'month' => '2026-11']))
        ->toThrow(QueryException::class);
});

it('allows one opening balance per employee and tax year', function () {
    $employee = Employee::factory()->create();
    OpeningBalance::factory()->create(['employee_id' => $employee->id, 'tax_year' => 2026]);
    OpeningBalance::factory()->create(['employee_id' => $employee->id, 'tax_year' => 2025]);

    expect(fn () => OpeningBalance::factory()->create(['employee_id' => $employee->id, 'tax_year' => 2026]))
        ->toThrow(QueryException::class);
});

it('allows one repayment per loan and payroll line, and any number of manual ones', function () {
    $loan = Loan::factory()->create();
    $item = PayrollItem::factory()->create(['employee_id' => $loan->employee_id]);
    LoanRepayment::factory()->create(['loan_id' => $loan->id, 'payroll_item_id' => $item->id]);
    LoanRepayment::factory()->count(2)->create(['loan_id' => $loan->id, 'payroll_item_id' => null]);

    expect(LoanRepayment::query()->count())->toBe(3)
        ->and(fn () => LoanRepayment::factory()->create(['loan_id' => $loan->id, 'payroll_item_id' => $item->id]))
        ->toThrow(QueryException::class);
});

// ---------------------------------------------------------------- foreign keys

it('deletes a draft run with its lines and their adjustments', function () {
    $run = payrollRun('2026-11');
    $item = PayrollItem::factory()->create(['payroll_run_id' => $run->id]);
    PayrollAdjustment::factory()->create(['payroll_item_id' => $item->id]);

    $run->delete();

    expect(PayrollItem::query()->count())->toBe(0)->and(PayrollAdjustment::query()->count())->toBe(0);
});

it('deletes a profile with its components', function () {
    $profile = salaryProfile(Employee::factory()->create());

    $profile->delete();

    expect(SalaryComponent::query()->count())->toBe(0);
});

it('refuses at the database to delete an employee with payroll rows', function () {
    $employee = Employee::factory()->create();
    salaryProfile($employee);

    expect(fn () => $employee->delete())->toThrow(QueryException::class);
});

// ---------------------------------------------------------------- models

it('derives what remains of a loan from the opening repaid figure and the repayments', function () {
    $loan = loanFor(Employee::factory()->create(), 50000, 5000, '2026-11', openingRepaidRupees: 38000);
    LoanRepayment::factory()->create(['loan_id' => $loan->id, 'amount' => 500_000, 'month' => '2026-11']);

    expect($loan->fresh()->remaining())->toBe(700_000)
        ->and($loan->fresh()->isCleared())->toBeFalse();

    $listed = Loan::query()->withRemaining()->find($loan->id);
    expect($listed->getAttributes())->toHaveKey('repayments_sum_amount')
        ->and($listed->remaining())->toBe(700_000);

    $loaded = Loan::query()->with('repayments')->find($loan->id);
    expect($loaded->remaining())->toBe(700_000);

    LoanRepayment::factory()->create(['loan_id' => $loan->id, 'amount' => 700_000, 'month' => '2026-12']);
    expect($loan->fresh()->remaining())->toBe(0)
        ->and($loan->fresh()->isCleared())->toBeTrue()
        ->and(Loan::query()->withRemaining()->find($loan->id)->remaining())->toBe(0);

    $loan->forceFill(['status' => 'cancelled'])->save();
    expect($loan->fresh()->isCleared())->toBeFalse();
});

it('gives a run its label and lock state', function (string $status, bool $locked, bool $draft) {
    $run = payrollRun('2026-11', $status);

    expect($run->label())->toBe('November 2026')
        ->and($run->isLocked())->toBe($locked)
        ->and($run->isDraft())->toBe($draft);
})->with([
    ['draft', false, true],
    ['approved', true, false],
    ['paid', true, false],
]);

it('relates lines to their run, employee, adjustments and repayments, and the employee to payroll rows', function () {
    $employee = Employee::factory()->create();
    $run = payrollRun('2026-11', 'approved');
    $item = PayrollItem::factory()->create(['payroll_run_id' => $run->id, 'employee_id' => $employee->id]);
    PayrollAdjustment::factory()->create(['payroll_item_id' => $item->id]);
    $loan = loanFor($employee, 10000, 5000, '2026-11');
    LoanRepayment::factory()->create(['loan_id' => $loan->id, 'payroll_item_id' => $item->id]);
    OpeningBalance::factory()->create(['employee_id' => $employee->id]);
    salaryProfile($employee);

    expect($item->run->is($run))->toBeTrue()
        ->and($item->employee->is($employee))->toBeTrue()
        ->and($item->adjustments)->toHaveCount(1)
        ->and($item->repayments)->toHaveCount(1)
        ->and($run->items)->toHaveCount(1)
        ->and($employee->salaryProfiles)->toHaveCount(1)
        ->and($employee->loans)->toHaveCount(1)
        ->and($employee->payrollItems)->toHaveCount(1)
        ->and($employee->openingBalances)->toHaveCount(1);
});

it('refuses unknown statuses and types', function (Closure $save) {
    $save();
})->throws(InvalidArgumentException::class)->with([
    'run status' => [fn () => payrollRun('2026-11', 'locked')],
    'run month' => [fn () => payrollRun('2026-13')],
    'loan type' => [fn () => Loan::factory()->create(['type' => 'gift'])],
    'loan status' => [fn () => Loan::factory()->create(['status' => 'cleared'])],
    'loan month' => [fn () => Loan::factory()->create(['start_month' => '2026-11-01'])],
    'tax mode' => [fn () => SalaryProfile::factory()->create(['tax_mode' => 'slab'])],
    'tax base' => [fn () => SalaryProfile::factory()->create(['tax_mode' => 'percentage', 'tax_base' => 'net'])],
    'component type' => [fn () => SalaryComponent::factory()->create(['type' => 'bonus'])],
    'adjustment type' => [fn () => PayrollAdjustment::factory()->create(['type' => 'bonus'])],
]);

it('never makes a fixed deduction or a one-off deduction taxable', function () {
    $component = SalaryComponent::factory()->deduction()->create(['is_taxable' => true]);
    $adjustment = PayrollAdjustment::factory()->create(['type' => 'deduction', 'is_taxable' => true]);

    expect($component->fresh()->is_taxable)->toBeFalse()->and($adjustment->fresh()->is_taxable)->toBeFalse();
});

// ---------------------------------------------------------------- config, settings, audit

it('declares the payroll values, the new setting defaults and the payroll audit area', function () {
    expect(config('hrm.payroll.run_statuses'))->toBe(['draft', 'approved', 'paid'])
        ->and(config('hrm.payroll.locked_statuses'))->toBe(['approved', 'paid'])
        ->and(config('hrm.payroll.tax_modes'))->toBe(['none', 'fixed', 'percentage'])
        ->and(config('hrm.payroll.tax_bases'))->toBe(['gross_taxable', 'basic'])
        ->and(config('hrm.payroll.component_types'))->toBe(['allowance', 'deduction'])
        ->and(config('hrm.payroll.loan_types'))->toBe(['loan', 'advance'])
        ->and(config('hrm.payroll.loan_statuses'))->toBe(['active', 'cancelled'])
        ->and(config('hrm.payroll.adjustment_types'))->toBe(['addition', 'deduction'])
        ->and(config('hrm.payroll.max_components'))->toBe(20)
        ->and(Settings::get('payroll.tax_year_start_month'))->toBe(7)
        ->and(Settings::get('payroll.day_pay_basis'))->toBe('calendar_days')
        ->and(Settings::get('attendance.overtime_rate_mode'))->toBe('multiple')
        ->and(Settings::get('attendance.overtime_multiplier_percent'))->toBe(150)
        ->and(Settings::get('attendance.overtime_fixed_rate'))->toBe(0)
        ->and(Settings::get('attendance.overtime_hours_per_day'))->toBe(8)
        ->and(Settings::get('attendance.overtime_enabled'))->toBeFalse()
        ->and(Audit::AREAS)->toHaveKey('payroll', 'Payroll');
});
