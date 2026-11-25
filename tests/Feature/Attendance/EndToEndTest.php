<?php

use App\Actions\Employees\UpdateEmployee;
use App\Models\Attendance;
use App\Models\AttendanceCorrection;
use App\Models\Device;
use App\Models\DevicePunch;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\Shift;
use App\Support\Attendance\AttendanceClock;
use App\Support\Attendance\MonthGrid;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/*
| Whole flows across the attendance pieces, through the real entry points (the /iclock endpoint, the scheduled commands,
| the screens' routes). "Now" is Tuesday 2026-11-24 12:00 in Karachi; attendance started on Monday 2026-11-16. The
| default shift is 09:00-18:00, 15 minutes grace, 4 h half day, Saturday and Sunday off; the day cutoff is 04:00.
*/

beforeEach(function () {
    $this->withoutVite();
    config(['hrm.devices.allowed_serials' => []]);
    $this->travelTo(CarbonImmutable::parse('2026-11-24 12:00:00', 'Asia/Karachi'));
    Settings::set('attendance', ['start_date' => '2026-11-16']);
    Mail::fake();
    $this->device = Device::factory()->create(['serial_no' => 'E2ESN01', 'name' => 'Front door']);
    $this->excelTemp = sys_get_temp_dir().'/lumino-e2e-'.bin2hex(random_bytes(6));
    config(['excel.temporary_files.local_path' => $this->excelTemp]);
});

afterEach(function () {
    File::deleteDirectory($this->excelTemp);
});

/** POSTs ATTLOG lines ([pin, "Y-m-d H:i:s"]) to the machine endpoint, as the MB20 does. */
function e2eUpload(array $lines): TestResponse
{
    $body = implode('', array_map(fn (array $l) => "{$l[0]}\t{$l[1]}\t0\t1\t0\t0\n", $lines));

    return test()->call('POST', '/iclock/cdata?SN=E2ESN01&table=ATTLOG', [], [], [], ['CONTENT_TYPE' => 'text/plain'], $body);
}

/**
 * The totals of one employee in the downloaded monthly export, by heading.
 *
 * @return array<string, mixed>
 */
function e2eExportTotals(TestResponse $response, string $code): array
{
    $directory = (string) config('excel.temporary_files.local_path');
    File::ensureDirectoryExists($directory);
    $path = $directory.'/t'.bin2hex(random_bytes(6)).'.xlsx';
    $base = $response->baseResponse;
    $base instanceof BinaryFileResponse ? copy($base->getFile()->getPathname(), $path) : file_put_contents($path, $response->streamedContent());

    $rows = IOFactory::createReader('Xlsx')->load($path)->getSheet(0)->toArray(null, false, false);
    $line = collect($rows)->first(fn (array $r) => $r[0] === $code);
    $headings = $rows[0];

    return array_combine(array_slice($headings, -11), array_slice($line, -11));
}

function e2eRow(Employee $e, string $date): ?Attendance
{
    return Attendance::query()->where('employee_id', $e->id)->onDate($date)->first();
}

/**
 * MonthGrid codes of one employee for days $from..$to of November 2026 (null = blank).
 *
 * @return array{codes: list<string|null>, totals: array<string, int|float>}
 */
function e2eMonth(Employee $e, int $from, int $to): array
{
    $row = MonthGrid::for('2026-11', app(AttendanceClock::class))->rows([$e->fresh()])[$e->id];
    $codes = [];
    for ($d = $from; $d <= $to; $d++) {
        $codes[] = $row['cells'][$d - 1][0] ?? null;
    }

    return ['codes' => $codes, 'totals' => $row['totals']];
}

