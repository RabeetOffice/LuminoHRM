<?php

namespace App\Support\Payroll;

use App\Models\PayrollItem;
use App\Models\PayrollRun;
use App\Support\Branding;
use Barryvdh\DomPDF\Facade\Pdf;
use DateTimeImmutable;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

/**
 * One person's payslip for an approved (or paid) run, as a PDF and as plain text.
 *
 * Built ONLY from what was frozen at approval: the item's stored breakdown (version 1, see the Phase 5 plan), the
 * item's bank snapshot (bank name, account number shown as "•••• 1234") and the run's company snapshot
 * (`summary.company` = {name, address, logo_path, logo {mime, data}, footer}). Nothing is recalculated and no setting, profile or employee
 * row is read, so rendering again later (a missing file, a resend) gives the same content.
 *
 * Files live on the private disk ("local", never public) at payslips/{month}/{employee_code}-{item_id}.pdf and are
 * served only through the payslip controllers. Writing a payslip touches only the three payslip columns of the item
 * (payslip_path, payslip_generated_at; payslip_emailed_at by the job), through a narrow query update: the figures,
 * breakdown and snapshot of an approved line are never written here.
 *
 *   PayslipPdf::render($item);        // PDF bytes (A4 portrait, DejaVu Sans)
 *   PayslipPdf::text($item);          // the same content as plain text
 *   PayslipPdf::store($item);         // writes the file, returns its path
 *   PayslipPdf::pathOrRender($item);  // the stored path, re-rendered first when the file is missing
 */
final class PayslipPdf
{
    public const DIRECTORY = 'payslips';

    private const LOGO_TYPES = ['png' => 'image/png', 'jpg' => 'image/jpeg'];

    public static function disk(): Filesystem
    {
        return Storage::disk('local');
    }

    /** PDF bytes of the payslip. */
    public static function render(PayrollItem $item): string
    {
        $content = self::content($item);
        $content['logo'] = self::logoDataUri($item);

        return Pdf::loadView('pdf.payslip', ['p' => $content])
            ->setPaper('a4', 'portrait')
            ->setOption(['defaultFont' => 'DejaVu Sans', 'isRemoteEnabled' => false, 'isFontSubsettingEnabled' => true])
            ->output();
    }

    /** The payslip's content as plain text (the PDF shows the same lines). */
    public static function text(PayrollItem $item): string
    {
        $p = self::content($item);
        $lines = array_values(array_filter([$p['company']['name'], $p['company']['address']], fn (string $l): bool => $l !== ''));
        $lines[] = $p['title'];

        $section = function (string $heading, array $rows) use (&$lines): void {
            $lines[] = '';
            $lines[] = $heading;
            foreach ($rows as [$label, $value]) {
                $lines[] = "{$label}: {$value}";
            }
        };

        $section('Employee', $p['employee']);
        $section('Earnings', [...$p['earnings'], ['Gross pay', $p['gross']]]);
        $section('Deductions', [...$p['deductions'], ['Total deductions', $p['total_deductions']]]);
        $section('Tax', [['Mode', $p['tax']['mode']], ['Taxable pay', $p['tax']['taxable_pay']], ['Tax', $p['tax']['amount']]]);
        $lines[] = '';
        $lines[] = "Net pay: {$p['net']}";
        $section('Attendance', $p['attendance']);

        if ($p['company']['footer'] !== '') {
            $lines[] = '';
            $lines[] = $p['company']['footer'];
        }

        return implode("\n", $lines)."\n";
    }

    /** Renders and writes the file on the private disk; records payslip_path and payslip_generated_at. */
    public static function store(PayrollItem $item): string
    {
        $path = self::pathFor($item);
        self::disk()->put($path, self::render($item));
        self::record($item, ['payslip_path' => $path, 'payslip_generated_at' => now()]);

        return $path;
    }

    /** The payslip's path, writing the file again (from the same stored data) when it is missing. */
    public static function pathOrRender(PayrollItem $item): string
    {
        $path = self::pathFor($item);

        if (! self::disk()->exists($path)) {
            return self::store($item);
        }

        if ($item->payslip_path !== $path) {
            self::record($item, ['payslip_path' => $path, 'payslip_generated_at' => $item->payslip_generated_at ?? now()]);
        }

        return $path;
    }

    /** payslips/2026-11/LD-0007-12.pdf: month and code from the frozen line, the item id makes it unique. */
    public static function pathFor(PayrollItem $item): string
    {
        $run = $item->run;
        $code = self::safeCode($item);

        return self::DIRECTORY."/{$run->month}/{$code}-{$item->id}.pdf";
    }

