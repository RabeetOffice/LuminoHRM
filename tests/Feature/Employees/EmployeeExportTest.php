<?php

use App\Models\Department;
use App\Models\Designation;
use App\Models\Employee;
use App\Models\EmployeeBankAccount;
use App\Models\EmployeeContact;
use App\Support\Settings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

const EXP_HEADERS = [
    'First Name*', 'Last Name*', 'Work Email*', 'Phone', 'Date of Birth', 'Gender', 'CNIC', 'Department*', 'Designation*',
    'Manager Email', 'Joining Date*', 'Employment Type*', 'Status*', 'Machine User ID', 'Employee Code', 'Address',
    'Emergency Contact Name', 'Emergency Contact Phone', 'Bank Name', 'Account Title', 'Account Number',
];

beforeEach(function () {
    // The Excel package's temporary files (a download is never "sent" in a test) go to a folder removed afterwards.
    $this->excelTemp = sys_get_temp_dir().'/lumino-excel-'.bin2hex(random_bytes(6));
    config(['excel.temporary_files.local_path' => $this->excelTemp]);
});

afterEach(function () {
    File::deleteDirectory($this->excelTemp);
});

/** A path for a temporary .xlsx inside this test's own folder (deleted after the test). */
function expTemp(): string
{
    $directory = (string) config('excel.temporary_files.local_path');
    File::ensureDirectoryExists($directory);

    return $directory.'/t'.bin2hex(random_bytes(6)).'.xlsx';
}

/** A local copy of the downloaded workbook. */
function expPath(TestResponse $response): string
{
    $base = $response->baseResponse;
    $path = expTemp();

    if ($base instanceof BinaryFileResponse) {
        copy($base->getFile()->getPathname(), $path);
    } else {
        file_put_contents($path, $response->streamedContent());
    }

    return $path;
}

function expSheet(TestResponse $response): Worksheet
{
    $reader = IOFactory::createReader('Xlsx');
    $reader->setReadDataOnly(true);

    return $reader->load(expPath($response))->getSheet(0);
}

/** @return list<list<mixed>> data rows (no header) */
function expRows(TestResponse $response): array
{
    $rows = expSheet($response)->toArray(null, false, false);
    array_shift($rows);

    return $rows;
}

/** Engineering/Engineer, Engineering/Lead, Sales/Sales Rep. */
function expOrg(): array
{
    $eng = Department::factory()->create(['name' => 'Engineering']);
    $sales = Department::factory()->create(['name' => 'Sales']);

    return [
        'eng' => $eng,
        'sales' => $sales,
        'engineer' => Designation::factory()->create(['department_id' => $eng->id, 'name' => 'Engineer']),
        'lead' => Designation::factory()->create(['department_id' => $eng->id, 'name' => 'Lead']),
        'rep' => Designation::factory()->create(['department_id' => $sales->id, 'name' => 'Sales Rep']),
    ];
}

function expPerson(array $org, array $attrs = []): Employee
{
    return Employee::factory()->create(array_merge([
        'department_id' => $org['eng']->id,
        'designation_id' => $org['engineer']->id,
    ], $attrs));
}

// ---------------------------------------------------------------- access

it('lets only Admin and HR export', function (string $role, int $status) {
    $user = userWithRole($role);
    if ($role === 'manager') {
        employeeFor($user);
    }

    expect($this->actingAs($user)->get('/employees/export')->getStatusCode())->toBe($status);
})->with([
    'admin' => ['admin', 200],
    'hr' => ['hr', 200],
    'manager' => ['manager', 403],
    'employee' => ['employee', 403],
]);

it('sends guests to the sign-in page', function () {
    $this->get('/employees/export')->assertRedirect('/login');
});

it('offers the export button only to people who may export', function (string $role, bool $can) {
    $user = userWithRole($role);
    employeeFor($user);

    $this->actingAs($user)->get('/employees')->assertOk()
        ->assertInertia(fn ($page) => $page->where('can.export', $can)->where('can.import', in_array($role, ['admin', 'hr'], true)));
})->with([
    'admin' => ['admin', true],
    'hr' => ['hr', true],
    'manager' => ['manager', false],
]);

// ---------------------------------------------------------------- file

it('names the file after today in the display timezone', function () {
    $admin = userWithRole('admin');
    Settings::set('company', ['timezone' => 'Pacific/Kiritimati']); // UTC+14
    $this->travelTo(Carbon::parse('2026-11-24 12:00:00', 'UTC'));

    $response = $this->actingAs($admin)->get('/employees/export')->assertOk();

    expect($response->headers->get('content-disposition'))->toContain('employees-2026-11-25.xlsx');
});

