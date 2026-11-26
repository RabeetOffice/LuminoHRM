<?php

use App\Models\Employee;
use App\Models\OpeningBalance;
use App\Models\PayrollItem;
use App\Models\PayrollRun;
use App\Support\Payroll\TaxReport;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia as Assert;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Activitylog\Models\Activity;

/* "Now" is 5 Dec 2026: tax year 2026-27 (July 2026 to June 2027). */

beforeEach(function () {
    $this->withoutVite();
    $this->travelTo(CarbonImmutable::parse('2026-12-05 12:00:00', 'Asia/Karachi'));
    $this->excelTemp = sys_get_temp_dir().'/lumino-excel-'.bin2hex(random_bytes(6));
    config(['excel.temporary_files.local_path' => $this->excelTemp]);
});

afterEach(function () {
    File::deleteDirectory($this->excelTemp);
});

function trPerson(string $code): Employee
{
    return Employee::factory()->create(['employee_code' => $code, 'first_name' => 'Person', 'last_name' => $code]);
}

/** A line in the run of $month (created with $status if missing): taxable 71,400 and tax 3,570 unless given (rupees). */
function trLine(Employee $e, string $month, string $status = 'approved', int $taxRupees = 3570, int $taxableRupees = 71400, array $attrs = []): PayrollItem
{
    $run = PayrollRun::query()->where('month', $month)->first() ?? payrollRun($month, $status);

    return PayrollItem::factory()->create([
        'payroll_run_id' => $run->id,
        'employee_id' => $e->id,
        'taxable_pay' => $taxableRupees * 100,
        'tax' => $taxRupees * 100,
        ...$attrs,
    ]);
}

function trRow(array $report, string $code): ?array
{
    foreach ($report['rows'] as $row) {
        if ($row['code'] === $code) {
            return $row;
        }
    }

    return null;
}

// ---------------------------------------------------------------- figures

it('adds the opening tax to the tax of approved and paid runs of the tax year', function () {
    $e = trPerson('LD-0001');
    OpeningBalance::factory()->create(['employee_id' => $e->id, 'tax_year' => 2026]); // Rs 12,000 on Rs 240,000
    trLine($e, '2026-11', 'paid');
    trLine($e, '2026-12', 'approved');

    $report = TaxReport::for(2026);
    $row = trRow($report, 'LD-0001');

    expect($report['year'])->toBe(2026)
        ->and($report['label'])->toBe('2026-27')
        ->and($report['months'])->toBe(['2026-07', '2026-08', '2026-09', '2026-10', '2026-11', '2026-12', '2027-01', '2027-02', '2027-03', '2027-04', '2027-05', '2027-06'])
        ->and($row['name'])->toBe('Person LD-0001')
        ->and($row['opening_tax'])->toBe(1_200_000)
        ->and($row['opening_taxable'])->toBe(24_000_000)
        ->and($row['months']['2026-11'])->toBe(['taxable' => 7_140_000, 'tax' => 357_000])
        ->and($row['months']['2026-12'])->toBe(['taxable' => 7_140_000, 'tax' => 357_000])
        ->and($row['months']['2026-10'])->toBe(['taxable' => 0, 'tax' => 0])
        ->and($row['total_tax'])->toBe(1_914_000) // 12,000 + 3,570 + 3,570 = 19,140
        ->and($row['total_taxable'])->toBe(38_280_000); // 240,000 + 71,400 + 71,400 = 382,800
});

it('counts a June 2027 run in 2026-27 and a July 2027 run in 2027-28', function () {
    $e = trPerson('LD-0001');
    trLine($e, '2026-06', 'approved', 1000); // 2025-26
    trLine($e, '2027-06', 'approved', 2000);
    trLine($e, '2027-07', 'approved', 4000);

    expect(trRow(TaxReport::for(2026), 'LD-0001')['total_tax'])->toBe(200_000)
        ->and(trRow(TaxReport::for(2027), 'LD-0001')['total_tax'])->toBe(400_000)
        ->and(trRow(TaxReport::for(2027), 'LD-0001')['months']['2027-07']['tax'])->toBe(400_000)
        ->and(trRow(TaxReport::for(2025), 'LD-0001')['total_tax'])->toBe(100_000);
});

