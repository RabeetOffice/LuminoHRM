<?php

namespace App\Exports;

use App\Imports\AssetRegisterImport;
use App\Models\AssetCategory;
use App\Support\Attendance\AttendanceClock;
use Maatwebsite\Excel\Concerns\Export;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * The asset register import template: sheet 1 "Assets" (the header row only, in AssetRegisterImport::columns() order; every
 * column formatted as text except Cost, a whole number), sheet 2 "Help" (what each column takes, the categories, the
 * conditions and statuses, an example). Every written cell is text (TextCells, formula-safe).
 */
final class AssetTemplateExport implements Export, WithMultipleSheets
{
    public const FILE_NAME = 'assets-template.xlsx';

    public function sheets(): array
    {
        $today = app(AttendanceClock::class)->attendanceToday();
        $categories = AssetCategory::query()->orderBy('sort_order')->orderBy('name')->pluck('name')->implode(', ');
        $label = fn (string $key) => AssetRegisterImport::label($key);
        $conditions = implode(', ', array_map($label, AssetRegisterImport::configList('hrm.assets.conditions')));
        $statuses = implode(', ', array_map($label, AssetRegisterImport::configList('hrm.assets.statuses')));

        return [
            OpeningTaxTemplateExport::templateSheet('Assets', array_keys(AssetRegisterImport::columns()), [1, 2, 3, 4, 5, 7, 8, 9, 10]),
            OpeningTaxTemplateExport::helpSheet([
                ['Column', 'Required', 'Format'],
                ['Asset Code', 'Optional', 'Leave blank for a new asset: the code is given when it is saved. To change an asset, its code, for example AST-0001 (the column may be left out).'],
                ['Name', 'Required', 'Up to '.AssetRegisterImport::NAME_MAX.' characters, for example Dell Latitude 5440.'],
                ['Category', 'Required', "An existing category: {$categories}. Add a new one under Settings > Asset categories first."],
                ['Serial', 'Optional', 'Up to '.AssetRegisterImport::SERIAL_MAX.' characters, unique. Without an Asset Code, a serial that matches an asset updates that asset.'],
                ['Purchase Date', 'Optional', 'YYYY-MM-DD, not in the future.'],
                ['Cost', 'Optional', 'Whole rupees, 0 to 99,999,999.'],
                ['Condition', 'Required for a new asset', "{$conditions}."],
                ['Status', 'Optional', "{$statuses}. Blank keeps the stored status (Available for a new asset). Only an Admin can retire an asset or bring a retired one back."],
                ['Holder Employee Code', 'Optional', 'The code of an active or probation employee, to hand the asset to them. The status must then be Assigned or blank.'],
                ['Assigned Date', 'With a holder', 'YYYY-MM-DD: the day it was handed over, not in the future and not before the person joined.'],
                [],
                ['Notes'],
                ['One row per asset. Up to 2,000 rows and 5 MB per file, .xlsx only. Nothing is saved until every row is ready.'],
                ['On an existing asset a blank Serial, Purchase Date, Cost, Condition or Status keeps what is stored, and a row equal to the stored asset changes nothing, so importing the same file again changes nothing.'],
                ['The import never takes an asset back: to give a held asset to someone else, or to change its status, return it on the asset page first.'],
                [],
                ['Example', '', "(blank) | Dell Latitude 5440 | Laptop | SN-12345 | 2026-01-15 | 185000 | Good | Assigned | LD-0001 | {$today}"],
            ]),
        ];
    }
}
