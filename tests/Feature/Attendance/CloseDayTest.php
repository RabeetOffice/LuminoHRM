<?php

use App\Models\Attendance;
use App\Models\Department;
use App\Models\Designation;
use App\Models\Employee;
use App\Models\Holiday;
use App\Support\Attendance\AttendanceClock;
use App\Support\Attendance\AttendanceRebuilder;
use App\Support\Attendance\DayCalculator;
use App\Support\Attendance\RebuildMarks;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/*
| Default shift 09:00-18:00, Saturday and Sunday off. 2026-11-23 is a Monday, 2026-11-21/22 the weekend before.
| "Now" is 2026-11-24 04:30 in Karachi (the first hourly run after the 04:00 day cutoff, which closes the 23rd) unless a
| test says otherwise. Attendance started on 2026-01-01
| (settings('attendance.start_date')) unless a test says otherwise.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-24 04:30:00', 'Asia/Karachi'));
    Settings::set('attendance', ['start_date' => '2026-01-01']);
});

/** @param  array<string, mixed>  $attrs */
function cdEmployee(array $attrs = []): Employee
{
    return Employee::factory()->create(['joining_date' => '2026-01-01', ...$attrs]);
}

function cdRow(Employee $e, string $date): ?Attendance
{
    return Attendance::query()->where('employee_id', $e->id)->where('date', $date)->first();
}

/** Every attendance row exactly as stored, by id. */
function cdSnapshot(): array
{
    return DB::table('attendances')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
}

it('creates the missing records of a past day and marks the day closed', function () {
    $absent = cdEmployee();
    $exempt = cdEmployee(['attendance_exempt' => true]);
    $notYet = cdEmployee(['joining_date' => '2026-11-24']);
    $gone = cdEmployee(['last_working_day' => '2026-11-20', 'status' => 'resigned']);
    $leftNoDate = cdEmployee(['status' => 'inactive']);
    $worked = cdEmployee();
    punch($worked, '2026-11-23 09:00:00');
    punch($worked, '2026-11-23 18:00:00');

    $this->artisan('hrm:attendance-close', ['date' => '2026-11-23'])->assertSuccessful();

    expect(cdRow($absent, '2026-11-23')->only(['status', 'closed', 'needs_attention', 'is_manual']))
        ->toBe(['status' => 'absent', 'closed' => true, 'needs_attention' => false, 'is_manual' => false])
        ->and(cdRow($exempt, '2026-11-23')->status)->toBe('present')
        ->and(cdRow($worked, '2026-11-23')->only(['status', 'worked_minutes', 'closed']))
        ->toBe(['status' => 'present', 'worked_minutes' => 540, 'closed' => true])
        ->and(cdRow($notYet, '2026-11-23'))->toBeNull()
        ->and(cdRow($gone, '2026-11-23'))->toBeNull()
        ->and(cdRow($leftNoDate, '2026-11-23'))->toBeNull();

    expect(cdRow($absent, '2026-11-23')->shift_id)->not->toBeNull();
});

it('records weekend and holiday days, holiday winning on a weekend', function () {
    $e = cdEmployee();
    Holiday::factory()->create(['date' => '2026-11-22']); // a Sunday
    Holiday::factory()->create(['date' => '2026-11-20']); // a Friday

    foreach (['2026-11-20', '2026-11-21', '2026-11-22'] as $date) {
        app(AttendanceRebuilder::class)->closeDay($date);
    }

    expect(cdRow($e, '2026-11-20')->status)->toBe('holiday')
        ->and(cdRow($e, '2026-11-21')->status)->toBe('weekend')
        ->and(cdRow($e, '2026-11-22')->status)->toBe('holiday');
});