it('(a) runs machine upload -> rebuild -> close -> export -> request -> manager -> HR -> export with every step audited', function () {
    $managerUser = userWithRole('manager');
    $manager = employeeFor($managerUser, ['joining_date' => '2026-01-01']);
    $user = userWithRole('employee');
    $employee = employeeFor($user, ['joining_date' => '2026-01-01', 'manager_id' => $manager->id, 'device_user_id' => '101',
        'employee_code' => 'E2E-0001']);
    $hrUser = userWithRole('hr');
    employeeFor($hrUser, ['joining_date' => '2026-01-01']);

    // Mon 16 on time, Tue 17 late 30, Wed 18 nothing, Thu 19 only a clock-in, Fri 20 on time.
    e2eUpload([
        ['101', '2026-11-16 09:00:00'], ['101', '2026-11-16 18:00:00'],
        ['101', '2026-11-17 09:30:00'], ['101', '2026-11-17 18:00:00'],
        ['101', '2026-11-19 09:00:00'],
        ['101', '2026-11-20 09:00:00'], ['101', '2026-11-20 18:00:00'],
    ])->assertOk()->assertSeeText('OK: 7');
    expect(DevicePunch::query()->where('employee_id', $employee->id)->count())->toBe(7);

    $this->artisan('hrm:attendance-rebuild')->assertSuccessful();
    // The hourly close: the 7 days before today, then the 16th by the backfill.
    $this->artisan('hrm:attendance-close')->expectsOutput('attendance_backfill date=2026-11-16 written=2')->assertSuccessful(); // the manager and HR (the employee's punch day was decided by the rebuild)

    expect(e2eRow($employee, '2026-11-18')->status)->toBe('absent')
        ->and(e2eRow($employee, '2026-11-19')->only(['status', 'needs_attention']))->toBe(['status' => 'present', 'needs_attention' => true]);

    $admin = userWithRole('admin');
    $before = e2eExportTotals($this->actingAs($admin)->get('/attendance/monthly/export?month=2026-11')->assertOk(), 'E2E-0001');
    expect($before)->toBe([
        'Present' => 3, 'Late' => 1, 'Half Day' => 0, 'Absent' => 2, 'On Leave' => 0, 'Weekend/Holiday' => 2,
        'Present-equivalent' => 4, 'Working Days' => 6, 'Worked Hours' => 26.5, 'Late Minutes' => 30, 'Overtime Minutes' => 0,
    ]);

    // The employee asks for the 18th; the manager approves, then HR.
    $this->actingAs($user)->post('/attendance/corrections', [
        'date' => '2026-11-18', 'clock_in' => '09:00', 'clock_out' => '18:00', 'reason' => 'The machine was down that morning.',
    ])->assertSessionHas('success');
    $request = AttendanceCorrection::query()->sole();
    $this->actingAs($managerUser)->put("/attendance/corrections/{$request->id}/manager-decide", ['decision' => 'approve'])
        ->assertSessionHas('success');
    $this->actingAs($hrUser)->put("/attendance/corrections/{$request->id}/hr-decide", ['decision' => 'approve'])
        ->assertSessionHas('success');

    expect(e2eRow($employee, '2026-11-18')->only(['status', 'is_manual', 'worked_minutes', 'note']))
        ->toBe(['status' => 'present', 'is_manual' => true, 'worked_minutes' => 540, 'note' => "Correction request #{$request->id}"]);

    // A later close or rebuild never touches the corrected day.
    $this->artisan('hrm:attendance-rebuild')->assertSuccessful();
    $this->artisan('hrm:attendance-close')->assertSuccessful();

    $after = e2eExportTotals($this->actingAs($admin)->get('/attendance/monthly/export?month=2026-11')->assertOk(), 'E2E-0001');
    expect($after)->toMatchArray(['Present' => 4, 'Absent' => 1, 'Present-equivalent' => 5, 'Working Days' => 6, 'Worked Hours' => 35.5]);

    $descriptions = Activity::query()->where('log_name', 'attendance')->pluck('description')->all();
    expect($descriptions)->toContain('Correction requested', 'Correction request approved by manager', 'Correction request approved', 'Attendance corrected')
        ->and(array_count_values($descriptions)['Monthly attendance exported'])->toBe(2);
    expect(Activity::query()->where('description', 'Attendance corrected')->sole()->causer_id)->toBe($hrUser->id);
});

