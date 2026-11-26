<?php

namespace App\Http\Controllers\Payroll;

use App\Exports\TaxReportExport;
use App\Http\Controllers\Controller;
use App\Models\PayrollRun;
use App\Models\User;
use App\Support\Attendance\AttendanceClock;
use App\Support\Audit\Audit;
use App\Support\Payroll\Money;
use App\Support\Payroll\TaxReport;
use App\Support\Payroll\TaxYear;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Excel as ExcelType;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Payroll > Tax Report (salary data: Admin / HR only, can:payroll.view on both routes).
 *
 * GET /payroll/tax-report?year=         employees × months of one tax year (TaxReport::for): opening tax, tax per month,
 *                                       total tax and total taxable pay; money as formatted strings
 * GET /payroll/tax-report/export?year=  the same year as an .xlsx (TaxReportExport), audited before the file is built
 *                                       (year and row count, never a figure)
 *
 * Years: from the earliest tax year with an opening row or an approved / paid run to the current tax year (the one
 * holding AttendanceClock::attendanceToday()); anything else in the URL means the current year. Fixed queries.
 */
class TaxReportController extends Controller
{
    public function index(Request $request, AttendanceClock $clock): Response
    {
        [$year, $years] = $this->year($request, $clock);
        $report = TaxReport::for($year);
        $start = TaxReport::startMonth();
        $money = fn (int $paisa): string => $paisa === 0 ? '–' : Money::format($paisa);

        return Inertia::render('payroll/tax-report', [
            'year' => $year,
            'label' => $report['label'],
            'years' => array_map(fn (int $y) => ['value' => $y, 'label' => TaxYear::label($y, $start)], $years),
            'months' => array_map(fn (string $m) => [
                'month' => $m,
                'label' => substr(PayrollRun::monthLabel($m), 0, 3),
                'title' => PayrollRun::monthLabel($m),
            ], $report['months']),
            'rows' => array_map(fn (array $row) => [
                'id' => $row['employee_id'],
                'code' => $row['code'],
                'name' => $row['name'],
                'opening_tax' => $money($row['opening_tax']),
                'opening_taxable' => $money($row['opening_taxable']),
                'months' => array_map(fn (array $f) => $money($f['tax']), $row['months']),
                'total_tax' => Money::format($row['total_tax']),
                'total_taxable' => Money::format($row['total_taxable']),
            ], $report['rows']),
            'totals' => [
                'opening_tax' => Money::format($report['totals']['opening_tax']),
                'opening_taxable' => Money::format($report['totals']['opening_taxable']),
                'months' => array_map(fn (array $f) => Money::format($f['tax']), $report['totals']['months']),
                'total_tax' => Money::format($report['totals']['total_tax']),
                'total_taxable' => Money::format($report['totals']['total_taxable']),
            ],
        ]);
    }

    public function export(Request $request, AttendanceClock $clock): BinaryFileResponse
    {
        /** @var User $user */
        $user = $request->user();
        [$year] = $this->year($request, $clock);
        $report = TaxReport::for($year);

        Audit::record('payroll', 'Tax report exported', null, [
            'tax_year' => $year,
            'label' => $report['label'],
            'rows' => count($report['rows']),
        ], $user);

        return Excel::download(new TaxReportExport($report), TaxReportExport::fileName($report['label']), ExcelType::XLSX);
    }

    /** @return array{0: int, 1: list<int>} the chosen year and the years offered (newest first) */
    private function year(Request $request, AttendanceClock $clock): array
    {
        $current = TaxReport::currentYear($clock->attendanceToday());
        $years = TaxReport::years($current);
        $asked = $request->query('year');
        $year = is_string($asked) && preg_match('/^\d{4}$/', $asked) === 1 && in_array((int) $asked, $years, true)
            ? (int) $asked
            : $current;

        return [$year, $years];
    }
}