    /** "payslip-2026-11-LD-0007.pdf": the name a person sees (download, mail attachment). */
    public static function filename(PayrollItem $item): string
    {
        return "payslip-{$item->run->month}-".self::safeCode($item).'.pdf';
    }

    /**
     * Every value the payslip shows, formatted, from the frozen data only.
     *
     * @return array{
     *     company: array{name: string, address: string, footer: string},
     *     title: string,
     *     employee: list<array{0: string, 1: string}>,
     *     earnings: list<array{0: string, 1: string}>,
     *     gross: string,
     *     deductions: list<array{0: string, 1: string}>,
     *     total_deductions: string,
     *     tax: array{mode: string, taxable_pay: string, amount: string},
     *     net: string,
     *     attendance: list<array{0: string, 1: string}>,
     * }
     */
    public static function content(PayrollItem $item): array
    {
        $b = is_array($item->breakdown) ? $item->breakdown : [];
        $company = self::company($item->run);
        $employee = (array) ($b['employee'] ?? []);
        $period = (array) ($b['period'] ?? []);
        $totals = (array) ($b['totals'] ?? []);
        $tax = (array) ($b['tax'] ?? []);
        $att = (array) ($b['attendance'] ?? []);

        $lines = fn (string $key): array => array_values(array_map(
            fn (array $line): array => [self::str($line['label'] ?? ''), Money::format((int) ($line['amount'] ?? 0))],
            array_filter((array) ($b[$key] ?? []), 'is_array'),
        ));

        $totalDeductions = (int) ($totals['attendance_deduction'] ?? 0) + (int) ($totals['fixed_deductions'] ?? 0)
            + (int) ($totals['other_deductions'] ?? 0) + (int) ($totals['loan_deduction'] ?? 0);

        return [
            'company' => $company,
            'title' => 'Payslip — '.PayrollRun::monthLabel($item->run->month),
            'employee' => [
                ['Name', self::str($employee['name'] ?? '')],
                ['Employee code', self::str($employee['code'] ?? '')],
                ['Department', self::orDash($employee['department'] ?? null)],
                ['Designation', self::orDash($employee['designation'] ?? null)],
                ['Joining date', self::date($employee['joining_date'] ?? null)],
                ['Bank', self::bank($item)],
            ],
            'earnings' => $lines('earnings'),
            'gross' => Money::format((int) ($totals['gross'] ?? 0)),
            'deductions' => $lines('deductions'),
            'total_deductions' => Money::format($totalDeductions),
            'tax' => [
                'mode' => self::taxMode($tax),
                'taxable_pay' => Money::format((int) ($tax['taxable_pay'] ?? 0)),
                'amount' => Money::format((int) ($tax['amount'] ?? ($totals['tax'] ?? 0))),
            ],
            'net' => Money::format((int) ($totals['net'] ?? 0)),
            'attendance' => [
                ['Days in month', self::num($period['days_in_month'] ?? 0)],
                ['Days paid', self::num($period['days_paid'] ?? 0)],
                ['Present', self::num($att['present'] ?? 0)],
                ['Late', self::num($att['late'] ?? 0)],
                ['Half days', self::num($att['half_day'] ?? 0)],
                ['Absent', self::num($att['absent'] ?? 0)],
                ['Paid leave', self::num($att['paid_leave'] ?? 0)],
                ['Unpaid leave', self::num($att['unpaid_leave'] ?? 0)],
                ['Late days deducted', self::num($att['late_days_deducted'] ?? 0)],
                ['Overtime', self::minutes((int) ($att['overtime_minutes'] ?? 0))],
            ],
        ];
    }

    /**
     * "5% of gross taxable pay" / "5.25% of basic pay" / "Fixed" / "Overridden for this month" / "No tax".
     *
     * @param  array<string, mixed>  $tax  the breakdown's tax block
     */
    public static function taxMode(array $tax): string
    {
        if (($tax['overridden'] ?? false) === true) {
            return 'Overridden for this month';
        }

        return match ($tax['mode'] ?? 'none') {
            'percentage' => self::percent((int) ($tax['rate_bp'] ?? 0)).' of '.(($tax['base'] ?? 'gross_taxable') === 'basic' ? 'basic pay' : 'gross taxable pay'),
            'fixed' => 'Fixed',
            default => 'No tax',
        };
    }

    // ------------------------------------------------------------------ helpers

    /** @param  array<string, mixed>  $values */
    private static function record(PayrollItem $item, array $values): void
    {
        // Only the payslip columns, never the frozen figures: a narrow update, then the model mirrors it (not dirty).
        PayrollItem::query()->whereKey($item->id)->update($values);
        $item->forceFill($values)->syncOriginalAttributes(array_keys($values));
    }