it('flags a single-punch day and closes the rows ingestion left open', function () {
    $single = cdEmployee();
    $full = cdEmployee();
    punch($single, '2026-11-23 09:40:00');
    punch($full, '2026-11-23 09:00:00');
    punch($full, '2026-11-23 18:00:00');

    // Decided during the day (open), as the every-minute rebuild would.
    $this->travelTo(CarbonImmutable::parse('2026-11-23 20:00:00', 'Asia/Karachi'));
    app(AttendanceRebuilder::class)->rebuild($single, '2026-11-23');
    app(AttendanceRebuilder::class)->rebuild($full, '2026-11-23');
    expect(cdRow($single, '2026-11-23')->only(['status', 'needs_attention', 'closed']))
        ->toBe(['status' => 'late', 'needs_attention' => false, 'closed' => false]);

    $this->travelTo(CarbonImmutable::parse('2026-11-24 04:30:00', 'Asia/Karachi'));
    $this->artisan('hrm:attendance-close', ['date' => '2026-11-23'])->assertSuccessful();

    expect(cdRow($single, '2026-11-23')->only(['status', 'late_minutes', 'needs_attention', 'closed']))
        ->toBe(['status' => 'late', 'late_minutes' => 40, 'needs_attention' => true, 'closed' => true])
        ->and(cdRow($full, '2026-11-23')->only(['needs_attention', 'closed']))
        ->toBe(['needs_attention' => false, 'closed' => true]);
});

it('never touches a manual row that is already closed', function () {
    $e = cdEmployee();
    $manual = Attendance::factory()->create(['employee_id' => $e->id, 'date' => '2026-11-23', 'is_manual' => true, 'needs_rebuild' => true, 'closed' => true]);
    punch($e, '2026-11-23 10:00:00');
    $before = (array) DB::table('attendances')->where('id', $manual->id)->first();

    $this->artisan('hrm:attendance-close', ['date' => '2026-11-23'])->assertSuccessful();

    expect((array) DB::table('attendances')->where('id', $manual->id)->first())->toBe($before);
});

it('finalises a manual row still open from its stored times with closed-day rules and its snapshot', function () {
    $e = cdEmployee();
    // Entered on the open day as 09:00-12:30 and stored with open-day values (present, no early minutes).
    $manual = Attendance::factory()->create([
        'employee_id' => $e->id, 'date' => '2026-11-23', 'is_manual' => true, 'closed' => false,
        'clock_in' => '2026-11-23 09:00:00', 'clock_out' => '2026-11-23 12:30:00', 'worked_minutes' => 210, 'note' => 'Correction request #3',
        'shift_rules' => ['shift_id' => null, 'name' => 'Old', 'start_time' => '09:00', 'end_time' => '18:00', 'grace_minutes' => 15,
            'half_day_hours' => 4.0, 'weekly_offs' => ['saturday', 'sunday']],
    ]);
    shiftFor($e, ['start_time' => '07:00', 'end_time' => '11:00']); // a later shift change must not matter
    punch($e, '2026-11-23 08:00:00');
    punch($e, '2026-11-23 20:00:00');

    $this->artisan('hrm:attendance-close', ['date' => '2026-11-23'])->assertSuccessful();

    $row = Attendance::query()->findOrFail($manual->id);
    expect($row->only(['status', 'worked_minutes', 'early_minutes', 'late_minutes', 'is_manual', 'closed', 'note']))
        ->toBe(['status' => 'half_day', 'worked_minutes' => 210, 'early_minutes' => 330, 'late_minutes' => 0, 'is_manual' => true,
            'closed' => true, 'note' => 'Correction request #3'])
        ->and($row->clock_in->format('Y-m-d H:i:s'))->toBe('2026-11-23 09:00:00')
        ->and($row->clock_out->format('Y-m-d H:i:s'))->toBe('2026-11-23 12:30:00')
        ->and($row->shift_rules['name'])->toBe('Old')
        ->and($row->shift_rules)->toHaveKeys(['overtime_min_minutes', 'duplicate_punch_minutes', 'attendance_exempt']);
});

it('finalises a still-open manual row with a short day from its literal times, not as a machine double tap', function () {
    $e = cdEmployee();
    $manual = Attendance::factory()->create([
        'employee_id' => $e->id, 'date' => '2026-11-23', 'is_manual' => true, 'closed' => false, 'status' => 'present',
        'clock_in' => '2026-11-23 09:00:00', 'clock_out' => '2026-11-23 09:40:00', 'worked_minutes' => 40,
    ]);

    $this->artisan('hrm:attendance-close', ['date' => '2026-11-23'])->assertSuccessful();

    expect(Attendance::query()->findOrFail($manual->id)->only(['status', 'worked_minutes', 'needs_attention', 'closed']))
        ->toBe(['status' => 'half_day', 'worked_minutes' => 40, 'needs_attention' => false, 'closed' => true]);
});

