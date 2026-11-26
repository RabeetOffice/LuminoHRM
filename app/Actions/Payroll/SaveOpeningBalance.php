<?php

namespace App\Actions\Payroll;

use App\Models\Employee;
use App\Models\OpeningBalance;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Payroll\Money;
use App\Support\Payroll\TaxYear;
use App\Support\Settings;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Sets an employee's opening tax figures for a tax year (Payroll > Opening Figures, and the opening-tax import): tax
 * deducted and taxable income to date before go-live, in paisa. Setting, never adding: saving the same figures again
 * changes nothing and audits nothing (so importing the same file twice is harmless). Unique per employee and tax year.
 *
 * Who: PayrollAccess::mayEditSalary (via SaveLoan::authorize), else 403. One transaction (3 attempts): the employee row
 * is locked first, then the opening row is read for update. Audited "Opening tax saved" (subject the employee; old /
 * attributes in paisa plus formatted copies, the tax year as "2026-27").
 *
 * $note: null clears it; pass $keepNote = true (the import, which has no note column) to leave the stored note alone.
 */
class SaveOpeningBalance
{
    private bool $changed = false;

    /** @throws AuthorizationException */
    public function save(User $by, Employee $employee, int $taxYear, int $taxDeducted, int $taxableIncome, ?string $note, bool $keepNote = false): OpeningBalance
    {
        SaveLoan::authorize($by, $employee);

        return DB::transaction(function () use ($by, $employee, $taxYear, $taxDeducted, $taxableIncome, $note, $keepNote): OpeningBalance {
            Employee::query()->whereKey($employee->getKey())->lockForUpdate()->first();

            /** @var OpeningBalance|null $row */
            $row = OpeningBalance::query()
                ->where('employee_id', $employee->getKey())
                ->where('tax_year', $taxYear)
                ->lockForUpdate()
                ->first();

            $note = is_string($note) && trim($note) !== '' ? mb_substr(trim($note), 0, 255) : null;
            $old = $row === null ? null : self::snapshot($row);
            $row ??= new OpeningBalance(['employee_id' => $employee->getKey(), 'tax_year' => $taxYear]);

            $row->fill([
                'tax_deducted' => $taxDeducted,
                'taxable_income' => $taxableIncome,
                'note' => $keepNote ? $row->note : $note,
            ]);

            if ($row->exists && ! $row->isDirty()) {
                $this->changed = false;

                return $row;
            }

            $row->save();

            Audit::record('payroll', 'Opening tax saved', $employee, [
                'tax_year' => $taxYear,
                'tax_year_label' => TaxYear::label($taxYear, self::startMonth()),
                'old' => $old,
                'attributes' => self::snapshot($row),
            ], $by);

            $this->changed = true;

            return $row;
        }, 3);
    }

    /** Whether the last save() wrote anything. */
    public function changed(): bool
    {
        return $this->changed;
    }

    /** settings('payroll.tax_year_start_month'), 1..12, default 7. */
    public static function startMonth(): int
    {
        $month = (int) Settings::get('payroll.tax_year_start_month', 7);

        return $month >= 1 && $month <= 12 ? $month : 7;
    }

    /** @return array<string, mixed> */
    private static function snapshot(OpeningBalance $row): array
    {
        return [
            'tax_deducted' => $row->tax_deducted,
            'tax_deducted_formatted' => Money::format($row->tax_deducted),
            'taxable_income' => $row->taxable_income,
            'taxable_income_formatted' => Money::format($row->taxable_income),
            'note' => $row->note,
        ];
    }
}