it('(b) moves a reused PIN from a leaver to a new hire across a closed day, each keeping their own days', function () {
    $hr = userWithRole('hr');
    $leaver = Employee::factory()->create(['joining_date' => '2025-01-01', 'device_user_id' => '201', 'status' => 'resigned',
        'last_working_day' => '2026-11-18']);
    $hire = Employee::factory()->create(['joining_date' => '2026-11-19', 'status' => 'probation']);

    // The leaver's last days, and the new hire's first day before HR moved the ID (still linked to the leaver).
    e2eUpload([
        ['201', '2026-11-17 09:00:00'], ['201', '2026-11-17 18:00:00'],
        ['201', '2026-11-18 09:05:00'], ['201', '2026-11-18 18:00:00'],
        ['201', '2026-11-19 09:00:00'], ['201', '2026-11-19 18:10:00'],
    ])->assertOk();
    $this->artisan('hrm:attendance-rebuild')->assertSuccessful();
    foreach (range(1, 2) as $run) {
        $this->artisan('hrm:attendance-close')->assertSuccessful();
    }

    expect(e2eRow($leaver, '2026-11-19'))->toBeNull() // after their last working day
        ->and(e2eRow($hire, '2026-11-19')->only(['status', 'closed']))->toBe(['status' => 'absent', 'closed' => true]);

    // HR frees the ID on the leaver's form and gives it to the new hire on Raw Punches.
    app(UpdateEmployee::class)->handle($leaver, ['device_user_id' => null], $hr, syncAccount: false);
    $this->actingAs($hr)->post('/attendance/punches/assign', ['device_user_id' => '201', 'employee_id' => $hire->id])
        ->assertSessionHasNoErrors();

    // A punch after the move, on the open day.
    e2eUpload([['201', '2026-11-24 08:55:00']])->assertOk();
    $this->artisan('hrm:attendance-rebuild')->assertSuccessful();

    expect(DevicePunch::query()->where('employee_id', $leaver->id)->count())->toBe(4) // closed days stay theirs
        ->and(DevicePunch::query()->where('employee_id', $hire->id)->count())->toBe(3);

    $leaverMonth = e2eMonth($leaver, 16, 20);
    $hireMonth = e2eMonth($hire, 18, 24);

    expect($leaverMonth['codes'])->toBe(['A', 'P', 'P', null, null])
        ->and($leaverMonth['totals'])->toMatchArray(['present' => 2, 'absent' => 1, 'worked_minutes' => 1075])
        ->and($hireMonth['codes'])->toBe([null, 'P', 'A', 'W', 'W', 'A', 'P'])
        ->and($hireMonth['totals'])->toMatchArray(['present' => 2, 'absent' => 2, 'off' => 2, 'worked_minutes' => 550]);
    // The new hire's closed 19th was decided again from its own snapshot, now with the moved punches.
    expect(e2eRow($hire, '2026-11-19')->only(['status', 'closed', 'is_manual']))->toBe(['status' => 'present', 'closed' => true, 'is_manual' => false]);
});

it('(c) adds and removes a holiday on a past closed week; the rebuild keeps each day\'s snapshot', function () {
    $worker = Employee::factory()->create(['joining_date' => '2026-01-01', 'device_user_id' => '301', 'employee_code' => 'E2E-W']);
    $absentee = Employee::factory()->create(['joining_date' => '2026-01-01', 'employee_code' => 'E2E-A']);
    $lines = [];
    foreach (['16', '17', '18', '19', '20'] as $d) {
        $lines[] = ['301', "2026-11-{$d} 09:00:00"];
        $lines[] = ['301', "2026-11-{$d} 18:00:00"];
    }
    e2eUpload($lines)->assertOk();
    $this->artisan('hrm:attendance-rebuild')->assertSuccessful();
    foreach (range(1, 2) as $run) {
        $this->artisan('hrm:attendance-close')->assertSuccessful();
    }
    expect(e2eMonth($worker, 16, 23)['totals'])->toMatchArray(['present' => 5, 'absent' => 1, 'off' => 2]);

    // The shift changes after the week was closed: closed days keep the rules they were decided with.
    Shift::default()->forceFill(['start_time' => '08:00', 'grace_minutes' => 0])->save();

    $admin = userWithRole('admin');
    $this->actingAs($admin)->post('/leave/holidays', ['name' => 'Iqbal Day', 'date' => '2026-11-18'])->assertSessionHas('success');
    expect(e2eRow($worker, '2026-11-18')->needs_rebuild)->toBeTrue();
    $this->artisan('hrm:attendance-rebuild')->assertSuccessful();

    expect(e2eRow($worker, '2026-11-18')->only(['status', 'overtime_minutes', 'closed']))->toBe(['status' => 'holiday', 'overtime_minutes' => 540, 'closed' => true])
        ->and(e2eMonth($worker, 16, 23)['totals'])->toMatchArray(['present' => 4, 'absent' => 1, 'off' => 3, 'equivalent' => 4, 'working_days' => 5])
        ->and(e2eMonth($absentee, 16, 23)['totals'])->toMatchArray(['present' => 0, 'absent' => 5, 'off' => 3, 'working_days' => 5]);

    $holiday = Holiday::query()->sole();
    $this->actingAs($admin)->delete("/leave/holidays/{$holiday->id}")->assertSessionHas('success');
    $this->artisan('hrm:attendance-rebuild')->assertSuccessful();

    // Back to a working day, decided with the 09:00 snapshot: on time, not 60 minutes late under the new 08:00 start.
    expect(e2eRow($worker, '2026-11-18')->only(['status', 'late_minutes', 'overtime_minutes']))->toBe(['status' => 'present', 'late_minutes' => 0, 'overtime_minutes' => 0])
        ->and(e2eRow($absentee, '2026-11-18')->status)->toBe('absent')
        ->and(e2eMonth($worker, 16, 23)['totals'])->toMatchArray(['present' => 5, 'absent' => 1, 'off' => 2, 'late' => 0])
        ->and(e2eMonth($absentee, 16, 23)['totals'])->toMatchArray(['absent' => 6, 'off' => 2]);
});

