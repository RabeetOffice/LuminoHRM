<?php

use App\Actions\Payroll\ApprovePayrollRun;
use App\Actions\Payroll\PayrollLineInputs;
use App\Models\Loan;
use App\Models\LoanRepayment;
use App\Models\PayrollItem;
use App\Support\Payroll\Money;
use App\Support\Payroll\PayslipPdf;
use App\Support\Payroll\TaxReport;
use App\Support\Payroll\TaxYear;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

require_once __DIR__.'/Support/run-helpers.php';

/*
 * One real run, end to end, built from rows and actions only (no hand-written lines): salary profiles, attendance,
 * a loan and a bank account, then GeneratePayrollRun, a one-off bonus through PayrollLineInputs and ApprovePayrollRun.
 * The same figures must then appear in the stored line, the payslip, the bank sheet, the summary, the tax report, the
 * dashboard tile and the loan balance, so a change to the breakdown that one consumer misses is caught here.
 * "Now" is 2026-12-05 12:00 in Karachi, attendance started 2026-11-01.
 */

beforeEach(function () {
    $this->withoutVite();
    $this->travelTo(CarbonImmutable::parse('2026-12-05 12:00:00', 'Asia/Karachi'));
    Settings::set('attendance', ['start_date' => '2026-11-01', 'late_rule_count' => 3]);
    Settings::set('company', ['name' => 'Lumino Digital', 'address' => '12 Main Boulevard, Lahore']);
    Mail::fake();
    Storage::fake('local');
    $this->excelTemp = sys_get_temp_dir().'/lumino-excel-'.bin2hex(random_bytes(6));
    config(['excel.temporary_files.local_path' => $this->excelTemp]);
    $this->hr = userWithRole('hr');
    $this->admin = userWithRole('admin');
});

afterEach(function () {
    File::deleteDirectory($this->excelTemp);
});

function e2eBook(TestResponse $response): Spreadsheet
{
    File::ensureDirectoryExists((string) config('excel.temporary_files.local_path'));
    $path = config('excel.temporary_files.local_path').'/t'.bin2hex(random_bytes(6)).'.xlsx';
    copy($response->baseResponse->getFile()->getPathname(), $path);

    return IOFactory::createReader('Xlsx')->load($path);
}

