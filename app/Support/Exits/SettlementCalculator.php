<?php

namespace App\Support\Exits;

use App\Models\Employee;
use App\Models\FinalSettlement;
use App\Models\FinalSettlementLine;
use App\Models\Resignation;
use App\Support\Payroll\LoanDue;
use App\Support\Payroll\Money;
use App\Support\Payroll\PayAdjustment;
use App\Support\Payroll\PayCalculator;
use App\Support\Payroll\PayInput;
use App\Support\Payroll\PayInputLoader;
use App\Support\Payroll\ProfileSegment;
use Illuminate\Database\Eloquent\Collection;

/**
 * The final settlement of one exit through the payroll calculator only (design 5.5):
 *
 *   $result = SettlementCalculator::calculate($settlement);
 *
 * 1. PayInputLoader::load($month, [$employee], [$id => [window_to => last working day, loans_in_full => true,
 *    adjustments => HR's lines, tax_override => the override]]): the pay window ends on the last working day, earnings,
 *    fixed deductions and fixed tax are prorated by calendar days, attendance (absences, half days, lates, unpaid
 *    leave, overtime) exactly as a run, and every active loan is due in full.
 * 2. Leave encashment (LeaveEncashment, when the setting is on) from the profile in force on the last day (the last
 *    segment) and the run's day-pay basis: a taxable addition "Leave encashment (6.5 days)" placed before HR's lines.
 * 3. PayCalculator::calculate. Its negative_net blocking is dropped (a settlement may end below zero: owed by the
 *    employee; nothing is paid and no loan is recovered). The calculator's "installment reduced" warnings are replaced
 *    by one loan shortfall warning: loan_shortfall = what the loans had left - what this settlement recovers.
 *
 * The breakdown is the calculator's (version PayCalculator::BREAKDOWN_VERSION; its totals equal the figures) plus the
 * `settlement` key: {last_working_day, exit_type, encashment: {enabled, type_name, days, halves, amount}|null, lines:
 * [{kind, type, label, amount, is_taxable}], loans: [{loan_id, type, remaining, recovered}], loan_shortfall,
 * owed_by_employee}. encashment is null when the setting is off (the breakdown then says so in `encashment_note`).
 *
 * Reads only (the loader's fixed number of queries plus the lines and the leave balance); writes nothing.
 */
final class SettlementCalculator
{
    public const ENCASHMENT_OFF = 'Leave encashment is off';

    public const NO_ENCASHMENT_TYPE = 'No leave type is set for encashment and none is named Annual, so no leave was encashed.';

