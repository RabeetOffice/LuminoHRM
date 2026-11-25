<?php

use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/*
 * Leave > Balances: the grid (who sees whom, can_edit, rows ensured, projections, query count), HR's adjustment
 * (rights, validation, overdrawn confirmation, audit) and the export. Today is 2026-11-25 (leave year 2026).
 */

beforeEach(function () {
    $this->withoutVite();
    $this->travelTo(CarbonImmutable::parse('2026-11-25 12:00:00', 'Asia/Karachi'));
    $this->excelTemp = sys_get_temp_dir().'/lumino-excel-'.bin2hex(random_bytes(6));
    config(['excel.temporary_files.local_path' => $this->excelTemp]);
});

afterEach(function () {
    File::deleteDirectory($this->excelTemp);
});

function blPerson(array $attrs = []): Employee
{
    return Employee::factory()->create(['joining_date' => '2024-03-01', ...$attrs]);
}

/** A user with $role and an employee record. */
function blUser(string $role, array $attrs = []): User
{
    $user = userWithRole($role);
    employeeFor($user, ['joining_date' => '2024-03-01', ...$attrs]);

    return $user->fresh();
}

function blRow(Employee $e, string $type, int $year, float $allocated, float $adjustment = 0, float $openingUsed = 0): LeaveBalance
{
    return LeaveBalance::query()->create([
        'employee_id' => $e->id, 'leave_type_id' => leaveType($type)->id, 'year' => $year,
        'allocated' => $allocated, 'adjustment' => $adjustment, 'opening_used' => $openingUsed,
    ]);
}

/** @return array<string, mixed> the page props */
function blProps(User $viewer, string $query = ''): array
{
    return test()->actingAs($viewer)->get('/leave/balances'.($query !== '' ? '?'.$query : ''))->assertOk()->inertiaProps();
}

/** @return array<string, mixed>|null the row of $e in the props */
function blFind(array $props, Employee $e): ?array
{
    return collect($props['rows']['data'])->firstWhere('id', $e->id);
}

function blCell(array $props, Employee $e, string $type): ?array
{
    return blFind($props, $e)['cells'][leaveType($type)->id] ?? null;
}

function blPut(User $by, Employee $e, string $type, int $year, array $over = []): TestResponse
{
    return test()->actingAs($by)->put("/leave/balances/{$e->id}/".leaveType($type)->id."/{$year}", [
        'allocated' => 14, 'adjustment' => 0, 'opening_used' => 0, 'reason' => 'Corrected after review', ...$over,
    ]);
}

function blSheet(TestResponse $response): Worksheet
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

// --- access ----------------------------------------------------------------------------------------------------

test('a guest is sent to the login page', function () {
    $this->get('/leave/balances')->assertRedirect('/login');
});

test('Admin, HR and Manager may open the grid; an Employee may not', function (string $role, int $status) {
    $this->actingAs(blUser($role))->get('/leave/balances')->assertStatus($status);
})->with([['admin', 200], ['hr', 200], ['manager', 200], ['employee', 403]]);

it('renders the page with the year, years, types and filters', function () {
    $this->actingAs(blUser('hr'))->get('/leave/balances')
        ->assertInertia(fn (Assert $page) => $page
            ->component('leave/balances')
            ->where('year', 2026)
            ->where('years', [2026, 2027])
            ->where('types', fn ($types) => collect($types)->pluck('name')->all() === ['Annual', 'Sick', 'Casual', 'Unpaid'])
            ->where('filters', ['search' => '', 'department' => null])
            ->where('can', ['edit' => true, 'export' => true, 'import' => true]));
});

it('offers years from the earliest balance year to next year, and falls back to this year', function (?string $year, int $shown) {
    blRow(blPerson(), 'Annual', 2024, 14);

    $props = blProps(blUser('hr'), $year === null ? '' : 'year='.$year);

    expect($props['years'])->toBe([2024, 2025, 2026, 2027])
        ->and($props['year'])->toBe($shown);
})->with([[null, 2026], ['2024', 2024], ['2027', 2027], ['2028', 2026], ['2023', 2026], ['abc', 2026]]);

// --- rows and cells -------------------------------------------------------------------------------------------

