<?php

namespace App\Support\Payroll;

/**
 * Everything the calculator needs for one person's month, gathered by PayInputLoader (Task 3) and turned into a
 * PayResult by PayCalculator (Task 2). Amounts in paisa, dates "Y-m-d", the month "Y-m". No logic here.
 *
 * - $employee: code, name, department, designation, joining_date (strings, for the breakdown).
 * - $windowFrom..$windowTo: the pay window (max(joining date, 1st) .. last day of the month).
 * - $segments: the window split at each salary profile's effective date, in date order (none = no profile).
 * - $basis: calendar_days | thirty | working_days; $basisDays: the day-pay divisor B.
 * - $attendanceHalves: date => 0..2 deducted halves from attendance (MonthGrid cells, counted days only).
 * - $unpaidLeaveHalves: date => 1..2 halves of unpaid leave from the leave ledger (every day of the window).
 * - $offDates: dates attendance records as a day off (cells O = holiday or W = weekly off).
 * - $manualWorkedDates: dates corrected by hand as worked (manual cells P or L).
 * - $lateDays: late days in the month; $lateRuleCount: settings late_rule_count (0 = off).
 * - $attendanceTotals: the MonthGrid totals row (present, late, half_day, absent, on_leave, off ...).
 * - $countedFrom: the first day attendance counts in the window, or null when none does.
 * - $loans: oldest first. $adjustments: HR's one-off items. $taxOverride: HR's tax for the month (paisa) or null.
 * - $exempt: attendance-exempt (no attendance deduction; unpaid leave still deducts).
 */
final readonly class PayInput
{
    /**
     * @param  array<string, mixed>  $employee  code, name, department, designation, joining_date
     * @param  list<ProfileSegment>  $segments
     * @param  array<string, int>  $attendanceHalves
     * @param  array<string, int>  $unpaidLeaveHalves
     * @param  list<string>  $offDates
     * @param  list<string>  $manualWorkedDates
     * @param  array<string, mixed>  $attendanceTotals
     * @param  list<LoanDue>  $loans
     * @param  list<PayAdjustment>  $adjustments
     */
    public function __construct(
        public int $employeeId,
        public array $employee,
        public string $month,
        public int $daysInMonth,
        public string $windowFrom,
        public string $windowTo,
        public array $segments,
        public string $basis,
        public int $basisDays,
        public array $attendanceHalves,
        public array $unpaidLeaveHalves,
        public array $offDates,
        public array $manualWorkedDates,
        public int $lateDays,
        public int $lateRuleCount,
        public int $overtimeMinutes,
        public OvertimeRule $overtime,
        public array $attendanceTotals,
        public ?string $countedFrom,
        public array $loans,
        public array $adjustments,
        public ?int $taxOverride,
        public ?string $taxOverrideReason,
        public bool $exempt,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'employee_id' => $this->employeeId,
            'employee' => $this->employee,
            'month' => $this->month,
            'days_in_month' => $this->daysInMonth,
            'window_from' => $this->windowFrom,
            'window_to' => $this->windowTo,
            'segments' => array_map(fn (ProfileSegment $s) => $s->toArray(), $this->segments),
            'basis' => $this->basis,
            'basis_days' => $this->basisDays,
            'attendance_halves' => $this->attendanceHalves,
            'unpaid_leave_halves' => $this->unpaidLeaveHalves,
            'off_dates' => $this->offDates,
            'manual_worked_dates' => $this->manualWorkedDates,
            'late_days' => $this->lateDays,
            'late_rule_count' => $this->lateRuleCount,
            'overtime_minutes' => $this->overtimeMinutes,
            'overtime' => $this->overtime->toArray(),
            'attendance_totals' => $this->attendanceTotals,
            'counted_from' => $this->countedFrom,
            'loans' => array_map(fn (LoanDue $l) => $l->toArray(), $this->loans),
            'adjustments' => array_map(fn (PayAdjustment $a) => $a->toArray(), $this->adjustments),
            'tax_override' => $this->taxOverride,
            'tax_override_reason' => $this->taxOverrideReason,
            'exempt' => $this->exempt,
        ];
    }
}
