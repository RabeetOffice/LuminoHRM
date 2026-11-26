<?php

use App\Models\Employee;
use App\Models\EmployeeBankAccount;
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
use Symfony\Component\HttpFoundation\BinaryFileResponse;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-12-05 12:00:00', 'Asia/Karachi'));
    $this->excelTemp = sys_get_temp_dir().'/lumino-excel-'.bin2hex(random_bytes(6));
    config(['excel.temporary_files.local_path' => $this->excelTemp]);
});

afterEach(function () {
    File::deleteDirectory($this->excelTemp);
});

function bsBook(TestResponse $response): Spreadsheet
{
    $directory = (string) config('excel.temporary_files.local_path');
    File::ensureDirectoryExists($directory);
    $path = $directory.'/t'.bin2hex(random_bytes(6)).'.xlsx';
    $base = $response->baseResponse;
    expect($base)->toBeInstanceOf(BinaryFileResponse::class);
    copy($base->getFile()->getPathname(), $path);

    return IOFactory::createReader('Xlsx')->load($path);
}

/** A line of $run for a new employee; amounts in whole rupees; bank snapshot given or none. */
function bsLine(PayrollRun $run, string $code, int $netRupees, ?string $account = '00123456789012', array $attrs = []): PayrollItem
{
    $employee = Employee::factory()->create(['employee_code' => $code, 'first_name' => 'Person', 'last_name' => $code]);

    return PayrollItem::factory()->create([
        'payroll_run_id' => $run->id,
        'employee_id' => $employee->id,
        'net' => $netRupees * 100,
        'bank_name' => $account === null ? null : 'Meezan Bank',
        'account_title' => $account === null ? null : "Person {$code}",
        'account_number' => $account,
        ...$attrs,
    ]);
}

// ---------------------------------------------------------------- access

it('lets only payroll.run download the bank sheet', function (string $role, int $status) {
    $user = userWithRole($role);
    employeeFor($user);
    $run = payrollRun('2026-11', 'approved');
    bsLine($run, 'BK-0001', 64930);

    expect($this->actingAs($user)->get("/payroll/runs/{$run->id}/bank-sheet")->getStatusCode())->toBe($status);
})->with([
    'admin' => ['admin', 200],
    'hr' => ['hr', 200],
    'manager' => ['manager', 403],
    'employee' => ['employee', 403],
]);

it('is 404 for a draft run and writes no audit entry', function () {
    $run = payrollRun('2026-11');
    bsLine($run, 'LD-0001', 64930);

    $this->actingAs(userWithRole('hr'))->get("/payroll/runs/{$run->id}/bank-sheet")->assertNotFound();
    expect(Activity::where('description', 'Bank sheet exported')->count())->toBe(0);
});

it('serves a paid run too', function () {
    $run = payrollRun('2026-11', 'paid');
    bsLine($run, 'LD-0001', 64930);

    $this->actingAs(userWithRole('admin'))->get("/payroll/runs/{$run->id}/bank-sheet")->assertOk();
});

// ---------------------------------------------------------------- contents