it('follows the tax year start month setting', function () {
    Settings::set('payroll', ['tax_year_start_month' => 1]);
    $e = trPerson('LD-0001');
    trLine($e, '2026-01', 'approved', 1000);
    trLine($e, '2026-12', 'approved', 2000);
    trLine($e, '2027-01', 'approved', 4000);

    $report = TaxReport::for(2026);
    expect($report['label'])->toBe('2026')
        ->and($report['months'][0])->toBe('2026-01')
        ->and(trRow($report, 'LD-0001')['total_tax'])->toBe(300_000);
});

it('never counts a draft run or an excluded line, and lists nobody without a figure', function () {
    $a = trPerson('LD-0001');
    $b = trPerson('LD-0002');
    trPerson('LD-0003'); // nothing at all
    trLine($a, '2026-11', 'approved');
    trLine($a, '2026-12', 'draft', 9999);
    trLine($b, '2026-12', 'draft', 9999);
    trLine($b, '2026-11', 'approved', 0, 0, ['excluded' => true]);

    $report = TaxReport::for(2026);

    expect(array_column($report['rows'], 'code'))->toBe(['LD-0001'])
        ->and($report['rows'][0]['total_tax'])->toBe(357_000)
        ->and($report['totals']['total_tax'])->toBe(357_000)
        ->and($report['totals']['months']['2026-12']['tax'])->toBe(0);
});

it('includes a leaver for the months they were paid and someone with only opening figures', function () {
    $leaver = trPerson('LD-0001');
    $leaver->forceFill(['status' => 'resigned', 'last_working_day' => '2026-08-31'])->save();
    trLine($leaver, '2026-07');
    trLine($leaver, '2026-08');
    $opening = trPerson('LD-0002');
    OpeningBalance::factory()->create(['employee_id' => $opening->id, 'tax_year' => 2026, 'tax_deducted' => 500_000, 'taxable_income' => 10_000_000]);
    OpeningBalance::factory()->create(['employee_id' => $opening->id, 'tax_year' => 2025]); // another year: not this report

    $report = TaxReport::for(2026);

    expect(array_column($report['rows'], 'code'))->toBe(['LD-0001', 'LD-0002'])
        ->and($report['rows'][0]['months']['2026-08']['tax'])->toBe(357_000)
        ->and($report['rows'][0]['months']['2026-09']['tax'])->toBe(0)
        ->and($report['rows'][0]['total_tax'])->toBe(714_000)
        ->and($report['rows'][1]['total_tax'])->toBe(500_000)
        ->and($report['totals']['opening_tax'])->toBe(500_000)
        ->and($report['totals']['total_tax'])->toBe(1_214_000)
        ->and($report['totals']['months']['2026-07'])->toBe(['taxable' => 7_140_000, 'tax' => 357_000]);
});

it('reads a fixed number of queries whatever the headcount', function () {
    $count = function (int $people): int {
        for ($i = 1; $i <= $people; $i++) {
            $e = trPerson(sprintf('LD-%02d%02d', $people, $i));
            OpeningBalance::factory()->create(['employee_id' => $e->id, 'tax_year' => 2026]);
            trLine($e, '2026-11');
            trLine($e, '2026-12');
        }
        TaxReport::startMonth(); // the settings read is cached after the first one
        DB::flushQueryLog();
        DB::enableQueryLog();
        TaxReport::for(2026);
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    };

    // Opening rows, counted lines, settlements, employees.
    expect($count(2))->toBe(4)->and($count(9))->toBe(4);
});

// ---------------------------------------------------------------- screen

it('lets only payroll.view open the report and its export', function (string $role, int $status) {
    $user = userWithRole($role);
    employeeFor($user);

    expect($this->actingAs($user)->get('/payroll/tax-report')->getStatusCode())->toBe($status)
        ->and($this->actingAs($user)->get('/payroll/tax-report/export')->getStatusCode())->toBe($status);
})->with([
    'admin' => ['admin', 200],
    'hr' => ['hr', 200],
    'manager' => ['manager', 403],
    'employee' => ['employee', 403],
]);