it('carries one approved run through the line, payslip, exports, tax report, dashboard and loan balance', function () {
    // A: 5% of gross taxable pay, a non-taxable allowance (Medical), a loan, no bank account.
    $a = prEmployee(['employee_code' => 'E2E-001', 'first_name' => 'Amna', 'last_name' => 'Khan']);
    salaryProfile($a);
    prFill($a);
    $loan = loanFor($a, 12000, 5000, '2026-11');

    // B: fixed tax, joins on the 16th (15 days), has a bank account.
    $b = prEmployee(['employee_code' => 'E2E-002', 'first_name' => 'Bilal', 'last_name' => 'Raza', 'joining_date' => '2026-11-16']);
    salaryProfile($b, 40000, [['Transport', 5000, true]], [], '2026-01-01', 'fixed', 150000, null);
    prFill($b, '2026-11-16');
    prBank($b, '0099887766554433');

    $run = prGenerate($this->hr);
    expect($run->items()->count())->toBe(2);

    // A taxable one-off bonus on A's line, through the real action.
    app(PayrollLineInputs::class)->addAdjustment($this->hr, prItem($run, $a), 'Eid bonus', 'addition', 10000, true);

    expect(app(ApprovePayrollRun::class)->approve($this->admin, $run, $run->fresh()->fingerprint()))->toBe('approved');

    $run->refresh();
    $lineA = prItem($run, $a);
    $lineB = prItem($run, $b);

    // The stored lines. A: gross 60,000 + 24,000 + 6,000 + 10,000 bonus; tax 5% of 94,000 (basic + rent + bonus); PF 3,000; loan 5,000.
    expect($lineA->days_paid)->toBe(30)
        ->and($lineA->gross)->toBe(prRs(100000))
        ->and($lineA->taxable_pay)->toBe(prRs(94000))
        ->and($lineA->tax)->toBe(prRs(4700))
        ->and($lineA->loan_deduction)->toBe(prRs(5000))
        ->and($lineA->fixed_deductions)->toBe(prRs(3000))
        ->and($lineA->net)->toBe(prRs(87300))
        // B: 15 of 30 days of (40,000 + 5,000) = 22,500; fixed tax 1,500 a month over 15 days = 750.
        ->and($lineB->days_paid)->toBe(15)
        ->and($lineB->gross)->toBe(prRs(22500))
        ->and($lineB->tax)->toBe(prRs(750))
        ->and($lineB->loan_deduction)->toBe(0)
        ->and($lineB->net)->toBe(prRs(21750))
        ->and($lineB->account_number)->toBe('0099887766554433')
        ->and($lineA->account_number)->toBeNull();

    $runNet = $lineA->net + $lineB->net;
    expect($run->status)->toBe('approved')
        ->and($run->net)->toBe($runNet)
        ->and($run->gross)->toBe($lineA->gross + $lineB->gross)
        ->and($run->tax)->toBe($lineA->tax + $lineB->tax)
        ->and($run->headcount)->toBe(2);

    // The payslips: built from the frozen breakdown, the same figures as the line.
    foreach ([$lineA, $lineB] as $line) {
        $slip = PayslipPdf::content($line);
        $text = PayslipPdf::text($line);

        expect($slip['gross'])->toBe(Money::format($line->gross))
            ->and($slip['net'])->toBe(Money::format($line->net))
            ->and($slip['tax']['amount'])->toBe(Money::format($line->tax))
            ->and($slip['total_deductions'])->toBe(Money::format($line->attendance_deduction + $line->fixed_deductions + $line->other_deductions + $line->loan_deduction))
            ->and($text)->toContain($slip['net'])
            ->and(PayslipPdf::render($line))->toStartWith('%PDF');
    }
    $slipA = PayslipPdf::content($lineA);
    expect($slipA['tax']['taxable_pay'])->toBe(Money::format($lineA->taxable_pay))
        ->and($slipA['deductions'])->toContain(['Loan installment', Money::format($lineA->loan_deduction)])
        ->and($slipA['earnings'])->toContain(['Eid bonus', Money::format(prRs(10000))]);

    // The bank sheet: B is a transfer, A is paid outside the sheet; together they are the run's net.
    $bank = e2eBook($this->actingAs($this->hr)->get("/payroll/runs/{$run->id}/bank-sheet")->assertOk());
    $transfers = $bank->getSheet(0);
    $outside = $bank->getSheet(1);
    expect($transfers->getCell('A2')->getValue())->toBe('E2E-002')
        ->and($transfers->getCell('E2')->getValue())->toBe('0099887766554433')
        ->and($transfers->getCell('F2')->getValue())->toBe(21750)
        ->and($transfers->getCell('F3')->getValue())->toBe(21750)
        ->and($outside->getCell('A2')->getValue())->toBe('E2E-001')
        ->and($outside->getCell('C2')->getValue())->toBe(87300)
        ->and($outside->getCell('C3')->getValue())->toBe(87300)
        ->and(($transfers->getCell('F3')->getValue() + $outside->getCell('C3')->getValue()) * 100)->toBe($runNet);

    // The summary export: a row per line and a totals row equal to the run.
    $summary = e2eBook($this->actingAs($this->hr)->get("/payroll/runs/{$run->id}/summary")->assertOk())->getSheet(0);
    $header = $summary->rangeToArray('A1:Q1', null, true, false)[0];
    $col = fn (string $name): string => chr(ord('A') + array_search($name, $header, true));
    foreach ([2 => $lineA, 3 => $lineB] as $row => $line) {
        expect($summary->getCell($col('Gross').$row)->getValue())->toBe(intdiv($line->gross, 100))
            ->and($summary->getCell($col('Tax').$row)->getValue())->toBe(intdiv($line->tax, 100))
            ->and($summary->getCell($col('Loan Deduction').$row)->getValue())->toBe(intdiv($line->loan_deduction, 100))
            ->and($summary->getCell($col('Net').$row)->getValue())->toBe(intdiv($line->net, 100));
    }
    expect($summary->getCell('B4')->getValue())->toBe('Total')
        ->and($summary->getCell($col('Gross').'4')->getValue())->toBe(intdiv($run->gross, 100))
        ->and($summary->getCell($col('Tax').'4')->getValue())->toBe(intdiv($run->tax, 100))
        ->and($summary->getCell($col('Net').'4')->getValue())->toBe(intdiv($runNet, 100));

    // The tax report: A's taxable pay and tax, B's taxable pay and fixed tax, for the run's tax year.
    $report = TaxReport::for(TaxYear::of('2026-11', TaxReport::startMonth()));
    $rows = collect($report['rows'])->keyBy('code');
    expect($rows['E2E-001']['months']['2026-11'])->toBe(['taxable' => $lineA->taxable_pay, 'tax' => $lineA->tax])
        ->and($rows['E2E-002']['months']['2026-11'])->toBe(['taxable' => $lineB->taxable_pay, 'tax' => $lineB->tax])
        ->and($report['totals']['months']['2026-11']['tax'])->toBe($run->tax);

    // The dashboard tile.
    $tile = $this->actingAs($this->hr)->get('/dashboard')->assertOk()->inertiaProps('payrollThisMonth');
    expect($tile['status'])->toBe('approved')
        ->and($tile['headcount'])->toBe(2)
        ->and($tile['net'])->toBe($runNet)
        ->and($tile['run_id'])->toBe($run->id);

    // The loan: one repayment equal to the line's deduction, Rs 12,000 down to Rs 7,000.
    $repayment = LoanRepayment::query()->sole();
    expect($repayment->payroll_item_id)->toBe($lineA->id)
        ->and($repayment->amount)->toBe($lineA->loan_deduction)
        ->and(Loan::query()->whereKey($loan->id)->withRemaining()->sole()->remaining())->toBe(prRs(12000) - $lineA->loan_deduction)
        ->and(PayrollItem::query()->whereKey($lineA->id)->value('net'))->toBe($lineA->net);
});

it('reports the gross taxable pay for a person taxed on basic only, matching the opening figure', function () {
    // 5% of BASIC: the line's taxable_pay column is the tax base (basic 60,000), the report shows gross taxable 84,000.
    $e = prEmployee(['employee_code' => 'E2E-003']);
    salaryProfile($e, 60000, [['House rent', 24000, true], ['Medical', 6000, false]], [['Provident fund', 3000]], '2026-01-01', 'percentage', 500, 'basic');
    prFill($e);

    $run = prGenerate($this->hr);
    app(ApprovePayrollRun::class)->approve($this->admin, $run, $run->fresh()->fingerprint());
    $line = prItem($run->refresh(), $e);

    expect($line->tax)->toBe(prRs(3000))
        ->and($line->taxable_pay)->toBe(prRs(60000));

    $row = collect(TaxReport::for(TaxYear::of('2026-11', TaxReport::startMonth()))['rows'])->sole();
    expect($row['months']['2026-11'])->toBe(['taxable' => prRs(84000), 'tax' => prRs(3000)])
        ->and($row['total_taxable'])->toBe(prRs(84000));
});