it('writes transfers with text account numbers, numeric whole-rupee amounts, a total and a second sheet', function () {
    $run = payrollRun('2026-11', 'approved');
    bsLine($run, 'LD-0002', 41400, '=HYPERLINK("http://x")');
    bsLine($run, 'LD-0001', 64930, '00123456789012');
    bsLine($run, 'LD-0003', 58761, null); // no bank account -> sheet 2
    bsLine($run, 'LD-0004', 30000, '111', ['excluded' => true]); // excluded -> nowhere
    bsLine($run, 'LD-0005', 0, '222'); // zero net -> nowhere
    bsLine($run, 'LD-0006', 7501, '   '); // blank account -> sheet 2

    $response = $this->actingAs(userWithRole('hr'))->get("/payroll/runs/{$run->id}/bank-sheet");
    $response->assertOk()->assertDownload('bank-transfer-2026-11.xlsx');
    $book = bsBook($response);

    expect($book->getSheetNames())->toBe(['Transfers', 'Paid outside the sheet']);

    $sheet = $book->getSheet(0);
    expect($sheet->rangeToArray('A1:G1', null, true, false)[0])->toBe(['Employee Code', 'Name', 'Bank', 'Account Title', 'Account Number / IBAN', 'Amount', 'Reference']);

    expect($sheet->getHighestRow())->toBe(4)
        ->and($sheet->getCell('A2')->getValue())->toBe('LD-0001')
        ->and($sheet->getCell('B2')->getValue())->toBe('Person LD-0001')
        ->and($sheet->getCell('C2')->getValue())->toBe('Meezan Bank')
        ->and($sheet->getCell('D2')->getValue())->toBe('Person LD-0001')
        ->and($sheet->getCell('E2')->getValue())->toBe('00123456789012')
        ->and($sheet->getCell('E2')->getDataType())->toBe(DataType::TYPE_STRING)
        ->and($sheet->getCell('F2')->getValue())->toBe(64930)
        ->and($sheet->getCell('F2')->getDataType())->toBe(DataType::TYPE_NUMERIC)
        ->and($sheet->getCell('G2')->getValue())->toBe('Salary November 2026');

    // A value that would be a formula stays text, with the quote prefix.
    expect($sheet->getCell('A3')->getValue())->toBe('LD-0002')
        ->and($sheet->getCell('E3')->getValue())->toBe('=HYPERLINK("http://x")')
        ->and($sheet->getCell('E3')->getDataType())->toBe(DataType::TYPE_STRING)
        ->and($sheet->getCell('E3')->getStyle()->getQuotePrefix())->toBeTrue()
        ->and($sheet->getCell('F3')->getValue())->toBe(41400);

    // Total row = 64,930 + 41,400.
    expect($sheet->getCell('B4')->getValue())->toBe('Total')
        ->and($sheet->getCell('F4')->getValue())->toBe(106330)
        ->and($sheet->getCell('F4')->getDataType())->toBe(DataType::TYPE_NUMERIC);

    $outside = $book->getSheet(1);
    expect($outside->rangeToArray('A1:D4', null, true, false))->toBe([
        ['Employee Code', 'Name', 'Amount', 'Reason'],
        ['LD-0003', 'Person LD-0003', 58761, 'No bank account'],
        ['LD-0006', 'Person LD-0006', 7501, 'No bank account'],
        [null, 'Total', 66262, null],
    ]);
});

it('uses the bank snapshot taken at approval, not the current bank account', function () {
    $run = payrollRun('2026-11', 'approved');
    $item = bsLine($run, 'LD-0001', 64930, '00123456789012');
    EmployeeBankAccount::factory()->create([
        'employee_id' => $item->employee_id, 'bank_name' => 'HBL', 'account_title' => 'Changed', 'account_number' => '999',
    ]);

    $sheet = bsBook($this->actingAs(userWithRole('admin'))->get("/payroll/runs/{$run->id}/bank-sheet"))->getSheet(0);

    expect($sheet->rangeToArray('C2:E2', null, true, false)[0])->toBe(['Meezan Bank', 'Person LD-0001', '00123456789012']);
});

it('audits the export before building it, with counts and no figures or account numbers', function () {
    $admin = userWithRole('admin');
    $run = payrollRun('2026-11', 'approved');
    bsLine($run, 'LD-0001', 64930, '00123456789012');
    bsLine($run, 'LD-0002', 41400, null);

    $this->actingAs($admin)->get("/payroll/runs/{$run->id}/bank-sheet")->assertOk();

    $entry = Activity::where('log_name', 'payroll')->where('description', 'Bank sheet exported')->sole();
    expect($entry->causer_id)->toBe($admin->id)
        ->and($entry->subject_type)->toBe(PayrollRun::class)
        ->and($entry->subject_id)->toBe($run->id)
        ->and($entry->properties->except(['ip', 'user_agent'])->all())->toBe(['month' => '2026-11', 'rows' => 1, 'outside_rows' => 1]);
    expect(json_encode($entry->properties))->not->toContain('64930')->not->toContain('00123456789012');
});

it('reads the same number of queries whatever the headcount', function () {
    $admin = userWithRole('admin');
    $count = function (int $people) use ($admin): int {
        PayrollItem::query()->delete();
        PayrollRun::query()->delete();
        $run = payrollRun('2026-11', 'approved');
        for ($i = 1; $i <= $people; $i++) {
            bsLine($run, sprintf('R%d-%04d', $people, $i), 1000 + $i, $i % 2 === 0 ? null : '0001');
        }
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($admin)->get("/payroll/runs/{$run->id}/bank-sheet")->assertOk();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    };

    $count(1); // warm-up: the first request also loads permissions and settings
    expect($count(2))->toBe($count(9));
});
