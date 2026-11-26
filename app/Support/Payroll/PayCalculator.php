<?php

namespace App\Support\Payroll;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Every money rule of a payroll line (Phase 5 design 5.3), pure: no database, no settings, no clock. The loader
 * (PayInputLoader) gathers the inputs; this turns one PayInput into one PayResult.
 *
 *   $result = PayCalculator::calculate($input);
 *   $item->fill($result->toItemAttributes())->save();
 *
 * All arithmetic is integer paisa. Every computed amount goes through Money::div exactly once (half up to a whole
 * rupee): each prorated component per segment, each attendance-deduction share per segment, overtime, percentage tax,
 * each prorated fixed deduction per segment, a fixed tax once over all paid days. Entered amounts (adjustments, tax
 * override, loan installments) are used as they are; a tax override must be whole rupees and not below zero.
 *
 * Order (design 5.3): earnings by segment → deducted halves per date (capped at 2) + late-rule halves on the last
 * segment → attendance deduction and its taxable / basic shares (capped at earned amounts) → overtime → one-off items
 * → tax → net before loans (below zero blocks) → loans oldest first, reduced so net is not below zero.
 */
final class PayCalculator
{
    public const BREAKDOWN_VERSION = 1;

    public static function calculate(PayInput $in): PayResult
    {
        if ($in->taxOverride !== null && ($in->taxOverride < 0 || $in->taxOverride % 100 !== 0)) {
            throw new InvalidArgumentException("A tax override must be whole rupees and not below zero [{$in->taxOverride} paisa].");
        }

        if ($in->segments === []) {
            return self::noProfile($in);
        }

        $d = $in->daysInMonth;
        $basisDays = $in->basisDays > 0 ? $in->basisDays : $d;
        $segments = $in->segments;
        $lastIndex = count($segments) - 1;
        $last = $segments[$lastIndex];

        $warnings = [];

        // 1–2. Earnings and fixed deductions, prorated by calendar days per segment.
        $daysPaid = 0;
        $basic = 0;
        $earnedTaxable = 0;
        /** @var array<string, array{label: string, amount: int, taxable: bool}> $allowanceLines */
        $allowanceLines = [];
        /** @var array<string, int> $fixedLines */
        $fixedLines = [];

        foreach ($segments as $segment) {
            $daysPaid += $segment->days;
            $segmentBasic = Money::div($segment->basic * $segment->days, $d);
            $basic += $segmentBasic;
            $earnedTaxable += $segmentBasic;

            foreach ($segment->allowances as $allowance) {
                $earned = Money::div($allowance->amount * $segment->days, $d);
                // Same name and taxability across segments → one line (a name taxable in one profile and not in
                // the other stays two lines, so the taxable figure on each line is true).
                $key = $allowance->name."\0".($allowance->taxable ? '1' : '0');
                $allowanceLines[$key] ??= ['label' => $allowance->name, 'amount' => 0, 'taxable' => $allowance->taxable];
                $allowanceLines[$key]['amount'] += $earned;

                if ($allowance->taxable) {
                    $earnedTaxable += $earned;
                }
            }

            foreach ($segment->deductions as $deduction) {
                $fixedLines[$deduction->name] = ($fixedLines[$deduction->name] ?? 0)
                    + Money::div($deduction->amount * $segment->days, $d);
            }
        }

        $allowances = array_sum(array_column($allowanceLines, 'amount'));
        $earnedGross = $basic + $allowances;
        $fixedDeductions = array_sum($fixedLines);

        $windowDays = self::daysBetween($in->windowFrom, $in->windowTo);
        if ($windowDays > $daysPaid) {
            $unpaid = $windowDays - $daysPaid;
            $warnings[] = [
                'key' => 'days_without_profile',
                'message' => "No salary profile covers {$unpaid} ".($unpaid === 1 ? 'day' : 'days').' of the pay window; they are not paid.',
            ];
        }

        // 3. Deducted halves per date, assigned to the segment containing the date.
        $offDates = array_fill_keys($in->offDates, true);
        $manualWorked = array_fill_keys($in->manualWorkedDates, true);
        $segmentHalves = array_fill(0, count($segments), 0);
        $unpaidCounted = 0;
        $offDayDates = [];
        $workedDayDates = [];

        $dates = array_unique([...array_keys($in->attendanceHalves), ...array_keys($in->unpaidLeaveHalves)]);
        sort($dates);

        foreach ($dates as $date) {
            $date = (string) $date;
            $index = self::segmentIndex($segments, $date);
            if ($index === null) {
                continue;
            }

            $unpaid = self::halves($in->unpaidLeaveHalves[$date] ?? 0);
            if ($unpaid > 0 && isset($offDates[$date])) {
                $offDayDates[] = $date;
                $unpaid = 0;
            }
            if ($unpaid > 0 && isset($manualWorked[$date])) {
                $workedDayDates[] = $date;
            }

            $attendance = $in->exempt ? 0 : self::halves($in->attendanceHalves[$date] ?? 0);
            $unpaidCounted += $unpaid;
            $segmentHalves[$index] += min(2, $attendance + $unpaid);
        }

        if ($offDayDates !== []) {
            $warnings[] = [
                'key' => 'unpaid_leave_on_off_day',
                'message' => 'Unpaid leave on a day off was not deducted: '.self::dateList($offDayDates).'.',
            ];
        }
        if ($workedDayDates !== []) {
            $warnings[] = [
                'key' => 'unpaid_leave_on_worked_day',
                'message' => 'Unpaid leave on a day corrected as worked was deducted: '.self::dateList($workedDayDates)
                    .'. Cancel the leave if the person worked.',
            ];
        }

        // Unpaid halves inside attendance's on_leave total (counted days, not days off), for the paid-leave figure.
        $unpaidInOnLeave = 0;
        if ($in->countedFrom !== null) {
            foreach ($in->unpaidLeaveHalves as $date => $halves) {
                $date = (string) $date;
                if ($date >= $in->countedFrom && ! isset($offDates[$date])) {
                    $unpaidInOnLeave += self::halves($halves);
                }
            }
        }

        $lateDaysDeducted = $in->lateRuleCount > 0 && ! $in->exempt ? intdiv(max(0, $in->lateDays), $in->lateRuleCount) : 0;
        $segmentHalves[$lastIndex] += $lateDaysDeducted * 2;
        $totalHalves = array_sum($segmentHalves);

        // 4. Attendance deduction and its taxable / basic shares, per segment, capped at the earned amounts.
        $attendanceDeduction = 0;
        $taxableShare = 0;
        $basicShare = 0;
        foreach ($segments as $i => $segment) {
            if ($segmentHalves[$i] === 0) {
                continue;
            }
            $attendanceDeduction += Money::div($segment->gross() * $segmentHalves[$i], 2 * $basisDays);
            $taxableShare += Money::div($segment->taxableGross() * $segmentHalves[$i], 2 * $basisDays);
            $basicShare += Money::div($segment->basic * $segmentHalves[$i], 2 * $basisDays);
        }
        $attendanceDeduction = min($attendanceDeduction, $earnedGross);
        $taxableShare = min($taxableShare, $earnedTaxable);
        $basicShare = min($basicShare, $basic);

        // 5. Overtime (taxable), at the last segment's pay.
        $overtime = 0;
        $overtimeLine = null;
        if ($in->overtime->enabled && $in->overtimeMinutes > 0 && ! $in->exempt) {
            $overtime = $in->overtime->mode === 'fixed'
                ? Money::div($in->overtime->fixedRatePaisa * $in->overtimeMinutes, 60)
                : Money::div(
                    $last->gross() * $in->overtimeMinutes * $in->overtime->multiplierPercent,
                    $basisDays * max(1, $in->overtime->hoursPerDay) * 60 * 100,
                );
            $overtimeLine = new PayLine('overtime', 'Overtime ('.self::minutesLabel($in->overtimeMinutes).')', $overtime, true);
        }

        // 6. One-off items.
        $additions = 0;
        $taxableAdditions = 0;
        $otherDeductions = 0;
        $additionLines = [];
        $adjustmentLines = [];
        foreach ($in->adjustments as $adjustment) {
            if ($adjustment->type === 'addition') {
                $additions += $adjustment->amount;
                if ($adjustment->taxable) {
                    $taxableAdditions += $adjustment->amount;
                }
                $additionLines[] = new PayLine('addition', $adjustment->label, $adjustment->amount, $adjustment->taxable, ['adjustment_id' => $adjustment->id]);
            } else {
                $otherDeductions += $adjustment->amount;
                $adjustmentLines[] = new PayLine('adjustment', $adjustment->label, $adjustment->amount, false, ['adjustment_id' => $adjustment->id]);
            }
        }

        $gross = $earnedGross + $overtime + $additions;

        // 7. Tax: mode and rate of the profile in force on the last day of the window.
        $taxMode = $last->taxMode;
        $taxBase = $taxMode === 'percentage' ? ($last->taxBase ?? 'gross_taxable') : null;
        $grossTaxableBase = $earnedTaxable - $taxableShare + $overtime + $taxableAdditions;
        $taxablePay = $taxBase === 'basic' ? $basic - $basicShare : $grossTaxableBase;

        $overridden = $in->taxOverride !== null;
        if ($overridden) {
            $tax = (int) $in->taxOverride;
        } elseif ($taxMode === 'fixed') {
            // One amount (the last profile's) over every paid day, rounded once.
            $tax = Money::div($last->taxValue * $daysPaid, $d);
        } elseif ($taxMode === 'percentage') {
            $tax = Money::div($taxablePay * $last->taxValue, 10000);
        } else {
            $tax = 0;
        }

        // 8. Net before loans.
        $net = $gross - $attendanceDeduction - $fixedDeductions - $otherDeductions - $tax;
        $blocking = [];
        if ($net < 0) {
            $blocking[] = 'negative_net';
        }

        // 9. Loans, oldest first, reduced so net is not below zero.
        $loanDeduction = 0;
        $loanDeductions = [];
        $loanLines = [];
        foreach ($in->loans as $loan) {
            $label = $loan->type === 'advance' ? 'Advance recovery' : 'Loan installment';
            $due = max(0, min($loan->installment, $loan->remaining));

            if ($loan->skipped) {
                $loanDeductions[$loan->loanId] = 0;
                $loanLines[] = new PayLine('loan', "{$label} (skipped this month)", 0, false, ['loan_id' => $loan->loanId, 'due' => 0, 'skipped' => true]);

                continue;
            }

            $amount = $net > 0 ? min($due, $net) : 0;
            if ($amount < $due && $blocking === []) {
                $warnings[] = [
                    'key' => 'installment_reduced',
                    'message' => "{$label} reduced to ".Money::format($amount).' of '.Money::format($due)
                        .' because pay is short; the rest stays on the '.($loan->type === 'advance' ? 'advance' : 'loan').'.',
                ];
            }

            $net -= $amount;
            $loanDeduction += $amount;
            $loanDeductions[$loan->loanId] = $amount;
            $loanLines[] = new PayLine('loan', $label, $amount, false, ['loan_id' => $loan->loanId, 'due' => $due]);
        }

        // 10. Breakdown (version 1).
        $earnings = [new PayLine('basic', 'Basic salary', $basic, true)];
        $taxabilities = [];
        foreach ($allowanceLines as $line) {
            $taxabilities[$line['label']][$line['taxable'] ? 1 : 0] = true;
        }
        foreach ($allowanceLines as $line) {
            // A name taxable in one profile and not in another: mark the non-taxable line so the payslip shows no
            // duplicate.
            $label = ! $line['taxable'] && count($taxabilities[$line['label']]) === 2
                ? "{$line['label']} (not taxable)"
                : $line['label'];
            $earnings[] = new PayLine('allowance', $label, $line['amount'], $line['taxable']);
        }
        if ($overtimeLine !== null) {
            $earnings[] = $overtimeLine;
        }
        $earnings = [...$earnings, ...$additionLines];

        $deductions = [];
        if ($totalHalves > 0) {
            $deductions[] = new PayLine('attendance', 'Attendance ('.self::halvesLabel($totalHalves).' '.($totalHalves === 2 ? 'day' : 'days').')', $attendanceDeduction);
        }
        foreach ($fixedLines as $name => $amount) {
            $deductions[] = new PayLine('fixed', (string) $name, $amount);
        }
        $deductions = [...$deductions, ...$adjustmentLines, ...$loanLines];

        $totals = [
            'gross' => $gross,
            'attendance_deduction' => $attendanceDeduction,
            'fixed_deductions' => $fixedDeductions,
            'other_deductions' => $otherDeductions,
            'loan_deduction' => $loanDeduction,
            'tax' => $tax,
            'net' => $net,
        ];

        $breakdown = [
            'version' => self::BREAKDOWN_VERSION,
            'employee' => self::employee($in),
            'period' => self::period($in, $daysPaid, $basisDays),
            'segments' => array_map(fn (ProfileSegment $s) => [
                'profile_id' => $s->profileId,
                'from' => $s->from,
                'to' => $s->to,
                'days' => $s->days,
                'gross' => $s->gross(),
                'taxable_gross' => $s->taxableGross(),
                'basic' => $s->basic,
            ], $segments),
            'earnings' => array_map(fn (PayLine $l) => $l->toArray(), $earnings),
            'deductions' => array_map(fn (PayLine $l) => $l->toArray(), $deductions),
            'tax' => [
                'mode' => $taxMode,
                'base' => $taxBase,
                'rate_bp' => $taxMode === 'percentage' ? $last->taxValue : null,
                'taxable_pay' => $taxablePay,
                'amount' => $tax,
                'overridden' => $overridden,
                'override_reason' => $overridden ? $in->taxOverrideReason : null,
            ],
            'attendance' => self::attendance($in, $unpaidCounted, $unpaidInOnLeave, $lateDaysDeducted, $totalHalves),
            'totals' => $totals,
            'warnings' => $warnings,
        ];

        return new PayResult(
            daysPaid: $daysPaid,
            basic: $basic,
            allowances: $allowances,
            overtime: $overtime,
            additions: $additions,
            gross: $gross,
            attendanceDeduction: $attendanceDeduction,
            fixedDeductions: $fixedDeductions,
            otherDeductions: $otherDeductions,
            loanDeduction: $loanDeduction,
            taxablePay: $taxablePay,
            tax: $tax,
            net: $net,
            loanDeductions: $loanDeductions,
            warnings: $warnings,
            blocking: $blocking,
            breakdown: $breakdown,
        );
    }

