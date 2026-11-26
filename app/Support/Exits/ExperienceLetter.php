<?php

namespace App\Support\Exits;

use App\Http\Requests\Settings\ExitRequest;
use App\Models\Resignation;
use App\Support\Branding;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * The experience letter: the template of Settings > Exit with the six placeholders filled in (aliases {name} and
 * {company}), dates in the company date format. Issuing stores the rendered text on the resignation (IssueExperienceLetter);
 * the PDF is built from that stored text only, so a later template or profile change never alters an issued letter.
 */
final class ExperienceLetter
{
    public const NOT_ISSUED = 'No experience letter has been issued yet.';

    /** @return list<string> */
    public static function placeholders(): array
    {
        return ExitRequest::PLACEHOLDERS;
    }

    /** The template with every known placeholder replaced; unknown braces stay as typed. */
    public static function render(string $template, Resignation $resignation): string
    {
        $employee = $resignation->employee;
        $employee->loadMissing(['department', 'designation']);
        $format = (string) settings('company.date_format', 'd M Y');

        $name = $employee->full_name;
        $company = trim((string) settings('company.name', ''));

        $values = [
            '{employee_name}' => $name,
            '{name}' => $name,
            '{designation}' => (string) ($employee->designation->name ?? ''),
            '{department}' => (string) ($employee->department->name ?? ''),
            '{joining_date}' => $employee->joining_date->format($format),
            '{last_working_day}' => $resignation->last_working_day->format($format),
            '{company_name}' => $company,
            '{company}' => $company,
        ];

        return strtr($template, $values);
    }

    /** PDF bytes of the issued letter (company header, issue date, the stored text). */
    public static function pdf(Resignation $resignation): string
    {
        $format = (string) settings('company.date_format', 'd M Y');
        $logo = settings('branding.logo');
        $path = is_string($logo) ? $logo : null;
        $filename = Branding::filename($path);
        $logoUri = null;

        if ($filename !== null && Branding::disk()->exists((string) $path)) {
            $mime = ['png' => 'image/png', 'jpg' => 'image/jpeg'][strtolower(pathinfo($filename, PATHINFO_EXTENSION))] ?? null;
            $logoUri = $mime === null ? null : "data:{$mime};base64,".base64_encode((string) Branding::disk()->get((string) $path));
        }

        $logoUri ??= \App\Support\Payroll\PayslipPdf::defaultLogoDataUri();

        return Pdf::loadView('pdf.experience-letter', ['l' => [
            'company' => trim((string) settings('company.name', '')),
            'address' => trim((string) preg_replace('/\s*\R\s*/u', ', ', (string) settings('company.address', ''))),
            'logo' => $logoUri,
            'date' => ($resignation->letter_issued_at ?? now())->format($format),
            'text' => (string) $resignation->letter_text,
        ]])
            ->setPaper('a4', 'portrait')
            ->setOption(['defaultFont' => 'DejaVu Sans', 'isRemoteEnabled' => false, 'isFontSubsettingEnabled' => true])
            ->output();
    }

    /** "Experience letter - LD-0007.pdf" */
    public static function filename(Resignation $resignation): string
    {
        $code = (string) preg_replace('/[^A-Za-z0-9-]/', '', (string) $resignation->employee->employee_code);

        return 'Experience letter - '.($code === '' ? 'employee' : $code).'.pdf';
    }
}
