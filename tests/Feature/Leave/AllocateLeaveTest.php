<?php

use App\Actions\Employees\CreateEmployee;
use App\Actions\Employees\UpdateEmployee;
use App\Actions\Leave\AdjustBalance;
use App\Console\Commands\AllocateLeave;
use App\Models\Department;
use App\Models\Designation;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Support\Attendance\AttendanceClock;
use App\Support\Leave\LeaveBalances;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Spatie\Activitylog\Models\Activity;

/*
 * hrm:leave-allocate, the balance rows a new employee gets, and the joining-date recalculation in UpdateEmployee.
 * Today is 2026-11-25 (leave year 2026). Seeded paid types: Annual 14, Sick 8, Casual 10 (and Unpaid, no rows).
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-25 12:00:00', 'Asia/Karachi'));
});

function allocEmployee(array $attrs = []): Employee
{
    return Employee::factory()->create(['joining_date' => '2024-03-01', ...$attrs]);
}

/** type name => allocated, for one employee and year. */
function alRows(Employee $e, int $year): array
{
    return LeaveBalance::query()->with('type')->where('employee_id', $e->id)->where('year', $year)->get()
        ->mapWithKeys(fn (LeaveBalance $b) => [$b->type->name => (float) $b->allocated])->sortKeys()->all();
}

function alAllocatedEntries(): int
{
    return Activity::query()->where('log_name', 'leave')->where('description', 'Leave allocated')->count();
}

it('creates the rows of the current year once, and audits only the run that created them', function () {
    $a = allocEmployee();
    $b = allocEmployee();

    $this->artisan('hrm:leave-allocate')->expectsOutputToContain('rows=6')->assertSuccessful();

    expect(alRows($a, 2026))->toBe(['Annual' => 14.0, 'Casual' => 10.0, 'Sick' => 8.0])
        ->and(alRows($b, 2026))->toHaveCount(3)
        ->and(LeaveBalance::query()->count())->toBe(6)
        ->and(alAllocatedEntries())->toBe(1);

    $entry = Activity::query()->where('description', 'Leave allocated')->first();
    expect($entry->subject_id)->toBeNull()
        ->and($entry->properties->all())->toMatchArray(['year' => 2026, 'rows' => 6]);

    $this->artisan('hrm:leave-allocate')->expectsOutputToContain('rows=0')->assertSuccessful();

    expect(LeaveBalance::query()->count())->toBe(6)
        ->and(alAllocatedEntries())->toBe(1);
});

it('pro-rates a mid-year joiner and gives nothing for a year before joining', function () {
    $joiner = allocEmployee(['joining_date' => '2026-07-15']);

    $this->artisan('hrm:leave-allocate')->assertSuccessful();

    expect(alRows($joiner, 2026))->toBe(['Annual' => 7.0, 'Casual' => 5.0, 'Sick' => 4.0]);
});

it('skips leavers with a past last working day, leavers without one, and people joining after the year', function () {
    $left = allocEmployee(['status' => 'resigned', 'last_working_day' => '2025-06-30']);
    $noLastDay = allocEmployee(['status' => 'terminated', 'last_working_day' => null]);
    $later = allocEmployee(['joining_date' => '2027-02-01']);
    $leavingThisYear = allocEmployee(['status' => 'resigned', 'last_working_day' => '2026-03-31']);

    $this->artisan('hrm:leave-allocate')->assertSuccessful();

    expect(alRows($left, 2026))->toBe([])
        ->and(alRows($noLastDay, 2026))->toBe([])
        ->and(alRows($later, 2026))->toBe([])
        ->and(alRows($leavingThisYear, 2026))->toHaveCount(3);

    $this->artisan('hrm:leave-allocate', ['year' => '2027'])->assertSuccessful();

    expect(alRows($later, 2027))->toBe(['Annual' => 12.5, 'Casual' => 9.0, 'Sick' => 7.0]) // joined in February
        ->and(alRows($left, 2027))->toBe([])
        ->and(alRows($leavingThisYear, 2027))->toBe([]);
});

it('never changes an existing row, and fills only the missing types', function () {
    $e = allocEmployee();
    LeaveBalance::query()->create([
        'employee_id' => $e->id, 'leave_type_id' => leaveType('Annual')->id, 'year' => 2026,
        'allocated' => 20, 'adjustment' => -2, 'opening_used' => 3,
    ]);

    $this->artisan('hrm:leave-allocate')->expectsOutputToContain('rows=2')->assertSuccessful();

    $annual = LeaveBalance::query()->where('employee_id', $e->id)->where('leave_type_id', leaveType('Annual')->id)->first();
    expect((float) $annual->allocated)->toBe(20.0)
        ->and((float) $annual->adjustment)->toBe(-2.0)
        ->and((float) $annual->opening_used)->toBe(3.0)
        ->and(alRows($e, 2026))->toHaveCount(3);
});

