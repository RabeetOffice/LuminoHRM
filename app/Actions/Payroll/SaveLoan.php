<?php

namespace App\Actions\Payroll;

use App\Models\Employee;
use App\Models\Loan;
use App\Models\PayrollRun;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Payroll\Money;
use App\Support\Payroll\PayrollAccess;
use App\Support\PayrollLock;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates and edits a loan or advance (Payroll > Loans & Advances, and the opening-loans import). Money in paisa.
 *
 * create(): type, amount, installment (an advance defaults to its whole amount), start_month (the first deduction
 * month), opening_repaid (repaid before go-live: opening loans only, else 0), note.
 * update(): installment and note any time (a draft run picks them up when recalculated; approved months keep their
 * repayments); amount, start month and opening repaid only while the loan has no repayment and was never due in an
 * approved month, else [amount] HAS_REPAYMENTS / [start_month] DUE_IN_LOCKED. Type never changes. A cancelled loan
 * never changes.
 *
 * Rules: installment 1 paisa .. amount; opening_repaid 0 .. amount - 1 (something is left to recover); start_month
 * after the latest approved payroll month ([start_month] "Payroll for November 2026 is approved; start deductions after
 * it."), which also keeps it out of every locked month.
 *
 * Who: PayrollAccess::mayEditSalary (payroll.run; never one's own loan unless Admin; an Admin's or HR person's only by
 * an Admin), else AuthorizationException (403). One transaction (3 attempts): the employee row is locked first, then
 * the payroll lock is read (shared), then the loan row; decisions are read after the locks. Audited "Loan created" /
 * "Loan updated" (subject the employee; old / attributes in paisa plus formatted copies). An update that changes
 * nothing writes and audits nothing. A loan is never deleted.
 */
class SaveLoan
{
    public const HAS_REPAYMENTS = 'This loan already has repayments; only the installment and note can change.';

    public const DUE_IN_LOCKED = 'This loan was due in an approved payroll month; only the installment and note can change.';

    public const CANCELLED = 'This loan is cancelled and can no longer change.';

    private bool $changed = false;

    /** Set by usingLatestLocked(): the latest approved month read once by a caller that wraps many saves. */
    private bool $latestGiven = false;

    private ?string $latest = null;

    /**
     * For a caller that runs many saves inside its own transaction (the import): the latest approved month it read
     * BEFORE that transaction, so no save calls PayrollLock::latestLocked() inside a write transaction.
     */
    public function usingLatestLocked(?string $latest): static
    {
        $this->latestGiven = true;
        $this->latest = $latest;

        return $this;
    }

    private function latestLocked(): ?string
    {
        return $this->latestGiven ? $this->latest : PayrollLock::latestLocked();
    }

    /**
     * @param  array{type: string, amount: int, installment?: int|null, start_month: string, opening_repaid?: int|null, note?: string|null}  $data
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function create(User $by, Employee $employee, array $data): Loan
    {
        self::authorize($by, $employee);
        // Read before the transaction: latestLocked() share-locks every locked run row (PayrollLock lock rules).
        $latest = $this->latestLocked();

        return DB::transaction(function () use ($by, $employee, $data, $latest): Loan {
            Employee::query()->whereKey($employee->getKey())->lockForUpdate()->first();

            $amount = (int) $data['amount'];
            $installment = $data['installment'] ?? null;
            $installment = $installment === null && $data['type'] === 'advance' ? $amount : (int) $installment;
            $openingRepaid = (int) ($data['opening_repaid'] ?? 0);

            self::checkFigures($amount, $installment, $openingRepaid);
            self::checkStartMonth($data['start_month'], $latest);

            $loan = Loan::query()->create([
                'employee_id' => $employee->getKey(),
                'type' => $data['type'],
                'amount' => $amount,
                'installment' => $installment,
                'start_month' => $data['start_month'],
                'opening_repaid' => $openingRepaid,
                'status' => 'active',
                'note' => self::note($data['note'] ?? null),
                'created_by' => $by->getKey(),
            ]);

            Audit::record('payroll', 'Loan created', $employee, [
                'loan_id' => $loan->id,
                'attributes' => self::snapshot($loan),
            ], $by);

            $this->changed = true;

            return $loan;
        }, 3);
    }

    /**
     * @param  array{installment: int, note?: string|null, amount?: int|null, start_month?: string|null, opening_repaid?: int|null}  $data
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function update(User $by, Loan $loan, array $data): Loan
    {
        $employee = $loan->employee()->firstOrFail();
        self::authorize($by, $employee);
        $latest = $this->latestLocked();

        return DB::transaction(function () use ($by, $loan, $employee, $data, $latest): Loan {
            Employee::query()->whereKey($employee->getKey())->lockForUpdate()->first();
            $startArg = array_key_exists('start_month', $data) && $data['start_month'] !== null ? (string) $data['start_month'] : null;

            // Payroll lock (shared, on one run row) after the employee lock, before the loan row.
            if ($startArg !== null && $startArg !== $loan->start_month) {
                self::checkStartMonth($startArg, $latest);
            }

            /** @var Loan $locked */
            $locked = Loan::query()->whereKey($loan->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->isActive()) {
                throw ValidationException::withMessages(['loan' => self::CANCELLED]);
            }

            $amount = array_key_exists('amount', $data) && $data['amount'] !== null ? (int) $data['amount'] : $locked->amount;
            $start = array_key_exists('start_month', $data) && $data['start_month'] !== null ? (string) $data['start_month'] : $locked->start_month;
            $openingRepaid = array_key_exists('opening_repaid', $data) && $data['opening_repaid'] !== null ? (int) $data['opening_repaid'] : $locked->opening_repaid;
            $installment = (int) $data['installment'];
            $note = array_key_exists('note', $data) ? self::note($data['note']) : $locked->note;

            $coreChanged = $amount !== $locked->amount || $start !== $locked->start_month || $openingRepaid !== $locked->opening_repaid;

            if ($coreChanged) {
                if ($locked->repayments()->exists()) {
                    throw ValidationException::withMessages(['amount' => self::HAS_REPAYMENTS]);
                }

                if ($latest !== null && $locked->start_month <= $latest) {
                    throw ValidationException::withMessages(['start_month' => self::DUE_IN_LOCKED]);
                }
            }

            self::checkFigures($amount, $installment, $openingRepaid);

            $old = self::snapshot($locked);
            $locked->fill([
                'amount' => $amount,
                'installment' => $installment,
                'start_month' => $start,
                'opening_repaid' => $openingRepaid,
                'note' => $note,
            ]);

            if (! $locked->isDirty()) {
                $this->changed = false;

                return $locked;
            }

            $locked->save();

            Audit::record('payroll', 'Loan updated', $employee, [
                'loan_id' => $locked->id,
                'old' => $old,
                'attributes' => self::snapshot($locked),
            ], $by);

            $this->changed = true;

            return $locked;
        }, 3);
    }

    /** Whether the last create() / update() wrote anything. */
    public function changed(): bool
    {
        return $this->changed;
    }

    /** @throws AuthorizationException */
    public static function authorize(User $by, Employee $employee): void
    {
        if (! PayrollAccess::mayEditSalary($by, $employee)) {
            throw new AuthorizationException('You cannot change this person\'s payroll.');
        }
    }

    /**
     * The checks every figure must pass (also used by the import before it saves).
     *
     * @throws ValidationException
     */
    public static function checkFigures(int $amount, int $installment, int $openingRepaid): void
    {
        $errors = [];

        if ($amount <= 0 || $amount > Money::MAX_AMOUNT) {
            $errors['amount'] = 'Enter an amount above zero.';
        }

        if ($installment <= 0 || $installment > max($amount, 0)) {
            $errors['installment'] = 'The installment must be above zero and no more than the amount.';
        }

        if ($openingRepaid < 0 || $openingRepaid >= max($amount, 1)) {
            $errors['opening_repaid'] = 'What was repaid before go-live must be less than the amount.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * The first deduction month must come after the latest approved payroll month ($latest, read by the caller before
     * its transaction) and must not be locked itself (PayrollLock::isLocked: one shared lock on that run row, so call it
     * after locking the employee row).
     *
     * @throws ValidationException
     */
    public static function checkStartMonth(string $month, ?string $latest): void
    {
        if ($latest !== null && $month <= $latest) {
            throw ValidationException::withMessages(['start_month' => self::startMessage($latest)]);
        }

        if (PayrollLock::isLocked($month)) {
            throw ValidationException::withMessages(['start_month' => self::startMessage($month)]);
        }
    }

    /** "Payroll for November 2026 is approved; start deductions after it." */
    public static function startMessage(string $latest): string
    {
        return 'Payroll for '.PayrollRun::monthLabel($latest).' is approved; start deductions after it.';
    }

    /** @return array<string, mixed> */
    public static function snapshot(Loan $loan): array
    {
        return [
            'type' => $loan->type,
            'amount' => $loan->amount,
            'amount_formatted' => Money::format($loan->amount),
            'installment' => $loan->installment,
            'installment_formatted' => Money::format($loan->installment),
            'start_month' => $loan->start_month,
            'opening_repaid' => $loan->opening_repaid,
            'opening_repaid_formatted' => Money::format($loan->opening_repaid),
            'note' => $loan->note,
        ];
    }

    private static function note(mixed $note): ?string
    {
        $note = is_string($note) ? trim($note) : null;

        return $note === '' || $note === null ? null : mb_substr($note, 0, 255);
    }
}
