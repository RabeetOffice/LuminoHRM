<?php

use App\Actions\Payroll\SaveLoan;
use App\Http\Controllers\EmployeeImportController;
use App\Http\Controllers\Payroll\OpeningImportController;
use App\Models\Employee;
use App\Models\Loan;
use App\Models\LoanRepayment;
use App\Models\OpeningBalance;
use App\Models\User;
use App\Support\EmployeeImportStore;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/*
 * The go-live payroll imports under /payroll/opening/import/{tax|loans}: template, upload + preview with per-row errors,
 * run (all or nothing, audited once per run plus per changed row), idempotent re-import. Today is 2026-11-25.
 */

const OPI_TAX = ['Employee Code', 'Tax Year', 'Taxable Income To Date', 'Tax Deducted To Date'];
const OPI_LOANS = ['Employee Code', 'Type', 'Original Amount', 'Repaid Before Go-Live', 'Monthly Installment', 'First Deduction Month', 'Note'];

beforeEach(function () {
    $this->withoutVite();
    $this->travelTo(CarbonImmutable::parse('2026-11-25 12:00:00', 'Asia/Karachi'));
    Storage::fake('local');
    $this->excelTemp = sys_get_temp_dir().'/lumino-excel-'.bin2hex(random_bytes(6));
    config(['excel.temporary_files.local_path' => $this->excelTemp]);
});

afterEach(function () {
    File::deleteDirectory($this->excelTemp);
});

function opiUser(string $role = 'hr'): User
{
    $user = userWithRole($role);
    employeeFor($user, ['joining_date' => '2024-03-01']);

    return $user->fresh();
}

function opiPerson(string $code): Employee
{
    return Employee::factory()->create(['employee_code' => $code, 'joining_date' => '2024-03-01']);
}

/**
 * An .xlsx with these headers and rows (ints / floats as number cells, strings as text cells).
 *
 * @param  list<string>  $headers
 * @param  list<list<mixed>>  $rows
 */
function opiXlsx(array $headers, array $rows): UploadedFile
{
    $book = new Spreadsheet;
    $sheet = $book->getActiveSheet();

    foreach ($headers as $i => $header) {
        $sheet->setCellValueExplicit([$i + 1, 1], $header, DataType::TYPE_STRING);
    }

    foreach ($rows as $r => $row) {
        foreach ($row as $c => $value) {
            if ($value === null) {
                continue;
            }

            is_string($value)
                ? $sheet->setCellValueExplicit([$c + 1, $r + 2], $value, DataType::TYPE_STRING)
                : $sheet->setCellValueExplicit([$c + 1, $r + 2], $value, DataType::TYPE_NUMERIC);
        }
    }

    $path = tempnam(sys_get_temp_dir(), 'opi').'.xlsx';
    (new XlsxWriter($book))->save($path);

    return new UploadedFile($path, 'opening.xlsx', null, null, true);
}

function opiPreview(User $by, string $kind, UploadedFile $file): array
{
    $response = test()->actingAs($by)->post("/payroll/opening/import/{$kind}/preview", ['file' => $file]);
    $response->assertSessionHasNoErrors()->assertRedirect();
    parse_str((string) parse_url((string) $response->headers->get('Location'), PHP_URL_QUERY), $query);

    return test()->actingAs($by)->get("/payroll/opening/import/{$kind}?token=".$query['token'])->assertOk()->inertiaProps('preview');
}

function opiRun(User $by, string $kind, string $token): TestResponse
{
    return test()->actingAs($by)->post("/payroll/opening/import/{$kind}/run", ['token' => $token]);
}

// --- access ----------------------------------------------------------------------------------------------------

