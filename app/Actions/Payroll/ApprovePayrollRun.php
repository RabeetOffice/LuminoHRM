<?php

namespace App\Actions\Payroll;

use App\Events\PayrollRunApproved;
use App\Models\Employee;
use App\Models\Loan;
use App\Models\LoanRepayment;
use App\Models\PayrollItem;
use App\Models\PayrollRun;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Payroll\Money;
use App\Support\Payroll\PayrollAccess;
use App\Support\Payroll\PayrollChecks;
use App\Support\Payroll\PayrollRoster;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Approving a draft payroll run (design 5.6 "Approve"): the run becomes approved, which locks the month
 * (App\Support\PayrollLock) for every attendance, leave, holiday, employment-date and salary write.
 *
 *   $outcome = $approve->approve($admin, $run, $fingerprintTheAdminReviewed);   // 'approved' | 'changed'
 *   $approve->changedLines;                                                     // with 'changed': how many people
 *
 * One transaction (3 tries; every captured value reset at the top of an attempt). Lock order (plan ruling): the run
 * row FOR UPDATE is the FIRST statement (approval never inserts or deletes a line, so it takes no employee lock), then
 * the loans of the run's people FOR UPDATE in ascending id. Then, reading only after the locks:
 *
 * 1. PayrollAccess::mayApprove (Admin; a run with the approver's own line only as the sole active Admin) else 403;
 *    a run that is not a draft, or a later month already approved → [run].
 * 2. Every line is calculated again from fresh inputs. The people must still be exactly the run's lines; otherwise
 *    [run] "recalculate first" (nothing written: adding or removing a line is recalculate()'s job).
 * 3. Figures (days paid, the money columns, blocking keys) that differ from the stored lines are written in place
 *    with the run totals, audited "Payroll recalculated at approval", and the outcome is 'changed' (committed, not
 *    approved). The outcome is also 'changed' when the stored figures no longer match the fingerprint the approver
 *    reviewed (someone recalculated meanwhile). A change only in the breakdown text (a renamed department, say) is
 *    written silently and the approval goes on.
 * 4. PayrollChecks::blocking non-empty → [run] with every blocking message; nothing written.
 * 5. Each non-excluded line gets its bank details copied (before the status changes: PayrollItem freezes the lines of
 *    an approved run); one loan repayment per loan line with an amount (capped at Loan::remaining() under the loan
 *    lock); summary.company = {name, address, logo_path, footer} and summary.checks = the warnings; status approved,
 *    approved_by / approved_at; audited "Payroll approved" (totals, own_payslip).
 *
 * After the commit: event(new PayrollRunApproved($runId)) once (payslips, Task 9).
 */
class ApprovePayrollRun
{
    public const ROSTER_CHANGED_MESSAGE = 'The people in this payroll changed since the last calculation. Recalculate it first.';

    /** With the 'changed' outcome: the number of lines whose figures changed (0 when only the fingerprint was stale). */
    public int $changedLines = 0;

    public function approve(User $by, PayrollRun $run, string $reviewedFingerprint): string
    {
        $outcome = 'changed';

        DB::transaction(function () use ($by, $run, $reviewedFingerprint, &$outcome): void {
            $outcome = 'changed';
            $this->changedLines = 0;

            $locked = PayrollRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();

            if (! PayrollAccess::mayApprove($by, $locked)) {
                throw new AuthorizationException;
            }

            if (! $locked->isDraft()) {
                throw ValidationException::withMessages(['run' => 'This payroll is already approved.']);
            }

            $later = PayrollRun::query()
                ->where('month', '>', $locked->month)
                ->whereIn('status', (array) config('hrm.payroll.locked_statuses'))
                ->orderBy('month')
                ->value('month');
            if ($later !== null) {
                throw ValidationException::withMessages(['run' => 'Payroll for '.PayrollRun::monthLabel((string) $later)
                    .' is already approved, so an earlier month cannot be approved.']);
            }

            $items = PayrollItem::query()
                ->where('payroll_run_id', $locked->id)
                ->with('adjustments')
                ->orderBy('employee_id')
                ->get()
                ->keyBy('employee_id');
            $employeeIds = array_map('intval', $items->keys()->all());

            Loan::query()->whereIn('employee_id', $employeeIds)->orderBy('id')->lockForUpdate()->get(['id']);

            $roster = PayrollRoster::forMonth($locked->month);
            $rosterIds = array_map('intval', $roster->included->pluck('id')->all());
            sort($rosterIds);
            if ($rosterIds !== $employeeIds) {
                throw ValidationException::withMessages(['run' => self::ROSTER_CHANGED_MESSAGE]);
            }

            $lines = $roster->included->isEmpty() ? [] : GeneratePayrollRun::calculateLines($locked->month, $roster->included, $items);

            $changed = [];
            foreach ($lines as $employeeId => $line) {
                $item = $items[$employeeId];
                $item->forceFill($line['attributes']);

                if (array_intersect(array_keys($item->getDirty()), ['days_paid', ...PayrollItem::FIGURES, 'blocking']) !== []) {
                    $changed[] = $employeeId;
                }
            }

            if ($changed !== []) {
                foreach ($items as $item) {
                    if ($item->isDirty()) {
                        $item->save();
                    }
                }
                GeneratePayrollRun::refreshTotals($locked, $roster);
                $this->changedLines = count($changed);

                Audit::record('payroll', 'Payroll recalculated at approval', $locked, [
                    'month' => $locked->month,
                    'changed' => count($changed),
                    'people' => array_values($roster->included->whereIn('id', $changed)->map(fn (Employee $e): array => ['id' => (int) $e->id, 'name' => $e->full_name])->all()),
                    ...GeneratePayrollRun::totals($locked),
                ], $by);

                return;
            }

            if ($locked->fingerprint() !== $reviewedFingerprint) {
                return;
            }

            // Same figures; a breakdown or warning text that moved (a renamed department, say) is stored silently.
            foreach ($items as $item) {
                if ($item->isDirty()) {
                    $item->save();
                }
            }

            $checks = PayrollChecks::for($locked);
            $blocking = array_values(array_filter($checks, fn (array $c): bool => $c['severity'] === 'block'));
            if ($blocking !== []) {
                throw ValidationException::withMessages(['run' => array_column($blocking, 'message')]);
            }

            $employees = $roster->included->keyBy('id');
            $loanLines = [];
            foreach ($items as $employeeId => $item) {
                if (! $item->excluded) {
                    $bank = $employees[$employeeId]->bankAccount;
                    $item->forceFill([
                        'bank_name' => $bank?->bank_name,
                        'account_title' => $bank?->account_title,
                        'account_number' => $bank?->account_number,
                    ]);

                    foreach ((array) ($item->breakdown['deductions'] ?? []) as $deduction) {
                        if (($deduction['key'] ?? null) === 'loan' && (int) ($deduction['amount'] ?? 0) > 0) {
                            $loanLines[] = [$item, (int) $deduction['loan_id'], (int) $deduction['amount']];
                        }
                    }

                    if ($item->isDirty()) {
                        $item->save();
                    }
                }
            }

            $loans = Loan::query()
                ->whereIn('id', array_unique(array_map(fn (array $l): int => $l[1], $loanLines)))
                ->withRemaining()
                ->get()
                ->keyBy('id');
            $repaid = [];
            $repaymentTotal = 0;
            foreach ($loanLines as [$item, $loanId, $amount]) {
                $loan = $loans->get($loanId);
                $amount = $loan === null ? 0 : min($amount, $loan->remaining() - ($repaid[$loanId] ?? 0));

                if ($amount <= 0) {
                    continue;
                }

                LoanRepayment::query()->create([
                    'loan_id' => $loanId,
                    'payroll_item_id' => $item->id,
                    'amount' => $amount,
                    'month' => $locked->month,
                    'created_by' => $by->id,
                ]);
                $repaid[$loanId] = ($repaid[$loanId] ?? 0) + $amount;
                $repaymentTotal += $amount;
            }

            $ownPayslip = PayrollAccess::approvesOwn($by, $locked);
            $summary = $locked->summary ?? [];
            $summary['company'] = [
                'name' => (string) settings('company.name', ''),
                'address' => (string) settings('company.address', ''),
                'logo_path' => settings('branding.logo'),
                'footer' => (string) settings('payroll.payslip_footer', ''),
            ];
            $summary['checks'] = array_values(array_filter($checks, fn (array $c): bool => $c['severity'] === 'warn'));

            $locked->forceFill([
                'summary' => $summary,
                'status' => 'approved',
                'approved_by' => $by->id,
                'approved_at' => now(),
            ])->save();

            Audit::record('payroll', 'Payroll approved', $locked, [
                'month' => $locked->month,
                ...GeneratePayrollRun::totals($locked),
                'repayments' => count($repaid),
                'repaid' => $repaymentTotal,
                'repaid_formatted' => Money::format($repaymentTotal),
                'warnings' => array_column($summary['checks'], 'key'),
                'own_payslip' => $ownPayslip,
            ], $by);

            $outcome = 'approved';
        }, 3);

        if ($outcome === 'approved') {
            event(new PayrollRunApproved($run->id));
        }

        return $outcome;
    }
}
