<?php

use App\Casts\NaiveDateTime;
use App\Models\Attendance;
use App\Models\AttendanceCorrection;
use App\Models\DevicePunch;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\Shift;
use App\Support\Attendance\AttendanceClock;
use App\Support\EmployeeAccess;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Runs the app as a server whose own clock zone is $zone (config and PHP default both). */
function nvZone(string $zone): void
{
    config(['app.timezone' => $zone]);
    date_default_timezone_set($zone);
}

/** Creates one attendance day per date for one employee. */
function nvDays(array $dates): Employee
{
    $employee = Employee::factory()->create();

    foreach ($dates as $date) {
        Attendance::factory()->create(['employee_id' => $employee->id, 'date' => $date]);
    }

    return $employee;
}

/** @return list<string> */
function nvDates(iterable $rows): array
{
    $out = [];

    foreach ($rows as $row) {
        $out[] = $row->date->format('Y-m-d');
    }

    sort($out);

    return $out;
}

// ---------------------------------------------------------------- date queries

it('finds a day with onDate from a string, a Carbon, or a value read from the cast', function () {
    nvZone('UTC');
    nvDays(['2026-10-31', '2026-11-01', '2026-11-02']);

    $read = Attendance::query()->where('date', '2026-11-01')->firstOrFail()->date;

    foreach (['2026-11-01', CarbonImmutable::parse('2026-11-01'), app(AttendanceClock::class)->now()->setDate(2026, 11, 1), $read, $read->endOfDay()] as $date) {
        expect(nvDates(Attendance::query()->onDate($date)->get()))->toBe(['2026-11-01']);
    }
});

it('returns every day of a month with betweenDates, the first and the last included', function () {
    nvDays(['2026-10-31', '2026-11-01', '2026-11-15', '2026-11-30', '2026-12-01']);
    $expected = ['2026-11-01', '2026-11-15', '2026-11-30'];

    $first = CarbonImmutable::parse('2026-11-01', 'Asia/Karachi');

    expect(nvDates(Attendance::query()->betweenDates('2026-11-01', '2026-11-30')->get()))->toBe($expected)
        ->and(nvDates(Attendance::query()->betweenDates($first, $first->endOfMonth())->get()))->toBe($expected)
        ->and(nvDates(Attendance::query()->betweenDates($first->startOfMonth(), $first->endOfMonth()->startOfDay())->get()))->toBe($expected);

    $read = Attendance::query()->where('date', '2026-11-15')->firstOrFail()->date;
    expect(nvDates(Attendance::query()->betweenDates($read->startOfMonth(), $read->endOfMonth())->get()))->toBe($expected);
});

it('has the same date scopes on holidays and correction requests', function () {
    Holiday::factory()->create(['date' => '2026-11-01']);
    Holiday::factory()->create(['date' => '2026-11-30']);
    Holiday::factory()->create(['date' => '2026-12-01']);
    AttendanceCorrection::factory()->create(['date' => '2026-11-01']);
    AttendanceCorrection::factory()->create(['date' => '2026-10-31']);

    $first = CarbonImmutable::parse('2026-11-01', 'Asia/Karachi');

    expect(nvDates(Holiday::query()->betweenDates($first, $first->endOfMonth())->get()))->toBe(['2026-11-01', '2026-11-30'])
        ->and(nvDates(Holiday::query()->onDate($first)->get()))->toBe(['2026-11-01'])
        ->and(nvDates(AttendanceCorrection::query()->betweenDates($first, $first->endOfMonth())->get()))->toBe(['2026-11-01'])
        ->and(nvDates(AttendanceCorrection::query()->onDate('2026-10-31')->get()))->toBe(['2026-10-31']);
});

it('refuses a date scope value that is not a date', function () {
    Attendance::query()->onDate('yesterday')->get();
})->throws(InvalidArgumentException::class);

// ---------------------------------------------------------------- serialisation

it('serialises cast values naively in only(), direct props and after the usual operations', function () {
    nvZone('UTC');
    $a = Attendance::factory()->create([
        'date' => '2026-11-24', 'clock_in' => '2026-11-24 00:30:00', 'clock_out' => '2026-11-24 18:00:00',
    ]);
    $read = Attendance::query()->findOrFail($a->id);

    expect(json_decode(json_encode($read->only(['date', 'clock_in', 'clock_out'])), true))
        ->toBe(['date' => '2026-11-24', 'clock_in' => '2026-11-24 00:30:00', 'clock_out' => '2026-11-24 18:00:00'])
        ->and(json_encode($read->date))->toBe('"2026-11-24"')
        ->and(json_encode($read->date->addDay()))->toBe('"2026-11-25"')
        ->and(json_encode($read->date->startOfMonth()))->toBe('"2026-11-01"')
        ->and(json_encode($read->clock_in->addMinutes(30)))->toBe('"2026-11-24 01:00:00"')
        ->and(json_encode($read->clock_in->startOfDay()))->toBe('"2026-11-24 00:00:00"')
        ->and($read->date)->toBeInstanceOf(CarbonImmutable::class)
        ->and($read->clock_in)->toBeInstanceOf(CarbonImmutable::class)
        // toArray() is unchanged.
        ->and($read->toArray())->toMatchArray(['date' => '2026-11-24', 'clock_in' => '2026-11-24 00:30:00']);
});

