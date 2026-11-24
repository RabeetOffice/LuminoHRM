<?php

namespace App\Imports;

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
 * Reads an employee workbook (.xlsx only) into plain rows, refusing anything suspicious before it is fully loaded.
 *
 * Before PhpSpreadsheet sees the file: it must be a zip with the workbook content type (no macro-enabled workbook, no
 * VBA project), and no part may unpack to more than the limits below (zip-bomb guard). Then the first worksheet's
 * declared size is read (a streaming scan) and more than 2,000 data rows is refused. Only then is the sheet loaded,
 * through a read filter (the header plus 2,000 rows, 60 columns), as data only: no styles, and formulas are NOT
 * calculated, so a cell holding "=1+1" is read as the text "=1+1". The declared size is only a first check (a row's `r`
 * attribute is optional, and without it the scan under-counts): the read filter notes any cell it is offered beyond the
 * limit, and the file is then refused too, so a long file is never silently cut short. Nothing is allocated per DECLARED
 * cell: only cells that exist and pass the filter are kept. PhpSpreadsheet refuses XML with entity declarations (XXE),
 * and PHP 8 does not load external entities; external links and macros are never followed or run.
 *
 * Cells are cleaned: zero-width characters removed, non-breaking spaces turned into spaces, trimmed, and one leading
 * apostrophe dropped. The first row must be the header (see EmployeeSheet). Blank rows and the template's example row
 * (First Name exactly EmployeeSheet::EXAMPLE_MARKER) are skipped.
 */
final class EmployeesImport implements ToArray, WithMultipleSheets, WithReadFilter
{
    private const MAX_PART_BYTES = 20 * 1024 * 1024;

    private const MAX_TOTAL_BYTES = 60 * 1024 * 1024;

    private const MAX_ENTRIES = 500;

    private const MAX_COLUMNS = 60;

    private const WORKBOOK_TYPE = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml';

    public const NOT_XLSX = 'Upload an Excel workbook in .xlsx format (like the template). .xls, .csv and macro-enabled files are not accepted.';

    public const UNREADABLE = 'The file could not be read as an Excel workbook.';

    public const TOO_MANY_ROWS = 'The file has more than 2,000 rows. Split it into smaller files.';

    public const TOO_BIG_UNPACKED = 'The file is too large to read once unpacked. Remove unused sheets, rows or formatting and try again.';

    public const NO_ROWS = 'The file has no employee rows below the header.';

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

        $this->filter = new RowLimitFilter(EmployeeSheet::MAX_ROWS + 1, self::MAX_COLUMNS);

        try {
            $sheets = Excel::toArray($this, $path, null, ExcelType::XLSX);
        } catch (Throwable) {
            throw new ImportFileException(self::UNREADABLE);
        }

        // The declared size can lie (rows without `r`): what the reader actually met decides.
        if ($this->filter->exceeded()) {
            throw new ImportFileException(self::TOO_MANY_ROWS);
        }