it('writes the template columns in the template order, and nothing else', function () {
    $admin = userWithRole('admin');
    $org = expOrg();
    $boss = expPerson($org, ['email' => 'boss@corp.test', 'last_name' => 'Aaron']);
    $person = expPerson($org, [
        'first_name' => 'Ayesha', 'last_name' => 'Khan', 'email' => 'ayesha@corp.test', 'phone' => '0300-1234567',
        'dob' => '1990-05-17', 'gender' => 'female', 'cnic' => '4220112345671', 'manager_id' => $boss->id,
        'joining_date' => '2024-03-01', 'employment_type' => 'part_time', 'status' => 'probation', 'device_user_id' => '0042',
        'employee_code' => 'LD-0042', 'department_id' => $org['eng']->id, 'designation_id' => $org['lead']->id,
    ]);
    EmployeeContact::create(['employee_id' => $person->id, 'address' => '12 Garden Road', 'emergency_name' => 'Sara', 'emergency_phone' => '0300-7654321']);
    EmployeeBankAccount::create(['employee_id' => $person->id, 'bank_name' => 'Meezan', 'account_title' => 'Ayesha Khan', 'account_number' => '0012345678']);

    $response = $this->actingAs($admin)->get('/employees/export')->assertOk();
    $sheet = expSheet($response);

    expect($sheet->rangeToArray('A1:V1')[0])->toBe([...EXP_HEADERS, null]);
    expect(expRows($response)[1])->toBe([
        'Ayesha', 'Khan', 'ayesha@corp.test', '0300-1234567', '1990-05-17', 'Female', '42201-1234567-1', 'Engineering', 'Lead',
        'boss@corp.test', '2024-03-01', 'Part Time', 'Probation', '0042', 'LD-0042', '12 Garden Road',
        'Sara', '0300-7654321', 'Meezan', 'Ayesha Khan', '0012345678',
    ]);

    // Numbers that must stay text are text cells.
    foreach (['D3', 'E3', 'G3', 'K3', 'N3', 'U3'] as $cell) {
        expect($sheet->getCell($cell)->getDataType())->toBe(DataType::TYPE_STRING);
    }
});

it('escapes cells that a spreadsheet would read as a formula', function (string $value) {
    $admin = userWithRole('admin');
    $org = expOrg();
    expPerson($org, ['first_name' => $value, 'last_name' => 'Zed']);

    $response = $this->actingAs($admin)->get('/employees/export')->assertOk();
    $sheet = expSheet($response);

    // XML turns a stored carriage return into a line feed when it is read back; the quote is what matters.
    expect($sheet->getCell('A2')->getValue())->toBe("'".str_replace("\r", "\n", $value));
    expect($sheet->getCell('A2')->getDataType())->toBe(DataType::TYPE_STRING);
})->with([
    'equals' => ['=HYPERLINK("http://evil.test","x")'],
    'plus' => ['+92300'],
    'minus' => ['-2+3'],
    'at' => ['@SUM(A1)'],
    'tab' => ["\tTab"],
    'carriage return' => ["\rCR"],
]);

// ---------------------------------------------------------------- scope and filters

it('exports every matching row, not just one page, sorted like the list', function () {
    $admin = userWithRole('admin');
    $org = expOrg();
    for ($i = 1; $i <= 60; $i++) {
        expPerson($org, ['last_name' => sprintf('Person%03d', 61 - $i), 'first_name' => 'P']);
    }

    $rows = expRows($this->actingAs($admin)->get('/employees/export?per_page=10&page=3'));

    expect($rows)->toHaveCount(60);
    expect(array_column($rows, 1))->toBe(array_map(fn ($n) => sprintf('Person%03d', $n), range(1, 60)));
});

it('honours the list filters', function (string $query, array $expected) {
    $admin = userWithRole('admin');
    $org = expOrg();
    expPerson($org, ['first_name' => 'Ada', 'last_name' => 'Lovelace', 'status' => 'active']);
    expPerson($org, ['first_name' => 'Bob', 'last_name' => 'Stone', 'status' => 'probation', 'department_id' => $org['sales']->id, 'designation_id' => $org['rep']->id]);
    expPerson($org, ['first_name' => 'Cy', 'last_name' => 'Young', 'status' => 'resigned', 'designation_id' => $org['lead']->id]);

    $query = strtr($query, [':eng' => $org['eng']->id, ':lead' => $org['lead']->id, ':rep' => $org['rep']->id]);
    $rows = expRows($this->actingAs($admin)->get('/employees/export?'.$query));

    expect(array_column($rows, 0))->toBe($expected);
})->with([
    'none' => ['', ['Ada', 'Bob', 'Cy']],
    'search' => ['search=love', ['Ada']],
    'department' => ['department_id=:eng', ['Ada', 'Cy']],
    'designation' => ['department_id=:eng&designation_id=:lead', ['Cy']],
    'designation of another department is ignored' => ['department_id=:eng&designation_id=:rep', ['Ada', 'Cy']],
    'status' => ['status=probation', ['Bob']],
    'unknown status is ignored' => ['status=nope', ['Ada', 'Bob', 'Cy']],
    'array-valued params are ignored' => ['search[]=x&status[]=active', ['Ada', 'Bob', 'Cy']],
]);

