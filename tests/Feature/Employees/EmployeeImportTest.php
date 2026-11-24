<?php

use App\Actions\Employees\CreateEmployee;
use App\Actions\Employees\EmployeeWriter;
use App\Models\Department;
use App\Models\Designation;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use Spatie\Activitylog\Models\Activity;

const IMP_HEADERS = [
    'First Name*', 'Last Name*', 'Work Email*', 'Phone', 'Date of Birth', 'Gender', 'CNIC', 'Department*', 'Designation*',
    'Manager Email', 'Joining Date*', 'Employment Type*', 'Status*', 'Machine User ID', 'Employee Code', 'Address',
    'Emergency Contact Name', 'Emergency Contact Phone', 'Bank Name', 'Account Title', 'Account Number',
];

const IMP_SUCCESS = "Login links were not sent — select employees on the list and choose 'Send set-password links'.";

beforeEach(function () {
    Storage::fake('local');
    // The Excel package's temporary copies go to a folder of this test's own, removed afterwards.
    $this->excelTemp = sys_get_temp_dir().'/lumino-excel-'.bin2hex(random_bytes(6));
    config(['excel.temporary_files.local_path' => $this->excelTemp]);
});

afterEach(function () {
    File::deleteDirectory($this->excelTemp);
});

/** Engineering (Engineer, Lead) and Sales (Sales Rep). */
function impOrg(): array
{
    $eng = Department::where('name', 'Engineering')->first() ?? Department::factory()->create(['name' => 'Engineering']);
    $sales = Department::where('name', 'Sales')->first() ?? Department::factory()->create(['name' => 'Sales']);
    $engineer = Designation::firstWhere(['department_id' => $eng->id, 'name' => 'Engineer']) ?? Designation::factory()->create(['department_id' => $eng->id, 'name' => 'Engineer']);
    $lead = Designation::firstWhere(['department_id' => $eng->id, 'name' => 'Lead']) ?? Designation::factory()->create(['department_id' => $eng->id, 'name' => 'Lead']);
    $rep = Designation::firstWhere(['department_id' => $sales->id, 'name' => 'Sales Rep']) ?? Designation::factory()->create(['department_id' => $sales->id, 'name' => 'Sales Rep']);

    return compact('eng', 'sales', 'engineer', 'lead', 'rep');
}

/** One valid row (keys are the header labels without the "*"), person number $n, merged with $over. */
function impRow(int $n = 1, array $over = []): array
{
    impOrg();

    return array_merge([
        'First Name' => 'Person',
        'Last Name' => 'Number'.$n,
        'Work Email' => "person{$n}@corp.test",
        'Phone' => '0300-'.str_pad((string) $n, 7, '0', STR_PAD_LEFT),
        'Date of Birth' => '1990-05-17',
        'Gender' => 'Female',
        'CNIC' => '42201-'.str_pad((string) $n, 7, '0', STR_PAD_LEFT).'-1',
        'Department' => 'Engineering',
        'Designation' => 'Engineer',
        'Manager Email' => '',
        'Joining Date' => '2024-03-01',
        'Employment Type' => 'Full Time',
        'Status' => 'Active',
        'Machine User ID' => (string) (100 + $n),
        'Employee Code' => '',
        'Address' => '12 Garden Road',
        'Emergency Contact Name' => 'Sara',
        'Emergency Contact Phone' => '0300-7654321',
        'Bank Name' => 'Meezan Bank',
        'Account Title' => 'Person '.$n,
        'Account Number' => 'PK36MEZN'.str_pad((string) $n, 8, '0', STR_PAD_LEFT),
    ], $over);
}

/**
 * Builds an .xlsx in memory. Cell values: strings are written as text cells, ints/floats as numbers,
 * ['formula' => '=1+1'] as a formula, null leaves the cell empty.
 *
 * @param  list<array<string, mixed>>  $rows
 * @param  list<string>|null  $headers
 */
function impXlsx(array $rows, ?array $headers = null, ?Closure $tweak = null, string $name = 'staff.xlsx'): UploadedFile
{
    $headers ??= IMP_HEADERS;
    $book = new Spreadsheet;
    $sheet = $book->getActiveSheet();
    $sheet->setTitle('Employees');

    foreach ($headers as $i => $header) {
        $sheet->setCellValueExplicit([$i + 1, 1], $header, DataType::TYPE_STRING);
    }

    foreach ($rows as $r => $row) {
        foreach ($headers as $i => $header) {
            $key = trim(str_replace('*', '', $header));

            if (! array_key_exists($key, $row) || $row[$key] === null) {
                continue;
            }

            $value = $row[$key];
            $coordinate = [$i + 1, $r + 2];

            if (is_array($value)) {
                $sheet->setCellValue($coordinate, $value['formula']);
            } elseif (is_int($value) || is_float($value)) {
                $sheet->setCellValueExplicit($coordinate, $value, DataType::TYPE_NUMERIC);
            } else {
                $sheet->setCellValueExplicit($coordinate, (string) $value, DataType::TYPE_STRING);
            }
        }
    }

    if ($tweak !== null) {
        $tweak($book);
    }

    $path = impTemp();
    $writer = new XlsxWriter($book);
    $writer->setPreCalculateFormulas(false);
    $writer->save($path);

    return new UploadedFile($path, $name, null, null, true);
}

/** A path for a temporary .xlsx inside this test's own folder (deleted after the test). */
function impTemp(): string
{
    $directory = (string) config('excel.temporary_files.local_path');
    File::ensureDirectoryExists($directory);

    return $directory.'/t'.bin2hex(random_bytes(6)).'.xlsx';
}

/** A local copy of a downloaded file. */
function impDownload(TestResponse $response): string
{
    $path = impTemp();
    copy($response->baseResponse->getFile()->getPathname(), $path);

    return $path;
}

