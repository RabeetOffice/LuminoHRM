<?php

use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\User;
use App\Support\EmployeeImportStore;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/*
 * The go-live opening-balance import under /leave/balances/import: template, upload + preview with per-row errors and
 * warnings, run (sets opening_used through AdjustBalance, audited), idempotent re-import. Today is 2026-11-25.
 */

const LBI_HEADERS = ['Employee Code', 'Leave Type', 'Year', 'Days Used'];

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

function lbiPerson(array $attrs = []): Employee
{
    return Employee::factory()->create(['joining_date' => '2024-03-01', ...$attrs]);
}

function lbiHr(string $role = 'hr'): User
{
    $user = userWithRole($role);
    employeeFor($user, ['joining_date' => '2024-03-01']);

    return $user->fresh();
}

/**
 * An .xlsx with the template header and these rows (lists of 4 cells; ints / floats as numbers, strings as text).
 *
 * @param  list<list<mixed>>  $rows
 */
function lbiXlsx(array $rows, ?array $headers = null): UploadedFile
{
    $book = new Spreadsheet;
    $sheet = $book->getActiveSheet();

    foreach ($headers ?? LBI_HEADERS as $i => $header) {
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

    $path = tempnam(sys_get_temp_dir(), 'lbi').'.xlsx';
    (new XlsxWriter($book))->save($path);

    return new UploadedFile($path, 'opening.xlsx', null, null, true);
}

/** Uploads the file and returns the preview props (fails the test when the upload is refused). */
function lbiPreview(User $by, UploadedFile $file): array
{
    $response = test()->actingAs($by)->post('/leave/balances/import/preview', ['file' => $file]);
    $response->assertSessionHasNoErrors()->assertRedirect();
    parse_str((string) parse_url((string) $response->headers->get('Location'), PHP_URL_QUERY), $query);

    return test()->actingAs($by)->get('/leave/balances/import?token='.$query['token'])->assertOk()->inertiaProps('preview');
}

function lbiRun(User $by, string $token): TestResponse
{
    return test()->actingAs($by)->post('/leave/balances/import/run', ['token' => $token]);
}

function lbiOpening(Employee $e, string $type, int $year): ?float
{
    $value = LeaveBalance::query()->where('employee_id', $e->id)->where('leave_type_id', leaveType($type)->id)->where('year', $year)->value('opening_used');

    return $value === null ? null : (float) $value;
}

// --- access ----------------------------------------------------------------------------------------------------

test('only HR and Admin may use the import', function (string $role, int $status) {
    $user = lbiHr($role);

    $this->actingAs($user)->get('/leave/balances/import')->assertStatus($status);
    $this->actingAs($user)->get('/leave/balances/import/template')->assertStatus($status);
    $this->actingAs($user)->post('/leave/balances/import/run', ['token' => 'x'])->assertStatus($status === 200 ? 302 : 403);
})->with([['admin', 200], ['hr', 200], ['manager', 403], ['employee', 403]]);

it('renders the upload step with the limits', function () {
    $this->actingAs(lbiHr())->get('/leave/balances/import')
        ->assertInertia(fn (Assert $page) => $page
            ->component('leave/balances-import')
            ->where('preview', null)
            ->where('expired', false)
            ->where('limits', ['rows' => 2000, 'megabytes' => 5]));
});

it('downloads a template with the four columns and a help sheet', function () {
    $response = $this->actingAs(lbiHr())->get('/leave/balances/import/template')->assertOk();
    $base = $response->baseResponse;
    expect($base)->toBeInstanceOf(BinaryFileResponse::class);

    $book = IOFactory::createReader('Xlsx')->load($base->getFile()->getPathname());

    expect($book->getSheet(0)->rangeToArray('A1:D1')[0])->toBe(LBI_HEADERS)
        ->and($book->getSheet(1)->getTitle())->toBe('Help')
        ->and(collect($book->getSheet(1)->toArray())->flatten()->filter()->implode(' '))->toContain('Annual, Sick, Casual');
});

// --- preview --------------------------------------------------------------------------------------------------

it('lists an error for each bad row', function () {
    $hr = lbiHr();
    $e = lbiPerson();
    $hrPerson = lbiHr();
    $ok = lbiPerson();

    $preview = lbiPreview($hr, lbiXlsx([
        ['NOPE-1', 'Annual', 2026, 1],                       // 2 unknown code
        [$e->employee_code, 'Unpaid', 2026, 1],              // 3 unpaid type
        [$e->employee_code, 'Annual', 2026, 1.25],           // 4 bad days
        [$e->employee_code, 'Sick', 2026, 400],              // 5 too many days
        [$hrPerson->employee->employee_code, 'Annual', 2026, 2], // 6 out of scope (an HR person, for HR)
        [$ok->employee_code, 'Casual', 2026, 1],             // 7 duplicate
        [$ok->employee_code, 'casual', 2026, 2],             // 8 duplicate (type letter case ignored)
        [$ok->employee_code, 'Annual', 2024, 1],             // 9 year not accepted
        [$ok->employee_code, 'Holiday', 2026, 1],            // 10 unknown type
        [$ok->employee_code, 'Annual', 2025, 3.5],           // 11 ready
    ]));

    $errors = collect($preview['rows'])->mapWithKeys(fn ($r) => [$r['line'] => $r['errors']]);

    expect($errors[2])->toBe(['No employee has the code "NOPE-1".'])
        ->and($errors[3])->toBe(['Unpaid is unpaid leave: it has no balance.'])
        ->and($errors[4])->toBe(['Days Used must be a number from 0 to 365, in whole or half days.'])
        ->and($errors[5])->toBe(['Days Used must be a number from 0 to 365, in whole or half days.'])
        ->and($errors[6])->toBe(["You cannot change this person's balance."])
        ->and($errors[7][0])->toContain('more than once')
        ->and($errors[8][0])->toContain('rows 7, 8')
        ->and($errors[9])->toBe(['Year must be 2026 or 2025.'])
        ->and($errors[10])->toBe(['No leave type is named "Holiday".'])
        ->and($errors[11])->toBe([])
        ->and($preview['totals'])->toMatchArray(['rows' => 10, 'ready' => 1, 'errors' => 9]);

    // The out-of-scope person's name is not shown.
    expect(collect($preview['rows'])->firstWhere('line', 6)['name'])->toBe('');

    lbiRun($hr, $preview['token'])->assertRedirect()->assertSessionHas('error');
    expect(LeaveBalance::query()->count())->toBe(0);
});

it('refuses a file without the columns, a non-xlsx file and too many rows', function () {
    $hr = lbiHr();

    $this->actingAs($hr)->post('/leave/balances/import/preview', ['file' => lbiXlsx([['A', 'B', 2026, 1]], ['Code', 'Type'])])
        ->assertSessionHasErrors('file');
    $this->actingAs($hr)->post('/leave/balances/import/preview', ['file' => UploadedFile::fake()->create('x.csv', 1)])
        ->assertSessionHasErrors('file');

    $rows = array_fill(0, 2001, ['LD-0001', 'Annual', 2026, 1]);
    $this->actingAs($hr)->post('/leave/balances/import/preview', ['file' => lbiXlsx($rows)])
        ->assertSessionHasErrors(['file' => 'The file has more than 2,000 rows. Split it into smaller files.']);
});

it('flags a row that leaves someone overdrawn as a warning, not an error', function () {
    $hr = lbiHr();
    $e = lbiPerson(['first_name' => 'Sara']);

    $preview = lbiPreview($hr, lbiXlsx([[$e->employee_code, 'Sick', 2026, 9]])); // Sick 8

    expect($preview['rows'][0]['errors'])->toBe([])
        ->and($preview['rows'][0]['warnings'])->toBe(['This leaves Sara overdrawn by 1 day.'])
        ->and($preview['totals']['warnings'])->toBe(1);

    lbiRun($hr, $preview['token'])->assertSessionHas('success');
    expect(lbiOpening($e, 'Sick', 2026))->toBe(9.0);
});

// --- run ------------------------------------------------------------------------------------------------------

it('sets opening used for every row, audits each change and the run, and is idempotent', function () {
    $hr = lbiHr();
    $a = lbiPerson();
    $b = lbiPerson();
    LeaveBalance::query()->create(['employee_id' => $a->id, 'leave_type_id' => leaveType('Annual')->id, 'year' => 2026, 'allocated' => 20, 'adjustment' => 1, 'opening_used' => 5]);

    $rows = [
        [$a->employee_code, 'Annual', 2026, 3.5],
        [$a->employee_code, 'Sick', '2026', '2'],
        [$b->employee_code, 'Casual', 2025, 4],
    ];

    $preview = lbiPreview($hr, lbiXlsx($rows));
    expect($preview['totals'])->toMatchArray(['rows' => 3, 'ready' => 3, 'errors' => 0]);

    lbiRun($hr, $preview['token'])->assertRedirect('/leave/balances')
        ->assertSessionHas('success', 'Imported 3 rows: 3 balances updated, 0 already up to date.');

    $annual = LeaveBalance::query()->where('employee_id', $a->id)->where('leave_type_id', leaveType('Annual')->id)->where('year', 2026)->first();
    expect((float) $annual->opening_used)->toBe(3.5)
        ->and((float) $annual->allocated)->toBe(20.0) // kept
        ->and((float) $annual->adjustment)->toBe(1.0)  // kept
        ->and(lbiOpening($a, 'Sick', 2026))->toBe(2.0)
        ->and(lbiOpening($b, 'Casual', 2025))->toBe(4.0);

    $entries = Activity::query()->where('description', 'Leave balance adjusted')->orderBy('id')->get();
    expect($entries)->toHaveCount(3)
        ->and($entries[0]->properties['reason'])->toBe('Opening balance import')
        ->and($entries[0]->properties['old']['opening_used'])->toEqual(5.0)
        ->and($entries[0]->properties['attributes']['opening_used'])->toBe(3.5);

    $run = Activity::query()->where('description', 'Leave opening balances imported')->sole();
    expect($run->subject_id)->toBeNull()
        ->and($run->causer_id)->toBe($hr->id)
        ->and($run->properties->all())->toMatchArray(['rows' => 3, 'changed' => 3, 'unchanged' => 0, 'years' => [2025, 2026]]);

    // The token is used up.
    lbiRun($hr, $preview['token'])->assertSessionHas('error');

    // The same file again: every row unchanged, nothing written.
    $again = lbiPreview($hr, lbiXlsx($rows));
    expect($again['totals']['unchanged'])->toBe(3);

    lbiRun($hr, $again['token'])->assertSessionHas('success', 'Imported 3 rows: 0 balances updated, 3 already up to date.');

    expect(lbiOpening($a, 'Annual', 2026))->toBe(3.5)
        ->and(Activity::query()->where('description', 'Leave balance adjusted')->count())->toBe(3);
});

it('refuses another user\'s token', function () {
    $hr = lbiHr();
    $preview = lbiPreview($hr, lbiXlsx([[lbiPerson()->employee_code, 'Annual', 2026, 1]]));

    lbiRun(lbiHr('admin'), $preview['token'])->assertSessionHas('error');

    expect(LeaveBalance::query()->count())->toBe(0)
        ->and(EmployeeImportStore::path($preview['token'], $hr))->not->toBeNull();
});

it('lets an Admin import an HR person\'s opening balance', function () {
    $admin = lbiHr('admin');
    $hrUser = lbiHr();

    $preview = lbiPreview($admin, lbiXlsx([[$hrUser->employee->employee_code, 'Annual', 2026, 1]]));
    lbiRun($admin, $preview['token'])->assertSessionHas('success');

    expect(lbiOpening($hrUser->employee, 'Annual', 2026))->toBe(1.0);
});
