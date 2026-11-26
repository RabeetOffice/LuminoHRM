<?php

namespace App\Imports;

use App\Actions\Payroll\SaveLoan;
use App\Models\Employee;
use App\Models\Loan;
use App\Models\PayrollRun;
use App\Models\User;
use App\Support\Payroll\Money;
use App\Support\PayrollLock;

/**
 * The go-live opening-loans workbook (Payroll > Opening Figures > Import loans): loans and advances already running when
 * the app starts, with what was repaid before go-live. Columns, exactly as the template (Note may be left out):
 *
 *   Employee Code | Type | Original Amount | Repaid Before Go-Live | Monthly Installment | First Deduction Month | Note
 *
 * The file is read by PayrollOpeningWorkbook. check() validates every row with a fixed number of queries whatever the
 * row count (the employees by code with their logins and roles, their active loans, the latest approved month):
 *   - Employee Code: an existing employee the user may edit (else "You cannot change this person's payroll.");
 *   - Type: Loan or Advance (letter case ignored);
 *   - Original Amount: whole rupees above 0; Repaid Before Go-Live: whole rupees, 0 up to less than the amount (blank =
 *     0); Monthly Installment: whole rupees, 1 up to the amount (blank on an Advance = the whole amount);
 *   - First Deduction Month: "YYYY-MM" (or a date Excel made of it; a bare number such as 7 or 202611 is refused); a NEW
 *     loan starts after the latest approved payroll month, this month or later, at most 5 years ahead;
 *   - Note: at most 255 characters; a blank cell or a missing Note column keeps the stored note;
 *   - the same person, type, amount and first month twice in the file: an error on each of those rows.
 *
 * Idempotent (sets, never adds): a row whose person, type, original amount and first month match an active loan
 * already stored updates that loan (installment, repaid before go-live, note; the repaid figure only while the loan has
 * no repayment and was never due in an approved month) instead of creating another; a row equal to it is unchanged.
 * A row that differs from the same person's active loan of that type in amount or first month is still a new loan, with
 * a warning (not an error) that it may be a correction; a row with the figures of a CANCELLED loan is an error.
 */
final class OpeningLoansImport extends PayrollOpeningWorkbook
{
    public static function columns(): array
    {
        return [
            'Employee Code' => 'code',
            'Type' => 'type',
            'Original Amount' => 'amount',
            'Repaid Before Go-Live' => 'repaid',
            'Monthly Installment' => 'installment',
            'First Deduction Month' => 'month',
            'Note' => 'note',
        ];
    }

    public static function optional(): array
    {
        return ['note'];
    }