function impPost(User $user, UploadedFile $file)
{
    return test()->actingAs($user)->post('/employees/import/preview', ['file' => $file]);
}

/** Uploads $rows and returns [token, preview props]. */
function impPreview(User $user, array $rows, ?array $headers = null): array
{
    $response = impPost($user, impXlsx($rows, $headers));
    $response->assertSessionHasNoErrors();
    $location = $response->headers->get('Location');
    expect($location)->toContain('/employees/import?token=');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    return [$query['token'], impPage($user, $query['token'])];
}

/** The preview prop of the import page for $token. */
function impPage(User $user, string $token): ?array
{
    return test()->actingAs($user)->get('/employees/import?token='.$token)->assertOk()->viewData('page')['props']['preview'];
}

/** The errors shown for spreadsheet row $line. */
function impErrors(array $preview, int $line): array
{
    foreach ($preview['rows'] as $row) {
        if ($row['line'] === $line) {
            return $row['errors'];
        }
    }

    throw new RuntimeException("No row {$line} in the preview.");
}

function impErrorText(array $preview, int $line): string
{
    return implode(' | ', impErrors($preview, $line));
}

function impRun(User $user, string $token)
{
    return test()->actingAs($user)->post('/employees/import/run', ['token' => $token]);
}

// ---------------------------------------------------------------- access

it('lets only Admin and HR reach every import route', function (string $role, int $status) {
    $user = userWithRole($role);
    impOrg();

    expect($this->actingAs($user)->get('/employees/import')->status())->toBe($status);
    expect($this->actingAs($user)->get('/employees/import/template')->getStatusCode())->toBe($status);
    expect(impPost($user, impXlsx([impRow()]))->status())->toBe($status === 200 ? 302 : 403);
    expect($this->actingAs($user)->post('/employees/import/run', ['token' => str_repeat('a', 48)])->status())->toBe($status === 200 ? 302 : 403);
})->with([
    'admin' => ['admin', 200],
    'hr' => ['hr', 200],
    'manager' => ['manager', 403],
    'employee' => ['employee', 403],
]);

it('sends guests to the sign-in page from every import route', function () {
    $this->get('/employees/import')->assertRedirect('/login');
    $this->get('/employees/import/template')->assertRedirect('/login');
    $this->post('/employees/import/preview')->assertRedirect('/login');
    $this->post('/employees/import/run')->assertRedirect('/login');
});

it('registers the import routes ahead of the numeric profile route', function () {
    $admin = userWithRole('admin');

    $this->actingAs($admin)->get('/employees/import')->assertOk()->assertInertia(fn ($page) => $page->component('employees/import')->where('preview', null));
});

// ---------------------------------------------------------------- template

it('serves a template with the exact header row, an example row and a help sheet', function () {
    $admin = userWithRole('admin');
    impOrg();

    $response = $this->actingAs($admin)->get('/employees/import/template')->assertOk();
    expect($response->headers->get('content-disposition'))->toContain('employee-import-template.xlsx');

    $book = IOFactory::createReader('Xlsx')->load(impDownload($response));

    $sheet = $book->getSheetByName('Employees');
    expect($sheet)->not->toBeNull();
    expect($sheet->rangeToArray('A1:U1')[0])->toBe(IMP_HEADERS);
    expect($sheet->getCell('V1')->getValue())->toBeNull();
    expect($sheet->getStyle('A1')->getFont()->getBold())->toBeTrue();
    expect($sheet->getFreezePane())->toBe('A2');
    expect((string) $sheet->getCell('A2')->getValue())->toStartWith('EXAMPLE');
    // Date columns are text so Excel does not reformat them.
    expect($sheet->getStyle('E2')->getNumberFormat()->getFormatCode())->toBe('@');
    expect($sheet->getStyle('K2')->getNumberFormat()->getFormatCode())->toBe('@');

    $help = $book->getSheetByName('Help');
    expect($help)->not->toBeNull();
    $text = implode("\n", array_map(fn ($row) => implode("\t", array_map('strval', $row)), $help->toArray()));
    foreach (['First Name', 'Required', 'Male', 'Female', 'Other', 'Full Time', 'Part Time', 'Contract', 'Intern', 'Active', 'Probation', 'Inactive', 'Resigned', 'Terminated', 'Engineering', 'Sales', 'Engineer', 'Lead', 'Sales Rep', 'YYYY-MM-DD'] as $needle) {
        expect($text)->toContain($needle);
    }
});

it('reads the downloaded template with only its example row as an empty import', function () {
    $admin = userWithRole('admin');
    impOrg();

    $path = impDownload($this->actingAs($admin)->get('/employees/import/template'));

    impPost($admin, new UploadedFile($path, 'template.xlsx', null, null, true))
        ->assertSessionHasErrors(['file' => 'The file has no employee rows below the header.']);
});

// ---------------------------------------------------------------- file-level refusals

