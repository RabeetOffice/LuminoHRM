<?php

namespace App\Imports;

use App\Actions\Payroll\SaveOpeningBalance;
use App\Models\Employee;
use App\Models\OpeningBalance;
use App\Models\User;
use App\Support\Attendance\AttendanceClock;
use App\Support\Payroll\Money;
use App\Support\Payroll\TaxYear;

/**
 * The go-live opening-tax workbook (Payroll > Opening Figures > Import tax): per employee and tax year, the taxable
 * income and the tax deducted so far that year before the app. Columns, exactly as the template:
 *
 *   Employee Code | Tax Year | Taxable Income To Date | Tax Deducted To Date
 *
 * The file is read by PayrollOpeningWorkbook. check() validates every row with a fixed number of queries whatever the
 * row count (the employees by code with their logins and roles, the stored opening rows):
 *   - Employee Code: an existing employee the user may edit (PayrollAccess::mayEditSalary, else "You cannot change this
 *     person's payroll.");
 *   - Tax Year: "2026-27" style, the current tax year or the previous one;
 *   - the two amounts: whole rupees, 0 or more (number cells, or digits with thousands commas);
 *   - the same person and tax year twice in the file: an error on each of those rows.
 * A row equal to what is stored is marked unchanged: the run sets (never adds), so importing the same file again
 * changes nothing.
 */
final class OpeningTaxImport extends PayrollOpeningWorkbook
{
    public static function columns(): array
    {
        return [
            'Employee Code' => 'code',
            'Tax Year' => 'tax_year',
            'Taxable Income To Date' => 'taxable_income',
            'Tax Deducted To Date' => 'tax_deducted',
        ];
    }

    /**
     * The tax years a row may name: the current one (by the attendance "today") and the previous one.
     *
     * @return list<int>
     */
    public static function acceptedYears(): array
    {
        $current = TaxYear::of(substr(app(AttendanceClock::class)->attendanceToday(), 0, 7), SaveOpeningBalance::startMonth());

        return [$current, $current - 1];
    }

    /**
     * @param  list<array{line: int, cells: array<string, mixed>}>  $rows
     * @return list<array{line: int, display: array{code: string, name: string, tax_year: string, taxable_income: string, tax_deducted: string}, errors: list<string>, unchanged: bool, data: array{employee_id: int, tax_year: int, taxable_income: int, tax_deducted: int}|null}>
     */
    public function check(array $rows, User $user): array
    {
        $start = SaveOpeningBalance::startMonth();
        $years = self::acceptedYears();
        $byCode = self::people($rows);

        $stored = $byCode->isEmpty()
            ? collect()
            : OpeningBalance::query()
                ->whereIn('employee_id', $byCode->pluck('id')->all())
                ->whereIn('tax_year', $years)
                ->get()
                ->keyBy(fn (OpeningBalance $o) => "{$o->employee_id}:{$o->tax_year}");

        $checked = [];
        $keys = [];

        foreach ($rows as $row) {
            $cells = $row['cells'];
            $errors = [];
            $code = self::text($cells['code'] ?? null);
            $yearText = self::text($cells['tax_year'] ?? null);
            $incomeText = self::text($cells['taxable_income'] ?? null);
            $taxText = self::text($cells['tax_deducted'] ?? null);

            $employee = self::employee($code, $byCode, $user, $errors);
            $year = TaxYear::parse($yearText, $start);
            $income = self::wholeRupees($cells['taxable_income'] ?? null);
            $tax = self::wholeRupees($cells['tax_deducted'] ?? null);

            if ($yearText === '') {
                $errors[] = 'Tax Year is required.';
            } elseif ($year === null || ! in_array($year, $years, true)) {
                $errors[] = 'Tax Year must be '.TaxYear::label($years[0], $start).' or '.TaxYear::label($years[1], $start).'.';
                $year = null;
            }

            if ($incomeText === '') {
                $errors[] = 'Taxable Income To Date is required.';
            } elseif ($income === null) {
                $errors[] = 'Taxable Income To Date must be whole rupees, 0 or more.';
            }

            if ($taxText === '') {
                $errors[] = 'Tax Deducted To Date is required.';
            } elseif ($tax === null) {
                $errors[] = 'Tax Deducted To Date must be whole rupees, 0 or more.';
            }

            $key = $employee !== null && $year !== null ? "{$employee->id}:{$year}" : null;

            if ($key !== null) {
                $keys[$key][] = $row['line'];
            }

            $checked[] = [
                'line' => $row['line'],
                'display' => [
                    'code' => $code,
                    'name' => $employee === null ? '' : $employee->full_name,
                    'tax_year' => $year !== null ? TaxYear::label($year, $start) : $yearText,
                    'taxable_income' => $income !== null ? Money::format($income) : $incomeText,
                    'tax_deducted' => $tax !== null ? Money::format($tax) : $taxText,
                ],
                'errors' => $errors,
                'key' => $key,
                'employee' => $employee,
                'year' => $year,
                'income' => $income,
                'tax' => $tax,
            ];
        }

        $result = [];

        foreach ($checked as $row) {
            if ($row['key'] !== null && count($keys[$row['key']]) > 1) {
                $row['errors'][] = 'This person and tax year appear more than once in the file (rows '.implode(', ', $keys[$row['key']]).').';
            }

            $data = null;
            $unchanged = false;

            if ($row['errors'] === []) {
                /** @var Employee $employee */
                $employee = $row['employee'];
                $existing = $stored->get($row['key']);
                $unchanged = $existing !== null
                    && $existing->tax_deducted === $row['tax']
                    && $existing->taxable_income === $row['income'];
                $data = [
                    'employee_id' => (int) $employee->id,
                    'tax_year' => (int) $row['year'],
                    'taxable_income' => (int) $row['income'],
                    'tax_deducted' => (int) $row['tax'],
                ];
            }

            $result[] = [
                'line' => $row['line'],
                'display' => $row['display'],
                'errors' => $row['errors'],
                'unchanged' => $unchanged,
                'data' => $data,
            ];
        }

        return $result;
    }
}
