<?php

/*
 * Shared fixtures for the settlement tests (Task 2): the spec 5.5 worked example on real rows. Required with
 * require_once; every helper is prefixed "st". "Now" is set by each file (2026-12-05 12:00 Karachi unless stated).
 */

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\FinalSettlement;
use App\Models\FinalSettlementLine;
use App\Models\LeaveBalance;
use App\Models\Resignation;
use App\Support\Settings;

if (! function_exists('stPerson')) {
    /** An employee joined 2025-01-01 (overridable) with profile P from 2026-01-01. */
    function stPerson(array $attrs = []): Employee
    {
        $e = Employee::factory()->create(['joining_date' => '2025-01-01', ...$attrs]);
        salaryProfile($e, from: max('2026-01-01', $e->joining_date->format('Y-m-d')));

        return $e;
    }

    /** Closed attendance rows from $from to $to: present, except the given dates => status. */
    function stDays(Employee $e, string $from, string $to, array $except = []): void
    {
        for ($d = new DateTimeImmutable($from); $d->format('Y-m-d') <= $to; $d = $d->modify('+1 day')) {
            $date = $d->format('Y-m-d');
            $status = $except[$date] ?? 'present';
            $extra = $status === 'late' ? ['late_minutes' => 20] : [];
            Attendance::factory()->create(['employee_id' => $e->id, 'date' => $date, 'status' => $status, 'closed' => true, ...$extra]);
        }
    }

    /** The worked example's exit: last working day 18 Nov 2026, one absent day (3 Nov), a draft settlement. */
    function stExample(?Employee $e = null, string $lastDay = '2026-11-18'): array
    {
        $e ??= stPerson();
        $exit = approvedExit($e, $lastDay);
        stDays($e, '2026-11-01', $lastDay, ['2026-11-03' => 'absent']);
        $settlement = settlementRow($exit);

        return [$e->refresh(), $exit, $settlement];
    }

    /** Leave encashment on, with $days left on the Annual type in 2026. */
    function stEncashment(Employee $e, float $days = 6.5): void
    {
        Settings::set('payroll', ['leave_encashment' => true]);
        $type = leaveType('Annual', ['is_paid' => true, 'carry_forward' => false]);
        LeaveBalance::query()->create(['employee_id' => $e->id, 'leave_type_id' => $type->id, 'year' => 2026, 'allocated' => $days]);
    }

    /** An HR line written directly (amount in rupees). */
    function stLine(FinalSettlement $s, string $kind, int $rupees, string $type = 'addition', bool $taxable = true): FinalSettlementLine
    {
        return FinalSettlementLine::query()->create([
            'final_settlement_id' => $s->id,
            'kind' => $kind,
            'type' => $kind === 'notice_shortfall' ? 'deduction' : ($kind === 'bonus' ? 'addition' : $type),
            'label' => ucfirst(str_replace('_', ' ', $kind)),
            'amount' => $rupees * 100,
            'is_taxable' => $taxable && $type === 'addition' && $kind !== 'notice_shortfall',
        ]);
    }

    /** Clearance complete: every hand item ticked (nothing held unless the test hands out an asset). */
    function stClear(Resignation $exit): void
    {
        $exit->clearances()->where('kind', '!=', 'assets')->update(['cleared_at' => now()]);
    }

    function stRs(int $rupees): int
    {
        return $rupees * 100;
    }
}