    public static function calculate(FinalSettlement $settlement): SettlementResult
    {
        /** @var Resignation $resignation */
        $resignation = $settlement->relationLoaded('resignation') ? $settlement->resignation : Resignation::query()->findOrFail($settlement->resignation_id);
        /** @var Employee $employee */
        $employee = Employee::query()->findOrFail($settlement->employee_id);
        $lastDay = $resignation->last_working_day->format('Y-m-d');
        $month = (string) $settlement->month;
        $id = (int) $employee->id;

        /** @var Collection<int, FinalSettlementLine> $lines */
        $lines = FinalSettlementLine::query()->where('final_settlement_id', $settlement->id)->orderBy('id')->get();

        $adjustments = array_values($lines->map(fn (FinalSettlementLine $line): PayAdjustment => new PayAdjustment(
            (int) $line->id,
            (string) $line->label,
            (string) $line->type,
            (int) $line->amount,
            $line->type === 'addition' && (bool) $line->is_taxable,
        ))->all());

        $inputs = PayInputLoader::load($month, new Collection([$employee]), [$id => [
            'window_to' => $lastDay,
            'loans_in_full' => true,
            'adjustments' => $adjustments,
            'tax_override' => $settlement->tax_overridden ? (int) $settlement->tax_override : null,
            'tax_override_reason' => $settlement->tax_overridden ? $settlement->tax_override_reason : null,
        ]]);
        $input = $inputs[$id];

        // Leave encashment from the profile in force on the last working day (the window's last segment).
        $lastSegment = $input->segments === [] ? null : $input->segments[count($input->segments) - 1];
        $encashment = $lastSegment instanceof ProfileSegment
            ? LeaveEncashment::for($employee, $lastDay, $lastSegment->toArray(), $input->basisDays > 0 ? $input->basisDays : $input->daysInMonth)
            : (settings('payroll.leave_encashment', false) ? ['type_name' => LeaveEncashment::leaveType()?->name, 'halves' => 0, 'amount' => 0] : null);

        if ($encashment !== null && $encashment['amount'] > 0) {
            $input = self::withAdjustments($input, [
                new PayAdjustment(0, 'Leave encashment ('.LeaveEncashment::daysLabel($encashment['halves']).')', 'addition', $encashment['amount'], true),
                ...$adjustments,
            ]);
        }

        $pay = PayCalculator::calculate($input);

        // Loans: what each had left (all due in full) and what the net allowed.
        $remaining = [];
        $loanRows = [];
        foreach ($input->loans as $loan) {
            /** @var LoanDue $loan */
            $remaining[$loan->loanId] = $loan->remaining;
        }
        $recovered = [];
        foreach ($remaining as $loanId => $left) {
            $recovered[$loanId] = (int) ($pay->loanDeductions[$loanId] ?? 0);
        }
        foreach ($input->loans as $loan) {
            $loanRows[] = ['loan_id' => $loan->loanId, 'type' => $loan->type, 'remaining' => $loan->remaining, 'recovered' => $recovered[$loan->loanId]];
        }
        $shortfall = max(0, array_sum($remaining) - array_sum($recovered));

        $warnings = array_values(array_filter($pay->warnings, fn (array $w): bool => $w['key'] !== 'installment_reduced'));

        if ($shortfall > 0) {
            $warnings[] = [
                'key' => 'loan_shortfall',
                'message' => 'Loan balance '.Money::format($shortfall).' is more than the settlement can recover; the rest stays on the loan.',
            ];
        }

        if ($encashment !== null && $encashment['type_name'] === null) {
            $warnings[] = ['key' => 'no_encashment_type', 'message' => self::NO_ENCASHMENT_TYPE];
        }

        if ($pay->net < 0) {
            $warnings[] = ['key' => 'owed_by_employee', 'message' => 'Owed by employee: '.Money::format(-$pay->net).'.'];
        }

        $blocking = array_values(array_diff($pay->blocking, ['negative_net']));

        $breakdown = $pay->breakdown;
        $breakdown['warnings'] = $warnings;
        $breakdown['settlement'] = [
            'last_working_day' => $lastDay,
            'exit_type' => (string) $resignation->type,
            'encashment' => $encashment === null ? null : [
                'enabled' => true,
                'type_name' => $encashment['type_name'],
                'days' => $encashment['halves'] % 2 === 0 ? intdiv($encashment['halves'], 2) : $encashment['halves'] / 2,
                'halves' => $encashment['halves'],
                'amount' => $encashment['amount'],
            ],
            'encashment_note' => $encashment === null ? self::ENCASHMENT_OFF : null,
            'lines' => $lines->map(fn (FinalSettlementLine $line): array => [
                'kind' => (string) $line->kind,
                'type' => (string) $line->type,
                'label' => (string) $line->label,
                'amount' => (int) $line->amount,
                'is_taxable' => $line->type === 'addition' && (bool) $line->is_taxable,
            ])->values()->all(),
            'loans' => $loanRows,
            'loan_shortfall' => $shortfall,
            'owed_by_employee' => $pay->net < 0 ? -$pay->net : 0,
        ];

        $figures = [
            'basic' => $pay->basic,
            'allowances' => $pay->allowances,
            'overtime' => $pay->overtime,
            'additions' => $pay->additions,
            'gross' => $pay->gross,
            'attendance_deduction' => $pay->attendanceDeduction,
            'fixed_deductions' => $pay->fixedDeductions,
            'other_deductions' => $pay->otherDeductions,
            'loan_deduction' => $pay->loanDeduction,
            'taxable_pay' => $pay->taxablePay,
            'tax' => $pay->tax,
            'net' => $pay->net,
            'encashment_days' => $encashment === null ? 0 : (int) $encashment['halves'],
            'encashment_amount' => $encashment === null ? 0 : (int) $encashment['amount'],
            'loan_shortfall' => $shortfall,
        ];

        return new SettlementResult($figures, $breakdown, $recovered, $remaining, $warnings, $blocking, $pay);
    }

    /**
     * The same input with other one-off items.
     *
     * @param  list<PayAdjustment>  $adjustments
     */
    private static function withAdjustments(PayInput $in, array $adjustments): PayInput
    {
        return new PayInput(...[...get_object_vars($in), 'adjustments' => $adjustments]);
    }
}
