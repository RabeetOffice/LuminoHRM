<?php

namespace App\Actions\Exits;

use App\Models\Clearance;
use App\Models\FinalSettlement;
use App\Models\Loan;
use App\Models\LoanRepayment;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Branding;
use App\Support\Exits\SettlementAccess;
use App\Support\Exits\SettlementCalculator;
use App\Support\Exits\SettlementChecks;
use App\Support\Exits\SettlementResult;
use App\Support\Payroll\Money;
use App\Support\Payroll\PayrollAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * An Admin approves a settlement draft (design 5.5 "Approve"). From then on it is frozen and freezes the person's
 * final month (PayrollLock::*For()).
 *
 * One transaction (3 tries). Lock order, every step a locking read before any plain read (MariaDB REPEATABLE READ):
 *   1. the employee row FOR UPDATE (serialises with every per-employee writer: leave, corrections, salary, loans);
 *   2. the resignation row FOR UPDATE;
 *   3. payroll_runs LOCK IN SHARE MODE (every run row, by month only: the previous-month gate reads them), BEFORE the
 *      settlement and the loans, so it cannot cycle with ApprovePayrollRun (run FOR UPDATE, then loans FOR UPDATE);
 *   4. the settlement row FOR UPDATE (writers that lock no employee row share-lock it and wait);
 *   5. the person's active loans FOR UPDATE in ascending id.
 * PayrollLock is never read on its own settlement row. The paid_in_run check (SettlementChecks::paidInRun) share-locks
 * the person's line of the settlement month's run only when that run is approved or paid: such a run is frozen, so no
 * writer holds its lines for update.
 *
 * Then: SettlementAccess::mayApprove (exit.manage; one's own only as the sole active Admin, else [settlement]
 * OWN_APPROVE; no rights 403); a frozen settlement [settlement] FROZEN; stale [settlement] STALE; the first blocking
 * check (SettlementChecks) [settlement]; a recalculation whose figures differ from the stored ones is saved and
 * committed, then refused CHANGED (the approver reviews and approves again; a breakdown-only change is saved silently).
 * Otherwise: summary.company = {name, address, logo_path, logo, footer} (the payroll run's shape), one loan_repayments
 * row per recovered loan (final_settlement_id, month = the settlement month), the clearance "assets" tick stored
 * (cleared_at now, cleared_by null = automatic), status approved, approved_by / at, own_settlement; audited
 * "Settlement approved" (every figure, loans recovered per loan, the shortfall, own flag).
 */
class ApproveSettlement
{
    public const CHANGED = 'Figures changed since the last calculation. Review them and approve again.';

    public function handle(User $by, FinalSettlement $settlement): FinalSettlement
    {
        $changed = false;

        $result = DB::transaction(function () use ($by, $settlement, &$changed): FinalSettlement {
            $changed = false;

            $employee = ExitGuards::lockEmployee((int) $settlement->employee_id);
            $resignation = ExitGuards::lockResignation((int) $settlement->resignation_id, $employee);
            ExitGuards::lockRuns();
            $locked = FinalSettlement::query()->whereKey($settlement->id)->lockForUpdate()->firstOrFail();
            $locked->setRelation('resignation', $resignation);
            Loan::query()->where('employee_id', $employee->id)->where('status', 'active')->orderBy('id')->lockForUpdate()->get(['id']);

            if (! SettlementAccess::mayApprove($by, $locked)) {
                if ($by->can('exit.manage')) {
                    ExitGuards::refuse(SettlementAccess::OWN_APPROVE, 'settlement');
                }

                throw new AuthorizationException;
            }

            if ($locked->status !== 'draft') {
                ExitGuards::refuse(RecalculateSettlement::FROZEN, 'settlement');
            }

            if ($locked->stale) {
                ExitGuards::refuse(SettlementChecks::STALE, 'settlement');
            }

            $blocking = array_values(array_filter(SettlementChecks::for($locked), fn (array $c): bool => $c['severity'] === 'block'));

            if ($blocking !== []) {
                ExitGuards::refuse($blocking[0]['message'], 'settlement');
            }

            $stored = SettlementResult::storedFigures($locked);
            $fresh = SettlementCalculator::calculate($locked);

            if ($fresh->figures !== $stored || $fresh->blocking !== []) {
                $old = $stored;
                $locked->forceFill([...$fresh->attributes(), 'calculated_at' => now()])->save();

                Audit::record('exit', 'Settlement recalculated', $resignation, [
                    ...ExitGuards::context($resignation, $employee),
                    'settlement' => $locked->id,
                    'month' => $locked->month,
                    'at_approval' => true,
                    'old' => SettlementResult::audited($old),
                    'attributes' => SettlementResult::audited($fresh->figures),
                ], $by);

                $changed = true;

                return $locked;
            }

            // Same figures: a breakdown text that moved (a renamed department, say) is stored with the approval.
            $locked->forceFill(['breakdown' => $fresh->breakdown]);

            $loans = Loan::query()->whereIn('id', array_keys($fresh->loanRecovered))->withRemaining()->get()->keyBy('id');
            $recovered = [];

            foreach ($fresh->loanRecovered as $loanId => $amount) {
                if ($amount <= 0) {
                    continue;
                }

                $loan = $loans->get($loanId);
                $left = $loan === null || ! $loan->isActive() ? 0 : $loan->remaining();

                if ($amount > $left) {
                    throw new LogicException("Settlement {$locked->id} recovers {$amount} paisa for loan {$loanId}, "
                        ."which has {$left} paisa left. The approval was rolled back.");
                }

                LoanRepayment::query()->create([
                    'loan_id' => $loanId,
                    'final_settlement_id' => $locked->id,
                    'amount' => $amount,
                    'month' => $locked->month,
                    'note' => 'Final settlement',
                    'created_by' => $by->id,
                ]);

                $recovered[] = ['loan_id' => $loanId, 'amount' => $amount, 'amount_formatted' => Money::format($amount),
                    'remaining_before' => $fresh->loanRemaining[$loanId] ?? 0];
            }

            // The automatic "assets" item is stored now (approval required nothing held).
            Clearance::query()->where('resignation_id', $resignation->id)->where('kind', 'assets')->whereNull('cleared_at')
                ->update(['cleared_at' => now(), 'cleared_by' => null]);

            $own = SettlementAccess::isOwn($by, (int) $locked->employee_id);
            $summary = (array) ($locked->summary ?? []);
            $summary['company'] = [
                'name' => (string) settings('company.name', ''),
                'address' => (string) settings('company.address', ''),
                'logo_path' => settings('branding.logo'),
                'logo' => self::logoSnapshot(settings('branding.logo')),
                'footer' => (string) settings('payroll.payslip_footer', ''),
            ];

            $locked->forceFill([
                'summary' => $summary,
                'status' => 'approved',
                'approved_by' => $by->id,
                'approved_at' => now(),
                'own_settlement' => $own && PayrollAccess::soleActiveAdmin($by),
            ])->save();

            Audit::record('exit', 'Settlement approved', $resignation, [
                ...ExitGuards::context($resignation, $employee),
                'settlement' => $locked->id,
                'month' => $locked->month,
                ...SettlementResult::audited($fresh->figures),
                'loans_recovered' => $recovered,
                'own_settlement' => (bool) $locked->own_settlement,
            ], $by);

            return $locked;
        }, attempts: 3);

        if ($changed) {
            throw ValidationException::withMessages(['settlement' => self::CHANGED]);
        }

        return $result->refresh();
    }

    /**
     * The logo's bytes for the snapshot, as ApprovePayrollRun stores them: only our own PNG / JPEG branding files.
     *
     * @return array{mime: string, data: string}|null
     */
    private static function logoSnapshot(mixed $path): ?array
    {
        $filename = Branding::filename($path);
        $mime = $filename === null ? null : (['png' => 'image/png', 'jpg' => 'image/jpeg'][strtolower(pathinfo($filename, PATHINFO_EXTENSION))] ?? null);

        if ($mime === null || ! Branding::disk()->exists((string) $path)) {
            return null;
        }

        return ['mime' => $mime, 'data' => base64_encode((string) Branding::disk()->get((string) $path))];
    }
}