test('only HR and Admin may use the imports', function (string $role, int $status) {
    $user = opiUser($role);

    foreach (['tax', 'loans'] as $kind) {
        $this->actingAs($user)->get("/payroll/opening/import/{$kind}")->assertStatus($status);
        $this->actingAs($user)->get("/payroll/opening/import/{$kind}/template")->assertStatus($status);
        $this->actingAs($user)->post("/payroll/opening/import/{$kind}/preview", [])->assertStatus($status === 200 ? 302 : 403);
        $this->actingAs($user)->post("/payroll/opening/import/{$kind}/run", ['token' => 'x'])->assertStatus($status === 200 ? 302 : 403);
    }
})->with([['admin', 200], ['hr', 200], ['manager', 403], ['employee', 403]]);

it('404s an unknown import kind', function () {
    $this->actingAs(opiUser())->get('/payroll/opening/import/salary')->assertNotFound();
});

it('renders the upload step with the limits', function () {
    $this->actingAs(opiUser())->get('/payroll/opening/import/loans')->assertInertia(fn (Assert $page) => $page
        ->component('payroll/opening/import')
        ->where('kind', 'loans')
        ->where('preview', null)
        ->where('expired', false)
        ->where('limits', ['rows' => 2000, 'megabytes' => 5]));
});

it('downloads both templates with their headers, a help sheet, text and number columns', function () {
    $hr = opiUser();

    foreach (['tax' => OPI_TAX, 'loans' => OPI_LOANS] as $kind => $headers) {
        $base = $this->actingAs($hr)->get("/payroll/opening/import/{$kind}/template")->assertOk()->baseResponse;
        expect($base)->toBeInstanceOf(BinaryFileResponse::class);
        $book = IOFactory::createReader('Xlsx')->load($base->getFile()->getPathname());
        $last = chr(ord('A') + count($headers) - 1);

        expect($book->getSheet(0)->rangeToArray("A1:{$last}1")[0])->toBe($headers)
            ->and($book->getSheet(1)->getTitle())->toBe('Help')
            ->and($book->getSheet(0)->getStyle('A2')->getNumberFormat()->getFormatCode())->toBe('@')
            ->and($book->getSheet(0)->getStyle('C2')->getNumberFormat()->getFormatCode())->toBe('0');
    }

    $help = $this->actingAs($hr)->get('/payroll/opening/import/tax/template')->baseResponse->getFile()->getPathname();
    expect(collect(IOFactory::createReader('Xlsx')->load($help)->getSheet(1)->toArray())->flatten()->filter()->implode(' '))
        ->toContain('2026-27 or 2025-26');
});

// --- tax --------------------------------------------------------------------------------------------------------

it('previews tax rows with per-row errors and saves nothing while any row has one', function () {
    $hr = opiUser();
    opiPerson('LD-0101');
    opiPerson('LD-0102');
    $admin = opiUser('admin');
    $admin->employee->update(['employee_code' => 'LD-0900']);

    $preview = opiPreview($hr, 'tax', opiXlsx(OPI_TAX, [
        ['LD-0101', '2026-27', 420000, 21000],
        ['LD-9999', '2026-27', 1, 1],
        ['LD-0900', '2026-27', 1, 1],
        ['LD-0102', '2024-25', 1, 1],
        ['LD-0102', '2025-26', -5, 10.5],
        ['LD-0101', '2026-27', 1, 1],
        ['=HYPERLINK("x")', '2026-27', '1,000', '50'],
    ]));

    expect($preview['totals'])->toBe(['rows' => 7, 'ready' => 0, 'errors' => 7, 'unchanged' => 0])
        ->and($preview['rows'][0]['errors'])->toBe(['This person and tax year appear more than once in the file (rows 2, 7).'])
        ->and($preview['rows'][0]['taxable_income'])->toBe('Rs 420,000')
        ->and($preview['rows'][1]['errors'])->toBe(['No employee has the code "LD-9999".'])
        ->and($preview['rows'][2]['errors'])->toBe(["You cannot change this person's payroll."])
        ->and($preview['rows'][2]['name'])->toBe('')
        ->and($preview['rows'][3]['errors'])->toBe(['Tax Year must be 2026-27 or 2025-26.'])
        ->and($preview['rows'][4]['errors'])->toBe([
            'Taxable Income To Date must be whole rupees, 0 or more.',
            'Tax Deducted To Date must be whole rupees, 0 or more.',
        ])
        ->and($preview['rows'][6]['errors'])->toBe(['No employee has the code "=HYPERLINK("x")".']);

    opiRun($hr, 'tax', $preview['token'])->assertRedirect()->assertSessionHas('error', EmployeeImportController::CHANGED);
    expect(OpeningBalance::query()->count())->toBe(0);
});

