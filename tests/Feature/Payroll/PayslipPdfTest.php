<?php

use App\Models\PayrollItem;
use App\Support\Payroll\PayslipPdf;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/PayslipFixtures.php';

/*
| Payslip PDF and text: built only from the line's stored breakdown, its bank snapshot and the run's company snapshot
| (never recalculated, no setting read), stored on the private disk, re-rendered identically when the file is missing.
*/

beforeEach(function () {
    Storage::fake('local');
});

it('renders the example line as text from the stored breakdown', function () {
    $item = psItem(psRun(), psSara());

    $text = PayslipPdf::text($item);

    expect($text)
        ->toContain('Lumino Digital')
        ->toContain('12 Main Boulevard, Lahore')
        ->toContain('Payslip — November 2026')
        ->toContain('Name: Sara Ahmed')
        ->toContain('Employee code: LD-0007')
        ->toContain('Department: HR')
        ->toContain('Designation: Officer')
        ->toContain('Joining date: 1 Mar 2024')
        ->toContain('Bank: Meezan Bank •••• 9012')
        ->not->toContain('00123456789012')
        ->toContain('Basic salary: Rs 60,000')
        ->toContain('House rent: Rs 24,000')
        ->toContain('Medical: Rs 6,000')
        ->toContain('Gross pay: Rs 90,000')
        ->toContain('Attendance (4.5 days): Rs 13,500')
        ->toContain('Provident fund: Rs 3,000')
        ->toContain('Loan installment: Rs 5,000')
        ->toContain('Total deductions: Rs 21,500')
        ->toContain('Mode: 5% of gross taxable pay')
        ->toContain('Taxable pay: Rs 71,400')
        ->toContain('Tax: Rs 3,570')
        ->toContain('Net pay: Rs 64,930')
        ->toContain('Days in month: 30')
        ->toContain('Days paid: 30')
        ->toContain('Present: 20')
        ->toContain('Late: 5')
        ->toContain('Half days: 1')
        ->toContain('Absent: 2')
        ->toContain('Paid leave: 0')
        ->toContain('Unpaid leave: 1')
        ->toContain('Late days deducted: 1')
        ->toContain('Overtime: None')
        ->toContain('This payslip is computer generated and needs no signature.');
});

it('never recalculates: figures come from the breakdown even when the columns differ', function () {
    $item = psItem(psRun(), psSara(), ['net' => 1, 'gross' => 1]);

    expect(PayslipPdf::text($item))->toContain('Net pay: Rs 64,930')->toContain('Gross pay: Rs 90,000');
});

it('keeps the company snapshot after the company settings change', function () {
    $item = psItem(psRun(), psSara());
    $before = PayslipPdf::text($item);

    Settings::set('company', ['name' => 'Renamed Ltd', 'address' => 'Elsewhere']);
    Settings::set('payroll', ['payslip_footer' => 'A new footer']);

    $after = PayslipPdf::text($item->fresh());

    expect($after)->toBe($before)
        ->toContain('Lumino Digital')
        ->not->toContain('Renamed Ltd')
        ->not->toContain('A new footer');
});

it('describes each tax mode', function (array $tax, string $text) {
    expect(PayslipPdf::taxMode($tax))->toBe($text);
})->with([
    'percentage of gross taxable' => [['mode' => 'percentage', 'base' => 'gross_taxable', 'rate_bp' => 500], '5% of gross taxable pay'],
    'percentage of basic' => [['mode' => 'percentage', 'base' => 'basic', 'rate_bp' => 525], '5.25% of basic pay'],
    'fixed' => [['mode' => 'fixed'], 'Fixed'],
    'overridden' => [['mode' => 'percentage', 'rate_bp' => 500, 'overridden' => true], 'Overridden for this month'],
    'none' => [['mode' => 'none'], 'No tax'],
]);

it('shows a skipped loan, half days of unpaid leave, overtime and no bank account', function () {
    $sara = psSara();
    $run = psRun();
    $item = psItem($run, $sara, [
        'account_number' => null,
        'bank_name' => null,
        'breakdown' => psBreakdown($sara, '2026-11', [
            'deductions' => [2 => ['label' => 'Loan installment (skipped this month)', 'amount' => 0, 'due' => 0, 'skipped' => true]],
            'attendance' => ['unpaid_leave' => 0.5, 'overtime_minutes' => 605],
        ]),
    ]);

    expect(PayslipPdf::text($item))
        ->toContain('Loan installment (skipped this month): Rs 0')
        ->toContain('Unpaid leave: 0.5')
        ->toContain('Overtime: 10 h 5 min')
        ->toContain('Bank: No bank account');
});

it('renders a one-page A4 PDF', function () {
    $item = psItem(psRun(), psSara());

    $pdf = PayslipPdf::render($item);

    expect($pdf)->toStartWith('%PDF')
        ->and(preg_match_all('#/Type\s*/Page[^s]#', $pdf))->toBe(1)
        ->and($pdf)->toContain('/MediaBox [0.000 0.000 595.280 841.890]');
});

it('stores the PDF on the private disk and records only the payslip columns', function () {
    $item = psItem(psRun(), psSara());
    $before = DB::table('payroll_items')->where('id', $item->id)->first();

    $path = PayslipPdf::store($item);

    expect($path)->toBe("payslips/2026-11/LD-0007-{$item->id}.pdf");
    Storage::disk('local')->assertExists($path);
    expect(Storage::disk('local')->get($path))->toStartWith('%PDF');

    $after = DB::table('payroll_items')->where('id', $item->id)->first();
    expect($after->payslip_path)->toBe($path)
        ->and($after->payslip_generated_at)->not->toBeNull();

    foreach ([...PayrollItem::FIGURES, 'breakdown', 'bank_name', 'account_number', 'excluded'] as $column) {
        expect($after->{$column})->toBe($before->{$column});
    }
});

it('re-renders a missing file from the same stored data', function () {
    $item = psItem(psRun(), psSara());
    $path = PayslipPdf::store($item);
    $text = PayslipPdf::text($item);

    Storage::disk('local')->delete($path);
    Settings::set('company', ['name' => 'Renamed Ltd']);

    expect(PayslipPdf::pathOrRender($item->fresh()))->toBe($path);
    Storage::disk('local')->assertExists($path);
    expect(PayslipPdf::text($item->fresh()))->toBe($text);
});

it('keeps an existing file', function () {
    $item = psItem(psRun(), psSara());
    $path = PayslipPdf::store($item);
    Storage::disk('local')->put($path, '%PDF-existing');

    expect(PayslipPdf::pathOrRender($item->fresh()))->toBe($path)
        ->and(Storage::disk('local')->get($path))->toBe('%PDF-existing');
});

it('names the file a person sees by month and code', function () {
    $item = psItem(psRun(), psSara());

    expect(PayslipPdf::filename($item))->toBe('payslip-2026-11-LD-0007.pdf');
});