it('refuses files that are not real .xlsx workbooks', function (Closure $make) {
    $admin = userWithRole('admin');
    impOrg();

    impPost($admin, $make())->assertSessionHasErrors('file');
    expect(Storage::disk('local')->allFiles())->toBe([]);
    expect(Employee::count())->toBe(0);
})->with([
    'csv' => [fn () => UploadedFile::fake()->createWithContent('staff.csv', "First Name*,Last Name*\nA,B\n")],
    'csv renamed to xlsx' => [fn () => UploadedFile::fake()->createWithContent('staff.xlsx', "First Name*,Last Name*\nA,B\n")],
    'xls name' => [fn () => impXlsx([impRow()], name: 'staff.xls')],
    'xlsm name' => [fn () => impXlsx([impRow()], name: 'staff.xlsm')],
    'zip that is not a workbook' => [function () {
        $path = impTemp();
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE);
        $zip->addFromString('hello.txt', 'hello');
        $zip->close();

        return new UploadedFile($path, 'staff.xlsx', null, null, true);
    }],
    'macro-enabled content renamed to xlsx' => [function () {
        $file = impXlsx([impRow()]);
        $zip = new ZipArchive;
        $zip->open($file->getPathname());
        $types = (string) $zip->getFromName('[Content_Types].xml');
        $zip->addFromString('[Content_Types].xml', str_replace(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml',
            'application/vnd.ms-excel.sheet.macroEnabled.main+xml',
            $types,
        ));
        $zip->addFromString('xl/vbaProject.bin', 'not really a macro');
        $zip->close();

        return $file;
    }],
    'no file' => [fn () => UploadedFile::fake()->create('staff.xlsx', 0)],
]);

it('refuses a file larger than 5 MB', function () {
    $admin = userWithRole('admin');

    impPost($admin, UploadedFile::fake()->create('staff.xlsx', 5121))
        ->assertSessionHasErrors(['file' => 'The file is larger than 5 MB.']);
});

it('refuses more than 2,000 data rows before reading them', function () {
    $admin = userWithRole('admin');
    $rows = [];
    for ($i = 1; $i <= 2001; $i++) {
        $rows[] = ['First Name' => 'P', 'Last Name' => (string) $i];
    }

    impPost($admin, impXlsx($rows))->assertSessionHasErrors(['file' => 'The file has more than 2,000 rows. Split it into smaller files.']);
    expect(Storage::disk('local')->allFiles())->toBe([]);
});

it('refuses a worksheet whose unpacked size is excessive (zip bomb guard)', function () {
    $admin = userWithRole('admin');
    $file = impXlsx([impRow()]);
    $zip = new ZipArchive;
    $zip->open($file->getPathname());
    $xml = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
    // 25 MB of whitespace compresses to almost nothing.
    $zip->addFromString('xl/worksheets/sheet1.xml', str_replace('<sheetData>', '<sheetData>'.str_repeat(' ', 25 * 1024 * 1024), $xml));
    $zip->close();
    expect(filesize($file->getPathname()))->toBeLessThan(5 * 1024 * 1024);

    impPost($admin, $file)->assertSessionHasErrors(['file' => 'The file is too large to read once unpacked. Remove unused sheets, rows or formatting and try again.']);
});

it('refuses a file missing a required column and names it', function () {
    $admin = userWithRole('admin');
    $headers = array_values(array_diff(IMP_HEADERS, ['Work Email*', 'Department*']));

    impPost($admin, impXlsx([impRow()], $headers))
        ->assertSessionHasErrors(['file' => 'The file is missing required columns: Work Email, Department. Use the header row from the template.']);
});

it('refuses a file whose header is not in the first row', function () {
    $admin = userWithRole('admin');

    $file = impXlsx([impRow()], tweak: fn (Spreadsheet $book) => $book->getActiveSheet()->insertNewRowBefore(1)->setCellValue('A1', 'Staff list'));

    impPost($admin, $file)->assertSessionHasErrors('file');
});

it('refuses a column that appears twice', function () {
    $admin = userWithRole('admin');

    impPost($admin, impXlsx([impRow()], [...IMP_HEADERS, 'work email']))
        ->assertSessionHasErrors(['file' => 'The column "Work Email" appears more than once.']);
});

it('refuses a file with no data rows', function () {
    $admin = userWithRole('admin');

    impPost($admin, impXlsx([]))->assertSessionHasErrors(['file' => 'The file has no employee rows below the header.']);
});

it('matches headers ignoring case, spaces and the star, and ignores unknown columns with a notice', function () {
    $admin = userWithRole('admin');
    $headers = array_map(fn ($h) => '  '.strtoupper(str_replace('*', '', $h)).' ', IMP_HEADERS);
    $headers[] = 'Notes';
    $row = impRow();
    $row['NOTES'] = 'ignore me';

    $file = impXlsx([], $headers, function (Spreadsheet $book) use ($row) {
        $sheet = $book->getActiveSheet();
        foreach (array_values($row) as $i => $value) {
            $sheet->setCellValueExplicit([$i + 1, 2], (string) $value, DataType::TYPE_STRING);
        }
        $sheet->setCellValueExplicit([22, 2], 'ignore me', DataType::TYPE_STRING);
    });

    $response = impPost($admin, $file)->assertSessionHasNoErrors();
    parse_str((string) parse_url((string) $response->headers->get('Location'), PHP_URL_QUERY), $query);
    $preview = impPage($admin, $query['token']);

    expect($preview['totals'])->toBe(['rows' => 1, 'ready' => 1, 'errors' => 0]);
    expect(implode(' ', $preview['notices']))->toContain('Notes');
});

// ---------------------------------------------------------------- storage and token

it('stores the upload under a random token with a sidecar and never the client name', function () {
    $admin = userWithRole('admin');

    [$token] = impPreview($admin, [impRow()]);

    expect($token)->toMatch('/^[A-Za-z0-9]{40,}$/');
    $files = Storage::disk('local')->allFiles();
    sort($files);
    expect($files)->toBe(["imports/{$token}.json", "imports/{$token}.xlsx"]);

    $meta = json_decode(Storage::disk('local')->get("imports/{$token}.json"), true);
    expect($meta['user_id'])->toBe($admin->id);
    expect($meta['sha256'])->toBe(hash_file('sha256', Storage::disk('local')->path("imports/{$token}.xlsx")));
    expect($meta)->toHaveKey('created_at');
    expect(json_encode($meta))->not->toContain('staff.xlsx');
});

