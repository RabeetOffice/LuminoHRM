<?php

use App\Models\Attendance;
use App\Models\Department;
use App\Models\Designation;
use App\Models\Employee;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/* "Now" is 2026-11-25 12:00 in Karachi; attendance started on 2026-01-01. */

beforeEach(function () {
    $this->withoutVite(); // the page is built once by the coordinator, not by this task
    $this->travelTo(CarbonImmutable::parse('2026-11-25 12:00:00', 'Asia/Karachi'));
    Settings::set('attendance', ['start_date' => '2026-01-01']);
    // The Excel package's temporary files go to a folder removed afterwards.
    $this->excelTemp = sys_get_temp_dir().'/lumino-excel-'.bin2hex(random_bytes(6));
    config(['excel.temporary_files.local_path' => $this->excelTemp]);
});

afterEach(function () {
    File::deleteDirectory($this->excelTemp);
});

/** The downloaded workbook's first sheet, with styles. */
function meSheet(TestResponse $response): Worksheet
{
    $directory = (string) config('excel.temporary_files.local_path');
    File::ensureDirectoryExists($directory);
    $path = $directory.'/t'.bin2hex(random_bytes(6)).'.xlsx';
    $base = $response->baseResponse;

    if ($base instanceof BinaryFileResponse) {
        copy($base->getFile()->getPathname(), $path);
    } else {
        file_put_contents($path, $response->streamedContent());
    }

    return IOFactory::createReader('Xlsx')->load($path)->getSheet(0);
}

/**
 * The employee lines of a sheet read with toArray(): the rows between the header and the blank row above the key.
 *
 * @param  list<list<mixed>>  $rows
 * @return list<list<mixed>>
 */
function meLines(array $rows): array
{
    $lines = [];

    foreach (array_slice($rows, 1) as $row) {
        if (array_filter($row, fn ($v) => $v !== null && $v !== '') === []) {
            break;
        }

        $lines[] = $row;
    }

    return $lines;
}

/** @param  array<string, mixed>  $attrs */
function mePerson(array $attrs = []): Employee
{
    return Employee::factory()->create(['joining_date' => '2025-01-01', ...$attrs]);
}

/** @param  array<string, mixed>  $attrs */
function meDay(Employee $e, string $date, ?string $status, array $attrs = []): void
{
    Attendance::factory()->create(['employee_id' => $e->id, 'date' => $date, 'status' => $status, 'closed' => true, ...$attrs]);
}

// ---------------------------------------------------------------- access

it('lets only the see-everyone attendance permission export', function (string $role, int $status) {
    $user = userWithRole($role);
    employeeFor($user);

    expect($this->actingAs($user)->get('/attendance/monthly/export?month=2026-11')->getStatusCode())->toBe($status);
})->with([
    'admin' => ['admin', 200],
    'hr' => ['hr', 200],
    'manager' => ['manager', 403],
    'employee' => ['employee', 403],
]);

it('refuses a month that has not started', function () {
    $admin = userWithRole('admin');

    $this->actingAs($admin)->get('/attendance/monthly/export?month=2026-12')
        ->assertRedirect('/attendance/monthly?month=2026-11');
    expect(Activity::where('description', 'Monthly attendance exported')->count())->toBe(0);
});

// ---------------------------------------------------------------- file

