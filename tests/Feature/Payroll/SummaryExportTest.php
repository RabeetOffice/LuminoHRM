<?php

use App\Models\Department;
use App\Models\Employee;
use App\Models\PayrollItem;
use App\Models\PayrollRun;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-12-05 12:00:00', 'Asia/Karachi'));
    $this->excelTemp = sys_get_temp_dir().'/lumino-excel-'.bin2hex(random_bytes(6));
    config(['excel.temporary_files.local_path' => $this->excelTemp]);
});

afterEach(function () {
    File::deleteDirectory($this->excelTemp);
});

function seBook(TestResponse $response): Spreadsheet
{
    $directory = (string) config('excel.temporary_files.local_path');
    File::ensureDirectoryExists($directory);
    $path = $directory.'/t'.bin2hex(random_bytes(6)).'.xlsx';
    copy($response->baseResponse->getFile()->getPathname(), $path);

    return IOFactory::createReader('Xlsx')->load($path);
}

/** Example 1 of the design (paisa): gross 90,000, attendance 13,500, PF 3,000, loan 5,000, tax 3,570, net 64,930. */
function seLine(PayrollRun $run, string $code, array $attrs = []): PayrollItem
{
    $dept = Department::factory()->create(['name' => 'Dept '.$code]);
    $employee = Employee::factory()->create([
        'employee_code' => $code, 'first_name' => 'Person', 'last_name' => $code, 'department_id' => $dept->id,
    ]);

    return PayrollItem::factory()->create([
        'payroll_run_id' => $run->id,
        'employee_id' => $employee->id,
        'days_paid' => 30,
        'basic' => 6_000_000,
        'allowances' => 3_000_000,
        'overtime' => 0,
        'additions' => 0,
        'gross' => 9_000_000,
        'attendance_deduction' => 1_350_000,
        'fixed_deductions' => 300_000,
        'other_deductions' => 0,
        'loan_deduction' => 500_000,
        'taxable_pay' => 7_140_000,
        'tax' => 357_000,
        'net' => 6_493_000,
        ...$attrs,
    ]);
}

it('lets only payroll.view download the summary', function (string $role, int $status) {
    $user = userWithRole($role);
    employeeFor($user);
    $run = payrollRun('2026-11', 'approved');
    seLine($run, 'BK-0001');

    expect($this->actingAs($user)->get("/payroll/runs/{$run->id}/summary")->getStatusCode())->toBe($status);
})->with([
    'admin' => ['admin', 200],
    'hr' => ['hr', 200],
    'manager' => ['manager', 403],
    'employee' => ['employee', 403],
]);

it('writes one row per line with every figure as a number equal to the item columns, and totals', function () {
    $run = payrollRun('2026-11', 'approved');
    seLine($run, 'LD-0002', [
        'days_paid' => 15, 'basic' => 3_000_000, 'allowances' => 1_200_000, 'overtime' => 562_500, 'additions' => 100_000,
        'gross' => 4_862_500, 'attendance_deduction' => 0, 'fixed_deductions' => 150_000, 'other_deductions' => 20_000,
        'loan_deduction' => 0, 'taxable_pay' => 4_500_000, 'tax' => 225_000, 'net' => 4_467_500,
    ]);
    seLine($run, 'LD-0001');
    seLine($run, 'LD-0003', ['excluded' => true, 'days_paid' => 0] + array_fill_keys(PayrollItem::FIGURES, 0));

    $response = $this->actingAs(userWithRole('hr'))->get("/payroll/runs/{$run->id}/summary");
    $response->assertOk()->assertDownload('payroll-summary-2026-11.xlsx');
    $sheet = seBook($response)->getSheet(0);

    expect($sheet->getTitle())->toBe('November 2026');
    expect($sheet->rangeToArray('A1:Q1', null, true, false)[0])->toBe([
        'Employee Code', 'Name', 'Department', 'Days Paid', 'Basic', 'Allowances', 'Overtime', 'Additions', 'Gross',
        'Attendance Deduction', 'Fixed Deductions', 'Other Deductions', 'Loan Deduction', 'Taxable Pay', 'Tax', 'Net',
        'Excluded',
    ]);
    expect($sheet->rangeToArray('A2:Q5', null, true, false))->toBe([
        ['LD-0001', 'Person LD-0001', 'Dept LD-0001', 30, 60000, 30000, 0, 0, 90000, 13500, 3000, 0, 5000, 71400, 3570, 64930, 'No'],
        ['LD-0002', 'Person LD-0002', 'Dept LD-0002', 15, 30000, 12000, 5625, 1000, 48625, 0, 1500, 200, 0, 45000, 2250, 44675, 'No'],
        ['LD-0003', 'Person LD-0003', 'Dept LD-0003', 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 'Yes'],
        [null, 'Total', null, 45, 90000, 42000, 5625, 1000, 138625, 13500, 4500, 200, 5000, 116400, 5820, 109605, null],
    ]);
    foreach (['D2', 'E2', 'P2', 'P5'] as $cell) {
        expect($sheet->getCell($cell)->getDataType())->toBe(DataType::TYPE_NUMERIC);
    }
    expect($sheet->getCell('A2')->getDataType())->toBe(DataType::TYPE_STRING);
});