it('shows the current tax year by default with formatted figures and the years offered', function () {
    $e = trPerson('LD-0001');
    OpeningBalance::factory()->create(['employee_id' => $e->id, 'tax_year' => 2026]);
    OpeningBalance::factory()->create(['employee_id' => $e->id, 'tax_year' => 2024]);
    trLine($e, '2026-11');
    trLine($e, '2026-12');

    $this->actingAs(userWithRole('hr'))->get('/payroll/tax-report')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('payroll/tax-report')
            ->where('year', 2026)
            ->where('label', '2026-27')
            ->where('years', [
                ['value' => 2026, 'label' => '2026-27'],
                ['value' => 2025, 'label' => '2025-26'],
                ['value' => 2024, 'label' => '2024-25'],
            ])
            ->where('months.0', ['month' => '2026-07', 'label' => 'Jul', 'title' => 'July 2026'])
            ->has('rows', 1)
            ->where('rows.0.code', 'LD-0001')
            ->where('rows.0.opening_tax', 'Rs 12,000')
            ->where('rows.0.months.2026-11', 'Rs 3,570')
            ->where('rows.0.months.2026-10', '–')
            ->where('rows.0.total_tax', 'Rs 19,140')
            ->where('rows.0.total_taxable', 'Rs 382,800')
            ->where('totals.total_tax', 'Rs 19,140'));
});

it('opens an earlier year from the list and falls back to the current year otherwise', function () {
    $e = trPerson('LD-0001');
    OpeningBalance::factory()->create(['employee_id' => $e->id, 'tax_year' => 2025]);
    $hr = userWithRole('hr');

    $this->actingAs($hr)->get('/payroll/tax-report?year=2025')
        ->assertInertia(fn (Assert $page) => $page->where('year', 2025)->has('rows', 1));
    foreach (['2027', '2019', 'abc', '2025-26'] as $year) {
        $this->actingAs($hr)->get("/payroll/tax-report?year={$year}")
            ->assertInertia(fn (Assert $page) => $page->where('year', 2026)->has('rows', 0));
    }
});

it('exports the year with numeric cells and audits it with the year and row count only', function () {
    $hr = userWithRole('hr');
    $e = Employee::factory()->create(['employee_code' => 'LD-0001', 'first_name' => '=cmd', 'last_name' => 'X']);
    OpeningBalance::factory()->create(['employee_id' => $e->id, 'tax_year' => 2026]);
    trLine($e, '2026-11');
    trLine($e, '2026-12');

    $response = $this->actingAs($hr)->get('/payroll/tax-report/export?year=2026');
    $response->assertOk()->assertDownload('tax-report-2026-27.xlsx');

    $directory = (string) config('excel.temporary_files.local_path');
    File::ensureDirectoryExists($directory);
    $path = $directory.'/t.xlsx';
    copy($response->baseResponse->getFile()->getPathname(), $path);
    $sheet = IOFactory::createReader('Xlsx')->load($path)->getSheet(0);

    expect($sheet->getTitle())->toBe('Tax 2026-27')
        ->and($sheet->rangeToArray('A1:F1', null, true, false)[0])->toBe(['Employee Code', 'Name', 'Opening Taxable Income', 'Opening Tax', 'July 2026 Taxable Pay', 'July 2026 Tax'])
        ->and($sheet->getCell('AD1')->getValue())->toBe('Total Tax');
    // Row 2: opening 240,000 / 12,000; Nov (columns M, N) and Dec (O, P); totals AC, AD.
    expect($sheet->rangeToArray('A2:D2', null, true, false)[0])->toBe(['LD-0001', '=cmd X', 240000, 12000])
        ->and($sheet->getCell('B2')->getStyle()->getQuotePrefix())->toBeTrue()
        ->and($sheet->getCell('M2')->getValue())->toBe(71400)
        ->and($sheet->getCell('N2')->getValue())->toBe(3570)
        ->and($sheet->getCell('P2')->getValue())->toBe(3570)
        ->and($sheet->getCell('AC2')->getValue())->toBe(382800)
        ->and($sheet->getCell('AD2')->getValue())->toBe(19140)
        ->and($sheet->getCell('AD2')->getDataType())->toBe(DataType::TYPE_NUMERIC)
        ->and($sheet->getCell('B3')->getValue())->toBe('Total')
        ->and($sheet->getCell('AD3')->getValue())->toBe(19140);

    $entry = Activity::where('log_name', 'payroll')->where('description', 'Tax report exported')->sole();
    expect($entry->causer_id)->toBe($hr->id)
        ->and($entry->properties->except(['ip', 'user_agent'])->all())->toBe(['tax_year' => 2026, 'label' => '2026-27', 'rows' => 1]);
});