    /** @return array{name: string, address: string, footer: string} */
    private static function company(PayrollRun $run): array
    {
        $c = (array) (($run->summary ?? [])['company'] ?? []);

        return [
            'name' => self::str($c['name'] ?? ''),
            'address' => trim((string) preg_replace('/\s*\R\s*/u', ', ', self::str($c['address'] ?? '', false))),
            'footer' => self::str($c['footer'] ?? '', false),
        ];
    }

    /** The snapshot's logo as a data URI when it is one of our branding files and still on disk (PNG / JPEG). */
    public static function logoDataUri(PayrollItem $item): ?string
    {
        return self::logoFromCompany((array) (($item->run->summary ?? [])['company'] ?? []));
    }

    /**
     * The logo of a company snapshot ({logo {mime, data}, logo_path}) as a data URI, or null. Also used by the final
     * settlement statement, whose summary carries the same snapshot.
     *
     * @param  array<string, mixed>  $company
     */
    public static function logoFromCompany(array $company): ?string
    {
        // The bytes frozen at approval win: a later logo change or a deleted file never alters an issued document.
        $frozen = $company['logo'] ?? null;
        if (is_array($frozen) && in_array($frozen['mime'] ?? null, self::LOGO_TYPES, true) && is_string($frozen['data'] ?? null) && $frozen['data'] !== '') {
            return "data:{$frozen['mime']};base64,{$frozen['data']}";
        }

        // Snapshots made before the bytes were frozen: the stored path, while the file is still there.
        $path = $company['logo_path'] ?? null;
        $filename = Branding::filename($path);

        if ($filename === null || ! Branding::disk()->exists((string) $path)) {
            return self::defaultLogoDataUri();
        }

        $mime = self::LOGO_TYPES[strtolower(pathinfo($filename, PATHINFO_EXTENSION))] ?? null;

        return $mime === null ? self::defaultLogoDataUri() : "data:{$mime};base64,".base64_encode((string) Branding::disk()->get((string) $path));
    }

    /** The bundled Lumino Digital logo (white background) as a data URI: used on documents until a company logo is uploaded. */
    public static function defaultLogoDataUri(): ?string
    {
        $file = public_path('brand/logo-on-white.png');

        return is_file($file) ? 'data:image/png;base64,'.base64_encode((string) file_get_contents($file)) : null;
    }

    private static function bank(PayrollItem $item): string
    {
        $number = preg_replace('/\s+/', '', (string) $item->account_number);

        if ($number === '' || $number === null) {
            return 'No bank account';
        }

        $masked = '•••• '.mb_substr($number, -4);
        $bank = trim((string) $item->bank_name);

        return $bank === '' ? $masked : "{$bank} {$masked}";
    }

    private static function safeCode(PayrollItem $item): string
    {
        $code = (string) preg_replace('/[^A-Za-z0-9-]/', '', (string) (($item->breakdown ?? [])['employee']['code'] ?? ''));

        return $code === '' ? 'employee' : $code;
    }

    public static function str(mixed $value, bool $oneLine = true): string
    {
        $text = is_scalar($value) ? trim((string) $value) : '';

        return $oneLine ? trim((string) preg_replace('/\s+/u', ' ', $text)) : $text;
    }

    public static function orDash(mixed $value): string
    {
        $text = self::str($value);

        return $text === '' ? '–' : $text;
    }

    /** "2024-03-01" -> "1 Mar 2024" (no time zone involved). */
    public static function date(mixed $value): string
    {
        $date = is_string($value) ? DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;

        return $date === false ? '–' : $date->format('j M Y');
    }

    /** 4 -> "4", 4.5 -> "4.5". */
    public static function num(mixed $value): string
    {
        if (! is_numeric($value)) {
            return '0';
        }

        $n = (float) $value;

        return floor($n) === $n ? (string) (int) $n : rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');
    }

    /** 500 -> "5%", 525 -> "5.25%". */
    private static function percent(int $bp): string
    {
        $whole = intdiv($bp, 100);
        $rest = $bp % 100;

        return ($rest === 0 ? (string) $whole : $whole.'.'.rtrim(sprintf('%02d', $rest), '0')).'%';
    }

    /** 600 -> "10 h", 45 -> "45 min", 605 -> "10 h 5 min", 0 -> "None". */
    public static function minutes(int $minutes): string
    {
        if ($minutes <= 0) {
            return 'None';
        }

        $h = intdiv($minutes, 60);
        $m = $minutes % 60;

        return trim(($h > 0 ? "{$h} h" : '').($m > 0 ? " {$m} min" : ''));
    }
}
