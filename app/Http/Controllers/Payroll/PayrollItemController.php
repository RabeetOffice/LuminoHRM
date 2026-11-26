<?php

namespace App\Http\Controllers\Payroll;

use App\Actions\Payroll\GeneratePayrollRun;
use App\Actions\Payroll\LoanActions;
use App\Actions\Payroll\PayrollLineInputs;
use App\Http\Controllers\Controller;
use App\Http\Requests\PayrollAdjustmentRequest;
use App\Http\Requests\PayrollTaxOverrideRequest;
use App\Models\Loan;
use App\Models\PayrollAdjustment;
use App\Models\PayrollItem;
use App\Models\PayrollRun;
use App\Models\User;
use App\Support\Payroll\PayrollAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * One line of a payroll run: the drawer's data (JSON) and HR's inputs on a draft line. The routes use scoped bindings (a line of
 * another run, an adjustment of another line: 404 before any body validation); a loan must be the line's person's (404).
 *
 *   GET    /payroll/runs/{run}/items/{item}                        can:payroll.view  the stored breakdown, inputs, loans
 *   POST   /payroll/runs/{run}/items/{item}/adjustments            can:payroll.run   add a one-off addition / deduction
 *   DELETE /payroll/runs/{run}/items/{item}/adjustments/{adjustment}
 *   PUT    /payroll/runs/{run}/items/{item}/tax                    amount (whole rupees) + reason, empty amount clears
 *   PUT    /payroll/runs/{run}/items/{item}/exclude                excluded (bool) + reason
 *   PUT    /payroll/runs/{run}/items/{item}/loans/{loan}           skipped (bool) + reason: this month's installment
 *
 * The inputs are PayrollLineInputs / LoanActions (draft runs only, PayrollAccess::mayEditSalary, 403 otherwise, each
 * recalculating the line); a loan skip and the recalculation of that person are two separate transactions (lock order). Money in the JSON
 * is integer paisa and the breakdown is exactly what is stored; the loans list reads only for a draft run.
 */
class PayrollItemController extends Controller
{
    public function show(Request $request, PayrollRun $run, PayrollItem $item): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $item->load(['employee.user.roles', 'adjustments']);
        $draft = $run->isDraft();
        $mayEdit = $draft && PayrollAccess::mayEditSalary($user, $item->employee);