it('still treats two machine punches less than an hour apart as one punch', function () {
    $e = cdEmployee();
    punch($e, '2026-11-23 09:00:00');
    punch($e, '2026-11-23 09:40:00');

    $this->artisan('hrm:attendance-close', ['date' => '2026-11-23'])->assertSuccessful();

    expect(cdRow($e, '2026-11-23')->only(['status', 'worked_minutes', 'needs_attention']))
        ->toBe(['status' => 'present', 'worked_minutes' => 0, 'needs_attention' => true]);
});

it('only marks a still-open manual override row closed', function () {
    $e = cdEmployee();
    $manual = Attendance::factory()->create([
        'employee_id' => $e->id, 'date' => '2026-11-23', 'is_manual' => true, 'status_overridden' => true, 'closed' => false,
        'clock_in' => '2026-11-23 09:00:00', 'clock_out' => '2026-11-23 13:00:00', 'worked_minutes' => 240, 'status' => 'present',
    ]);
    $before = (array) DB::table('attendances')->where('id', $manual->id)->first();

    $this->artisan('hrm:attendance-close', ['date' => '2026-11-23'])->assertSuccessful();

    $after = (array) DB::table('attendances')->where('id', $manual->id)->first();
    expect((bool) $after['closed'])->toBeTrue()
        ->and(array_diff_key($after, ['closed' => 1, 'updated_at' => 1]))->toBe(array_diff_key($before, ['closed' => 1, 'updated_at' => 1]));
});

it('changes nothing when run twice', function () {
    Settings::set('attendance', ['start_date' => '2026-11-17']); // nothing older to backfill
    cdEmployee();
    cdEmployee(['attendance_exempt' => true]);
    $single = cdEmployee();
    punch($single, '2026-11-23 09:00:00');

    $this->artisan('hrm:attendance-close')->assertSuccessful();
    $first = cdSnapshot();
    expect($first)->not->toBeEmpty();

    $this->travel(10)->minutes();
    $this->artisan('hrm:attendance-close')->assertSuccessful();
    $this->artisan('hrm:attendance-close', ['date' => '2026-11-23'])->assertSuccessful();

    expect(cdSnapshot())->toBe($first);
});

it('picks the company date with the server clock in UTC', function () {
    config(['app.timezone' => 'UTC']);
    date_default_timezone_set('UTC');
    $e = cdEmployee();

    // 18:30 UTC on the 24th is 23:30 on the 24th in Karachi: the 24th is still open.
    $this->travelTo(CarbonImmutable::parse('2026-11-24 18:30:00', 'UTC'));
    $this->artisan('hrm:attendance-close')->assertSuccessful();
    expect(cdRow($e, '2026-11-23'))->not->toBeNull()
        ->and(cdRow($e, '2026-11-24'))->toBeNull();

    // 19:30 UTC is 00:30 on the 25th in Karachi: before the 04:00 cutoff the 24th is still open.
    $this->travelTo(CarbonImmutable::parse('2026-11-24 19:30:00', 'UTC'));
    $this->artisan('hrm:attendance-close')->assertSuccessful();
    expect(cdRow($e, '2026-11-24'))->toBeNull();

    // 23:30 UTC is 04:30 on the 25th in Karachi (UTC would still say the 24th): the 24th is closed now.
    $this->travelTo(CarbonImmutable::parse('2026-11-24 23:30:00', 'UTC'));
    $this->artisan('hrm:attendance-close')->assertSuccessful();
    expect(cdRow($e, '2026-11-24')?->status)->toBe('absent')
        ->and(cdRow($e, '2026-11-25'))->toBeNull();
});

it('catches up the previous 7 days in the hourly run', function () {
    Settings::set('attendance', ['start_date' => '2026-11-17']); // older days are the backfill's (tested below)
    $e = cdEmployee();

    $this->artisan('hrm:attendance-close')
        ->expectsOutput('attendance_close date=2026-11-17 written=1')
        ->expectsOutput('attendance_close date=2026-11-23 written=1')
        ->assertSuccessful();

    $dates = Attendance::query()->where('employee_id', $e->id)->orderBy('date')->get()->map(fn ($a) => $a->getRawOriginal('date'))->all();
    expect($dates)->toBe(['2026-11-17', '2026-11-18', '2026-11-19', '2026-11-20', '2026-11-21', '2026-11-22', '2026-11-23']);
    expect(Attendance::query()->where('closed', false)->count())->toBe(0);
});

