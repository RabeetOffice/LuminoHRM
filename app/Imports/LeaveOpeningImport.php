<?php

namespace App\Imports;

use App\Actions\Leave\AdjustBalance;
use App\Console\Commands\AllocateLeave;
use App\Models\Employee;
use App\Models\LeaveType;
use App\Models\User;
use App\Support\Attendance\AttendanceClock;
use App\Support\Leave\BalanceLine;
use App\Support\Leave\LeaveBalances;
use App\Support\Leave\LeaveDays;
use App\Support\Leave\LeaveRouting;
use Illuminate\Database\Eloquent\Collection;
use Maatwebsite\Excel\Concerns\ToArray;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\WithReadFilter;
use Maatwebsite\Excel\Excel as ExcelType;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use Throwable;
use ZipArchive;

/**
 * The go-live opening-balance workbook (Leave > Balances > Import): per employee, paid type and year, the days used so
 * far that year before the app ("Days Used", stored as opening_used). Columns, exactly as the template:
 *
 *   Employee Code | Leave Type | Year | Days Used
 *
 * read() checks the file the way the employee import does (EmployeesImport, whose guards are private, so they are
 * repeated here): a real .xlsx (zip signature, workbook content type, no macros), nothing that unpacks too large, at most
 * 2,000 data rows (declared size first, then the read filter, which notes any row beyond the limit), data only, formulas
 * never calculated. Only the first sheet is read; blank rows are skipped; cells are cleaned (EmployeesImport::clean).
 *
 * check() validates every row against the current data with a fixed number of queries whatever the row count (the
 * employees by code, the types, the privileged logins, and LeaveBalances::forEmployees for the overdrawn warnings):
 *   - Employee Code: an existing employee; the user must administer their leave (LeaveRouting::mayAdminister), else
 *     "You cannot change this person's balance."; employed at some point in that year;
 *   - Leave Type: the name of an active paid type (letter case ignored: names are unique ignoring case);
 *   - Year: the current leave year or the previous one;
 *   - Days Used: 0 to 365 in steps of 0.5;
 *   - the same person, type and year twice in the file: an error on each of those rows.
 * A row that would leave the person overdrawn is a warning, not an error (the run confirms it). A row equal to what is
 * stored is marked unchanged: importing the same file again changes nothing.
 */
final class LeaveOpeningImport implements ToArray, WithMultipleSheets, WithReadFilter
{
    /** header => key, in the template's order. Every column is required. */
    public const COLUMNS = [
        'Employee Code' => 'code',
        'Leave Type' => 'type',
        'Year' => 'year',
        'Days Used' => 'days',
    ];

    public const MAX_ROWS = 2000;

    public const MAX_KILOBYTES = 5120;

    public const MAX_DAYS = 365;

    public const NOT_ADMINISTRABLE = 'You cannot change this person\'s balance.';

    public const NO_ROWS = 'The file has no rows below the header.';

    private const MAX_PART_BYTES = 20 * 1024 * 1024;

    private const MAX_TOTAL_BYTES = 60 * 1024 * 1024;

    private const MAX_ENTRIES = 500;

    private const MAX_COLUMNS = 20;

    private const WORKBOOK_TYPE = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml';

    private ?RowLimitFilter $filter = null;

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

