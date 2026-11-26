<?php

namespace App\Support\Exits;

use App\Models\FinalSettlement;
use App\Models\PayrollRun;
use App\Support\Payroll\Money;
use App\Support\Payroll\PayslipPdf;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\Filesystem\Filesystem;
use LogicException;

/**
 * The final settlement statement of an approved (or paid) settlement, as a PDF.
 *
 * Built ONLY from what was frozen at approval: the stored breakdown (PayResult::breakdown plus the `settlement` key, see
 * the Phase 6c plan) and the settlement's company snapshot (`summary.company`, same shape as a payroll run's). Nothing
 * is recalculated and no profile or setting is read, so rendering again later gives the same content.
 *
 * Files live on the private disk (PayslipPdf::disk()) at settlements/{month}/{code}-{id}.pdf. Writing one touches only
 * statement_path, through a narrow query update. A draft has no statement (LogicException).
 */
final class SettlementStatementPdf
{
    public const DIRECTORY = 'settlements';

    public const NOT_READY = 'The statement is available once the settlement is approved.';

    public static function disk(): Filesystem
    {
        return PayslipPdf::disk();
    }

    /** PDF bytes. */
    public static function render(FinalSettlement $settlement): string
    {
        $content = self::content($settlement);
        $content['logo'] = PayslipPdf::logoFromCompany((array) (($settlement->summary ?? [])['company'] ?? []));

        return Pdf::loadView('pdf.settlement', ['p' => $content])
            ->setPaper('a4', 'portrait')
            ->setOption(['defaultFont' => 'DejaVu Sans', 'isRemoteEnabled' => false, 'isFontSubsettingEnabled' => true])
            ->output();
    }

    /** Renders and writes the file; records statement_path. */
    public static function store(FinalSettlement $settlement): string
    {
        $bytes = self::render($settlement);
        $path = self::pathFor($settlement);
        self::disk()->put($path, $bytes);

        FinalSettlement::query()->whereKey($settlement->id)->update(['statement_path' => $path]);
        $settlement->forceFill(['statement_path' => $path])->syncOriginalAttributes(['statement_path']);

        return $path;
    }

    /** The stored path, writing the file again (from the same stored data) when it is missing. */
    public static function pathOrRender(FinalSettlement $settlement): string
    {
        self::assertReady($settlement);
        $path = self::pathFor($settlement);

        return self::disk()->exists($path) ? $path : self::store($settlement);
    }

    /** settlements/2026-12/LD-0007-12.pdf */
    public static function pathFor(FinalSettlement $settlement): string
    {
        return self::DIRECTORY."/{$settlement->month}/".self::safeCode($settlement)."-{$settlement->id}.pdf";
    }

    /** "Final settlement - LD-0007 - December 2026.pdf" */
    public static function filename(FinalSettlement $settlement): string
    {
        return 'Final settlement - '.self::safeCode($settlement).' - '.PayrollRun::monthLabel($settlement->month).'.pdf';
    }