it('closes an explicit past date only, and refuses today or later', function (string $date, bool $ok) {
    $e = cdEmployee();

    $this->artisan('hrm:attendance-close', ['date' => $date])->assertExitCode($ok ? 0 : 1)->run();

    expect(Attendance::query()->where('employee_id', $e->id)->count())->toBe($ok ? 1 : 0);
})->with([
    'a week ago' => ['2026-11-16', true],
    'today' => ['2026-11-24', false],
    'tomorrow' => ['2026-11-25', false],
    'not a date' => ['24-11-2026', false],
]);

it('closes 55 employees x 31 days with a query count that does not grow with the number of employees', function () {
    $department = Department::factory()->create();
    $designation = Designation::factory()->create(['department_id' => $department->id]);
    $make = fn (int $n) => Employee::factory()->count($n)->create([
        'joining_date' => '2026-01-01', 'department_id' => $department->id, 'designation_id' => $designation->id,
    ]);

    $this->travelTo(CarbonImmutable::parse('2026-12-01 04:30:00', 'Asia/Karachi'));
    $rebuilder = app(AttendanceRebuilder::class);

    $counter = new ArrayObject(['n' => 0]);
    DB::listen(function () use ($counter) {
        $counter['n']++;
    });

    $queriesFor = function (callable $work) use ($counter): int {
        $counter['n'] = 0;
        $work();

        return $counter['n'];
    };

    // One day with 5 employees, then a different day with 55 (some with punches): same number of queries.
    $first = $make(5);
    $rebuilder->closeDay('2026-09-30'); // warm-up: the settings are read once per process
    $small = $queriesFor(fn () => $rebuilder->closeDay('2026-10-01'));

    $rest = $make(50);
    $everyone = $first->concat($rest);
    foreach ($everyone->take(20) as $employee) {
        punch($employee, '2026-10-02 09:00:00');
        punch($employee, '2026-10-02 18:00:00');
    }
    $large = $queriesFor(fn () => $rebuilder->closeDay('2026-10-02'));

    expect($large)->toBe($small)->and($small)->toBeLessThanOrEqual(15);

    // The whole of November: linear in days with the same small constant.
    $month = $queriesFor(function () use ($rebuilder) {
        for ($d = 1; $d <= 30; $d++) {
            $rebuilder->closeDay(sprintf('2026-11-%02d', $d));
        }
    });

    expect($month)->toBeLessThanOrEqual(30 * $small);
    expect(Attendance::query()->where('date', '>=', '2026-11-01')->count())->toBe(55 * 30)
        ->and(Attendance::query()->where('date', '2026-10-02')->where('status', 'present')->count())->toBe(20);
});

/** @return Collection<int, Employee> */
function cdStaff(int $n): Collection
{
    $department = Department::factory()->create();
    $designation = Designation::factory()->create(['department_id' => $department->id]);

    return Employee::factory()->count($n)->create([
        'joining_date' => '2026-01-01', 'department_id' => $department->id, 'designation_id' => $designation->id,
    ]);
}

it('creates no rows at all on the first close while attendance has not started', function () {
    Settings::set('attendance', ['start_date' => null]);
    cdStaff(55);

    $this->artisan('hrm:attendance-close')->assertSuccessful();

    expect(Attendance::query()->count())->toBe(0);
});

it('still decides days with punches while attendance has not started', function () {
    Settings::set('attendance', ['start_date' => null]);
    $staff = cdStaff(3);
    punch($staff[0], '2026-11-23 09:00:00');
    punch($staff[0], '2026-11-23 18:00:00');

    $this->artisan('hrm:attendance-close')->assertSuccessful();

    expect(Attendance::query()->count())->toBe(1)
        ->and(cdRow($staff[0], '2026-11-23')->only(['status', 'closed']))->toBe(['status' => 'present', 'closed' => true]);
});

