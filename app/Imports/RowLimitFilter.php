<?php

namespace App\Imports;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;

/**
 * Lets the reader keep only the first $rows rows and $columns columns, and remembers whether it was ever offered a cell
 * in a row below the limit. PhpSpreadsheet asks about every cell that exists in the sheet XML (numbering rows itself
 * when the optional `r` attributes are missing), so exceeded() tells the truth even when the file's declared size lies.
 */
final class RowLimitFilter implements IReadFilter
{
    private bool $exceeded = false;

    public function __construct(private readonly int $rows, private readonly int $columns) {}

    public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
    {
        if ($row > $this->rows) {
            $this->exceeded = true;

            return false;
        }

        return Coordinate::columnIndexFromString($columnAddress) <= $this->columns;
    }

    public function exceeded(): bool
    {
        return $this->exceeded;
    }
}