it('deletes the previous pending import of the same user on a new upload', function () {
    $admin = userWithRole('admin');
    $hr = userWithRole('hr');

    [$first] = impPreview($admin, [impRow(1)]);
    [$other] = impPreview($hr, [impRow(2)]);
    [$second] = impPreview($admin, [impRow(3)]);

    expect(Storage::disk('local')->exists("imports/{$first}.xlsx"))->toBeFalse();
    expect(Storage::disk('local')->exists("imports/{$first}.json"))->toBeFalse();
    expect(Storage::disk('local')->exists("imports/{$second}.xlsx"))->toBeTrue();
    expect(Storage::disk('local')->exists("imports/{$other}.xlsx"))->toBeTrue();
});

it('shows no preview for a token of another user, an unknown token or a malformed one', function () {
    $admin = userWithRole('admin');
    $hr = userWithRole('hr');
    [$token] = impPreview($admin, [impRow()]);

    expect(impPage($hr, $token))->toBeNull();
    expect(impPage($admin, str_repeat('b', 48)))->toBeNull();
    expect(impPage($admin, '../../.env'))->toBeNull();
});

// ---------------------------------------------------------------- preview rows

it('lists every row with its key fields and Ready status', function () {
    $admin = userWithRole('admin');

    [, $preview] = impPreview($admin, [impRow(1), impRow(2, ['Manager Email' => 'person1@corp.test', 'Status' => 'Probation'])]);

    expect($preview['totals'])->toBe(['rows' => 2, 'ready' => 2, 'errors' => 0]);
    expect($preview['rows'][1])->toMatchArray([
        'line' => 3,
        'name' => 'Person Number2',
        'email' => 'person2@corp.test',
        'department' => 'Engineering',
        'designation' => 'Engineer',
        'manager' => 'person1@corp.test',
        'status' => 'Probation',
        'errors' => [],
    ]);
    // Never more than the parsed key fields.
    expect(array_keys($preview['rows'][0]))->toEqualCanonicalizing(['line', 'name', 'email', 'department', 'designation', 'manager', 'status', 'errors']);
    expect(json_encode($preview))->not->toContain('42201')->not->toContain('PK36MEZN');
});

it('puts each row error on the right row with a readable message', function (array $over, string $expected) {
    $admin = userWithRole('admin');

    [, $preview] = impPreview($admin, [impRow(1), impRow(2, $over), impRow(3)]);

    expect(impErrors($preview, 2))->toBe([]);
    expect(impErrors($preview, 4))->toBe([]);
    expect(impErrorText($preview, 3))->toContain($expected);
    expect($preview['totals'])->toBe(['rows' => 3, 'ready' => 2, 'errors' => 1]);
})->with([
    'missing first name' => [['First Name' => ''], 'The First Name field is required.'],
    'bad email' => [['Work Email' => 'not-an-email'], 'The Work Email field must be a valid email address.'],
    'bad CNIC' => [['CNIC' => '12345'], 'The CNIC must have exactly 13 digits (dashes are fine).'],
    'unknown department' => [['Department' => 'Marketing'], 'Department "Marketing" does not exist. Add it under Organization first.'],
    'designation of another department' => [['Designation' => 'Sales Rep'], 'Designation "Sales Rep" does not exist in the Engineering department.'],
    'missing department' => [['Department' => ''], 'The Department field is required.'],
    'unknown gender' => [['Gender' => 'Robot'], 'Gender "Robot" is not allowed. Use one of: Male, Female, Other.'],
    'unknown employment type' => [['Employment Type' => 'Freelance'], 'Employment Type "Freelance" is not allowed. Use one of: Full Time, Part Time, Contract, Intern.'],
    'unknown status' => [['Status' => 'Retired'], 'Status "Retired" is not allowed. Use one of: Active, Probation, Inactive, Resigned, Terminated.'],
    'bad joining date' => [['Joining Date' => '2024-13-45'], 'Joining Date "2024-13-45" is not a valid date. Use YYYY-MM-DD (for example 2024-03-01), DD/MM/YYYY or an Excel date.'],
    'missing joining date' => [['Joining Date' => ''], 'The Joining Date field is required.'],
    'too young' => [['Date of Birth' => '2015-01-01'], 'The date of birth must be at least 16 years before the joining date.'],
    'machine id too long' => [['Machine User ID' => str_repeat('9', 21)], 'The Machine User ID field must not be greater than 20 characters.'],
    'unknown manager' => [['Manager Email' => 'nobody@corp.test'], 'Manager Email "nobody@corp.test" does not match an existing employee or another row in this file.'],
    'self manager' => [['Manager Email' => 'person2@corp.test'], 'A person cannot be their own manager.'],
]);

it('truncates long cell values in error messages to 60 characters', function () {
    $admin = userWithRole('admin');
    $long = str_repeat('X', 300);

    [, $preview] = impPreview($admin, [impRow(1, ['Department' => $long, 'First Name' => $long])]);

    foreach (impErrors($preview, 2) as $error) {
        expect($error)->not->toContain(str_repeat('X', 61));
    }
    expect(mb_strlen($preview['rows'][0]['name']))->toBeLessThanOrEqual(121);
    expect($preview['rows'][0]['department'])->not->toContain(str_repeat('X', 61));
});

it('reports duplicates inside the file on both rows', function (string $column, array $first, array $second) {
    $admin = userWithRole('admin');

    [, $preview] = impPreview($admin, [impRow(1, $first), impRow(2), impRow(3, $second)]);

    expect(impErrorText($preview, 2))->toContain("{$column} is also used in row 4.");
    expect(impErrorText($preview, 4))->toContain("{$column} is also used in row 2.");
    expect(impErrors($preview, 3))->toBe([]);
})->with([
    'email (case-insensitive)' => ['Work Email', ['Work Email' => 'Same@Corp.test'], ['Work Email' => 'same@corp.test']],
    'CNIC (dashes or not)' => ['CNIC', ['CNIC' => '42201-9999999-1'], ['CNIC' => '4220199999991']],
    'employee code' => ['Employee Code', ['Employee Code' => 'LD-0500'], ['Employee Code' => 'LD-0500']],
    'machine user ID' => ['Machine User ID', ['Machine User ID' => '555'], ['Machine User ID' => '555']],
]);

