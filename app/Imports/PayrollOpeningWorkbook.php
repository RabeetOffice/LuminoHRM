<?php

namespace App\Imports;

use App\Models\Employee;
use App\Models\User;
use App\Support\Payroll\PayrollAccess;
use Illuminate\Database\Eloquent\Collection;
use Maatwebsite\Excel\Concerns\ToArray;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\WithReadFilter;
use Maatwebsite\Excel\Excel as ExcelType;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use Throwable;
use ZipArchive;

/**
 * The file handling shared by the two go-live payroll imports (OpeningTaxImport, OpeningLoansImport), the same guards as
 * the leave opening-balance import (LeaveOpeningImport): a real .xlsx (zip signature, workbook content type, no macros),
 * nothing that unpacks too large, at most 2,000 data rows (declared size first, then the read filter), data only,
 * formulas never calculated, only the first sheet, blank rows skipped, cells cleaned (EmployeesImport::clean), the
 * header row matched by name (ignoring case, spaces and "*"), unknown columns ignored with a notice.
 *
 * Also the cell readers both imports use: text(), wholeRupees() (a whole-rupee amount as paisa, from a number cell or
 * digits with optional thousands commas) and month() ("YYYY-MM" text, or a date Excel made of it), and people() (the
 * employees named by the rows, with their logins and roles: one query each, for PayrollAccess::mayEditSalary).
 */
abstract class PayrollOpeningWorkbook implements ToArray, WithMultipleSheets, WithReadFilter
{
    public const MAX_ROWS = 2000;

    public const MAX_KILOBYTES = 5120;

    /** Largest whole-rupee amount a row may carry (the forms' limit). */
    public const MAX_RUPEES = 99_999_999;

    public const NOT_EDITABLE = 'You cannot change this person\'s payroll.';

    public const NO_ROWS = 'The file has no rows below the header.';

    private const MAX_PART_BYTES = 20 * 1024 * 1024;

    private const MAX_TOTAL_BYTES = 60 * 1024 * 1024;

    private const MAX_ENTRIES = 500;

    private const MAX_COLUMNS = 20;

    private const WORKBOOK_TYPE = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml';

    private ?RowLimitFilter $filter = null;

    /**
     * header => key, in the template's order.
     *
     * @return array<string, string>
     */
    abstract public static function columns(): array;

    /**
     * The keys that may be left blank in every row.
     *
     * @return list<string>
     */
    public static function optional(): array
    {
        return [];
    }

    /**
     * @return array{rows: list<array{line: int, cells: array<string, mixed>}>, notices: list<string>}
     *
     * @throws ImportFileException with a message for the person who uploaded the file
     */
    public function read(string $path): array
    {
        $this->assertWorkbook($path);
        $this->assertRowCount($path);

        $this->filter = new RowLimitFilter(self::MAX_ROWS + 1, self::MAX_COLUMNS);

        try {
            $sheets = Excel::toArray($this, $path, null, ExcelType::XLSX);
        } catch (Throwable) {
            throw new ImportFileException(EmployeesImport::UNREADABLE);
        }

        if ($this->filter->exceeded()) {
            throw new ImportFileException(EmployeesImport::TOO_MANY_ROWS);
        }

        return $this->rows(array_values($sheets[0] ?? []));
    }

    /** Required by ToArray; read() takes the rows from Excel::toArray instead. */
    public function array(array $array): void {}

    /** Only the first worksheet; the "Help" sheet is never loaded. */
    public function sheets(): array
    {
        return [0 => $this];
    }

    public function readFilter(): IReadFilter
    {
        return $this->filter ??= new RowLimitFilter(self::MAX_ROWS + 1, self::MAX_COLUMNS);
    }

    /** A cell as trimmed text: 2026.0 → "2026", 1.5 → "1.5", null → "". */
    public static function text(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_float($value) && fmod($value, 1.0) === 0.0 && abs($value) < 1e12) {
            return (string) (int) $value;
        }