it('imports tax rows, sets rather than adds, and audits once per run', function () {
    $hr = opiUser();
    $a = opiPerson('LD-0101');
    $b = opiPerson('LD-0102');
    OpeningBalance::query()->create(['employee_id' => $b->id, 'tax_year' => 2025, 'tax_deducted' => 100, 'taxable_income' => 100, 'note' => 'Kept']);

    $file = fn () => opiXlsx(OPI_TAX, [
        ['ld-0101', '2026-27', 420000, 21000],
        ['LD-0102', '2025-26', '1,200,000', '60000'],
    ]);

    $preview = opiPreview($hr, 'tax', $file());
    expect($preview['totals'])->toBe(['rows' => 2, 'ready' => 2, 'errors' => 0, 'unchanged' => 0]);

    opiRun($hr, 'tax', $preview['token'])->assertRedirect('/payroll/opening')
        ->assertSessionHas('success', 'Imported 2 rows: 2 opening tax figures saved, 0 already up to date.');

    $rowA = OpeningBalance::query()->where('employee_id', $a->id)->sole();
    $rowB = OpeningBalance::query()->where('employee_id', $b->id)->sole();
    expect($rowA->tax_year)->toBe(2026)
        ->and($rowA->taxable_income)->toBe(42000000)
        ->and($rowA->tax_deducted)->toBe(2100000)
        ->and($rowB->taxable_income)->toBe(120000000)
        ->and($rowB->tax_deducted)->toBe(6000000)
        ->and($rowB->note)->toBe('Kept');

    expect(Activity::query()->where('description', 'Opening tax saved')->count())->toBe(2);
    $run = Activity::query()->where('description', 'Opening tax imported')->sole();
    expect($run->properties['changed'])->toBe(2)->and($run->properties['tax_years'])->toBe([2025, 2026]);

    // The same file again: every row unchanged, nothing written.
    $again = opiPreview($hr, 'tax', $file());
    expect($again['totals']['unchanged'])->toBe(2);
    opiRun($hr, 'tax', $again['token'])->assertSessionHas('success', 'Imported 2 rows: 0 opening tax figures saved, 2 already up to date.');
    expect(OpeningBalance::query()->count())->toBe(2)
        ->and(Activity::query()->where('description', 'Opening tax saved')->count())->toBe(2);
});

it('keeps a tax preview in its own slot and refuses a used or foreign token', function () {
    $hr = opiUser();
    $other = opiUser();
    opiPerson('LD-0101');

    $tax = opiPreview($hr, 'tax', opiXlsx(OPI_TAX, [['LD-0101', '2026-27', 1, 1]]));
    $loans = opiPreview($hr, 'loans', opiXlsx(OPI_LOANS, [['LD-0101', 'Loan', 1000, 0, 100, '2026-12', null]]));

    // A loans upload keeps the tax preview; a tax token is no good on the loans route or for someone else.
    expect(EmployeeImportStore::path($tax['token'], $hr, OpeningImportController::KIND_TAX))->not->toBeNull();
    opiRun($hr, 'loans', $tax['token'])->assertSessionHas('error', EmployeeImportController::REFUSED);
    opiRun($other, 'tax', $tax['token'])->assertSessionHas('error', EmployeeImportController::REFUSED);

    opiRun($hr, 'tax', $tax['token'])->assertSessionHas('success');
    opiRun($hr, 'tax', $tax['token'])->assertSessionHas('error', EmployeeImportController::REFUSED);
    expect(EmployeeImportStore::path($loans['token'], $hr, OpeningImportController::KIND_LOANS))->not->toBeNull();
});