it('records absences only from the attendance start date', function () {
    Settings::set('attendance', ['start_date' => '2026-11-19']); // a Thursday
    $staff = cdStaff(55);

    $this->artisan('hrm:attendance-close')->assertSuccessful();

    expect(Attendance::query()->where('date', '<', '2026-11-19')->count())->toBe(0)
        ->and(Attendance::query()->count())->toBe(55 * 5)
        ->and(Attendance::query()->where('status', 'absent')->count())->toBe(55 * 3) // 19, 20, 23
        ->and(Attendance::query()->where('status', 'weekend')->count())->toBe(55 * 2) // 21, 22
        ->and(cdRow($staff[0], '2026-11-19')->status)->toBe('absent');
});

it('decides the pending rows (status null) of a closed day and leaves none behind', function () {
    $worked = cdEmployee();
    $relinked = cdEmployee();
    punch($worked, '2026-11-23 09:00:00');
    punch($worked, '2026-11-23 18:00:00');
    // Bare rows as punch ingestion makes them; the second one's punch has since gone to someone else.
    foreach ([$worked, $relinked] as $e) {
        Attendance::query()->create(['employee_id' => $e->id, 'date' => '2026-11-23', 'status' => null, 'needs_rebuild' => true]);
    }

    $this->artisan('hrm:attendance-close', ['date' => '2026-11-23'])->assertSuccessful();

    expect(Attendance::query()->where('date', '2026-11-23')->whereNull('status')->count())->toBe(0)
        ->and(cdRow($worked, '2026-11-23')->only(['status', 'closed']))->toBe(['status' => 'present', 'closed' => true])
        ->and(cdRow($relinked, '2026-11-23')->only(['status', 'closed']))->toBe(['status' => 'absent', 'closed' => true]);
});

it('closes everyone else when one employee-day cannot be decided, and flags that one', function () {
    Log::spy();
    $broken = cdEmployee();
    $fine = cdEmployee();
    $bad = shiftFor($broken, ['name' => 'Broken']);
    DB::table('shifts')->where('id', $bad->id)->update(['start_time' => '18:00:00', 'end_time' => '09:00:00']);
    punch($broken, '2026-11-23 09:00:00');
    Attendance::query()->create(['employee_id' => $broken->id, 'date' => '2026-11-23', 'status' => null, 'needs_rebuild' => true]);

    $this->artisan('hrm:attendance-close', ['date' => '2026-11-23'])->assertSuccessful();

    expect(cdRow($fine, '2026-11-23')->only(['status', 'closed']))->toBe(['status' => 'absent', 'closed' => true])
        ->and(cdRow($broken, '2026-11-23')->only(['status', 'needs_attention', 'needs_rebuild', 'closed']))
        ->toBe(['status' => null, 'needs_attention' => true, 'needs_rebuild' => false, 'closed' => false]);
    Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context) => $context['employee_id'] === $broken->id)->atLeast()->once();
});

it('closes the later days when one day of the catch-up fails', function () {
    Settings::set('attendance', ['start_date' => '2026-11-17']);
    Log::spy();
    $e = cdEmployee();
    $real = new AttendanceRebuilder(app(DayCalculator::class), app(AttendanceClock::class));
    $rebuilder = Mockery::mock(AttendanceRebuilder::class, [app(DayCalculator::class), app(AttendanceClock::class)]);
    $rebuilder->shouldReceive('closeDay')->andReturnUsing(
        fn (string $date) => $date === '2026-11-19' ? throw new RuntimeException('boom') : $real->closeDay($date),
    );
    $this->app->instance(AttendanceRebuilder::class, $rebuilder);

    $this->artisan('hrm:attendance-close')
        ->expectsOutput('attendance_close date=2026-11-19 failed=RuntimeException')
        ->expectsOutput('attendance_close date=2026-11-23 written=1')
        ->assertFailed();

    expect(Attendance::query()->where('employee_id', $e->id)->count())->toBe(6)
        ->and(cdRow($e, '2026-11-19'))->toBeNull()
        ->and(cdRow($e, '2026-11-20')->status)->toBe('absent');
    Log::shouldHaveReceived('error')->with('Attendance day close failed', ['exception' => RuntimeException::class, 'date' => '2026-11-19'])->once();
});