it('(d) changes the day cutoff from 04:00 to 02:00: stored days stay, new punches follow the new cutoff', function () {
    $e = Employee::factory()->create(['joining_date' => '2026-01-01', 'device_user_id' => '401']);

    // A late night under the 04:00 cutoff: 03:00 on the 21st is the 20th's clock-out.
    e2eUpload([['401', '2026-11-20 09:00:00'], ['401', '2026-11-21 03:00:00']])->assertOk();
    $this->artisan('hrm:attendance-rebuild')->assertSuccessful();
    $this->artisan('hrm:attendance-close')->assertSuccessful();
    $friday = e2eRow($e, '2026-11-20');
    expect($friday->getRawOriginal('clock_out'))->toBe('2026-11-21 03:00:00')
        ->and($friday->worked_minutes)->toBe(1080);

    $this->actingAs(userWithRole('admin'))->put('/hrm-settings/attendance', ['day_cutoff_time' => '02:00'])->assertSessionHasNoErrors();
    expect(app(AttendanceClock::class)->dayCutoff())->toBe('02:00');

    // A new late night: 03:00 is now after the cutoff, so it is the 24th's own punch, not the 23rd's clock-out.
    e2eUpload([['401', '2026-11-23 09:00:00'], ['401', '2026-11-24 03:00:00'], ['401', '2026-11-24 01:30:00']])->assertOk();
    expect(DevicePunch::query()->where('punch_time', '2026-11-24 03:00:00')->value('punch_date'))->toBe('2026-11-24')
        ->and(DevicePunch::query()->where('punch_time', '2026-11-24 01:30:00')->value('punch_date'))->toBe('2026-11-23')
        ->and(DevicePunch::query()->where('punch_time', '2026-11-21 03:00:00')->value('punch_date'))->toBe('2026-11-20');

    $this->artisan('hrm:attendance-rebuild')->assertSuccessful();
    $this->artisan('hrm:attendance-close')->assertSuccessful();
    $this->artisan('hrm:attendance-rebuild', ['--from' => '2026-11-20', '--to' => '2026-11-20'])->assertSuccessful();

    expect(e2eRow($e, '2026-11-20')->only(['worked_minutes', 'status']))->toBe(['worked_minutes' => 1080, 'status' => 'present'])
        ->and(e2eRow($e, '2026-11-20')->getRawOriginal('clock_out'))->toBe('2026-11-21 03:00:00')
        ->and(e2eRow($e, '2026-11-23')->getRawOriginal('clock_out'))->toBe('2026-11-24 01:30:00')
        ->and(e2eRow($e, '2026-11-24')->getRawOriginal('clock_in'))->toBe('2026-11-24 03:00:00');
});
