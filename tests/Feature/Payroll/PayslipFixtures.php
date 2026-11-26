<?php

use App\Models\Employee;
use App\Models\PayrollItem;
use App\Models\PayrollRun;
use App\Models\User;

/*
| Fixtures for the payslip tests (PayslipPdfTest, PayslipMailTest, PayslipAccessTest): an approved November 2026 run
| with the company snapshot Task 7 stores at approval, and lines carrying the plan's example breakdown (version 1,
| net Rs 64,930). Loaded with require_once by each of those files.
*/

/** A run of $month with the company snapshot (name, address, logo_path, footer) taken at approval. */
function psRun(string $month = '2026-11', string $status = 'approved', array $company = []): PayrollRun
{
    return payrollRun($month, $status, ['summary' => ['company' => [
        'name' => 'Lumino Digital',
        'address' => "12 Main Boulevard\nLahore",
        'logo_path' => null,
        'footer' => 'This payslip is computer generated and needs no signature.',
        ...$company,
    ]]]);
}

/** The plan's example breakdown for $employee (code, name and department frozen into it). */
function psBreakdown(Employee $employee, string $month = '2026-11', array $overrides = []): array
{
    return array_replace_recursive([
        'version' => 1,
        'employee' => [
            'id' => $employee->id, 'code' => $employee->employee_code, 'name' => $employee->full_name,
            'department' => 'HR', 'designation' => 'Officer', 'joining_date' => '2024-03-01',
        ],
        'period' => ['month' => $month, 'from' => $month.'-01', 'to' => $month.'-30', 'days_in_month' => 30, 'days_paid' => 30, 'basis' => 'calendar_days', 'basis_days' => 30],
        'segments' => [['profile_id' => 3, 'from' => $month.'-01', 'to' => $month.'-30', 'days' => 30, 'gross' => 9000000, 'taxable_gross' => 8400000, 'basic' => 6000000]],
        'earnings' => [
            ['key' => 'basic', 'label' => 'Basic salary', 'amount' => 6000000, 'taxable' => true],
            ['key' => 'allowance', 'label' => 'House rent', 'amount' => 2400000, 'taxable' => true],
            ['key' => 'allowance', 'label' => 'Medical', 'amount' => 600000, 'taxable' => false],
        ],
        'deductions' => [
            ['key' => 'attendance', 'label' => 'Attendance (4.5 days)', 'amount' => 1350000, 'taxable' => false],
            ['key' => 'fixed', 'label' => 'Provident fund', 'amount' => 300000, 'taxable' => false],
            ['key' => 'loan', 'label' => 'Loan installment', 'amount' => 500000, 'taxable' => false, 'loan_id' => 4, 'due' => 500000],
        ],
        'tax' => ['mode' => 'percentage', 'base' => 'gross_taxable', 'rate_bp' => 500, 'taxable_pay' => 7140000, 'amount' => 357000, 'overridden' => false, 'override_reason' => null],
        'attendance' => ['counted_from' => $month.'-01', 'present' => 20, 'late' => 5, 'half_day' => 1, 'absent' => 2, 'on_leave' => 1, 'unpaid_leave' => 1, 'paid_leave' => 0, 'off' => 8, 'late_days_deducted' => 1, 'deducted_halves' => 9, 'overtime_minutes' => 0],
        'totals' => ['gross' => 9000000, 'attendance_deduction' => 1350000, 'fixed_deductions' => 300000, 'other_deductions' => 0, 'loan_deduction' => 500000, 'tax' => 357000, 'net' => 6493000],
        'warnings' => [],
    ], $overrides);
}

/** A line of $run for $employee with the example figures, breakdown and a bank snapshot. */
function psItem(PayrollRun $run, Employee $employee, array $attrs = []): PayrollItem
{
    return PayrollItem::query()->create([
        'payroll_run_id' => $run->id,
        'employee_id' => $employee->id,
        'days_paid' => 30,
        'basic' => 6000000,
        'allowances' => 3000000,
        'gross' => 9000000,
        'attendance_deduction' => 1350000,
        'fixed_deductions' => 300000,
        'loan_deduction' => 500000,
        'taxable_pay' => 7140000,
        'tax' => 357000,
        'net' => 6493000,
        'breakdown' => psBreakdown($employee, $run->month),
        'bank_name' => 'Meezan Bank',
        'account_title' => $employee->full_name,
        'account_number' => '00123456789012',
        ...$attrs,
    ]);
}

/** An employee LD-0007 "Sara Ahmed" (no login). */
function psSara(): Employee
{
    return Employee::factory()->create(['employee_code' => 'LD-0007', 'first_name' => 'Sara', 'last_name' => 'Ahmed', 'joining_date' => '2024-03-01']);
}

/** A user holding $role with an employee record. */
function psUser(string $role, array $attrs = []): User
{
    $user = userWithRole($role);
    employeeFor($user, ['joining_date' => '2024-03-01', ...$attrs]);

    return $user->refresh();
}