it('creates no rows for inactive or unpaid types', function () {
    leaveType('Casual', ['is_active' => false]);
    $e = allocEmployee();

    $this->artisan('hrm:leave-allocate')->assertSuccessful();

    expect(array_keys(alRows($e, 2026)))->toBe(['Annual', 'Sick']);
});

it('refuses a year more than one ahead and anything that is not a year', function (string $year) {
    $this->artisan('hrm:leave-allocate', ['year' => $year])->assertFailed();

    expect(LeaveBalance::query()->count())->toBe(0);
})->with(['2028', 'abc', '26']);

it('catches up a missed 1 January on the next run', function () {
    $e = allocEmployee();
    $this->travelTo(CarbonImmutable::parse('2027-01-03 10:00:00', 'Asia/Karachi'));
    expect(substr(app(AttendanceClock::class)->attendanceToday(), 0, 4))->toBe('2027');

    $this->artisan('hrm:leave-allocate')->assertSuccessful();

    expect(alRows($e, 2027))->toHaveCount(3);
});

it('is scheduled hourly at :40', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($e) => str_contains((string) $e->command, 'hrm:leave-allocate'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('40 * * * *');
});

it('gives a new employee the rows of the current leave year', function () {
    $department = Department::factory()->create();
    $designation = Designation::factory()->create(['department_id' => $department->id]);

    $employee = app(CreateEmployee::class)->handle([
        'first_name' => 'New', 'last_name' => 'Joiner', 'email' => 'new.joiner@corp.test',
        'department_id' => $department->id, 'designation_id' => $designation->id, 'joining_date' => '2026-10-01',
    ], userWithRole('admin'), false);

    expect(alRows($employee, 2026))->toBe(['Annual' => 3.5, 'Casual' => 2.5, 'Sick' => 2.0]);
});

it('recalculates an untouched allocation when the joining date changes, and leaves an edited one', function () {
    $e = allocEmployee(['joining_date' => '2026-07-01']); // Annual 7, Sick 4, Casual 5
    $this->artisan('hrm:leave-allocate')->assertSuccessful();
    LeaveBalance::query()->where('employee_id', $e->id)->where('leave_type_id', leaveType('Sick')->id)->update(['allocated' => 5]);
    $admin = userWithRole('admin');

    app(UpdateEmployee::class)->handle($e, ['joining_date' => '2026-04-01'], $admin, syncAccount: false); // 9 months

    expect(alRows($e, 2026))->toBe(['Annual' => 10.5, 'Casual' => 7.5, 'Sick' => 5.0]);

    $entries = Activity::query()->where('description', 'Leave allocation recalculated')->orderBy('id')->get();
    expect($entries)->toHaveCount(2)
        ->and($entries[0]->subject_id)->toBe($e->id)
        ->and($entries[0]->causer_id)->toBe($admin->id)
        ->and($entries[0]->properties['type'])->toBe('Annual')
        ->and($entries[0]->properties['old'])->toEqual(['allocated' => 7.0])
        ->and($entries[0]->properties['attributes'])->toEqual(['allocated' => 10.5]);
});

it('recalculates the old joining year too when the date moves to another year', function () {
    $e = allocEmployee(['joining_date' => '2026-01-01']);
    $this->artisan('hrm:leave-allocate')->assertSuccessful();

    app(UpdateEmployee::class)->handle($e, ['joining_date' => '2027-01-01'], userWithRole('admin'), syncAccount: false);

    // 2026 is now before joining: allocationFor gives 0.
    expect(alRows($e, 2026))->toBe(['Annual' => 0.0, 'Casual' => 0.0, 'Sick' => 0.0]);
});

it('allocates the full quota to a mid-year joiner when pro-rating is off', function () {
    Settings::set('leave', ['prorate_new_joiners' => false]);
    $joiner = allocEmployee(['joining_date' => '2026-07-15']);

    $this->artisan('hrm:leave-allocate')->assertSuccessful();

    expect(alRows($joiner, 2026))->toBe(['Annual' => 14.0, 'Casual' => 10.0, 'Sick' => 8.0]);
});