it('refuses a file that is not an .xlsx or has missing columns', function () {
    $hr = opiUser();

    $this->actingAs($hr)->post('/payroll/opening/import/tax/preview', ['file' => UploadedFile::fake()->create('a.csv', 1)])
        ->assertSessionHasErrors('file');
    $this->actingAs($hr)->post('/payroll/opening/import/tax/preview', ['file' => opiXlsx(['Employee Code', 'Tax Year'], [['LD-1', '2026-27']])])
        ->assertSessionHasErrors(['file' => 'The file is missing required columns: Taxable Income To Date, Tax Deducted To Date. Use the header row from the template.']);
});

// --- loans ------------------------------------------------------------------------------------------------------

it('imports opening loans with repaid before go-live, and a re-import changes nothing', function () {
    $hr = opiUser();
    $a = opiPerson('LD-0101');
    $b = opiPerson('LD-0102');

    $file = fn (int $installment = 5000) => opiXlsx(OPI_LOANS, [
        ['LD-0101', 'Loan', 50000, 38000, $installment, '2026-12', 'Car repair'],
        ['LD-0102', 'advance', 20000, null, null, '2027-01', null],
    ]);

    $preview = opiPreview($hr, 'loans', $file());
    expect($preview['totals'])->toBe(['rows' => 2, 'ready' => 2, 'errors' => 0, 'unchanged' => 0])
        ->and($preview['rows'][0]['remaining'])->toBe('Rs 12,000')
        ->and($preview['rows'][0]['action'])->toBe('New')
        ->and($preview['rows'][1]['installment'])->toBe('Rs 20,000');

    opiRun($hr, 'loans', $preview['token'])->assertRedirect('/payroll/loans')
        ->assertSessionHas('success', 'Imported 2 rows: 2 loans saved, 0 already up to date.');

    $loan = Loan::query()->where('employee_id', $a->id)->sole();
    expect($loan->type)->toBe('loan')
        ->and($loan->amount)->toBe(5000000)
        ->and($loan->opening_repaid)->toBe(3800000)
        ->and($loan->installment)->toBe(500000)
        ->and($loan->start_month)->toBe('2026-12')
        ->and($loan->note)->toBe('Car repair')
        ->and($loan->remaining())->toBe(1200000);

    $advance = Loan::query()->where('employee_id', $b->id)->sole();
    expect($advance->type)->toBe('advance')->and($advance->installment)->toBe(2000000)->and($advance->opening_repaid)->toBe(0);

    expect(Activity::query()->where('description', 'Loan created')->count())->toBe(2)
        ->and(Activity::query()->where('description', 'Opening loans imported')->sole()->properties['remaining_created'])->toBe(3200000);

    $again = opiPreview($hr, 'loans', $file());
    expect($again['totals']['unchanged'])->toBe(2);
    opiRun($hr, 'loans', $again['token'])->assertSessionHas('success', 'Imported 2 rows: 0 loans saved, 2 already up to date.');
    expect(Loan::query()->count())->toBe(2);

    // A changed installment updates the stored loan instead of adding another, even after a repayment.
    LoanRepayment::query()->create(['loan_id' => $loan->id, 'amount' => 100000, 'month' => '2026-12', 'note' => 'Cash']);
    $changed = opiPreview($hr, 'loans', $file(4000));
    expect($changed['rows'][0]['action'])->toBe('Updates the stored loan');
    opiRun($hr, 'loans', $changed['token'])->assertSessionHas('success', 'Imported 2 rows: 1 loans saved, 1 already up to date.');
    expect(Loan::query()->count())->toBe(2)->and($loan->fresh()->installment)->toBe(400000);
});

