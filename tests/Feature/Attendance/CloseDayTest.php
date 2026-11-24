<?php

use App\Models\Attendance;
use App\Models\Department;
use App\Models\Designation;
use App\Models\Employee;
use App\Models\Holiday;
use App\Support\Attendance\AttendanceRebuilder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/*
| Default shift 09:00-18:00, Saturday and Sunday off. 2026-11-23 is a Monday, 2026-11-21/22 the weekend before.
| "Now" is 2026-11-24 00:30 in Karachi (the nightly run) unless a test says otherwise.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-24 00:30:00', 'Asia/Karachi'));
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

    $this->travelTo(CarbonImmutable::parse('2026-11-24 00:30:00', 'Asia/Karachi'));
    $this->artisan('hrm:attendance-close', ['date' => '2026-11-23'])->assertSuccessful();

    expect(cdRow($single, '2026-11-23')->only(['status', 'late_minutes', 'needs_attention', 'closed']))
        ->toBe(['status' => 'late', 'late_minutes' => 40, 'needs_attention' => true, 'closed' => true])
        ->and(cdRow($full, '2026-11-23')->only(['needs_attention', 'closed']))
        ->toBe(['needs_attention' => false, 'closed' => true]);
});

it('never touches a manual row', function () {
    $e = cdEmployee();
    $manual = Attendance::factory()->create(['employee_id' => $e->id, 'date' => '2026-11-23', 'is_manual' => true, 'needs_rebuild' => true]);
    punch($e, '2026-11-23 10:00:00');
    $before = (array) DB::table('attendances')->where('id', $manual->id)->first();

    $this->artisan('hrm:attendance-close', ['date' => '2026-11-23'])->assertSuccessful();

    expect((array) DB::table('attendances')->where('id', $manual->id)->first())->toBe($before);
});

it('changes nothing when run twice', function () {
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

    // 19:30 UTC is 00:30 on the 25th in Karachi (UTC would still say the 24th): the 24th is closed now.
    $this->travelTo(CarbonImmutable::parse('2026-11-24 19:30:00', 'UTC'));
    $this->artisan('hrm:attendance-close')->assertSuccessful();
    expect(cdRow($e, '2026-11-24')?->status)->toBe('absent')
        ->and(cdRow($e, '2026-11-25'))->toBeNull();
});

it('catches up the previous 7 days, and no further', function () {
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

    $this->travelTo(CarbonImmutable::parse('2026-12-01 00:30:00', 'Asia/Karachi'));
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