    /** No salary profile in force on any day of the window: every figure 0, blocks approval. */
    private static function noProfile(PayInput $in): PayResult
    {
        $basisDays = $in->basisDays > 0 ? $in->basisDays : $in->daysInMonth;

        $breakdown = [
            'version' => self::BREAKDOWN_VERSION,
            'employee' => self::employee($in),
            'period' => self::period($in, 0, $basisDays),
            'segments' => [],
            'earnings' => [],
            'deductions' => [],
            'tax' => [
                'mode' => 'none',
                'base' => null,
                'rate_bp' => null,
                'taxable_pay' => 0,
                'amount' => 0,
                'overridden' => false,
                'override_reason' => null,
            ],
            'attendance' => self::attendance($in, 0, 0, 0, 0),
            'totals' => [
                'gross' => 0,
                'attendance_deduction' => 0,
                'fixed_deductions' => 0,
                'other_deductions' => 0,
                'loan_deduction' => 0,
                'tax' => 0,
                'net' => 0,
            ],
            'warnings' => [],
        ];

        return new PayResult(0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, [], [], ['no_profile'], $breakdown);
    }

    /** @return array<string, mixed> */
    private static function employee(PayInput $in): array
    {
        return [
            'id' => $in->employeeId,
            'code' => $in->employee['code'] ?? null,
            'name' => $in->employee['name'] ?? null,
            'department' => $in->employee['department'] ?? null,
            'designation' => $in->employee['designation'] ?? null,
            'joining_date' => $in->employee['joining_date'] ?? null,
        ];
    }

