<?php

namespace App\Actions\Payroll;

use App\Models\Employee;
use App\Models\Loan;
use App\Models\LoanRepayment;
use App\Models\LoanSkip;
use App\Models\PayrollRun;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Payroll\Money;
use App\Support\PayrollLock;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * What HR does to a loan after it exists: skip a month, undo a skip, record a manual (cash) repayment; and Admin's
 * cancel (write off the remainder). Money in paisa. A loan is never deleted.
 *
 * Months: a skip, an unskip or a repayment must be in a month after the latest approved payroll month (so never in a
 * locked one: "Payroll for November 2026 is approved, so this can no longer change."). Skips and repayments in locked
 * months never change.
 *
 * Who: SaveLoan::authorize (PayrollAccess::mayEditSalary) for all four, else 403; cancel also needs payroll.approve.
 * Each runs in one transaction (3 attempts) that locks the employee row first, then reads the payroll lock (shared),
 * then locks the loan row; every decision (remaining, existing skip, status) is read after the locks, so two
 * repayments racing each other can never recover more than what is left. Audited "Loan skipped" / "Loan unskipped" /
 * "Loan repaid" / "Loan cancelled" (subject the employee; amounts in paisa plus a formatted copy).
 */
class LoanActions
{
    public const NOT_ACTIVE = 'This loan is cancelled and can no longer change.';

    public const NOTHING_LEFT = 'Nothing is left to recover on this loan.';

    public const ALREADY_SKIPPED = 'This month is already skipped.';

    public const BEFORE_START = 'Deductions start in :month; skip that month or a later one.';

    /** @throws AuthorizationException|ValidationException */
    public function skip(User $by, Loan $loan, string $month, string $reason): LoanSkip
    {
        $employee = $this->authorizeFor($by, $loan);

        return DB::transaction(function () use ($by, $loan, $employee, $month, $reason): LoanSkip {
            $this->lockEmployee($employee);
            self::checkMonth($month, 'month');
            $locked = $this->lockLoan($loan);

            if (! $locked->isActive()) {
                throw ValidationException::withMessages(['month' => self::NOT_ACTIVE]);
            }

            if ($locked->remaining() <= 0) {
                throw ValidationException::withMessages(['month' => self::NOTHING_LEFT]);
            }

            if ($month < $locked->start_month) {
                throw ValidationException::withMessages([
                    'month' => str_replace(':month', PayrollRun::monthLabel($locked->start_month), self::BEFORE_START),
                ]);
            }

            if ($locked->skips()->where('month', $month)->exists()) {
                throw ValidationException::withMessages(['month' => self::ALREADY_SKIPPED]);
            }

            $skip = LoanSkip::query()->create([
                'loan_id' => $locked->id,
                'month' => $month,
                'reason' => trim($reason),
                'created_by' => $by->getKey(),
            ]);

            Audit::record('payroll', 'Loan skipped', $employee, [
                'loan_id' => $locked->id,
                'month' => $month,
                'reason' => $skip->reason,
            ], $by);

            return $skip;
        }, 3);
    }

    /**
     * @throws AuthorizationException|ValidationException
     * @throws ModelNotFoundException no skip of that month
     */
    public function unskip(User $by, Loan $loan, string $month): void
    {
        $employee = $this->authorizeFor($by, $loan);

        DB::transaction(function () use ($by, $loan, $employee, $month): void {
            $this->lockEmployee($employee);
            self::checkMonth($month, 'month');
            $locked = $this->lockLoan($loan);

            if (! $locked->isActive()) {
                throw ValidationException::withMessages(['month' => self::NOT_ACTIVE]);
            }

            /** @var LoanSkip $skip */
            $skip = $locked->skips()->where('month', $month)->firstOrFail();
            $skip->delete();

            Audit::record('payroll', 'Loan unskipped', $employee, [
                'loan_id' => $locked->id,
                'month' => $month,
                'reason' => $skip->reason,
            ], $by);
        }, 3);
    }