it('previews loan rows with per-row errors, including a locked month', function () {
    $hr = opiUser();
    $a = opiPerson('LD-0101');
    opiPerson('LD-0102');
    payrollRun('2026-11', 'approved');
    $hr->employee->update(['employee_code' => 'LD-0500']);
    $stored = loanFor($a, 30000, 3000, '2026-12');
    LoanRepayment::query()->create(['loan_id' => $stored->id, 'amount' => 100000, 'month' => '2026-12']);

    $preview = opiPreview($hr, 'loans', opiXlsx(OPI_LOANS, [
        ['LD-0101', 'Loan', 50000, 38000, 5000, '2026-11', null],
        ['LD-0102', 'Mortgage', 0, 0, 0, '2026-13', null],
        ['LD-0102', 'Loan', 10000, 10000, 20000, '2027-03', null],
        ['LD-0102', 'Loan', 10000, null, null, '2027-01', null],
        ['LD-0500', 'Loan', 1000, 0, 100, '2026-12', null],
        ['LD-0101', 'Loan', 30000, 5000, 3000, '2026-12', null],
        ['LD-0102', 'Loan', 9000, 0, 1000, '2027-02', null],
        ['LD-0102', 'Loan', 9000, 0, 1000, '2027-02', null],
    ]));

    $errors = array_column($preview['rows'], 'errors');
    expect($preview['totals']['errors'])->toBe(8)
        ->and($errors[0])->toBe(['Payroll for November 2026 is approved; start deductions after it.'])
        ->and($errors[1])->toBe([
            'Type must be Loan or Advance.',
            'Original Amount must be whole rupees above 0.',
            'Monthly Installment must be whole rupees above 0.',
            'First Deduction Month must be a month written as YYYY-MM, for example 2026-12.',
        ])
        ->and($errors[2])->toBe([
            'Repaid Before Go-Live must be less than the Original Amount (nothing would be left to recover).',
            'Monthly Installment cannot be more than the Original Amount.',
        ])
        ->and($errors[3])->toBe(['Monthly Installment is required.'])
        ->and($errors[4])->toBe(["You cannot change this person's payroll."])
        ->and($errors[5])->toBe(['This loan already has repayments; only the installment and note can change.'])
        ->and($errors[6][0])->toContain('appears more than once in the file (rows 8, 9)');

    opiRun($hr, 'loans', $preview['token'])->assertSessionHas('error', EmployeeImportController::CHANGED);
    expect(Loan::query()->count())->toBe(1);
});

it('reads a first deduction month that Excel turned into a date', function () {
    $hr = opiUser();
    opiPerson('LD-0101');
    // 46357 = 1 December 2026 as an Excel serial date.
    $preview = opiPreview($hr, 'loans', opiXlsx(OPI_LOANS, [['LD-0101', 'Loan', 1000, 0, 100, 46357, null]]));

    expect($preview['rows'][0]['errors'])->toBe([])->and($preview['rows'][0]['month'])->toBe('2026-12');
});

// --- review fixes ------------------------------------------------------------------------------------------------

it('leaves one loan when the same row is saved twice, however the two runs interleave', function () {
    $hr = opiUser();
    $person = opiPerson('ZZ-0101');
    $data = ['type' => 'loan', 'amount' => 5000000, 'installment' => 500000, 'start_month' => '2026-12', 'opening_repaid' => 3800000];

    // Both runs decided "new" before either saved: the second must find the first one's loan and update it.
    (new SaveLoan)->import($hr, $person, $data);
    $second = new SaveLoan;
    $second->import($hr, $person, [...$data, 'installment' => 400000]);

    expect(Loan::query()->count())->toBe(1)
        ->and(Loan::query()->sole()->installment)->toBe(400000)
        ->and($second->changed())->toBeTrue();
});

it('refuses to save a row whose figures match a cancelled loan', function () {
    $hr = opiUser();
    $person = opiPerson('ZZ-0101');
    loanFor($person, 50000, 5000, '2026-12')->forceFill(['status' => 'cancelled'])->save();

    expect(fn () => (new SaveLoan)->import($hr, $person, ['type' => 'loan', 'amount' => 5000000, 'installment' => 500000, 'start_month' => '2026-12']))
        ->toThrow(ValidationException::class);
    expect(Loan::query()->count())->toBe(1);
});