    /**
     * Every value the statement shows, formatted, from the stored breakdown and snapshot only.
     *
     * @return array<string, mixed>
     *
     * @throws LogicException the settlement is still a draft
     */
    public static function content(FinalSettlement $settlement): array
    {
        self::assertReady($settlement);

        $b = is_array($settlement->breakdown) ? $settlement->breakdown : [];
        $s = (array) ($b['settlement'] ?? []);
        $employee = (array) ($b['employee'] ?? []);
        $period = (array) ($b['period'] ?? []);
        $totals = (array) ($b['totals'] ?? []);
        $tax = (array) ($b['tax'] ?? []);
        $att = (array) ($b['attendance'] ?? []);
        $c = (array) (($settlement->summary ?? [])['company'] ?? []);

        $lines = fn (string $key): array => array_values(array_map(
            fn (array $line): array => [PayslipPdf::str($line['label'] ?? ''), Money::format((int) ($line['amount'] ?? 0))],
            array_filter((array) ($b[$key] ?? []), 'is_array'),
        ));

        $net = (int) ($totals['net'] ?? $settlement->net);
        $owed = max((int) ($s['owed_by_employee'] ?? 0), $net < 0 ? -$net : 0);
        $encashment = is_array($s['encashment'] ?? null) && (($s['encashment']['enabled'] ?? false) === true) ? $s['encashment'] : null;

        $loans = [];
        foreach (array_filter((array) ($s['loans'] ?? []), 'is_array') as $loan) {
            $loans[] = [
                PayslipPdf::str($loan['type'] ?? 'Loan'),
                'Outstanding '.Money::format((int) ($loan['remaining'] ?? 0)),
                Money::format((int) ($loan['recovered'] ?? 0)),
            ];
        }

        $deductionsTotal = (int) ($totals['attendance_deduction'] ?? 0) + (int) ($totals['fixed_deductions'] ?? 0)
            + (int) ($totals['other_deductions'] ?? 0) + (int) ($totals['loan_deduction'] ?? 0);

        return [
            'company' => [
                'name' => PayslipPdf::str($c['name'] ?? ''),
                'address' => trim((string) preg_replace('/\s*\R\s*/u', ', ', PayslipPdf::str($c['address'] ?? '', false))),
                'footer' => PayslipPdf::str($c['footer'] ?? '', false),
            ],
            'title' => 'Final settlement — '.PayrollRun::monthLabel($settlement->month),
            'employee' => [
                ['Name', PayslipPdf::str($employee['name'] ?? '')],
                ['Employee code', PayslipPdf::str($employee['code'] ?? '')],
                ['Department', PayslipPdf::orDash($employee['department'] ?? null)],
                ['Designation', PayslipPdf::orDash($employee['designation'] ?? null)],
                ['Joining date', PayslipPdf::date($employee['joining_date'] ?? null)],
                ['Exit', ucfirst(PayslipPdf::str($s['exit_type'] ?? 'resignation'))],
                ['Last working day', PayslipPdf::date($s['last_working_day'] ?? null)],
                ['Pay period', PayslipPdf::date($period['from'] ?? null).' to '.PayslipPdf::date($period['to'] ?? null)],
            ],
            'earnings' => $lines('earnings'),
            'gross' => Money::format((int) ($totals['gross'] ?? 0)),
            'deductions' => $lines('deductions'),
            'total_deductions' => Money::format($deductionsTotal),
            'tax' => [
                'mode' => PayslipPdf::taxMode($tax),
                'taxable_pay' => Money::format((int) ($tax['taxable_pay'] ?? 0)),
                'amount' => Money::format((int) ($tax['amount'] ?? ($totals['tax'] ?? 0))),
            ],
            'encashment' => $encashment === null ? null : [
                PayslipPdf::str($encashment['type_name'] ?? 'Leave encashment'),
                PayslipPdf::num($encashment['days'] ?? 0).' days',
                Money::format((int) ($encashment['amount'] ?? 0)),
            ],
            'loans' => $loans,
            'loan_shortfall' => (int) ($s['loan_shortfall'] ?? 0) > 0 ? Money::format((int) $s['loan_shortfall']) : null,
            'owed' => $owed > 0,
            'net_label' => $owed > 0 ? 'Owed by employee' : 'Net pay',
            'net' => Money::format($owed > 0 ? $owed : $net),
            'attendance' => [
                ['Days in month', PayslipPdf::num($period['days_in_month'] ?? 0)],
                ['Days paid', PayslipPdf::num($period['days_paid'] ?? 0)],
                ['Present', PayslipPdf::num($att['present'] ?? 0)],
                ['Late', PayslipPdf::num($att['late'] ?? 0)],
                ['Half days', PayslipPdf::num($att['half_day'] ?? 0)],
                ['Absent', PayslipPdf::num($att['absent'] ?? 0)],
                ['Paid leave', PayslipPdf::num($att['paid_leave'] ?? 0)],
                ['Unpaid leave', PayslipPdf::num($att['unpaid_leave'] ?? 0)],
                ['Late days deducted', PayslipPdf::num($att['late_days_deducted'] ?? 0)],
                ['Overtime', PayslipPdf::minutes((int) ($att['overtime_minutes'] ?? 0))],
            ],
        ];
    }

    /** @throws LogicException */
    private static function assertReady(FinalSettlement $settlement): void
    {
        if (! $settlement->isFrozen()) {
            throw new LogicException(self::NOT_READY);
        }
    }

    private static function safeCode(FinalSettlement $settlement): string
    {
        $code = (string) preg_replace('/[^A-Za-z0-9-]/', '', (string) (($settlement->breakdown ?? [])['employee']['code'] ?? ''));

        return $code === '' ? 'employee' : $code;
    }
}