it('reports duplicates against the database', function (string $column, array $existing, array $over, string $expected) {
    $admin = userWithRole('admin');
    $org = impOrg();
    Employee::factory()->create(array_merge(['department_id' => $org['eng']->id, 'designation_id' => $org['engineer']->id], $existing));

    [, $preview] = impPreview($admin, [impRow(1, $over)]);

    expect(impErrorText($preview, 2))->toContain($expected);
})->with([
    'email' => ['email', ['email' => 'taken@corp.test'], ['Work Email' => 'TAKEN@corp.test'], 'An employee with this email already exists.'],
    'CNIC' => ['cnic', ['cnic' => '4220188888881'], ['CNIC' => '42201-8888888-1'], 'An employee with this CNIC already exists.'],
    'machine user ID' => ['device', ['device_user_id' => '777'], ['Machine User ID' => '777'], 'This machine user ID is already used by another employee.'],
    'employee code' => ['code', ['employee_code' => 'LD-0900'], ['Employee Code' => 'LD-0900'], 'This employee code is already taken.'],
]);

it('reports an email that belongs to the login of another employee', function () {
    $admin = userWithRole('admin');
    $user = userWithRole('employee');
    employeeFor($user, ['email' => 'someone.else@corp.test']);
    $user->forceFill(['email' => 'login@corp.test'])->save();

    [, $preview] = impPreview($admin, [impRow(1, ['Work Email' => 'login@corp.test'])]);

    expect(impErrorText($preview, 2))->toContain('This email already belongs to the login of another employee.');
});

// ---------------------------------------------------------------- managers

it('accepts a manager in the same file above or below, and an existing employee', function () {
    $admin = userWithRole('admin');
    $org = impOrg();
    Employee::factory()->create(['email' => 'boss@corp.test', 'status' => 'active', 'department_id' => $org['eng']->id, 'designation_id' => $org['engineer']->id]);

    [$token, $preview] = impPreview($admin, [
        impRow(1, ['Manager Email' => 'person3@corp.test']), // forward reference
        impRow(2, ['Manager Email' => 'person1@corp.test']), // backward reference
        impRow(3, ['Manager Email' => 'BOSS@corp.test']),    // existing employee
    ]);

    expect($preview['totals']['errors'])->toBe(0);

    impRun($admin, $token)->assertRedirect('/employees');

    $boss = Employee::firstWhere('email', 'boss@corp.test');
    $p1 = Employee::firstWhere('email', 'person1@corp.test');
    $p2 = Employee::firstWhere('email', 'person2@corp.test');
    $p3 = Employee::firstWhere('email', 'person3@corp.test');
    expect($p3->manager_id)->toBe($boss->id);
    expect($p1->manager_id)->toBe($p3->id);
    expect($p2->manager_id)->toBe($p1->id);
});

it('refuses a reporting loop among rows on every row in the loop', function () {
    $admin = userWithRole('admin');

    [, $preview] = impPreview($admin, [
        impRow(1, ['Manager Email' => 'person2@corp.test']),
        impRow(2, ['Manager Email' => 'person3@corp.test']),
        impRow(3, ['Manager Email' => 'person1@corp.test']),
        impRow(4, ['Manager Email' => 'person1@corp.test']), // reports into the loop but is not part of it
    ]);

    foreach ([2, 3, 4] as $line) {
        expect(impErrorText($preview, $line))->toContain('The reporting lines of rows 2, 3, 4 loop back on themselves.');
    }
    expect(impErrorText($preview, 5))->not->toContain('loop back');
});

it('refuses an existing manager who is not active or on probation', function (string $status) {
    $admin = userWithRole('admin');
    $org = impOrg();
    Employee::factory()->create(['email' => 'gone@corp.test', 'status' => $status, 'department_id' => $org['eng']->id, 'designation_id' => $org['engineer']->id]);

    [, $preview] = impPreview($admin, [impRow(1, ['Manager Email' => 'gone@corp.test'])]);

    expect(impErrorText($preview, 2))->toContain('The manager gone@corp.test is not Active or on Probation.');
})->with(['inactive', 'resigned', 'terminated']);

it('refuses a manager row in the file who is not active or on probation', function () {
    $admin = userWithRole('admin');

    [, $preview] = impPreview($admin, [impRow(1, ['Status' => 'Resigned']), impRow(2, ['Manager Email' => 'person1@corp.test'])]);

    expect(impErrors($preview, 2))->toBe([]);
    expect(impErrorText($preview, 3))->toContain('The manager in row 2 is not Active or on Probation.');
});

// ---------------------------------------------------------------- cell formats

it('accepts dates as YYYY-MM-DD text, Excel date cells and DD/MM/YYYY', function () {
    $admin = userWithRole('admin');

    [$token, $preview] = impPreview($admin, [
        impRow(1, ['Joining Date' => '2024-03-01', 'Date of Birth' => '1990-05-17']),
        impRow(2, ['Joining Date' => 45352, 'Date of Birth' => 33010.0]), // 2024-03-01 and 1990-05-17 as serials
        impRow(3, ['Joining Date' => '01/03/2024', 'Date of Birth' => '17/05/1990']),
    ]);

    expect($preview['totals']['errors'])->toBe(0);
    impRun($admin, $token);

    foreach ([1, 2, 3] as $n) {
        $employee = Employee::firstWhere('email', "person{$n}@corp.test");
        expect($employee->joining_date->format('Y-m-d'))->toBe('2024-03-01');
        expect($employee->dob->format('Y-m-d'))->toBe('1990-05-17');
    }
});