        return trim((string) $value);
    }

    /**
     * A whole-rupee amount 0..MAX_RUPEES as paisa, or null: a number cell without a fraction, or text of digits with
     * optional thousands commas ("64,930", "64930"). Never a float in the result.
     */
    public static function wholeRupees(mixed $value): ?int
    {
        if (is_int($value)) {
            $rupees = $value;
        } elseif (is_float($value)) {
            if (! is_finite($value) || fmod($value, 1.0) !== 0.0 || abs($value) > self::MAX_RUPEES) {
                return null;
            }
            $rupees = (int) $value;
        } else {
            $text = self::text($value);

            if (preg_match('/^(\d{1,3}(,\d{3})+|\d{1,9})$/', $text) !== 1) {
                return null;
            }
            $rupees = (int) str_replace(',', '', $text);
        }

        return $rupees >= 0 && $rupees <= self::MAX_RUPEES ? $rupees * 100 : null;
    }

    /** "YYYY-MM" from text, or from a date Excel made of it (a serial number); null when it is neither. */
    public static function month(mixed $value): ?string
    {
        if (is_int($value) || is_float($value)) {
            if ($value < 1 || $value > 2958465) {
                return null;
            }

            try {
                return ExcelDate::excelToDateTimeObject((float) $value)->format('Y-m');
            } catch (Throwable) {
                return null;
            }
        }

        $text = self::text($value);

        return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $text) === 1 ? $text : null;
    }

    /**
     * The employees named by these rows' "code" cells, keyed by the upper-cased code, with their logins and roles
     * loaded (so PayrollAccess::mayEditSalary runs no query per row).
     *
     * @param  list<array{line: int, cells: array<string, mixed>}>  $rows
     * @return \Illuminate\Support\Collection<string, Employee>
     */
    protected static function people(array $rows): \Illuminate\Support\Collection
    {
        $codes = [];
        foreach ($rows as $row) {
            $code = self::text($row['cells']['code'] ?? null);
            if ($code !== '') {
                $codes[mb_strtoupper($code)] = true;
            }
        }

        /** @var Collection<int, Employee> $employees */
        $employees = $codes === []
            ? new Collection
            : Employee::query()->with('user.roles')->whereIn('employee_code', array_keys($codes))->get();

        return $employees->keyBy(fn (Employee $e) => mb_strtoupper((string) $e->employee_code));
    }

    /**
     * The employee of a row, or the errors that stop it: missing code, unknown code, someone $user may not edit.
     *
     * @param  \Illuminate\Support\Collection<string, Employee>  $byCode
     * @param  list<string>  $errors
     */
    protected static function employee(string $code, \Illuminate\Support\Collection $byCode, User $user, array &$errors): ?Employee
    {
        if ($code === '') {
            $errors[] = 'Employee Code is required.';

            return null;
        }

        $employee = $byCode->get(mb_strtoupper($code));

        if ($employee === null) {
            $errors[] = "No employee has the code \"{$code}\".";

            return null;
        }

        if (! PayrollAccess::mayEditSalary($user, $employee)) {
            $errors[] = self::NOT_EDITABLE;

            return null;
        }

        return $employee;
    }

    private function assertWorkbook(string $path): void
    {
        $handle = @fopen($path, 'rb');
        $signature = $handle === false ? '' : (string) fread($handle, 4);

        if ($handle !== false) {
            fclose($handle);
        }

        if ($signature !== "PK\x03\x04") {
            throw new ImportFileException(EmployeesImport::NOT_XLSX);
        }

        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new ImportFileException(EmployeesImport::NOT_XLSX);
        }

        try {
            $types = $zip->locateName('[Content_Types].xml') === false ? '' : (string) $zip->getFromName('[Content_Types].xml', 1024 * 1024);

            if (! str_contains($types, self::WORKBOOK_TYPE)
                || str_contains(strtolower($types), 'macroenabled')
                || $zip->locateName('xl/workbook.xml') === false
                || $zip->locateName('xl/vbaProject.bin', ZipArchive::FL_NOCASE) !== false) {
                throw new ImportFileException(EmployeesImport::NOT_XLSX);
            }

            if ($zip->numFiles > self::MAX_ENTRIES) {
                throw new ImportFileException(EmployeesImport::TOO_BIG_UNPACKED);
            }

            $total = 0;

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $size = (int) ($zip->statIndex($i)['size'] ?? 0);
                $total += $size;

                if ($size > self::MAX_PART_BYTES || $total > self::MAX_TOTAL_BYTES) {
                    throw new ImportFileException(EmployeesImport::TOO_BIG_UNPACKED);
                }
            }
        } finally {
            $zip->close();
        }
    }

    private function assertRowCount(string $path): void
    {
        try {
            $reader = new Xlsx;
            $reader->setReadDataOnly(true);
            $info = $reader->listWorksheetInfo($path)[0] ?? null;
        } catch (Throwable) {
            throw new ImportFileException(EmployeesImport::UNREADABLE);
        }

        if ($info === null) {
            throw new ImportFileException(EmployeesImport::UNREADABLE);
        }

        if ((int) $info['totalRows'] > self::MAX_ROWS + 1) {
            throw new ImportFileException(EmployeesImport::TOO_MANY_ROWS);
        }
    }

    /**
     * @param  list<array<int, mixed>>  $sheet  row 1 first
     * @return array{rows: list<array{line: int, cells: array<string, mixed>}>, notices: list<string>}
     */
    private function rows(array $sheet): array
    {
        $known = [];
        foreach (static::columns() as $name => $key) {
            $known[EmployeeSheet::normaliseHeader($name)] = $key;
        }

        $columns = [];
        $ignored = [];

        foreach ($sheet[0] ?? [] as $position => $name) {
            $name = is_scalar($name) ? EmployeesImport::clean($name) : null;

            if (! is_string($name) || $name === '') {
                continue;
            }

            $key = $known[EmployeeSheet::normaliseHeader($name)] ?? null;

            if ($key === null) {
                $ignored[] = mb_strimwidth($name, 0, 60, '…');

                continue;
            }

            if (in_array($key, $columns, true)) {
                throw new ImportFileException('The column "'.array_search($key, static::columns(), true).'" appears more than once.');
            }

            $columns[$position] = $key;
        }

        $missing = array_keys(array_filter(
            static::columns(),
            fn (string $key) => ! in_array($key, $columns, true) && ! in_array($key, static::optional(), true),
        ));

        if ($missing !== []) {
            throw new ImportFileException('The file is missing required columns: '.implode(', ', $missing).'. Use the header row from the template.');
        }

        $notices = $ignored === [] ? [] : ['These columns are not part of the template and were ignored: '.implode(', ', $ignored).'.'];
        $rows = [];

        foreach (array_slice($sheet, 1) as $index => $values) {
            $cells = [];

            foreach ($columns as $position => $key) {
                $value = $values[$position] ?? null;
                $cells[$key] = is_scalar($value) ? EmployeesImport::clean($value) : null;
            }

            if (array_filter($cells, fn ($value) => $value !== null && $value !== '') === []) {
                continue;
            }

            $rows[] = ['line' => $index + 2, 'cells' => $cells];
        }

        if ($rows === []) {
            throw new ImportFileException(self::NO_ROWS);
        }

        return ['rows' => $rows, 'notices' => $notices];
    }
}