it('runs one loans import at a time, whatever the upload token', function () {
    $hr = opiUser();
    opiPerson('ZZ-0101');
    $file = fn () => opiXlsx(OPI_LOANS, [['ZZ-0101', 'Loan', 1000, 0, 100, '2026-12', null]]);
    $first = opiPreview($hr, 'loans', $file());

    $lock = Cache::lock('payroll-opening-import:loans', 60);
    expect($lock->get())->toBeTrue();
    opiRun($hr, 'loans', $first['token'])->assertSessionHas('error', EmployeeImportController::BUSY);
    expect(Loan::query()->count())->toBe(0);
    $lock->release();

    // With the lock free the same file runs; uploaded again (a second token) it leaves the one loan.
    opiRun($hr, 'loans', $first['token'])->assertSessionHas('success');
    $second = opiPreview($hr, 'loans', $file());
    opiRun($hr, 'loans', $second['token'])->assertSessionHas('success');
    expect(Loan::query()->count())->toBe(1);
});

it('warns when a row looks like a correction of the same person\'s active loan, and still adds it', function () {
    $hr = opiUser();
    $person = opiPerson('ZZ-0101');
    loanFor($person, 50000, 5000, '2026-12');

    $preview = opiPreview($hr, 'loans', opiXlsx(OPI_LOANS, [
        ['ZZ-0101', 'Loan', 60000, 0, 5000, '2026-12', null],
        ['ZZ-0101', 'Advance', 9000, 0, null, '2026-12', null],
    ]));

    expect($preview['rows'][0]['errors'])->toBe([])
        ->and($preview['rows'][0]['warnings'])->toBe(['This person already has an active loan of Rs 50,000 from December 2026. Change it on the loan page if this row corrects it.'])
        ->and($preview['rows'][1]['warnings'])->toBe([])
        ->and($preview['totals']['errors'])->toBe(0);

    opiRun($hr, 'loans', $preview['token'])->assertSessionHas('success');
    expect(Loan::query()->count())->toBe(3);
});

it('refuses a row that matches a cancelled loan', function () {
    $hr = opiUser();
    $person = opiPerson('ZZ-0101');
    loanFor($person, 50000, 5000, '2026-12')->forceFill(['status' => 'cancelled'])->save();

    $preview = opiPreview($hr, 'loans', opiXlsx(OPI_LOANS, [['ZZ-0101', 'Loan', 50000, 0, 5000, '2026-12', null]]));

    expect($preview['rows'][0]['errors'])->toBe(['A cancelled loan with these figures exists.'])
        ->and($preview['rows'][0]['warnings'])->toBe([]);
    opiRun($hr, 'loans', $preview['token'])->assertSessionHas('error', EmployeeImportController::CHANGED);
    expect(Loan::query()->count())->toBe(1);
});

it('keeps the stored loan note when the cell is blank or the column is missing', function () {
    $hr = opiUser();
    $person = opiPerson('ZZ-0101');
    $loan = loanFor($person, 50000, 5000, '2026-12');
    $loan->forceFill(['note' => 'Car repair'])->save();

    $blank = opiPreview($hr, 'loans', opiXlsx(OPI_LOANS, [['ZZ-0101', 'Loan', 50000, 0, 4000, '2026-12', null]]));
    opiRun($hr, 'loans', $blank['token'])->assertSessionHas('success');
    expect($loan->fresh()->installment)->toBe(400000)->and($loan->fresh()->note)->toBe('Car repair');

    $without = opiPreview($hr, 'loans', opiXlsx(array_slice(OPI_LOANS, 0, 6), [['ZZ-0101', 'Loan', 50000, 0, 3000, '2026-12']]));
    expect($without['rows'][0]['action'])->toBe('Updates the stored loan');
    opiRun($hr, 'loans', $without['token'])->assertSessionHas('success');
    expect($loan->fresh()->installment)->toBe(300000)->and($loan->fresh()->note)->toBe('Car repair');

    // A blank note and nothing else different is "no change".
    $same = opiPreview($hr, 'loans', opiXlsx(OPI_LOANS, [['ZZ-0101', 'Loan', 50000, 0, 3000, '2026-12', null]]));
    expect($same['totals']['unchanged'])->toBe(1);
});