// Measured: 13 queries to close a day of 55 employees (10 before Phase 4; the leave days are one more read per batch;
// Phase 5 adds two payroll lock reads: closeDay's own check of the date and the batch's locked months),
// whether the close creates the rows or ingestion already made pending ones (the updates are one bulk upsert per 200
// rows, not one UPDATE per row).
it('closes a day whose rows ingestion already created with the same queries as one it creates', function () {
    $staff = cdStaff(55);
    $this->travelTo(CarbonImmutable::parse('2026-12-01 04:30:00', 'Asia/Karachi'));
    $rebuilder = app(AttendanceRebuilder::class);

    $counter = new ArrayObject(['n' => 0]);
    DB::listen(function () use ($counter) {
        $counter['n']++;
    });
    $queriesFor = function (callable $work) use ($counter): int {
        $counter['n'] = 0;
        $work();

        return $counter['n'];
    };

    $rebuilder->closeDay('2026-10-01'); // warm-up: the settings are read once per process
    $created = $queriesFor(fn () => $rebuilder->closeDay('2026-10-02'));

    // Every one of the 55 has an open pending row (status null) made by ingestion; 20 have both punches.
    foreach ($staff as $i => $employee) {
        if ($i < 20) {
            punch($employee, '2026-10-05 09:00:00');
            punch($employee, '2026-10-05 18:00:00');
        }
        Attendance::query()->create(['employee_id' => $employee->id, 'date' => '2026-10-05', 'status' => null, 'needs_rebuild' => true]);
    }
    $existing = $queriesFor(fn () => $rebuilder->closeDay('2026-10-05'));

    // 14: Phase 5 adds closeDay's own lock read, the batch's locked months and the safety net's re-read of the lock.
    expect($existing)->toBe($created)->and($created)->toBeLessThanOrEqual(14)
        ->and(Attendance::query()->where('date', '2026-10-05')->whereNull('status')->count())->toBe(0)
        ->and(Attendance::query()->where('date', '2026-10-05')->where('closed', true)->count())->toBe(55)
        ->and(Attendance::query()->where('date', '2026-10-05')->where('status', 'present')->count())->toBe(20);

});

it('keeps a day open until the day cutoff: a 02:00 clock-out arriving before the close is in the closed result', function () {
    $e = cdEmployee();
    punch($e, '2026-11-23 09:00:00');

    // 00:30 on the 24th: the 23rd's punch window runs to 04:00, so it is still open and nothing closes it.
    $this->travelTo(CarbonImmutable::parse('2026-11-24 00:30:00', 'Asia/Karachi'));
    expect(app(AttendanceClock::class)->attendanceToday())->toBe('2026-11-23');
    app(AttendanceRebuilder::class)->rebuild($e, '2026-11-23');
    $this->artisan('hrm:attendance-close')->expectsOutput('attendance_close date=2026-11-22 written=1')->assertSuccessful();
    $this->artisan('hrm:attendance-close', ['date' => '2026-11-23'])->assertFailed();
    expect(cdRow($e, '2026-11-23')->only(['status', 'closed', 'needs_attention']))
        ->toBe(['status' => 'present', 'closed' => false, 'needs_attention' => false]);

    // The late clock-out arrives at 02:00 (the 23rd's attendance day) and the every-minute rebuild runs.
    $this->travelTo(CarbonImmutable::parse('2026-11-24 02:00:30', 'Asia/Karachi'));
    $out = punch($e, '2026-11-24 02:00:00');
    expect($out->punch_date)->toBe('2026-11-23');
    app(AttendanceRebuilder::class)->rebuild($e, '2026-11-23');
    expect(cdRow($e, '2026-11-23')->closed)->toBeFalse();

    // 04:30: the first hourly run after the cutoff closes the 23rd with its clock-out.
    $this->travelTo(CarbonImmutable::parse('2026-11-24 04:30:00', 'Asia/Karachi'));
    $this->artisan('hrm:attendance-close')->expectsOutput('attendance_close date=2026-11-23 written=1')->assertSuccessful();

    expect(cdRow($e, '2026-11-23')->getRawOriginal('clock_out'))->toBe('2026-11-24 02:00:00')
        ->and(cdRow($e, '2026-11-23')->only(['status', 'worked_minutes', 'closed', 'needs_attention']))
        ->toBe(['status' => 'present', 'worked_minutes' => 1020, 'closed' => true, 'needs_attention' => false]);
});

