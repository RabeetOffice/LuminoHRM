<?php

/*
 * Shared fixtures for the payroll run tests (Task 7): GenerateRunTest, PayrollChecksTest, LineInputsTest,
 * ApproveRunTest. Required with require_once; every helper is prefixed "pr".
 */

use App\Actions\Payroll\GeneratePayrollRun;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\EmployeeBankAccount;
use App\Models\PayrollItem;
use App\Models\PayrollRun;
use App\Models\User;

if (! function_exists('prUser')) {
    /** An active user of $role with an employee record (joined 2025-01-01 unless $attrs say otherwise). */
    function prUser(string $role, array $attrs = []): array
    {
        $user = userWithRole($role);
        $employee = employeeFor($user, ['joining_date' => '2025-01-01', ...$attrs]);

        return [$user, $employee];
    }

    /** An employee without a login, joined 2025-01-01 unless $attrs say otherwise. */
    function prEmployee(array $attrs = []): Employee
    {
        return Employee::factory()->create(['joining_date' => '2025-01-01', ...$attrs]);
    }

    function prBank(Employee $e, string $number = '0123456789012345'): EmployeeBankAccount
    {
        return EmployeeBankAccount::query()->create([
            'employee_id' => $e->id,
            'bank_name' => 'Meezan Bank',
            'account_title' => $e->full_name,
            'account_number' => $number,
        ]);
    }

    function prDay(Employee $e, string $date, ?string $status, array $attrs = []): Attendance
    {
        return Attendance::factory()->create(['employee_id' => $e->id, 'date' => $date, 'status' => $status, 'closed' => true, ...$attrs]);
    }

    /** A closed "present" row on every date from $from to $to that has no row yet. */
    function prFill(Employee $e, string $from = '2026-11-01', string $to = '2026-11-30'): void
    {
        $have = Attendance::query()->where('employee_id', $e->id)->betweenDates($from, $to)->pluck('date')
            ->map(fn ($d): string => substr((string) $d, 0, 10))->flip();

        for ($d = new DateTimeImmutable($from); $d->format('Y-m-d') <= $to; $d = $d->modify('+1 day')) {
            if (! $have->has($d->format('Y-m-d'))) {
                prDay($e, $d->format('Y-m-d'), 'present');
            }
        }
    }

    /**
     * Design example 1 on real rows (November 2026): profile P, 2 absent days, 1 half day, 5 late days (rule 3 -> 1 day),
     * 1 unpaid leave day, loan 12,000 with installment 5,000; every other day present. Line net 64,930.
     */
    function prExample1(Employee $e): void
    {
        salaryProfile($e);
        prDay($e, '2026-11-03', 'absent');
        prDay($e, '2026-11-04', 'absent');
        prDay($e, '2026-11-05', 'half_day');
        prDay($e, '2026-11-06', 'on_leave');
        foreach (['2026-11-09', '2026-11-10', '2026-11-11', '2026-11-12', '2026-11-13'] as $date) {
            prDay($e, $date, 'late', ['late_minutes' => 25]);
        }
        approvedLeave($e, '2026-11-06', null, null, 'Unpaid');
        loanFor($e, 12000, 5000, '2026-11');
        prFill($e);
    }

    function prGenerate(User $by, string $month = '2026-11'): PayrollRun
    {
        return app(GeneratePayrollRun::class)->generate($by, $month);
    }

    function prItem(PayrollRun $run, Employee $e): ?PayrollItem
    {
        return PayrollItem::query()->where('payroll_run_id', $run->id)->where('employee_id', $e->id)->first();
    }

    /** Rupees -> paisa. */
    function prRs(int $rupees): int
    {
        return $rupees * 100;
    }
}