it('refuses a first deduction month that is in the past or that Excel mangled', function () {
    $hr = opiUser();
    opiPerson('ZZ-0101');

    $preview = opiPreview($hr, 'loans', opiXlsx(OPI_LOANS, [
        ['ZZ-0101', 'Loan', 1000, 0, 100, 7, null],            // "7" is the 7th of January 1900 to Excel
        ['ZZ-0101', 'Loan', 1000, 0, 100, 202611, null],       // someone typed 202611
        ['ZZ-0101', 'Loan', 1000, 0, 100, '2026-10', null],    // last month
        ['ZZ-0101', 'Loan', 1000, 0, 100, '2026-11', null],    // this month: fine
        ['ZZ-0101', 'Loan', 1000, 0, 100, '2090-01', null],    // far ahead
    ]));

    $errors = array_column($preview['rows'], 'errors');
    $written = 'First Deduction Month must be a month written as YYYY-MM, for example 2026-12.';
    expect($errors[0])->toBe([$written])
        ->and($errors[1])->toBe([$written])
        ->and($errors[2])->toBe(['First Deduction Month must be this month (November 2026) or later.'])
        ->and($errors[3])->toBe([])
        ->and($errors[4])->toBe(['First Deduction Month is too far ahead.']);
});

it('saves the rows in employee order', function () {
    $hr = opiUser();
    $first = opiPerson('ZZ-0101');
    $second = opiPerson('ZZ-0102');

    $preview = opiPreview($hr, 'loans', opiXlsx(OPI_LOANS, [
        ['ZZ-0102', 'Loan', 1000, 0, 100, '2026-12', null],
        ['ZZ-0101', 'Loan', 1000, 0, 100, '2026-12', null],
    ]));
    opiRun($hr, 'loans', $preview['token'])->assertSessionHas('success');

    expect(Loan::query()->where('employee_id', $first->id)->value('id'))
        ->toBeLessThan(Loan::query()->where('employee_id', $second->id)->value('id'));
});

it('lets an import change the installment of a loan with repayments but never its repaid figure', function () {
    $hr = opiUser();
    $loan = loanFor(opiPerson('ZZ-0101'), 50000, 5000, '2026-12', 38000);
    LoanRepayment::query()->create(['loan_id' => $loan->id, 'amount' => 100000, 'month' => '2026-12', 'note' => 'Cash']);

    $ok = opiPreview($hr, 'loans', opiXlsx(OPI_LOANS, [['ZZ-0101', 'Loan', 50000, 38000, 4000, '2026-12', 'Lower']]));
    expect($ok['rows'][0]['errors'])->toBe([]);
    opiRun($hr, 'loans', $ok['token'])->assertSessionHas('success');
    expect($loan->fresh()->installment)->toBe(400000)->and($loan->fresh()->note)->toBe('Lower');

    $refused = opiPreview($hr, 'loans', opiXlsx(OPI_LOANS, [['ZZ-0101', 'Loan', 50000, 30000, 4000, '2026-12', null]]));
    expect($refused['rows'][0]['errors'])->toBe(['This loan already has repayments; only the installment and note can change.']);
    expect($loan->fresh()->opening_repaid)->toBe(3800000);
});

it('imports opening tax for the previous tax year', function () {
    $hr = opiUser();
    $person = opiPerson('ZZ-0101');

    $preview = opiPreview($hr, 'tax', opiXlsx(OPI_TAX, [['ZZ-0101', '2025-26', 900000, 45000]]));
    expect($preview['rows'][0]['errors'])->toBe([])->and($preview['rows'][0]['tax_year'])->toBe('2025-26');
    opiRun($hr, 'tax', $preview['token'])->assertSessionHas('success');

    $row = OpeningBalance::query()->where('employee_id', $person->id)->sole();
    expect($row->tax_year)->toBe(2025)->and($row->taxable_income)->toBe(90000000)->and($row->tax_deducted)->toBe(4500000);
});