        return response()->json([
            'id' => $item->id,
            'employee' => [
                'id' => $item->employee_id,
                'name' => $item->employee->full_name,
                'code' => $item->employee->employee_code,
            ],
            'month' => $run->month,
            'status' => $run->status,
            'days_paid' => $item->days_paid,
            'figures' => collect(PayrollItem::FIGURES)->mapWithKeys(fn (string $f): array => [$f => (int) $item->{$f}])->all(),
            'breakdown' => $item->breakdown,
            'warnings' => $item->warnings,
            'blocking' => $item->blocking,
            'excluded' => $item->excluded,
            'excluded_reason' => $item->excluded_reason,
            'tax_overridden' => $item->tax_overridden,
            'tax_override' => $item->tax_override,
            'tax_override_reason' => $item->tax_override_reason,
            'adjustments' => $item->adjustments->sortBy('id')->map(fn (PayrollAdjustment $a): array => [
                'id' => $a->id,
                'label' => $a->label,
                'type' => $a->type,
                'amount' => $a->amount,
                'is_taxable' => $a->is_taxable,
            ])->values()->all(),
            'loans' => $draft ? $this->loans($item, $run, (array) ($item->breakdown['deductions'] ?? [])) : [],
            'can_edit' => $mayEdit,
        ]);
    }

    public function storeAdjustment(PayrollAdjustmentRequest $request, PayrollRun $run, PayrollItem $item, PayrollLineInputs $inputs): RedirectResponse
    {
        $inputs->addAdjustment(
            $request->user(), $item, (string) $request->validated('label'), (string) $request->validated('type'),
            $request->amount(), $request->taxable(),
        );

        return back()->with('success', 'Added.');
    }

    public function destroyAdjustment(Request $request, PayrollRun $run, PayrollItem $item, PayrollAdjustment $adjustment, PayrollLineInputs $inputs): RedirectResponse
    {
        $inputs->removeAdjustment($request->user(), $adjustment);

        return back()->with('success', 'Removed.');
    }

    public function tax(PayrollTaxOverrideRequest $request, PayrollRun $run, PayrollItem $item, PayrollLineInputs $inputs): RedirectResponse
    {
        $amount = $request->amount();
        $inputs->overrideTax($request->user(), $item, $amount, $request->validated('reason'));

        return back()->with('success', $amount === null ? 'Tax override cleared.' : 'Tax set for this month.');
    }

    public function exclude(Request $request, PayrollRun $run, PayrollItem $item, PayrollLineInputs $inputs): RedirectResponse
    {
        $data = $request->validate([
            'excluded' => ['required', 'boolean'],
            'reason' => ['nullable', 'string', 'max:255'],
        ], ['excluded.required' => 'Choose whether to exclude this person.']);

        if ($request->boolean('excluded')) {
            $inputs->exclude($request->user(), $item, (string) ($data['reason'] ?? ''));

            return back()->with('success', 'Excluded from this payroll.');
        }

        $inputs->include($request->user(), $item);

        return back()->with('success', 'Included again.');
    }

    public function loanSkip(Request $request, PayrollRun $run, PayrollItem $item, Loan $loan, LoanActions $loans, GeneratePayrollRun $generate): RedirectResponse
    {
        // The route turns scoped bindings off (the loan is the person's, not the run's), so the line is checked here.
        abort_unless($item->payroll_run_id === $run->id, 404);
        abort_unless($loan->employee_id === $item->employee_id, 404);
        $data = $request->validate([
            'skipped' => ['required', 'boolean'],
            'reason' => ['nullable', 'string', 'max:255'],
        ], ['skipped.required' => 'Choose whether to skip this installment.']);

        if (! $run->isDraft()) {
            throw ValidationException::withMessages(['run' => GeneratePayrollRun::LOCKED_MESSAGE]);
        }

        /** @var User $user */
        $user = $request->user();
        $skip = $request->boolean('skipped');

        if ($skip && mb_strlen(trim((string) ($data['reason'] ?? ''))) < 3) {
            throw ValidationException::withMessages(['reason' => 'Give a reason.']);
        }

        // Two steps, each in its own transaction, so the lock order stays employee -> loan and, separately, employees ->
        // run (approval takes run -> loans). If approval slips in between, its fingerprint protocol catches the change.
        if ($skip) {
            $loans->skip($user, $loan, $run->month, (string) $data['reason']);
        } else {
            $loans->unskip($user, $loan, $run->month);
        }

        $generate->recalculate($user, $run, $item->employee_id);

        return back()->with('success', $skip ? 'Installment skipped for this month.' : 'Installment restored.');
    }

    /**
     * The person's loans that can be skipped this month: active, started by the run month and not paid off, or
     * already skipped this month (so the skip can be undone).
     *
     * @param  array<int, mixed>  $deductions  the stored breakdown's deduction lines
     * @return list<array<string, mixed>>
     */
    private function loans(PayrollItem $item, PayrollRun $run, array $deductions): array
    {
        $due = [];
        foreach ($deductions as $line) {
            if (is_array($line) && ($line['key'] ?? null) === 'loan') {
                $due[(int) ($line['loan_id'] ?? 0)] = (int) ($line['amount'] ?? 0);
            }
        }

        $rows = Loan::query()
            ->withRemaining()
            ->with('skips')
            ->where('employee_id', $item->employee_id)
            ->where('status', 'active')
            ->where('start_month', '<=', $run->month)
            ->orderBy('id')
            ->get()
            ->map(function (Loan $loan) use ($run, $due): ?array {
                $skip = $loan->skips->firstWhere('month', $run->month);

                if ($skip === null && $loan->remaining() <= 0) {
                    return null;
                }

                return [
                    'id' => $loan->id,
                    'type' => $loan->type,
                    'installment' => $loan->installment,
                    'remaining' => $loan->remaining(),
                    'deduction' => $due[$loan->id] ?? 0,
                    'skipped' => $skip !== null,
                    'skip_reason' => $skip?->reason,
                ];
            })
            ->filter()
            ->all();

        return array_values($rows);
    }
}
