<?php

namespace App\Exports;

use App\Imports\AssetRegisterImport;
use App\Models\Asset;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

/**
 * The asset register as a workbook: every row of the list's query (its filters, every page), with the import template's
 * columns in the template's order plus Holder Name at the end (the import ignores it), so an export can be edited and
 * imported again. Only assets.assign (Admin, HR) exports, so the Cost column is always there.
 *
 * Cost is a numeric cell in whole rupees (a cost of 0 stays 0: strict null comparison); dates are "Y-m-d" text;
 * everything else is a text cell, and text that begins like a formula keeps its value and gets the quote-prefix flag
 * (TextCells).
 */
final class AssetsExport implements FromQuery, WithCustomValueBinder, WithEvents, WithHeadings, WithMapping, WithStrictNullComparison, WithTitle
{
    use TextCells {
        bindValue as bindText;
    }

    public const HOLDER_NAME = 'Holder Name';

    /** The Cost column's letter, written as a number. */
    private const COST_COLUMN = 'F';

    /** @param  Builder<Asset>  $rows  the list's query, already filtered and ordered (ends with a unique id) */
    public function __construct(private readonly Builder $rows) {}

    /** @return list<string> */
    public static function headers(): array
    {
        return [...array_keys(AssetRegisterImport::columns()), self::HOLDER_NAME];
    }

    public function query(): Builder
    {
        return $this->rows->with([
            'category:id,name',
            'openAssignment:id,asset_id,employee_id,assigned_on',
            'openAssignment.employee:id,employee_code,first_name,last_name',
        ]);
    }

    public function title(): string
    {
        return 'Assets';
    }

    public function headings(): array
    {
        return self::headers();
    }

    /**
     * @param  Asset  $row
     * @return list<string|int|null>
     */
    public function map($row): array
    {
        $open = $row->openAssignment;

        return [
            $row->asset_code,
            $row->name,
            (string) $row->category?->name,
            (string) $row->serial_number,
            (string) $row->purchase_date?->format('Y-m-d'),
            // Whole rupees as a number (costs are entered in whole rupees).
            $row->cost === null ? null : intdiv($row->cost, 100),
            AssetRegisterImport::label($row->condition),
            AssetRegisterImport::label($row->status),
            (string) $open?->employee?->employee_code,
            (string) $open?->assigned_on->format('Y-m-d'),
            (string) $open?->employee?->full_name,
        ];
    }

    /** Numbers (the cost) as numeric cells; everything else as text (TextCells). */
    public function bindValue(Cell $cell, mixed $value): bool
    {
        if (is_int($value)) {
            $cell->setValueExplicit($value, DataType::TYPE_NUMERIC);

            return true;
        }

        return $this->bindText($cell, $value);
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event): void {
                $sheet = $event->sheet->getDelegate();
                self::styleSheet($sheet, count(self::headers()));
                $last = $sheet->getHighestRow();

                if ($last >= 2) {
                    $sheet->getStyle(self::COST_COLUMN.'2:'.self::COST_COLUMN.$last)->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_NUMBER);
                }
            },
        ];
    }
}