it('reads enums whatever their case and spacing', function () {
    $admin = userWithRole('admin');

    [$token, $preview] = impPreview($admin, [
        impRow(1, ['Gender' => '  MALE ', 'Employment Type' => 'part-time', 'Status' => ' probation']),
        impRow(2, ['Gender' => 'female', 'Employment Type' => 'FULL_TIME', 'Status' => 'ACTIVE ']),
        impRow(3, ['Gender' => '', 'Employment Type' => 'Contract', 'Status' => 'Active']),
    ]);

    expect($preview['totals']['errors'])->toBe(0);
    impRun($admin, $token);

    $one = Employee::firstWhere('email', 'person1@corp.test');
    expect([$one->gender, $one->employment_type, $one->status])->toBe(['male', 'part_time', 'probation']);
    expect(Employee::firstWhere('email', 'person3@corp.test')->gender)->toBeNull();
});

it('matches department and designation names whatever their case and spacing', function () {
    $admin = userWithRole('admin');

    [, $preview] = impPreview($admin, [impRow(1, ['Department' => ' engineering ', 'Designation' => "LEAD\u{00A0}"])]);

    expect($preview['totals']['errors'])->toBe(0);
});

it('strips leading apostrophes, non-breaking spaces and zero-width characters, and reads formulas as text', function () {
    $admin = userWithRole('admin');

    [$token, $preview] = impPreview($admin, [impRow(1, [
        'First Name' => "'Zara\u{200B}",
        'Last Name' => "\u{00A0}Ali\u{FEFF}",
        'CNIC' => "'4220112345671",
        'Machine User ID' => '\'0042',
        'Address' => ['formula' => '=1+1'],
        'Phone' => 3001234567,
    ])]);

    expect($preview['totals']['errors'])->toBe(0);
    impRun($admin, $token);

    $employee = Employee::firstWhere('email', 'person1@corp.test');
    expect($employee->first_name)->toBe('Zara');
    expect($employee->last_name)->toBe('Ali');
    expect($employee->cnic)->toBe('4220112345671');
    expect($employee->device_user_id)->toBe('0042');
    expect($employee->phone)->toBe('3001234567');
    expect($employee->contact->address)->toBe('=1+1');
});

it('accepts a CNIC typed as a number', function () {
    $admin = userWithRole('admin');

    [$token, $preview] = impPreview($admin, [impRow(1, ['CNIC' => 4220112345671])]);

    expect($preview['totals']['errors'])->toBe(0);
    impRun($admin, $token);
    expect(Employee::firstWhere('email', 'person1@corp.test')->cnic)->toBe('4220112345671');
});

it('skips the template example row and blank rows', function () {
    $admin = userWithRole('admin');

    [$token, $preview] = impPreview($admin, [
        impRow(9, ['First Name' => 'EXAMPLE - replace or delete this row', 'Department' => 'Nowhere']),
        impRow(1),
        ['First Name' => null],
        impRow(2),
    ]);

    expect($preview['totals'])->toBe(['rows' => 2, 'ready' => 2, 'errors' => 0]);
    expect(array_column($preview['rows'], 'line'))->toBe([3, 5]);
    expect(implode(' ', $preview['notices']))->toContain('Row 2 is the template');

    impRun($admin, $token);
    expect(Employee::count())->toBe(2);
});

// ---------------------------------------------------------------- employee code: Admin vs HR

it('honours explicit codes for an Admin and assigns blank ones in file order', function () {
    $admin = userWithRole('admin');
    $org = impOrg();
    Employee::factory()->create(['employee_code' => 'LD-0007', 'department_id' => $org['eng']->id, 'designation_id' => $org['engineer']->id]);

    [$token, $preview] = impPreview($admin, [
        impRow(1),
        impRow(2, ['Employee Code' => 'LD-0009']),
        impRow(3, ['Manager Email' => 'person4@corp.test']),
        impRow(4),
        impRow(5),
    ]);
    expect($preview['totals']['errors'])->toBe(0);

    impRun($admin, $token)->assertRedirect('/employees');

    $codes = collect([1, 2, 3, 4, 5])->map(fn ($n) => Employee::firstWhere('email', "person{$n}@corp.test")->employee_code)->all();
    expect($codes)->toBe(['LD-0008', 'LD-0009', 'LD-0010', 'LD-0011', 'LD-0012']);
});

it('refuses a filled Employee Code column for HR', function () {
    $hr = userWithRole('hr');

    [, $preview] = impPreview($hr, [impRow(1), impRow(2, ['Employee Code' => 'LD-0500'])]);

    expect(impErrors($preview, 2))->toBe([]);
    expect(impErrorText($preview, 3))->toContain('Only an Admin can set the Employee Code. Leave it blank and a code is assigned.');
});

it('assigns codes for HR imports', function () {
    $hr = userWithRole('hr');

    [$token] = impPreview($hr, [impRow(1), impRow(2)]);
    impRun($hr, $token)->assertRedirect('/employees');

    expect(Employee::orderBy('id')->pluck('employee_code')->all())->toBe(['LD-0001', 'LD-0002']);
});

// ---------------------------------------------------------------- privileged accounts