it('flags open days from the attendance day of now when a shift changes after midnight', function () {
    $e = cdEmployee();
    punch($e, '2026-11-23 09:00:00');
    $this->travelTo(CarbonImmutable::parse('2026-11-24 00:30:00', 'Asia/Karachi'));
    app(AttendanceRebuilder::class)->rebuild($e, '2026-11-23');

    expect(RebuildMarks::forEmployee($e->id))->toBe(1)
        ->and(cdRow($e, '2026-11-23')->needs_rebuild)->toBeTrue();
});

it('finalises a still-open manual row with only a late clock-in as a late clock-in, not a clock-out', function () {
    $e = cdEmployee();
    $manual = Attendance::factory()->create([
        'employee_id' => $e->id, 'date' => '2026-11-23', 'is_manual' => true, 'closed' => false, 'status' => 'late',
        'clock_in' => '2026-11-23 14:00:00', 'clock_out' => null, 'late_minutes' => 300,
    ]);

    $this->artisan('hrm:attendance-close', ['date' => '2026-11-23'])->assertSuccessful();

    $row = Attendance::query()->findOrFail($manual->id);
    expect($row->only(['status', 'late_minutes', 'early_minutes', 'needs_attention', 'closed']))
        ->toBe(['status' => 'late', 'late_minutes' => 300, 'early_minutes' => 0, 'needs_attention' => true, 'closed' => true])
        ->and($row->getRawOriginal('clock_in'))->toBe('2026-11-23 14:00:00')
        ->and($row->getRawOriginal('clock_out'))->toBeNull();
});

// ---------------------------------------------------------------- backfill of older days (I-2)

/** Runs the hourly close and returns how many queries it ran. */
function cdCountedClose(): int
{
    $counter = new ArrayObject(['n' => 0]);
    DB::listen(function () use ($counter) {
        $counter['n']++;
    });

    test()->artisan('hrm:attendance-close')->assertSuccessful();

    return $counter['n'];
}

it('backfills every day from a start date set 20 days back, a bounded number of days per run', function () {
    Settings::set('attendance', ['start_date' => '2026-11-04']);
    $staff = cdStaff(3);
    punch($staff[0], '2026-11-05 09:00:00');
    punch($staff[0], '2026-11-05 18:00:00');

    // Run 1: the 7 recent days, then the 10 oldest missing days; 3 remain for the next run.
    $this->artisan('hrm:attendance-close')
        ->expectsOutput('attendance_close date=2026-11-23 written=3')
        ->expectsOutput('attendance_backfill date=2026-11-04 written=3')
        ->expectsOutput('attendance_backfill date=2026-11-13 written=3')
        ->doesntExpectOutput('attendance_backfill date=2026-11-14 written=3')
        ->expectsOutput('attendance_backfill remaining=3')
        ->assertSuccessful();

    $this->travel(1)->hours();
    $this->artisan('hrm:attendance-close')
        ->expectsOutput('attendance_backfill date=2026-11-14 written=3')
        ->expectsOutput('attendance_backfill date=2026-11-16 written=3')
        ->doesntExpectOutput('attendance_backfill remaining=0')
        ->assertSuccessful();

    foreach ($staff as $e) {
        $dates = Attendance::query()->where('employee_id', $e->id)->orderBy('date')->get()->map(fn ($a) => $a->getRawOriginal('date'))->all();
        expect($dates)->toHaveCount(20)->and($dates[0])->toBe('2026-11-04')->and(end($dates))->toBe('2026-11-23');
    }

    expect(cdRow($staff[0], '2026-11-05')->status)->toBe('present')
        ->and(cdRow($staff[1], '2026-11-05')->status)->toBe('absent')
        ->and(cdRow($staff[1], '2026-11-07')->status)->toBe('weekend')
        ->and(Attendance::query()->where('closed', false)->count())->toBe(0);
});