    /**
     * Every row with its errors, warnings and, when it has no error, the values to save.
     *
     * @param  list<array{line: int, cells: array<string, mixed>}>  $rows
     * @return list<array{line: int, display: array{code: string, name: string, type: string, year: string, days: string}, errors: list<string>, warnings: list<string>, unchanged: bool, data: array{employee_id: int, type_id: int, year: int, days: float}|null}>
     */
    public function check(array $rows, User $user): array
    {
        $current = (int) substr(app(AttendanceClock::class)->attendanceToday(), 0, 4);
        $years = [$current - 1, $current];

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
            : Employee::query()->whereIn('employee_code', array_keys($codes))->get();
        $byCode = $employees->keyBy(fn (Employee $e) => mb_strtoupper((string) $e->employee_code));

        $types = LeaveType::query()->get()->keyBy(fn (LeaveType $t) => mb_strtolower(trim($t->name)));
        $routing = new LeaveRouting;

        $employed = [];
        foreach ($years as $year) {
            $employed[$year] = $employees->isEmpty()
                ? []
                : array_flip(AllocateLeave::employedIn(Employee::query()->whereKey($employees->modelKeys()), $year)->pluck('id')->all());
        }

        // First pass: field checks and the key of each row.
        $checked = [];
        $keys = [];

        foreach ($rows as $row) {
            $cells = $row['cells'];
            $errors = [];
            $codeText = self::text($cells['code'] ?? null);
            $typeText = self::text($cells['type'] ?? null);
            $yearText = self::text($cells['year'] ?? null);
            $daysText = self::text($cells['days'] ?? null);

            $employee = $codeText === '' ? null : $byCode->get(mb_strtoupper($codeText));
            $type = $typeText === '' ? null : $types->get(mb_strtolower($typeText));
            $year = preg_match('/^\d{4}$/', $yearText) === 1 ? (int) $yearText : null;
            $days = is_numeric($daysText) ? (float) $daysText : null;

            if ($codeText === '') {
                $errors[] = 'Employee Code is required.';
            } elseif ($employee === null) {
                $errors[] = "No employee has the code \"{$codeText}\".";
            } elseif (! $routing->mayAdminister($user, $employee)) {
                $errors[] = self::NOT_ADMINISTRABLE;
            }

            if ($typeText === '') {
                $errors[] = 'Leave Type is required.';
            } elseif ($type === null) {
                $errors[] = "No leave type is named \"{$typeText}\".";
            } elseif ($type->isUnpaid()) {
                $errors[] = "{$type->name} is unpaid leave: it has no balance.";
            } elseif (! $type->is_active) {
                $errors[] = "{$type->name} is inactive.";
            }

            if ($yearText === '') {
                $errors[] = 'Year is required.';
            } elseif ($year === null || ! in_array($year, $years, true)) {
                $errors[] = "Year must be {$years[1]} or {$years[0]}.";
            } elseif ($employee !== null && ! isset($employed[$year][$employee->id]) && ! in_array(self::NOT_ADMINISTRABLE, $errors, true)) {
                $errors[] = "{$employee->full_name} was not employed in {$year}.";
            }

            if ($daysText === '') {
                $errors[] = 'Days Used is required.';
            } elseif ($days === null || $days < 0 || $days > self::MAX_DAYS || abs($days * 2 - round($days * 2)) > 1e-9) {
                $errors[] = 'Days Used must be a number from 0 to 365, in whole or half days.';
            }

            $key = $employee !== null && $type !== null && $year !== null ? "{$employee->id}:{$type->id}:{$year}" : null;

            if ($key !== null) {
                $keys[$key][] = $row['line'];
            }

            $checked[] = [
                'line' => $row['line'],
                'display' => [
                    'code' => $codeText,
                    'name' => $employee !== null && ! in_array(self::NOT_ADMINISTRABLE, $errors, true) ? $employee->full_name : '',
                    'type' => $type->name ?? $typeText,
                    'year' => $yearText,
                    'days' => $days !== null ? BalanceLine::format(LeaveDays::fromHalves(LeaveDays::halves($days))) : $daysText,
                ],
                'errors' => $errors,
                'key' => $key,
                'employee' => $employee,
                'type' => $type,
                'year' => $year,
                'days' => $days,
            ];
        }

        // Second pass: duplicates, then the overdrawn warnings and "unchanged" for the rows that are ready.
        foreach ($checked as $i => $row) {
            if ($row['key'] !== null && count($keys[$row['key']]) > 1) {
                $checked[$i]['errors'][] = 'This person, type and year appear more than once in the file (rows '.implode(', ', $keys[$row['key']]).').';
            }
        }

        $lines = [];
        foreach ($years as $year) {
            $people = new Collection(array_values(array_filter(array_map(
                fn (array $r) => $r['errors'] === [] && $r['year'] === $year ? $r['employee'] : null,
                $checked,
            ))));
            $lines[$year] = $people->isEmpty() ? [] : app(LeaveBalances::class)->forEmployees($people->unique('id')->values(), $year);
        }

        $result = [];

        foreach ($checked as $row) {
            $warnings = [];
            $unchanged = false;
            $data = null;

            if ($row['errors'] === []) {
                /** @var Employee $employee */
                $employee = $row['employee'];
                /** @var LeaveType $type */
                $type = $row['type'];
                $days = LeaveDays::fromHalves(LeaveDays::halves($row['days']));
                $line = $lines[$row['year']][$employee->id][$type->id] ?? null;

                if ($line !== null) {
                    $remaining = LeaveDays::fromHalves(
                        LeaveDays::halves($line->remaining) + LeaveDays::halves($line->openingUsed) - LeaveDays::halves($days),
                    );
                    $unchanged = ! $line->projected && LeaveDays::halves($line->openingUsed) === LeaveDays::halves($days);

                    if ($remaining < 0) {
                        $warnings[] = rtrim(str_replace(' Tick the box to confirm.', '', AdjustBalance::overdrawnMessage($employee, $remaining)));
                    }
                }

                $data = ['employee_id' => $employee->id, 'type_id' => $type->id, 'year' => (int) $row['year'], 'days' => $days];
            }

            $result[] = [
                'line' => $row['line'],
                'display' => $row['display'],
                'errors' => $row['errors'],
                'warnings' => $warnings,
                'unchanged' => $unchanged,
                'data' => $data,
            ];
        }

        return $result;
    }

    /** A cell as trimmed text: 2026.0 → "2026", 1.5 → "1.5", null → "". */
    private static function text(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_float($value) && fmod($value, 1.0) === 0.0 && abs($value) < 1e12) {
            return (string) (int) $value;
        }

        return trim((string) $value);
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
        foreach (self::COLUMNS as $name => $key) {
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
                throw new ImportFileException('The column "'.array_search($key, self::COLUMNS, true).'" appears more than once.');
            }

            $columns[$position] = $key;
        }

        $missing = array_keys(array_filter(self::COLUMNS, fn (string $key) => ! in_array($key, $columns, true)));

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