it('names the file after the month and writes one row per employee with letters per day and totals', function () {
    $admin = userWithRole('admin');
    $dept = Department::factory()->create(['name' => 'Engineering']);
    $desig = Designation::factory()->create(['department_id' => $dept->id, 'name' => 'Engineer']);
    $e = mePerson(['first_name' => 'Ayesha', 'last_name' => 'Khan', 'employee_code' => 'LD-0001',
        'department_id' => $dept->id, 'designation_id' => $desig->id]);
    meDay($e, '2026-11-02', 'present', ['worked_minutes' => 540, 'overtime_minutes' => 45]);
    meDay($e, '2026-11-03', 'late', ['worked_minutes' => 510, 'late_minutes' => 30]);
    meDay($e, '2026-11-04', 'half_day', ['worked_minutes' => 210]);
    meDay($e, '2026-11-05', 'absent', ['worked_minutes' => 0, 'clock_in' => null, 'clock_out' => null]);
    meDay($e, '2026-11-06', 'on_leave', ['worked_minutes' => 0, 'clock_in' => null, 'clock_out' => null]);
    meDay($e, '2026-11-07', 'weekend', ['worked_minutes' => 0, 'clock_in' => null, 'clock_out' => null]);
    meDay($e, '2026-11-10', 'holiday', ['worked_minutes' => 0, 'clock_in' => null, 'clock_out' => null]);
    meDay($e, '2026-11-11', null);

    $response = $this->actingAs($admin)->get('/attendance/monthly/export?month=2026-11')->assertOk();
    expect($response->headers->get('content-disposition'))->toContain('attendance-2026-11.xlsx');

    $sheet = meSheet($response);
    $rows = $sheet->toArray(null, false, false);

    // Header, one employee, a blank row, the key.
    expect($rows)->toHaveCount(4)
        ->and($rows[2][0])->toBeNull()
        ->and($rows[3][0])->toBe('Key: P present · L late · HD half day · A absent · LV on leave · H holiday · W weekend · ½ half-day leave · … pending calculation (not counted) · – no record');
    expect(array_slice($rows[0], 0, 6))->toBe(['Employee Code', 'Name', 'Department', 'Designation', '01 Sun', '02 Mon'])
        ->and(array_slice($rows[0], 4 + 30))->toBe([
            'Present', 'Late', 'Half Day', 'Absent', 'On Leave', 'Weekend/Holiday', 'Present-equivalent', 'Working Days',
            'Worked Hours', 'Late Minutes', 'Overtime Minutes',
        ]);

    $line = $rows[1];
    expect(array_slice($line, 0, 4))->toBe(['LD-0001', 'Ayesha Khan', 'Engineering', 'Engineer'])
        // The 11th is pending calculation (…), the rest without a row have no record (–).
        ->and(array_slice($line, 4, 12))->toBe(['–', 'P', 'L', 'HD', 'A', 'LV', 'W', '–', '–', 'H', '…', '–'])
        // Days still to come.
        ->and($line[4 + 25])->toBe('–')
        ->and(array_slice($line, 4 + 30))->toBe([1, 1, 1, 1, 1, 2, 2.5, 4, 21.0, 30, 45]);

    // Names, codes and day letters are text cells; the totals are numeric cells Excel can sum.
    foreach ($sheet->getRowIterator(2, 2) as $row) {
        foreach ($row->getCellIterator() as $column => $cell) {
            $isTotal = Coordinate::columnIndexFromString($column) > 4 + 30;
            expect($cell->getDataType())->toBe($isTotal ? DataType::TYPE_NUMERIC : DataType::TYPE_STRING);
        }
    }
    expect($sheet->getStyle('AQ2')->getNumberFormat()->getFormatCode())->toBe('0.00'); // worked hours
});

it('has 28, 29, 30 or 31 day columns', function (string $month, int $days) {
    $admin = userWithRole('admin');
    mePerson();

    $rows = meSheet($this->actingAs($admin)->get('/attendance/monthly/export?month='.$month))->toArray(null, false, false);

    expect($rows[0])->toHaveCount(4 + $days + 11)
        ->and($rows[0][4 + $days - 1])->toStartWith(sprintf('%02d ', $days));
})->with([
    ['2026-02', 28],
    ['2024-02', 29],
    ['2026-09', 30],
    ['2026-10', 31],
]);

it('keeps formula-looking names as text with the quote-prefix style', function () {
    $admin = userWithRole('admin');
    mePerson(['first_name' => '=HYPERLINK("http://evil.test")', 'last_name' => 'X', 'employee_code' => '+92300']);

    $sheet = meSheet($this->actingAs($admin)->get('/attendance/monthly/export?month=2026-11'));

    expect($sheet->getCell('B2')->getValue())->toBe('=HYPERLINK("http://evil.test") X')
        ->and($sheet->getCell('B2')->getDataType())->toBe(DataType::TYPE_STRING)
        ->and($sheet->getStyle('B2')->getQuotePrefix())->toBeTrue()
        ->and($sheet->getCell('A2')->getValue())->toBe('+92300')
        ->and($sheet->getStyle('A2')->getQuotePrefix())->toBeTrue();
});

it('exports every employee of the month, not one page, and honours search and department', function () {
    $admin = userWithRole('admin');
    $sales = Department::factory()->create();
    for ($i = 1; $i <= 30; $i++) {
        mePerson(['last_name' => sprintf('Person%02d', $i)]);
    }
    mePerson(['last_name' => 'Seller', 'department_id' => $sales->id,
        'designation_id' => Designation::factory()->create(['department_id' => $sales->id])->id]);
    mePerson(['last_name' => 'NotYet', 'joining_date' => '2026-12-01']);

    $all = meSheet($this->actingAs($admin)->get('/attendance/monthly/export?month=2026-11&page=2'))->toArray(null, false, false);
    $sellers = meSheet($this->actingAs($admin)->get('/attendance/monthly/export?month=2026-11&department_id='.$sales->id))->toArray(null, false, false);
    $search = meSheet($this->actingAs($admin)->get('/attendance/monthly/export?month=2026-11&search=person07'))->toArray(null, false, false);

    expect(meLines($all))->toHaveCount(31)
        ->and(meLines($sellers))->toHaveCount(1)
        ->and($sellers[1][1])->toEndWith('Seller')
        ->and(meLines($search))->toHaveCount(1);
});