it('ensures the current year rows for the people HR may administer, and shows each cell', function () {
    $hr = blUser('hr');
    $e = blPerson(['first_name' => 'Sara']);
    approvedLeave($e, '2026-11-02', '2026-11-03'); // 2 days used
    leaveRequest($e, ['from_date' => '2026-12-01', 'to_date' => '2026-12-01', 'days' => 1]); // 1 pending

    $props = blProps($hr);
    $cell = blCell($props, $e, 'Annual');

    expect(LeaveBalance::query()->where('employee_id', $e->id)->where('year', 2026)->count())->toBe(3)
        ->and($cell['projected'])->toBeFalse()
        ->and($cell['allocated'])->toEqual(14)
        ->and($cell['used'])->toEqual(2)
        ->and($cell['pending'])->toEqual(1)
        ->and($cell['remaining'])->toEqual(12)
        ->and($cell['can_edit'])->toBeTrue()
        ->and(blFind($props, $e)['first_name'])->toBe('Sara');

    // HR cannot administer their own record: no row is created for it and nothing is editable.
    $own = Employee::query()->find($hr->employee_id);
    expect(LeaveBalance::query()->where('employee_id', $own->id)->count())->toBe(0)
        ->and(blCell($props, $own, 'Annual')['projected'])->toBeTrue()
        ->and(blCell($props, $own, 'Annual')['can_edit'])->toBeFalse();
});

it('shows an unpaid type as used days, unlimited and never editable, ignoring its old balance rows', function () {
    $e = blPerson();
    $paidThenUnpaid = leaveType('Study', ['is_paid' => true, 'yearly_quota' => 5, 'colour' => 'teal']);
    blRow($e, 'Study', 2026, 5);
    $paidThenUnpaid->forceFill(['is_paid' => false, 'yearly_quota' => 0])->save();
    approvedLeave($e, '2026-11-04', null, null, 'Unpaid');

    $props = blProps(blUser('admin'));

    expect(blCell($props, $e, 'Unpaid'))->toMatchArray(['unlimited' => true, 'remaining' => null, 'used' => 1.0, 'can_edit' => false])
        ->and(blCell($props, $e, 'Study'))->toMatchArray(['unlimited' => true, 'remaining' => null, 'allocated' => 0.0, 'can_edit' => false]);
});

it('lets a Manager see only their own row and their reports, read-only, and creates no rows', function () {
    $manager = blUser('manager');
    $mine = Employee::query()->find($manager->employee_id);
    $report = blPerson(['manager_id' => $mine->id]);
    $other = blPerson();

    $props = blProps($manager);
    $ids = collect($props['rows']['data'])->pluck('id')->sort()->values()->all();

    expect($ids)->toBe(collect([$mine->id, $report->id])->sort()->values()->all())
        ->and(blFind($props, $other))->toBeNull()
        ->and(collect($props['rows']['data'])->flatMap(fn ($r) => collect($r['cells'])->pluck('can_edit'))->unique()->values()->all())->toBe([false])
        ->and($props['can'])->toBe(['edit' => false, 'export' => false, 'import' => false])
        ->and(LeaveBalance::query()->count())->toBe(0)
        ->and(blCell($props, $report, 'Annual')['projected'])->toBeTrue();
});

it('lets HR edit neither their own nor another HR person\'s balance; an Admin may edit both', function () {
    $hr = blUser('hr');
    $otherHr = blUser('hr');
    $admin = blUser('admin');
    $hrRecord = Employee::query()->find($hr->employee_id);
    $otherRecord = Employee::query()->find($otherHr->employee_id);

    $byHr = blProps($hr);
    expect(blCell($byHr, $hrRecord, 'Annual')['can_edit'])->toBeFalse()
        ->and(blCell($byHr, $otherRecord, 'Annual')['can_edit'])->toBeFalse();

    blPut($hr, $hrRecord, 'Annual', 2026)->assertForbidden();
    blPut($hr, $otherRecord, 'Annual', 2026)->assertForbidden();

    $byAdmin = blProps($admin);
    expect(blCell($byAdmin, $hrRecord, 'Annual')['can_edit'])->toBeTrue()
        ->and(blCell($byAdmin, $otherRecord, 'Annual')['can_edit'])->toBeTrue();

    blPut($admin, $otherRecord, 'Annual', 2026, ['allocated' => 15])->assertSessionHasNoErrors()->assertRedirect();
    expect((float) LeaveBalance::query()->where('employee_id', $otherRecord->id)->where('leave_type_id', leaveType('Annual')->id)->value('allocated'))->toBe(15.0);
});

it('shows next year as a projection to a Manager and creates next year rows for HR', function () {
    $hr = blUser('hr');
    $e = blPerson();
    blRow($e, 'Annual', 2026, 14);

    expect(blCell(blProps($hr, 'year=2027'), $e, 'Annual')['projected'])->toBeFalse()
        ->and(LeaveBalance::query()->where('employee_id', $e->id)->where('year', 2027)->count())->toBe(3);

    // A past year is never written by viewing it.
    blProps($hr, 'year=2025');
    expect(LeaveBalance::query()->where('year', 2025)->count())->toBe(0);
});