it('never looks further back than 62 days, nor before the start date', function () {
    Settings::set('attendance', ['start_date' => '2026-01-01']);
    $e = cdEmployee();

    foreach (range(1, 6) as $run) {
        $this->artisan('hrm:attendance-close')->assertSuccessful();
    }

    $first = Attendance::query()->where('employee_id', $e->id)->orderBy('date')->first()->getRawOriginal('date');
    expect($first)->toBe('2026-09-23') // 2026-11-24 - 62 days
        ->and(Attendance::query()->where('employee_id', $e->id)->count())->toBe(62);
});

it('backfills the days one person\'s window gained, and never re-decides closed days', function () {
    Settings::set('attendance', ['start_date' => '2026-11-01']);
    $e = cdEmployee(['joining_date' => '2026-11-18']);
    $other = cdEmployee();
    foreach (range(1, 3) as $run) {
        $this->artisan('hrm:attendance-close')->assertSuccessful();
    }
    expect(Attendance::query()->where('employee_id', $e->id)->count())->toBe(6); // 18th to 23rd
    $closed = cdRow($other, '2026-11-10');
    // A later shift change must not reach a day that is already closed.
    shiftFor($other, ['start_time' => '07:00', 'end_time' => '11:00']);
    $before = cdSnapshot();

    // The joining date was entered wrongly: really the 2nd.
    $e->forceFill(['joining_date' => '2026-11-02'])->save();
    $this->artisan('hrm:attendance-close')->expectsOutput('attendance_backfill date=2026-11-02 written=1')
        ->expectsOutput('attendance_backfill remaining=5')->assertSuccessful();
    $this->artisan('hrm:attendance-close')->expectsOutput('attendance_backfill date=2026-11-16 written=1')->assertSuccessful();

    expect(Attendance::query()->where('employee_id', $e->id)->count())->toBe(22)
        ->and(cdRow($e, '2026-11-10')->status)->toBe('absent');
    $unchanged = collect(cdSnapshot())->filter(fn ($r) => $r['employee_id'] === $other->id)->values()->all();
    expect($unchanged)->toBe(collect($before)->filter(fn ($r) => $r['employee_id'] === $other->id)->values()->all())
        ->and(cdRow($other, '2026-11-10')->getRawOriginal('shift_rules'))->toBe($closed->getRawOriginal('shift_rules'));
});

it('backfills a leaver set back to active and does not backfill a leaver without a last working day', function () {
    Settings::set('attendance', ['start_date' => '2026-11-10']);
    $gone = cdEmployee(['status' => 'inactive']);
    foreach (range(1, 2) as $run) {
        $this->artisan('hrm:attendance-close')->assertSuccessful();
    }
    expect(Attendance::query()->where('employee_id', $gone->id)->count())->toBe(0);

    $gone->forceFill(['status' => 'active'])->save();
    foreach (range(1, 2) as $run) {
        $this->artisan('hrm:attendance-close')->assertSuccessful();
    }
    expect(Attendance::query()->where('employee_id', $gone->id)->count())->toBe(14); // 10th to 23rd
});

it('stays idle and writes nothing while the start date is unset, within a fixed query budget', function () {
    Settings::set('attendance', ['start_date' => null]);
    cdStaff(3);
    cdCountedClose(); // warm-up: the settings are read once per process

    $few = cdCountedClose();
    cdStaff(40);
    $many = cdCountedClose();

    expect(Attendance::query()->count())->toBe(0)
        ->and($many)->toBe($few)
        ->and($few)->toBeLessThanOrEqual(7 * 7); // 7 days, 7 queries each (one is the safety net's payroll lock re-read)
});

it('costs three extra queries when nothing older is missing', function () {
    cdStaff(5);
    Settings::set('attendance', ['start_date' => '2026-11-17']); // no backfill range at all
    cdCountedClose();
    $recentOnly = cdCountedClose();

    Settings::set('attendance', ['start_date' => '2026-11-04']);
    foreach (range(1, 2) as $run) {
        $this->artisan('hrm:attendance-close')->assertSuccessful();
    }
    $rows = Attendance::query()->count();

    // The backfill's candidates, its grouped count and (Phase 5) the locked payroll months of the range.
    expect(cdCountedClose())->toBe($recentOnly + 3)
        ->and(Attendance::query()->count())->toBe($rows);
});