    /** @return array<string, mixed> */
    private static function period(PayInput $in, int $daysPaid, int $basisDays): array
    {
        return [
            'month' => $in->month,
            'from' => $in->windowFrom,
            'to' => $in->windowTo,
            'days_in_month' => $in->daysInMonth,
            'days_paid' => $daysPaid,
            'basis' => $in->basis,
            'basis_days' => $basisDays,
        ];
    }

    /**
     * $unpaidHalves: unpaid halves that counted towards the deduction; $unpaidInOnLeaveHalves: unpaid halves on dates
     * attendance counts (≥ counted_from, not days off), i.e. the part of on_leave that is unpaid.
     *
     * @return array<string, mixed>
     */
    private static function attendance(PayInput $in, int $unpaidHalves, int $unpaidInOnLeaveHalves, int $lateDaysDeducted, int $deductedHalves): array
    {
        $totals = $in->attendanceTotals;
        $unpaidLeave = self::halvesNumber($unpaidHalves);
        $onLeave = $totals['on_leave'] ?? 0;
        // The loader's own figure (from the cells) wins; otherwise on_leave minus its unpaid part.
        $paidLeave = array_key_exists('paid_leave', $totals)
            ? $totals['paid_leave']
            : $onLeave - self::halvesNumber($unpaidInOnLeaveHalves);

        return [
            'counted_from' => $in->countedFrom,
            'present' => $totals['present'] ?? 0,
            'late' => $totals['late'] ?? 0,
            'half_day' => $totals['half_day'] ?? 0,
            'absent' => $totals['absent'] ?? 0,
            'on_leave' => $onLeave,
            'unpaid_leave' => $unpaidLeave,
            'paid_leave' => $paidLeave > 0 ? $paidLeave : 0,
            'off' => $totals['off'] ?? 0,
            'late_days_deducted' => $lateDaysDeducted,
            'deducted_halves' => $deductedHalves,
            'overtime_minutes' => $in->overtimeMinutes,
        ];
    }