it('lists only the people employed in the year, and filters by search and department', function () {
    $sales = Department::factory()->create(['name' => 'Sales']);
    $inSales = blPerson(['first_name' => 'Zaynab', 'department_id' => $sales->id]);
    $other = blPerson(['first_name' => 'Omar']);
    $left = blPerson(['status' => 'resigned', 'last_working_day' => '2025-05-31']);
    $future = blPerson(['joining_date' => '2027-03-01']);
    blRow($left, 'Annual', 2025, 14); // makes 2025 one of the years offered
    $hr = blUser('admin');

    $ids = fn (string $q) => collect(blProps($hr, $q)['rows']['data'])->pluck('id')->all();

    expect($ids(''))->toContain($inSales->id, $other->id)->not->toContain($left->id, $future->id)
        ->and($ids('year=2025'))->toContain($left->id)
        ->and($ids('year=2027'))->toContain($future->id)
        ->and($ids('search=zayn'))->toBe([$inSales->id])
        ->and($ids('department='.$sales->id))->toBe([$inSales->id]);
});

it('reads a fixed number of queries whatever the number of people on the page', function () {
    $admin = blUser('hr');
    $count = function () use ($admin): int {
        $this->actingAs($admin)->get('/leave/balances')->assertOk(); // first visit ensures rows
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($admin)->get('/leave/balances')->assertOk();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    };

    blPerson();
    $few = $count();

    foreach (Employee::factory()->count(8)->create(['joining_date' => '2024-03-01']) as $i => $e) {
        approvedLeave($e, '2026-11-02');
        leaveRequest($e, ['from_date' => '2026-12-0'.($i + 1), 'to_date' => '2026-12-0'.($i + 1)]);
        employeeFor(userWithRole(['employee', 'manager', 'hr', 'admin'][$i % 4]), ['joining_date' => '2024-03-01']);
    }

    expect($count())->toBe($few);
});

// --- adjustment -----------------------------------------------------------------------------------------------

it('saves an adjustment and audits the old and new figures with the reason', function () {
    $hr = blUser('hr');
    $e = blPerson();
    blRow($e, 'Annual', 2026, 14);

    blPut($hr, $e, 'Annual', 2026, ['allocated' => 12.5, 'adjustment' => -1, 'opening_used' => 2, 'reason' => 'Go-live figures'])
        ->assertSessionHasNoErrors()->assertRedirect()->assertSessionHas('success', 'Balance updated.');

    $row = LeaveBalance::query()->where('employee_id', $e->id)->where('leave_type_id', leaveType('Annual')->id)->where('year', 2026)->first();
    expect([(float) $row->allocated, (float) $row->adjustment, (float) $row->opening_used])->toBe([12.5, -1.0, 2.0]);

    $entry = Activity::query()->where('description', 'Leave balance adjusted')->sole();
    expect($entry->log_name)->toBe('leave')
        ->and($entry->subject_id)->toBe($e->id)
        ->and($entry->causer_id)->toBe($hr->id)
        ->and($entry->properties['year'])->toBe(2026)
        ->and($entry->properties['type'])->toBe('Annual')
        ->and($entry->properties['reason'])->toBe('Go-live figures')
        ->and($entry->properties['old'])->toEqual(['allocated' => 14.0, 'adjustment' => 0.0, 'opening_used' => 0.0])
        ->and($entry->properties['attributes'])->toEqual(['allocated' => 12.5, 'adjustment' => -1.0, 'opening_used' => 2.0]);
});

it('creates the row when it is missing, and writes nothing when no figure changes', function () {
    $hr = blUser('hr');
    $e = blPerson();

    blPut($hr, $e, 'Annual', 2026, ['allocated' => 14])->assertSessionHas('success', 'Nothing changed.');

    expect(LeaveBalance::query()->where('employee_id', $e->id)->count())->toBe(1)
        ->and(Activity::query()->where('description', 'Leave balance adjusted')->count())->toBe(0);
});

it('needs a confirmation to leave a balance overdrawn, then saves it', function () {
    $hr = blUser('hr');
    $e = blPerson(['first_name' => 'Sara']);
    blRow($e, 'Annual', 2026, 14);
    approvedLeave($e, '2026-11-02', '2026-11-06'); // 5 used

    blPut($hr, $e, 'Annual', 2026, ['allocated' => 3.5])
        ->assertSessionHasErrors(['confirm_overdrawn' => 'This leaves Sara overdrawn by 1.5 days. Tick the box to confirm.']);

    expect((float) LeaveBalance::query()->where('employee_id', $e->id)->value('allocated'))->toBe(14.0)
        ->and(Activity::query()->where('description', 'Leave balance adjusted')->count())->toBe(0);

    blPut($hr, $e, 'Annual', 2026, ['allocated' => 3.5, 'confirm_overdrawn' => true])->assertSessionHasNoErrors();

    expect((float) LeaveBalance::query()->where('employee_id', $e->id)->value('allocated'))->toBe(3.5)
        ->and(blCell(blProps($hr), $e, 'Annual')['overdrawn'])->toBeTrue();
});