it('blanks the days after a mid-month leaver\'s last working day and does not count them', function () {
    $admin = userWithRole('admin');
    $e = mePerson(['last_name' => 'Leaver', 'status' => 'resigned', 'last_working_day' => '2026-11-12']);
    meDay($e, '2026-11-11', 'present', ['worked_minutes' => 540]);
    meDay($e, '2026-11-12', 'late', ['worked_minutes' => 500, 'late_minutes' => 15]);
    meDay($e, '2026-11-13', 'present', ['worked_minutes' => 540]);
    meDay($e, '2026-11-16', 'absent', ['worked_minutes' => 0, 'clock_in' => null, 'clock_out' => null]);

    $rows = meSheet($this->actingAs($admin)->get('/attendance/monthly/export?month=2026-11'))->toArray(null, false, false);

    expect(meLines($rows))->toHaveCount(1);
    $line = $rows[1];
    expect(array_slice($line, 4 + 10, 6))->toBe(['P', 'L', '–', '–', '–', '–'])
        ->and(array_slice($line, 4 + 30))->toBe([1, 1, 0, 0, 0, 0, 2, 2, 17.33, 15, 0]);
});

it('leaves out a leaver with no last working day unless they have an attendance row in the month', function () {
    $admin = userWithRole('admin');
    mePerson(['last_name' => 'Stays']);
    $gone = mePerson(['last_name' => 'Gone', 'status' => 'terminated', 'last_working_day' => null]);
    meDay($gone, '2026-10-20', 'present');

    $october = meSheet($this->actingAs($admin)->get('/attendance/monthly/export?month=2026-10'))->toArray(null, false, false);
    $november = meSheet($this->actingAs($admin)->get('/attendance/monthly/export?month=2026-11'))->toArray(null, false, false);

    expect(meLines($october))->toHaveCount(2)
        ->and(meLines($november))->toHaveCount(1)
        ->and($november[1][1])->toEndWith('Stays');
});

it('writes one audit entry with the month, count and filters and no row data', function () {
    $admin = userWithRole('admin');
    mePerson(['first_name' => 'Ada', 'last_name' => 'Lovelace']);
    mePerson(['first_name' => 'Bob', 'last_name' => 'Stone']);

    $this->actingAs($admin)->get('/attendance/monthly/export?month=2026-10&search=ada')->assertOk();

    $entry = Activity::where('log_name', 'attendance')->where('description', 'Monthly attendance exported')->sole();
    expect($entry->causer_id)->toBe($admin->id)
        ->and($entry->properties['month'])->toBe('2026-10')
        ->and($entry->properties['rows'])->toBe(1)
        ->and($entry->properties['filters'])->toBe(['search' => 'ada', 'department_id' => null]);
    expect(json_encode($entry->properties))->not->toContain('Lovelace');
});

it('runs no query per employee or per day', function () {
    $admin = userWithRole('admin');
    $count = function (string $month) use ($admin): int {
        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });
        $this->actingAs($admin)->get('/attendance/monthly/export?month='.$month)->assertOk();

        return $queries;
    };

    $first = mePerson();
    meDay($first, '2026-02-02', 'present');
    $count('2026-01'); // warm-up
    $february = $count('2026-02');

    foreach (Employee::factory()->count(12)->create(['joining_date' => '2025-01-01']) as $e) {
        for ($d = 1; $d <= 31; $d++) {
            meDay($e, sprintf('2026-03-%02d', $d), 'late', ['late_minutes' => 5]);
        }
    }
    $march = $count('2026-03');

    expect($march)->toBe($february)->and($march)->toBeLessThanOrEqual(10);
});

// ---------------------------------------------------------------- half-day leave (Phase 4)

it('writes half-day leave as P½ / A½ and the halves in numeric totals', function () {
    $admin = userWithRole('admin');
    $e = mePerson(['employee_code' => 'LD-0002']);
    meDay($e, '2026-11-02', 'present', ['leave_half' => 'am', 'worked_minutes' => 270]);
    meDay($e, '2026-11-03', 'absent', ['leave_half' => 'pm', 'worked_minutes' => 120]);
    meDay($e, '2026-11-04', 'on_leave', ['worked_minutes' => 0, 'clock_in' => null, 'clock_out' => null]);
    meDay($e, '2026-11-05', 'present', ['worked_minutes' => 540]);

    $sheet = meSheet($this->actingAs($admin)->get('/attendance/monthly/export?month=2026-11')->assertOk());
    $line = $sheet->toArray(null, false, false)[1];

    expect(array_slice($line, 4, 6))->toBe(['–', 'P½', 'A½', 'LV', 'P', '–'])
        // Present, Late, Half Day, Absent, On Leave, Weekend/Holiday, Present-equivalent, Working Days.
        ->and(array_slice($line, 4 + 30, 8))->toBe([1.5, 0, 0, 0.5, 2, 0, 1.5, 2]);

    foreach (['AI', 'AL', 'AM'] as $column) {
        expect($sheet->getCell($column.'2')->getDataType())->toBe(DataType::TYPE_NUMERIC);
    }
});