it('titles a draft run DRAFT', function () {
    $run = payrollRun('2026-11');
    seLine($run, 'LD-0001');

    $response = $this->actingAs(userWithRole('admin'))->get("/payroll/runs/{$run->id}/summary");
    $response->assertOk()->assertDownload('payroll-summary-2026-11-DRAFT.xlsx');
    $book = seBook($response);

    expect($book->getSheet(0)->getTitle())->toBe('DRAFT November 2026')
        ->and($book->getProperties()->getTitle())->toBe('DRAFT - Payroll summary November 2026');
});

it('takes code, name and department from the frozen breakdown when present', function () {
    $run = payrollRun('2026-11', 'approved');
    seLine($run, 'LD-0001', ['breakdown' => ['version' => 1, 'employee' => [
        'id' => 1, 'code' => 'LD-0001', 'name' => '=Old Name', 'department' => 'Old Dept',
    ]]]);

    $sheet = seBook($this->actingAs(userWithRole('admin'))->get("/payroll/runs/{$run->id}/summary"))->getSheet(0);

    expect($sheet->rangeToArray('A2:C2', null, true, false)[0])->toBe(['LD-0001', '=Old Name', 'Old Dept'])
        ->and($sheet->getCell('B2')->getDataType())->toBe(DataType::TYPE_STRING)
        ->and($sheet->getCell('B2')->getStyle()->getQuotePrefix())->toBeTrue();
});

it('audits the export with the run, status and row count, no figures', function () {
    $hr = userWithRole('hr');
    $run = payrollRun('2026-11');
    seLine($run, 'LD-0001');

    $this->actingAs($hr)->get("/payroll/runs/{$run->id}/summary")->assertOk();

    $entry = Activity::where('log_name', 'payroll')->where('description', 'Payroll summary exported')->sole();
    expect($entry->causer_id)->toBe($hr->id)
        ->and($entry->subject_id)->toBe($run->id)
        ->and($entry->properties->except(['ip', 'user_agent'])->all())->toBe(['month' => '2026-11', 'status' => 'draft', 'rows' => 1]);
});

it('reads the same number of queries whatever the headcount', function () {
    $admin = userWithRole('admin');
    $count = function (int $people) use ($admin): int {
        PayrollItem::query()->delete();
        PayrollRun::query()->delete();
        $run = payrollRun('2026-11', 'approved');
        for ($i = 1; $i <= $people; $i++) {
            seLine($run, sprintf('R%d-%04d', $people, $i));
        }
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($admin)->get("/payroll/runs/{$run->id}/summary")->assertOk();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    };

    $count(1); // warm-up: the first request also loads permissions and settings
    expect($count(2))->toBe($count(8));
});

it('is 404 for an unknown run', function () {
    $this->actingAs(userWithRole('admin'))->get('/payroll/runs/999/summary')->assertNotFound();
});