it('refuses for HR, and links for an Admin, a row whose email is an existing Admin account', function () {
    $hr = userWithRole('hr');
    $admin = userWithRole('admin');
    $other = userWithRole('admin');
    $other->forceFill(['email' => 'chief@corp.test'])->save();

    [, $preview] = impPreview($hr, [impRow(1, ['Work Email' => 'chief@corp.test'])]);
    expect(impErrorText($preview, 2))->toContain('This login belongs to an Admin or HR account. Ask an Admin to make this change.');

    [$token, $preview] = impPreview($admin, [impRow(1, ['Work Email' => 'chief@corp.test'])]);
    expect($preview['totals']['errors'])->toBe(0);
    impRun($admin, $token)->assertRedirect('/employees');

    $employee = Employee::firstWhere('email', 'chief@corp.test');
    expect($other->fresh()->employee_id)->toBe($employee->id);
    expect($other->fresh()->hasRole('admin'))->toBeTrue();
    expect(User::where('email', 'chief@corp.test')->count())->toBe(1);
});

it('links an existing plain account with no employee for HR', function () {
    $hr = userWithRole('hr');
    $plain = userWithRole('employee');
    $plain->forceFill(['email' => 'plain@corp.test'])->save();

    [$token, $preview] = impPreview($hr, [impRow(1, ['Work Email' => 'plain@corp.test'])]);
    expect($preview['totals']['errors'])->toBe(0);
    impRun($hr, $token);

    expect($plain->fresh()->employee_id)->toBe(Employee::firstWhere('email', 'plain@corp.test')->id);
});

// ---------------------------------------------------------------- run

it('imports a clean 300-row file in one transaction with sequential codes and correct manager links', function () {
    Notification::fake();
    Mail::fake();
    $hr = userWithRole('hr');

    $rows = [];
    for ($n = 1; $n <= 300; $n++) {
        // Person 1 manages 2..10; every later person reports to the person ten rows above.
        $manager = $n === 1 ? '' : ($n <= 10 ? 'person1@corp.test' : 'person'.($n - 10).'@corp.test');
        $rows[] = impRow($n, ['Manager Email' => $manager]);
    }

    [$token, $preview] = impPreview($hr, $rows);
    expect($preview['totals'])->toBe(['rows' => 300, 'ready' => 300, 'errors' => 0]);

    impRun($hr, $token)
        ->assertRedirect('/employees')
        ->assertSessionHas('success', 'Imported 300 employees. '.IMP_SUCCESS);

    expect(Employee::count())->toBe(300);
    expect(User::whereNotNull('employee_id')->count())->toBe(300);
    for ($n = 1; $n <= 300; $n++) {
        $employee = Employee::firstWhere('email', "person{$n}@corp.test");
        expect($employee->employee_code)->toBe(sprintf('LD-%04d', $n));
        $expected = $n === 1 ? null : ($n <= 10 ? 'person1@corp.test' : 'person'.($n - 10).'@corp.test');
        expect($employee->manager?->email)->toBe($expected);
    }
    Notification::assertNothingSent();
    Mail::assertNothingQueued();
    Mail::assertNothingSent();

    // One entry for the run, with counts only.
    $entry = Activity::where('log_name', 'employees')->where('description', 'Employees imported')->sole();
    expect($entry->causer_id)->toBe($hr->id);
    expect($entry->properties->toArray())->toMatchArray(['created' => 300, 'updated' => 0, 'failed' => 0, 'logins_disabled' => 0, 'linked_existing' => 0]);
    expect(json_encode($entry->properties))->not->toContain('person')->not->toContain('42201');

    // The file is gone, and the token cannot be used again.
    expect(Storage::disk('local')->allFiles())->toBe([]);
    impRun($hr, $token)->assertRedirect('/employees/import')->assertSessionHas('error');
    expect(Employee::count())->toBe(300);
})->group('slow');

it('saves nothing when one row is bad', function () {
    $admin = userWithRole('admin');

    [$token, $preview] = impPreview($admin, [impRow(1), impRow(2, ['Department' => 'Nowhere']), impRow(3)]);
    expect($preview['totals']['errors'])->toBe(1);

    impRun($admin, $token)->assertRedirect('/employees/import?token='.$token)->assertSessionHas('error');

    expect(Employee::count())->toBe(0);
    expect(User::count())->toBe(1);
    expect(Storage::disk('local')->exists("imports/{$token}.xlsx"))->toBeTrue();
});

it('re-validates against the current data at run time', function () {
    $admin = userWithRole('admin');
    $org = impOrg();

    [$token, $preview] = impPreview($admin, [impRow(1), impRow(2)]);
    expect($preview['totals']['errors'])->toBe(0);

    // Someone adds person2 through the form between the preview and the run.
    Employee::factory()->create(['email' => 'person2@corp.test', 'department_id' => $org['eng']->id, 'designation_id' => $org['engineer']->id]);

    impRun($admin, $token)
        ->assertRedirect('/employees/import?token='.$token)
        ->assertSessionHas('error', 'Some rows are no longer valid because the data changed since the preview. Nothing was imported. Fix the rows marked below and upload the file again.');

    expect(Employee::count())->toBe(1);
    expect(impErrorText(impPage($admin, $token), 3))->toContain('An employee with this email already exists.');
});

