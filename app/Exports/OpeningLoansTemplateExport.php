<?php

namespace App\Exports;

use App\Imports\OpeningLoansImport;
use App\Models\PayrollRun;
use App\Support\Attendance\AttendanceClock;
use App\Support\Payroll\LoanSchedule;
use App\Support\PayrollLock;
use Maatwebsite\Excel\Concerns\Export;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * The opening-loans import template: sheet 1 "Opening Loans" (the header row only: Employee Code, Type, Original Amount,
 * Repaid Before Go-Live, Monthly Installment, First Deduction Month, Note; the code, type, month and note columns as
 * text so Excel keeps "2026-12" as typed, the amounts as whole numbers), sheet 2 "Help" (what each column takes, the
 * earliest first deduction month, an example). Every written cell is text (TextCells, formula-safe).
 */
final class OpeningLoansTemplateExport implements Export, WithMultipleSheets
{
    public const FILE_NAME = 'opening-loans-template.xlsx';

    public function sheets(): array
    {
        $latest = PayrollLock::latestLocked();
        $earliest = $latest === null
            ? 'Any month'
            : 'A month after '.PayrollRun::monthLabel($latest).' (the latest approved payroll)';
        $example = $latest === null ? substr(app(AttendanceClock::class)->attendanceToday(), 0, 7) : LoanSchedule::addMonth($latest);

        return [
            OpeningTaxTemplateExport::templateSheet('Opening Loans', array_keys(OpeningLoansImport::columns()), [1, 2, 6, 7]),
            OpeningTaxTemplateExport::helpSheet([
                ['Column', 'Required', 'Format'],
                ['Employee Code', 'Required', 'The code of an existing employee, for example LD-0001.'],
                ['Type', 'Required', 'Loan or Advance.'],
                ['Original Amount', 'Required', 'Whole rupees above 0: the amount paid out to the person.'],
                ['Repaid Before Go-Live', 'Optional', 'Whole rupees, 0 or more and less than the Original Amount: what was recovered before this app. Blank = 0.'],
                ['Monthly Installment', 'Required', 'Whole rupees, 1 up to the Original Amount. May be blank on an Advance: the whole amount in one month.'],
                ['First Deduction Month', 'Required', "YYYY-MM, for example {$example}: the first payroll month that deducts it. {$earliest}."],
                ['Note', 'Optional', 'Up to 255 characters.'],
                [],
                ['Notes'],
                ['One row per loan. Up to 2,000 rows and 5 MB per file, .xlsx only. Loans are paid out outside this app; payroll only recovers them.'],
                ['A row with the same person, type, original amount and first month as a stored active loan updates that loan instead of adding another, so importing the same file again changes nothing.'],
                ['Nothing is saved until every row is ready.'],
                [],
                ['Example', '', "LD-0001 | Loan | 50000 | 38000 | 5000 | {$example} | Car repair"],
            ]),
        ];
    }
}