    /** @param  list<ProfileSegment>  $segments */
    private static function segmentIndex(array $segments, string $date): ?int
    {
        foreach ($segments as $i => $segment) {
            if ($date >= $segment->from && $date <= $segment->to) {
                return $i;
            }
        }

        return null;
    }

    private static function halves(mixed $value): int
    {
        return max(0, min(2, (int) $value));
    }

    /** Halves as days: 9 → 4.5, 8 → 4. */
    private static function halvesNumber(int $halves): int|float
    {
        return $halves % 2 === 0 ? intdiv($halves, 2) : $halves / 2;
    }

    private static function halvesLabel(int $halves): string
    {
        return intdiv($halves, 2).($halves % 2 === 1 ? '.5' : '');
    }

    /** 600 → "10 h", 45 → "45 min", 605 → "10 h 5 min". */
    private static function minutesLabel(int $minutes): string
    {
        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;

        return trim(($hours > 0 ? "{$hours} h" : '').($rest > 0 ? " {$rest} min" : ''));
    }

    /** Calendar days from $from to $to inclusive ("Y-m-d"); 0 when either is malformed or $to is before $from. */
    private static function daysBetween(string $from, string $to): int
    {
        $a = DateTimeImmutable::createFromFormat('!Y-m-d', $from);
        $b = DateTimeImmutable::createFromFormat('!Y-m-d', $to);
        if ($a === false || $b === false || $b < $a) {
            return 0;
        }

        return (int) $a->diff($b)->days + 1;
    }

    /** @param  list<string>  $dates  "Y-m-d" → "14 Nov, 21 Nov" */
    private static function dateList(array $dates): string
    {
        return implode(', ', array_map(function (string $date) {
            $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);

            return $parsed === false ? $date : $parsed->format('j M');
        }, $dates));
    }
}