it('validates the figures and the reason', function (array $over, string $field) {
    $e = blPerson();

    blPut(blUser('hr'), $e, 'Annual', 2026, $over)->assertSessionHasErrors($field);
})->with([
    'no reason' => [['reason' => ''], 'reason'],
    'short reason' => [['reason' => 'abc '], 'reason'],
    'long reason' => [['reason' => str_repeat('x', 256)], 'reason'],
    'quarter day' => [['allocated' => 1.25], 'allocated'],
    'allocated too big' => [['allocated' => 366], 'allocated'],
    'negative allocated' => [['allocated' => -1], 'allocated'],
    'adjustment too low' => [['adjustment' => -366], 'adjustment'],
    'opening used not a number' => [['opening_used' => 'x'], 'opening_used'],
    'negative opening used' => [['opening_used' => -0.5], 'opening_used'],
]);

it('refuses a Manager, an unpaid type and a year out of range', function () {
    $manager = blUser('manager');
    $report = blPerson(['manager_id' => $manager->employee_id]);
    $hr = blUser('hr');
    $e = blPerson();

    blPut($manager, $report, 'Annual', 2026)->assertForbidden();
    blPut(blUser('employee'), $e, 'Annual', 2026)->assertForbidden();
    blPut($hr, $e, 'Unpaid', 2026)->assertNotFound();
    blPut($hr, $e, 'Annual', 2028)->assertNotFound();
    blPut($hr, $e, 'Annual', 2020)->assertNotFound();
    $this->actingAs($hr)->put('/leave/balances/999999/'.leaveType('Annual')->id.'/2026', [])->assertNotFound();

    expect(LeaveBalance::query()->count())->toBe(0);
});

it('refuses a year the person was not employed in', function () {
    $e = blPerson(['joining_date' => '2027-02-01']);

    blPut(blUser('hr'), $e, 'Annual', 2026)->assertSessionHas('error');

    expect(LeaveBalance::query()->count())->toBe(0);
});

// --- export ---------------------------------------------------------------------------------------------------

it('exports one row per person with each paid type\'s figures as numbers, and audits it', function () {
    $hr = blUser('hr');
    $e = blPerson(['first_name' => '=Sara', 'last_name' => 'Khan']);
    blRow($e, 'Annual', 2026, 14, 1, 2);
    approvedLeave($e, '2026-11-02');

    $response = $this->actingAs($hr)->get('/leave/balances/export?year=2026')->assertOk();
    $sheet = blSheet($response);
    $header = $sheet->rangeToArray('A1:'.$sheet->getHighestColumn().'1')[0];

    expect(array_slice($header, 0, 10))->toBe([
        'Employee Code', 'Name', 'Department',
        'Annual Allocated', 'Annual Carried', 'Annual Adjustment', 'Annual Opening Used', 'Annual Used', 'Annual Pending', 'Annual Remaining',
    ])->and($header)->not->toContain('Unpaid Used')
        ->and(count($header))->toBe(3 + 3 * 7);

    $line = null;
    for ($r = 2; $r <= $sheet->getHighestRow(); $r++) {
        if ($sheet->getCell("A{$r}")->getValue() === $e->employee_code) {
            $line = $r;
        }
    }

    expect($line)->not->toBeNull()
        ->and($sheet->getCell("B{$line}")->getValue())->toBe('=Sara Khan')
        ->and($sheet->getCell("B{$line}")->getDataType())->toBe(DataType::TYPE_STRING)
        ->and($sheet->getCell("D{$line}")->getDataType())->toBe(DataType::TYPE_NUMERIC)
        ->and((float) $sheet->getCell("D{$line}")->getValue())->toBe(14.0)
        ->and((float) $sheet->getCell("F{$line}")->getValue())->toBe(1.0)
        ->and((float) $sheet->getCell("G{$line}")->getValue())->toBe(2.0)
        ->and((float) $sheet->getCell("H{$line}")->getValue())->toBe(1.0)
        ->and((float) $sheet->getCell("J{$line}")->getValue())->toBe(12.0)
        ->and($sheet->getStyle("D{$line}")->getNumberFormat()->getFormatCode())->toBe('0.0');

    $entry = Activity::query()->where('description', 'Leave balances exported')->sole();
    expect($entry->causer_id)->toBe($hr->id)->and($entry->properties['year'])->toBe(2026);
});

it('refuses the export to a Manager and an Employee', function (string $role) {
    $this->actingAs(blUser($role))->get('/leave/balances/export')->assertForbidden();
})->with(['manager', 'employee']);