    /**
     * A manual (cash) repayment of 1 paisa up to what is left, in a month after the latest approved one.
     *
     * @throws AuthorizationException|ValidationException
     */
    public function repay(User $by, Loan $loan, int $amount, string $month, string $note): LoanRepayment
    {
        $employee = $this->authorizeFor($by, $loan);

        return DB::transaction(function () use ($by, $loan, $employee, $amount, $month, $note): LoanRepayment {
            $this->lockEmployee($employee);
            self::checkMonth($month, 'month');
            $locked = $this->lockLoan($loan);

            if (! $locked->isActive()) {
                throw ValidationException::withMessages(['amount' => self::NOT_ACTIVE]);
            }

            $remaining = $locked->remaining();

            if ($remaining <= 0) {
                throw ValidationException::withMessages(['amount' => self::NOTHING_LEFT]);
            }

            if ($amount <= 0 || $amount > $remaining) {
                throw ValidationException::withMessages([
                    'amount' => 'Enter an amount above zero and no more than what is left ('.Money::format($remaining).').',
                ]);
            }

            $repayment = LoanRepayment::query()->create([
                'loan_id' => $locked->id,
                'payroll_item_id' => null,
                'amount' => $amount,
                'month' => $month,
                'note' => trim($note) === '' ? null : mb_substr(trim($note), 0, 255),
                'created_by' => $by->getKey(),
            ]);

            Audit::record('payroll', 'Loan repaid', $employee, [
                'loan_id' => $locked->id,
                'month' => $month,
                'amount' => $amount,
                'amount_formatted' => Money::format($amount),
                'remaining_before' => $remaining,
                'remaining_after' => $remaining - $amount,
                'remaining_after_formatted' => Money::format($remaining - $amount),
                'note' => $repayment->note,
            ], $by);

            return $repayment;
        }, 3);
    }

    /**
     * Admin writes the remainder off: status cancelled; repayments stay.
     *
     * @throws AuthorizationException|ValidationException
     */
    public function cancel(User $by, Loan $loan, string $reason): void
    {
        if (! $by->can('payroll.approve')) {
            throw new AuthorizationException('Only an Admin can cancel a loan.');
        }

        $employee = $this->authorizeFor($by, $loan);

        DB::transaction(function () use ($by, $loan, $employee, $reason): void {
            $this->lockEmployee($employee);
            $locked = $this->lockLoan($loan);

            if (! $locked->isActive()) {
                throw ValidationException::withMessages(['reason' => 'This loan is already cancelled.']);
            }

            $remaining = $locked->remaining();
            $locked->forceFill([
                'status' => 'cancelled',
                'cancelled_by' => $by->getKey(),
                'cancelled_at' => now(),
                'cancel_reason' => mb_substr(trim($reason), 0, 255),
            ])->save();

            Audit::record('payroll', 'Loan cancelled', $employee, [
                'loan_id' => $locked->id,
                'written_off' => $remaining,
                'written_off_formatted' => Money::format($remaining),
                'reason' => $locked->cancel_reason,
            ], $by);
        }, 3);
    }

    /**
     * A month a skip, unskip or repayment may use: a real "Y-m" after every approved payroll month. ONE read of the
     * payroll lock (PayrollLock::rangeTouchesLocked, a shared lock) inside the write transaction, after the employee row
     * is locked; the message names the first approved month at or after $month.
     *
     * @throws ValidationException
     */
    public static function checkMonth(string $month, string $field): void
    {
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) !== 1) {
            throw ValidationException::withMessages([$field => 'Choose a month.']);
        }

        $locked = PayrollLock::rangeTouchesLocked($month.'-01', '9999-12-31');

        if ($locked !== null) {
            throw ValidationException::withMessages([$field => PayrollLock::message($locked)]);
        }
    }

    /** @throws AuthorizationException */
    private function authorizeFor(User $by, Loan $loan): Employee
    {
        /** @var Employee $employee */
        $employee = $loan->employee()->with('user')->firstOrFail();
        SaveLoan::authorize($by, $employee);

        return $employee;
    }

    /** Lock order: the employee row first, then the payroll lock read (shared), then the loan row. */
    private function lockEmployee(Employee $employee): void
    {
        Employee::query()->whereKey($employee->getKey())->lockForUpdate()->first();
    }

    private function lockLoan(Loan $loan): Loan
    {
        /** @var Loan $locked */
        $locked = Loan::query()->whereKey($loan->getKey())->lockForUpdate()->firstOrFail();

        return $locked;
    }
}