it('serialises holiday, correction and punch values naively too', function () {
    nvZone('UTC');
    $holiday = Holiday::factory()->create(['date' => '2026-12-25']);
    $correction = AttendanceCorrection::factory()->create(['date' => '2026-11-03']);
    $punch = punch('777', '2026-11-24 00:01:00');

    expect(json_encode(Holiday::query()->findOrFail($holiday->id)->only(['date'])))->toBe('{"date":"2026-12-25"}')
        ->and(json_encode(AttendanceCorrection::query()->findOrFail($correction->id)->only(['date'])))->toBe('{"date":"2026-11-03"}')
        ->and(json_encode(DevicePunch::query()->findOrFail($punch->id)->only(['punch_time'])))->toBe('{"punch_time":"2026-11-24 00:01:00"}');
});

// ---------------------------------------------------------------- zone conversion on write

it('stores now() as the company wall clock even when the server zone differs', function () {
    nvZone('UTC');
    $this->travelTo(CarbonImmutable::parse('2026-11-24 20:30:00', 'UTC')); // 2026-11-25 01:30 in Asia/Karachi

    $a = Attendance::factory()->create(['date' => now(), 'clock_in' => now(), 'clock_out' => null]);

    expect($a->getAttributes()['date'])->toBe('2026-11-25')
        ->and($a->getAttributes()['clock_in'])->toBe('2026-11-25 01:30:00')
        ->and(DB::table('attendances')->where('id', $a->id)->value('date'))->toBe('2026-11-25');
});

it('stores values read from the casts and from the attendance clock unchanged', function () {
    nvZone('UTC');
    $a = Attendance::factory()->create(['date' => '2026-11-24', 'clock_in' => '2026-11-24 23:59:59', 'clock_out' => null]);
    $read = Attendance::query()->findOrFail($a->id);

    $read->clock_out = $read->clock_in;
    $read->date = $read->date->addDay();
    $read->save();

    expect($read->getAttributes()['clock_out'])->toBe('2026-11-24 23:59:59')
        ->and($read->getAttributes()['date'])->toBe('2026-11-25')
        ->and(NaiveDateTime::normalise(app(AttendanceClock::class)->now()->setDateTime(2026, 11, 24, 7, 5, 0)))->toBe('2026-11-24 07:05:00');
});

// ---------------------------------------------------------------- default shift guard

it('refuses to clear the flag of the only default shift outside makeDefault()', function () {
    $general = Shift::default();
    $general->is_default = false;

    expect(fn () => $general->save())->toThrow(LogicException::class)
        ->and(fn () => Shift::default()->update(['is_default' => false]))->toThrow(LogicException::class)
        ->and(Shift::query()->where('is_default', true)->pluck('id')->all())->toBe([$general->id])
        ->and(Shift::count())->toBe(1);

    // Other edits of the default, and moving the default with makeDefault(), still work.
    Shift::default()->update(['grace_minutes' => 5]);
    $late = Shift::factory()->create(['name' => 'Late']);
    Shift::makeDefault($late);

    expect(Shift::query()->where('is_default', true)->pluck('id')->all())->toBe([$late->id])
        ->and(Shift::query()->findOrFail($general->id)->grace_minutes)->toBe(5);
});

// ---------------------------------------------------------------- default shift migration

it('keeps the default shift data migration independent of DefaultShift', function () {
    $source = file_get_contents(database_path('migrations/2026_11_24_200100_create_default_shift.php'));

    expect($source)->not->toContain('DefaultShift::')
        ->not->toContain('use App\Support\Attendance\DefaultShift');
});

// ---------------------------------------------------------------- the punch() helper

it('gives an employee a machine user ID that nobody holds and no punch uses', function () {
    $a = Employee::factory()->create(['device_user_id' => null]);
    $b = Employee::factory()->create(['device_user_id' => (string) (1000 + $a->id)]);
    $c = Employee::factory()->create(['device_user_id' => null]);
    $stray = punch((string) (1000 + $c->id), '2026-11-24 08:00'); // an unknown user's punch

    $pa = punch($a, '2026-11-24 09:00');
    $pc = punch($c, '2026-11-24 09:00');

    expect($pa->employee_id)->toBe($a->id)
        ->and($pa->device_user_id)->not->toBe($b->device_user_id)
        ->and($pc->employee_id)->toBe($c->id)
        ->and($pc->device_user_id)->not->toBe($stray->device_user_id)
        ->and($stray->fresh()->employee_id)->toBeNull();
});

// ---------------------------------------------------------------- delete blockers

it('blocks deleting an employee who has attendance correction requests', function () {
    $admin = userWithRole('admin');
    $employee = Employee::factory()->create();
    $access = new EmployeeAccess($admin);

    AttendanceCorrection::factory()->create(['employee_id' => $employee->id]);

    expect($access->deleteBlockers($employee))->toBe(['This employee has attendance correction requests. Set the status to Inactive, Resigned or Terminated instead.'])
        ->and($access->canDelete($employee))->toBeFalse();
});