it('writes one audit entry with the count and filters and no row data', function () {
    $admin = userWithRole('admin');
    $org = expOrg();
    expPerson($org, ['first_name' => 'Ada', 'last_name' => 'Lovelace', 'email' => 'ada@corp.test', 'cnic' => '4220100000011']);
    expPerson($org, ['first_name' => 'Bob', 'last_name' => 'Stone']);

    $this->actingAs($admin)->get('/employees/export?search=ada&status=active')->assertOk();

    $entry = Activity::where('log_name', 'employees')->where('description', 'Employees exported')->sole();
    expect($entry->causer_id)->toBe($admin->id);
    expect($entry->properties['rows'])->toBe(1);
    expect($entry->properties['filters'])->toBe(['search' => 'ada', 'department_id' => null, 'designation_id' => null, 'status' => 'active']);
    expect($entry->properties['columns'])->toBe(21);
    $json = json_encode($entry->properties);
    expect($json)->not->toContain('Lovelace')->not->toContain('ada@corp.test')->not->toContain('42201');
});

// ---------------------------------------------------------------- round trip

it('re-imports its own export into an empty database as the same people', function () {
    Storage::fake('local');
    $admin = userWithRole('admin');
    $org = expOrg();
    $boss = expPerson($org, ['first_name' => 'Bea', 'last_name' => 'Boss', 'email' => 'bea@corp.test', 'employee_code' => 'LD-0010', 'cnic' => '4220100000011', 'device_user_id' => '0010', 'dob' => '1980-01-02', 'gender' => 'female', 'phone' => '0300-0000010']);
    $one = expPerson($org, ['first_name' => '=Eve', 'last_name' => 'Ng', 'email' => 'eve@corp.test', 'employee_code' => 'LD-0011', 'manager_id' => $boss->id, 'status' => 'resigned', 'employment_type' => 'contract', 'cnic' => null, 'device_user_id' => null, 'department_id' => $org['sales']->id, 'designation_id' => $org['rep']->id]);
    EmployeeContact::create(['employee_id' => $one->id, 'address' => '-1 Minus Street', 'emergency_name' => 'Kin', 'emergency_phone' => '+92 300 1111111']);
    EmployeeBankAccount::create(['employee_id' => $one->id, 'bank_name' => 'HBL', 'account_title' => 'Eve Ng', 'account_number' => '000123']);

    $before = Employee::with(['contact', 'bankAccount', 'manager', 'department', 'designation'])->orderBy('email')->get()
        ->map(fn (Employee $e) => expSnapshot($e))->all();

    $path = expPath($this->actingAs($admin)->get('/employees/export'));

    EmployeeContact::query()->delete();
    EmployeeBankAccount::query()->delete();
    Employee::query()->update(['manager_id' => null]);
    Employee::query()->delete();

    $response = $this->actingAs($admin)->post('/employees/import/preview', ['file' => new UploadedFile($path, 'export.xlsx', null, null, true)]);
    $response->assertSessionHasNoErrors();
    parse_str((string) parse_url((string) $response->headers->get('Location'), PHP_URL_QUERY), $query);
    $preview = $this->actingAs($admin)->get('/employees/import?token='.$query['token'])->viewData('page')['props']['preview'];
    expect($preview['totals'])->toBe(['rows' => 2, 'ready' => 2, 'errors' => 0]);

    $this->actingAs($admin)->post('/employees/import/run', ['token' => $query['token']])->assertRedirect('/employees');

    $after = Employee::with(['contact', 'bankAccount', 'manager', 'department', 'designation'])->orderBy('email')->get()
        ->map(fn (Employee $e) => expSnapshot($e))->all();
    expect($after)->toBe($before);
});

/** Everything the template carries about a person, by value (ids differ after a re-import). */
function expSnapshot(Employee $e): array
{
    return [
        $e->first_name, $e->last_name, $e->email, $e->phone, $e->dob?->format('Y-m-d'), $e->gender, $e->cnic,
        $e->department->name, $e->designation->name, $e->manager?->email, $e->joining_date->format('Y-m-d'),
        $e->employment_type, $e->status, $e->device_user_id, $e->employee_code,
        $e->contact?->address, $e->contact?->emergency_name, $e->contact?->emergency_phone,
        $e->bankAccount?->bank_name, $e->bankAccount?->account_title, $e->bankAccount?->account_number,
    ];
}