    /**
     * @param  list<array{line: int, cells: array<string, mixed>}>  $rows
     * @return list<array{line: int, display: array<string, string>, errors: list<string>, warnings: list<string>, unchanged: bool, data: array{employee_id: int, loan_id: int|null, type: string, amount: int, opening_repaid: int, installment: int, start_month: string, note?: string}|null}>
     */
    public function check(array $rows, User $user): array
    {
        $byCode = self::people($rows);
        $latest = PayrollLock::latestLocked();

        $existing = [];
        $cancelled = [];
        $activeOf = [];
        if ($byCode->isNotEmpty()) {
            $loans = Loan::query()
                ->whereIn('status', ['active', 'cancelled'])
                ->whereIn('employee_id', $byCode->pluck('id')->all())
                ->withExists('repayments')
                ->orderBy('id')
                ->get();

            foreach ($loans as $loan) {
                $key = "{$loan->employee_id}:{$loan->type}:{$loan->amount}:{$loan->start_month}";

                if ($loan->status === 'cancelled') {
                    $cancelled[$key] = true;

                    continue;
                }

                $existing[$key] ??= $loan;
                $activeOf["{$loan->employee_id}:{$loan->type}"][] = $loan;
            }
        }

        $checked = [];
        $keys = [];

        foreach ($rows as $row) {
            $cells = $row['cells'];
            $errors = [];
            $code = self::text($cells['code'] ?? null);
            $typeText = self::text($cells['type'] ?? null);
            $amountText = self::text($cells['amount'] ?? null);
            $repaidText = self::text($cells['repaid'] ?? null);
            $installmentText = self::text($cells['installment'] ?? null);
            $monthText = self::text($cells['month'] ?? null);
            $note = self::text($cells['note'] ?? null);

            $employee = self::employee($code, $byCode, $user, $errors);
            $type = match (mb_strtolower($typeText)) {
                'loan' => 'loan',
                'advance' => 'advance',
                default => null,
            };
            $amount = self::wholeRupees($cells['amount'] ?? null);
            $repaid = $repaidText === '' ? 0 : self::wholeRupees($cells['repaid'] ?? null);
            $installment = $installmentText === '' && $type === 'advance' ? $amount : self::wholeRupees($cells['installment'] ?? null);
            $month = self::month($cells['month'] ?? null);

            if ($typeText === '') {
                $errors[] = 'Type is required.';
            } elseif ($type === null) {
                $errors[] = 'Type must be Loan or Advance.';
            }

            if ($amountText === '') {
                $errors[] = 'Original Amount is required.';
            } elseif ($amount === null || $amount === 0) {
                $errors[] = 'Original Amount must be whole rupees above 0.';
                $amount = null;
            }

            if ($repaid === null) {
                $errors[] = 'Repaid Before Go-Live must be whole rupees, 0 or more.';
            } elseif ($amount !== null && $repaid >= $amount) {
                $errors[] = 'Repaid Before Go-Live must be less than the Original Amount (nothing would be left to recover).';
            }

            if ($installmentText === '' && $type !== 'advance') {
                $errors[] = 'Monthly Installment is required.';
            } elseif ($installment === null || $installment === 0) {
                $errors[] = 'Monthly Installment must be whole rupees above 0.';
                $installment = null;
            } elseif ($amount !== null && $installment > $amount) {
                $errors[] = 'Monthly Installment cannot be more than the Original Amount.';
            }

            if ($monthText === '') {
                $errors[] = 'First Deduction Month is required.';
            } elseif ($month === null) {
                $errors[] = 'First Deduction Month must be a month written as YYYY-MM, for example 2026-12.';
            }

            if (mb_strlen($note) > 255) {
                $errors[] = 'Note must be 255 characters or fewer.';
            }

            $key = $employee !== null && $type !== null && $amount !== null && $month !== null
                ? "{$employee->id}:{$type}:{$amount}:{$month}"
                : null;

            if ($key !== null) {
                $keys[$key][] = $row['line'];
            }

            $checked[] = [
                'line' => $row['line'],
                'display' => [
                    'code' => $code,
                    'name' => $employee === null ? '' : $employee->full_name,
                    'type' => $type === null ? $typeText : ucfirst($type),
                    'amount' => $amount !== null ? Money::format($amount) : $amountText,
                    'repaid' => $repaid !== null ? Money::format($repaid) : $repaidText,
                    'installment' => $installment !== null ? Money::format($installment) : $installmentText,
                    'remaining' => $amount !== null && $repaid !== null && $repaid < $amount ? Money::format($amount - $repaid) : '',
                    'month' => $month ?? $monthText,
                    'note' => mb_strimwidth($note, 0, 80, '…'),
                    'action' => '',
                ],
                'errors' => $errors,
                'key' => $key,
                'employee' => $employee,
                'type' => $type,
                'amount' => $amount,
                'repaid' => $repaid,
                'installment' => $installment,
                'month' => $month,
                'note' => $note === '' ? null : $note,
                'cancelled' => $key !== null && isset($cancelled[$key]),
            ];
        }

        $result = [];

        foreach ($checked as $row) {
            if ($row['key'] !== null && count($keys[$row['key']]) > 1) {
                $row['errors'][] = 'This loan (person, type, amount and first month) appears more than once in the file (rows '.implode(', ', $keys[$row['key']]).').';
            }

            $match = $row['key'] !== null ? ($existing[$row['key']] ?? null) : null;
            $unchanged = false;
            $warnings = [];

            if ($row['errors'] === [] && $match === null && $latest !== null && $row['month'] <= $latest) {
                $row['errors'][] = SaveLoan::startMessage($latest);
            }

            if ($row['errors'] === [] && $match === null) {
                $window = SaveLoan::startWindowError((string) $row['month'], 'First Deduction Month');

                if ($window !== null) {
                    $row['errors'][] = $window;
                } elseif ($row['cancelled']) {
                    $row['errors'][] = SaveLoan::CANCELLED_MATCH;
                } else {
                    /** @var Employee $person */
                    $person = $row['employee'];

                    foreach ($activeOf["{$person->id}:{$row['type']}"] ?? [] as $other) {
                        $warnings[] = "This person already has an active {$other->type} of ".Money::format($other->amount).' from '
                            .PayrollRun::monthLabel($other->start_month).'. Change it on the loan page if this row corrects it.';
                    }
                }
            }

            if ($row['errors'] === [] && $match !== null && $match->opening_repaid !== $row['repaid']) {
                if ((bool) $match->getAttribute('repayments_exists')) {
                    $row['errors'][] = SaveLoan::HAS_REPAYMENTS;
                } elseif ($latest !== null && $match->start_month <= $latest) {
                    $row['errors'][] = SaveLoan::DUE_IN_LOCKED;
                }
            }

            $data = null;

            if ($row['errors'] === []) {
                /** @var Employee $employee */
                $employee = $row['employee'];
                $unchanged = $match !== null
                    && $match->opening_repaid === $row['repaid']
                    && $match->installment === $row['installment']
                    && ($row['note'] === null || $match->note === $row['note']);
                $row['display']['action'] = $match === null ? 'New' : ($unchanged ? 'No change' : 'Updates the stored loan');
                $data = [
                    'employee_id' => (int) $employee->id,
                    'loan_id' => $match?->id,
                    'type' => (string) $row['type'],
                    'amount' => (int) $row['amount'],
                    'opening_repaid' => (int) $row['repaid'],
                    'installment' => (int) $row['installment'],
                    'start_month' => (string) $row['month'],
                ];

                // A blank cell (or no Note column) leaves the stored note alone.
                if ($row['note'] !== null) {
                    $data['note'] = $row['note'];
                }
            }

            $result[] = [
                'line' => $row['line'],
                'display' => $row['display'],
                'errors' => $row['errors'],
                'warnings' => $row['errors'] === [] ? $warnings : [],
                'unchanged' => $unchanged,
                'data' => $data,
            ];
        }

        return $result;
    }
}