        return $this->rows(array_values($sheets[0] ?? []));
    }

    /** Required by ToArray; read() takes the rows from Excel::toArray instead. */
    public function array(array $array): void {}

    /** Only the first worksheet ("Employees" in the template); the "Help" sheet is never loaded. */
    public function sheets(): array
    {
        return [0 => $this];
    }

    /** The header plus MAX_ROWS rows, 60 columns; it remembers whether a cell beyond the last row was offered. */
    public function readFilter(): IReadFilter
    {
        return $this->filter ??= new RowLimitFilter(EmployeeSheet::MAX_ROWS + 1, self::MAX_COLUMNS);
    }

    /** A real .xlsx container: zip signature, workbook content type, no macros, nothing that unpacks too large. */
    private function assertWorkbook(string $path): void
    {
        $handle = @fopen($path, 'rb');
        $signature = $handle === false ? '' : (string) fread($handle, 4);

        if ($handle !== false) {
            fclose($handle);
        }

        if ($signature !== "PK\x03\x04") {
            throw new ImportFileException(self::NOT_XLSX);
        }

        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new ImportFileException(self::NOT_XLSX);
        }

        try {
            $types = $zip->locateName('[Content_Types].xml') === false ? '' : (string) $zip->getFromName('[Content_Types].xml', 1024 * 1024);

            if (! str_contains($types, self::WORKBOOK_TYPE)
                || str_contains(strtolower($types), 'macroenabled')
                || $zip->locateName('xl/workbook.xml') === false
                || $zip->locateName('xl/vbaProject.bin', ZipArchive::FL_NOCASE) !== false) {
                throw new ImportFileException(self::NOT_XLSX);
            }

            if ($zip->numFiles > self::MAX_ENTRIES) {
                throw new ImportFileException(self::TOO_BIG_UNPACKED);
            }

            $total = 0;

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $size = (int) ($zip->statIndex($i)['size'] ?? 0);
                $total += $size;

                if ($size > self::MAX_PART_BYTES || $total > self::MAX_TOTAL_BYTES) {
                    throw new ImportFileException(self::TOO_BIG_UNPACKED);
                }
            }
        } finally {
            $zip->close();
        }
    }

    /** The first sheet's size from a streaming scan, before anything is loaded into memory. */
    private function assertRowCount(string $path): void
    {
        try {
            $reader = new Xlsx;
            $reader->setReadDataOnly(true);
            $info = $reader->listWorksheetInfo($path)[0] ?? null;
        } catch (Throwable) {
            throw new ImportFileException(self::UNREADABLE);
        }

        if ($info === null) {
            throw new ImportFileException(self::UNREADABLE);
        }

        if ((int) $info['totalRows'] > EmployeeSheet::MAX_ROWS + 1) {
            throw new ImportFileException(self::TOO_MANY_ROWS);
        }
    }

    /**
     * @param  list<array<int, mixed>>  $sheet  row 1 first
     * @return array{rows: list<array{line: int, cells: array<string, mixed>}>, notices: list<string>}
     */
    private function rows(array $sheet): array
    {
        $header = array_map(fn ($cell) => is_scalar($cell) ? self::clean($cell) : null, $sheet[0] ?? []);
        [$columns, $notices] = $this->mapHeader($header);

        $rows = [];

        foreach (array_slice($sheet, 1) as $index => $values) {
            $line = $index + 2;
            $cells = [];

            foreach ($columns as $position => $key) {
                $value = $values[$position] ?? null;
                $cells[$key] = is_scalar($value) ? self::clean($value) : null;
            }

            if (array_filter($cells, fn ($value) => $value !== null && $value !== '') === []) {
                continue; // a blank row
            }

            $first = $cells['first_name'] ?? null;

            if ($first === EmployeeSheet::EXAMPLE_MARKER) {
                $notices[] = "Row {$line} is the template's example row and was skipped.";

                continue;
            }

            $rows[] = ['line' => $line, 'cells' => $cells];
        }

        if ($rows === []) {
            throw new ImportFileException(self::NO_ROWS);
        }

        return ['rows' => $rows, 'notices' => $notices];
    }

    /**
     * Column position => field key, and notices about ignored columns.
     *
     * @param  array<int, mixed>  $header
     * @return array{0: array<int, string>, 1: list<string>}
     */
    private function mapHeader(array $header): array
    {
        $known = [];

        foreach (EmployeeSheet::COLUMNS as $name => $key) {
            $known[EmployeeSheet::normaliseHeader($name)] = $key;
        }

        $columns = [];
        $ignored = [];

        foreach ($header as $position => $name) {
            if (! is_string($name) || $name === '') {
                continue;
            }

            $key = $known[EmployeeSheet::normaliseHeader($name)] ?? null;

            if ($key === null) {
                $ignored[] = mb_strimwidth($name, 0, 60, '…');

                continue;
            }

            if (in_array($key, $columns, true)) {
                throw new ImportFileException('The column "'.EmployeeSheet::label($key).'" appears more than once.');
            }

            $columns[$position] = $key;
        }

        $missing = array_filter(
            EmployeeSheet::COLUMNS,
            fn (string $key) => EmployeeSheet::isRequired($key) && ! in_array($key, $columns, true),
        );

        if ($missing !== []) {
            $names = implode(', ', array_map(EmployeeSheet::label(...), $missing));

            throw new ImportFileException("The file is missing required columns: {$names}. Use the header row from the template.");
        }

        $notices = $ignored === [] ? [] : ['These columns are not part of the template and were ignored: '.implode(', ', $ignored).'.'];

        return [$columns, $notices];
    }

    /** Text cleaned as described in the class comment; numbers stay numbers (a date may be an Excel serial). */
    public static function clean(mixed $value): mixed
    {
        if (is_bool($value)) {
            return $value ? 'TRUE' : 'FALSE';
        }

        if (! is_string($value)) {
            return $value;
        }

        $value = (string) preg_replace('/[\x{200B}-\x{200D}\x{2060}\x{FEFF}]/u', '', $value);
        $value = trim((string) preg_replace('/[\x{00A0}\x{2007}\x{202F}]/u', ' ', $value));

        if (str_starts_with($value, "'")) {
            $value = trim(substr($value, 1));
        }

        return $value;
    }
}