it('refuses a token of another user, an expired one, a reused one and a tampered file', function () {
    $admin = userWithRole('admin');
    $hr = userWithRole('hr');
    $refused = 'This import is no longer available. It may have expired, been used already or belong to someone else. Upload the file again.';

    // Another user.
    [$token] = impPreview($admin, [impRow(1)]);
    impRun($hr, $token)->assertRedirect('/employees/import')->assertSessionHas('error', $refused);
    expect(Employee::count())->toBe(0);
    expect(Storage::disk('local')->exists("imports/{$token}.xlsx"))->toBeTrue();

    // Expired after 60 minutes.
    $this->travel(61)->minutes();
    impRun($admin, $token)->assertRedirect('/employees/import')->assertSessionHas('error', $refused);
    expect(Employee::count())->toBe(0);
    $this->travelBack();

    // Tampered: the stored file no longer matches its hash.
    [$token] = impPreview($admin, [impRow(2)]);
    Storage::disk('local')->put("imports/{$token}.xlsx", impXlsx([impRow(2), impRow(3)])->getContent());
    impRun($admin, $token)->assertRedirect('/employees/import')->assertSessionHas('error', $refused);
    expect(Employee::count())->toBe(0);

    // Used once, then again.
    [$token] = impPreview($admin, [impRow(4)]);
    impRun($admin, $token)->assertRedirect('/employees');
    impRun($admin, $token)->assertRedirect('/employees/import')->assertSessionHas('error', $refused);
    expect(Employee::count())->toBe(1);

    // Malformed or missing token.
    $this->actingAs($admin)->post('/employees/import/run', ['token' => '../x'])->assertRedirect('/employees/import')->assertSessionHas('error', $refused);
    $this->actingAs($admin)->post('/employees/import/run', [])->assertRedirect('/employees/import')->assertSessionHas('error', $refused);
});

it('rolls everything back and keeps the file when a save fails half way', function () {
    $admin = userWithRole('admin');
    [$token] = impPreview($admin, [impRow(1), impRow(2), impRow(3)]);

    $this->app->instance(CreateEmployee::class, new class($this->app->make(EmployeeWriter::class)) extends CreateEmployee
    {
        private int $calls = 0;

        public function handle(array $data, User $by, bool $sendLink = true, ?string $code = null): Employee
        {
            if (++$this->calls === 3) {
                throw new RuntimeException('disk full');
            }

            return parent::handle($data, $by, $sendLink, $code);
        }
    });

    impRun($admin, $token)
        ->assertRedirect('/employees/import?token='.$token)
        ->assertSessionHas('error', 'The import failed and nothing was saved. Please try again.');

    expect(Employee::count())->toBe(0);
    expect(User::count())->toBe(1);
    expect(Activity::where('description', 'Employees imported')->count())->toBe(0);
    expect(Storage::disk('local')->exists("imports/{$token}.xlsx"))->toBeTrue();
});

it('creates resigned, terminated and inactive rows with a disabled login and no last working day', function () {
    Notification::fake();
    $admin = userWithRole('admin');

    [$token, $preview] = impPreview($admin, [
        impRow(1, ['Status' => 'Resigned']),
        impRow(2, ['Status' => 'Terminated']),
        impRow(3, ['Status' => 'Inactive']),
        impRow(4, ['Status' => 'Probation']),
    ]);
    expect($preview['totals']['errors'])->toBe(0);

    impRun($admin, $token)->assertRedirect('/employees');

    foreach ([1 => 'resigned', 2 => 'terminated', 3 => 'inactive'] as $n => $status) {
        $employee = Employee::firstWhere('email', "person{$n}@corp.test");
        expect($employee->status)->toBe($status);
        expect($employee->last_working_day)->toBeNull();
        expect($employee->user->is_active)->toBeFalse();
    }
    expect(Employee::firstWhere('email', 'person4@corp.test')->user->is_active)->toBeTrue();
    expect(Activity::where('description', 'Employees imported')->sole()->properties['logins_disabled'])->toBe(3);
    Notification::assertNothingSent();
});

it('gives every imported login the employee role and an unusable password', function () {
    $admin = userWithRole('admin');
    [$token] = impPreview($admin, [impRow(1)]);
    impRun($admin, $token);

    $user = User::firstWhere('email', 'person1@corp.test');
    expect($user->getRoleNames()->all())->toBe(['employee']);
    expect($user->last_login_at)->toBeNull();
    expect(strlen($user->password))->toBeGreaterThan(20);
});

// ---------------------------------------------------------------- cleanup

it('prunes import files older than 60 minutes', function () {
    $admin = userWithRole('admin');
    [$old] = impPreview($admin, [impRow(1)]);
    $hr = userWithRole('hr');

    $this->travel(61)->minutes();
    [$fresh] = impPreview($hr, [impRow(2)]);
    // An orphan file with no sidecar, last changed two hours ago.
    Storage::disk('local')->put('imports/orphan.xlsx', 'x');
    touch(Storage::disk('local')->path('imports/orphan.xlsx'), now()->subHours(2)->getTimestamp());
    // The Excel package's temporary copies: an old one (an aborted download) and a fresh one.
    File::ensureDirectoryExists($this->excelTemp);
    File::put($this->excelTemp.'/laravel-excel-old.xlsx', 'x');
    touch($this->excelTemp.'/laravel-excel-old.xlsx', now()->subHours(2)->getTimestamp());
    File::put($this->excelTemp.'/laravel-excel-new.xlsx', 'x');
    touch($this->excelTemp.'/laravel-excel-new.xlsx', now()->getTimestamp());

    $this->artisan('hrm:prune-imports')->assertSuccessful();

    expect(File::exists($this->excelTemp.'/laravel-excel-old.xlsx'))->toBeFalse();
    expect(File::exists($this->excelTemp.'/laravel-excel-new.xlsx'))->toBeTrue();

    expect(Storage::disk('local')->exists("imports/{$old}.xlsx"))->toBeFalse();
    expect(Storage::disk('local')->exists("imports/{$old}.json"))->toBeFalse();
    expect(Storage::disk('local')->exists('imports/orphan.xlsx'))->toBeFalse();
    expect(Storage::disk('local')->exists("imports/{$fresh}.xlsx"))->toBeTrue();
    expect(Storage::disk('local')->exists("imports/{$fresh}.json"))->toBeTrue();
});

it('schedules the import cleanup hourly', function () {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($event) => str_contains((string) $event->command, 'hrm:prune-imports'));

    expect($events)->toHaveCount(1);
    expect($events->first()->expression)->toBe('0 * * * *');
});