it('handles at most 1,000 people per run, reports the rest as remaining, and the next run continues', function () {
    $department = Department::factory()->create();
    $designation = Designation::factory()->create(['department_id' => $department->id]);
    Employee::factory()->count(AllocateLeave::MAX_EMPLOYEES + 2)->create([
        'joining_date' => '2024-03-01', 'department_id' => $department->id, 'designation_id' => $designation->id,
    ]);

    $this->artisan('hrm:leave-allocate')->expectsOutputToContain('rows=3000 remaining=2')->assertSuccessful();
    expect(LeaveBalance::query()->count())->toBe(3000);

    $this->artisan('hrm:leave-allocate')->expectsOutputToContain('rows=6 remaining=0')->assertSuccessful();
    expect(LeaveBalance::query()->count())->toBe(3006);
});
it('recalculates the untouched years between the old and the new joining year too', function () {
    $e = allocEmployee(['joining_date' => '2024-03-01']);
    foreach ([2024 => 11.5, 2025 => 14, 2026 => 14] as $year => $allocated) {
        LeaveBalance::query()->create(['employee_id' => $e->id, 'leave_type_id' => leaveType('Annual')->id, 'year' => $year, 'allocated' => $allocated, 'adjustment' => 0, 'opening_used' => 0]);
    }

    $update = app(UpdateEmployee::class);
    $update->handle($e, ['joining_date' => '2026-07-01'], userWithRole('admin'), syncAccount: false);

    expect(alRows($e, 2024))->toBe(['Annual' => 0.0])
        ->and(alRows($e, 2025))->toBe(['Annual' => 0.0]) // between the two joining years
        ->and(alRows($e, 2026))->toBe(['Annual' => 7.0])
        ->and($update->warnings())->toBe([]);
});

it('treats a row HR edited as touched even when it is back at the old allocation', function () {
    $e = allocEmployee(['joining_date' => '2026-07-01']);
    $this->artisan('hrm:leave-allocate')->assertSuccessful(); // Annual 7
    $admin = userWithRole('admin');
    $adjust = app(AdjustBalance::class);
    $adjust->handle($e, leaveType('Annual'), 2026, 8, 0, 0, 'Agreed extra day', true, $admin);
    $adjust->handle($e, leaveType('Annual'), 2026, 7, 0, 0, 'Extra day withdrawn', true, $admin);

    app(UpdateEmployee::class)->handle($e->fresh(), ['joining_date' => '2026-04-01'], $admin, syncAccount: false);

    expect(alRows($e, 2026))->toBe(['Annual' => 7.0, 'Casual' => 7.5, 'Sick' => 6.0]);
});

it('applies a recalculation that leaves a balance overdrawn and returns a warning', function () {
    $e = allocEmployee(['joining_date' => '2026-01-01']);
    $this->artisan('hrm:leave-allocate')->assertSuccessful(); // Annual 14
    approvedLeave($e, '2026-11-02', '2026-11-11'); // 10 days (the helper books every date)

    $update = app(UpdateEmployee::class);
    $update->handle($e, ['joining_date' => '2026-07-01'], userWithRole('admin'), syncAccount: false); // Annual 7

    expect(alRows($e, 2026)['Annual'])->toBe(7.0)
        ->and($update->warnings())->toBe(['Annual 2026 is now overdrawn by 3 days.']);
});

it('also warns when a later year fed by the carry ends overdrawn after a joining-date recalculation', function () {
    $e = allocEmployee(['joining_date' => '2026-01-01']);
    $this->artisan('hrm:leave-allocate')->assertSuccessful(); // 2026 Annual 14
    app(LeaveBalances::class)->ensureYear($e, 2027); // as approving 2027 leave would
    approvedLeave($e, '2026-11-02', '2026-11-10'); // 9 of 14 in 2026: 5 left, 5 carried into 2027
    approvedLeave($e, '2027-02-01', '2027-02-19'); // 19 in 2027: everything

    $update = app(UpdateEmployee::class);
    // Joining 1 Jul 2026: Annual 2026 becomes 7 → 7 − 9 = −2 (nothing carries), so 2027 ends 14 − 19 = −5.
    $update->handle($e, ['joining_date' => '2026-07-01'], userWithRole('admin'), syncAccount: false);

    expect($update->warnings())->toBe(['Annual 2026 is now overdrawn by 2 days.', 'Annual 2027 is now overdrawn by 5 days.']);
});

it('warns about a later year even when the recalculated year itself stays in credit', function () {
    $e = allocEmployee(['joining_date' => '2026-01-01']);
    $this->artisan('hrm:leave-allocate')->assertSuccessful();
    app(LeaveBalances::class)->ensureYear($e, 2027); // as approving 2027 leave would
    approvedLeave($e, '2026-11-02', '2026-11-03'); // 2 of 14 in 2026: 12 left, 5 carried
    approvedLeave($e, '2027-02-01', '2027-02-19'); // 19 in 2027

    $update = app(UpdateEmployee::class);
    // Joining 1 Oct 2026: Annual 2026 becomes 3.5 → 1.5 left, 1.5 carried, 2027 ends 14 + 1.5 − 19 = −3.5.
    $update->handle($e, ['joining_date' => '2026-10-01'], userWithRole('admin'), syncAccount: false);

    expect($update->warnings())->toBe(['Annual 2027 is now overdrawn by 3.5 days.']);
});
