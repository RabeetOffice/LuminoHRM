<?php

use App\Mail\LeaveRequestDecided;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveDay;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Support\Attendance\AttendanceClock;
use App\Support\Attendance\MonthGrid;
use App\Support\Leave\LeaveBalances;
use App\Support\Leave\LeaveLedger;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/*
| Whole leave flows through the real entry points (the screens' routes and the scheduled commands), final fix round.
| Unless a test travels elsewhere, "now" is Wednesday 2026-11-25 14:00 in Karachi and attendance started on
| 2026-01-01. The default shift is 09:00-18:00, 4 h half day, Saturday and Sunday off. Annual: 14 a year, carry
| forward, cap 5.
*/

beforeEach(function () {
    $this->withoutVite();
    $this->travelTo(CarbonImmutable::parse('2026-11-25 14:00:00', 'Asia/Karachi'));
    Settings::set('attendance', ['start_date' => '2026-01-01']);
    Mail::fake();
    $this->excelTemp = sys_get_temp_dir().'/lumino-le2e-'.bin2hex(random_bytes(6));
    config(['excel.temporary_files.local_path' => $this->excelTemp]);
});

afterEach(function () {
    File::deleteDirectory($this->excelTemp);
});

/** @return array{0: User, 1: Employee, 2: User, 3: Employee} an employee and their manager (both with logins) */
function le2eTeam(): array
{
    $managerUser = userWithRole('manager');
    $manager = employeeFor($managerUser, ['joining_date' => '2026-01-01']);
    $user = userWithRole('employee');
    $employee = employeeFor($user, ['joining_date' => '2026-01-01', 'manager_id' => $manager->id, 'employee_code' => 'LE2E-01']);

    return [$user, $employee, $managerUser, $manager];
}

function le2eApply(User $user, string $from, ?string $to = null, ?string $half = null, string $type = 'Annual', array $extra = []): TestResponse
{
    return test()->actingAs($user)->from('/my/leaves')->post('/leave/requests', [
        'leave_type_id' => leaveType($type)->id,
        'from_date' => $from,
        'to_date' => $to ?? $from,
        'half' => $half,
        'reason' => 'Family matters to attend to.',
        ...$extra,
    ]);
}

function le2eDecide(User $user, LeaveRequest $r, string $step): TestResponse
{
    return test()->actingAs($user)->from('/leave/requests')->put("/leave/requests/{$r->id}/{$step}-decide", ['decision' => 'approve', 'note' => null]);
}

function le2eLatest(Employee $e): LeaveRequest
{
    return LeaveRequest::query()->where('employee_id', $e->id)->latest('id')->firstOrFail();
}

function le2eRow(Employee $e, string $date): ?Attendance
{
    return Attendance::query()->where('employee_id', $e->id)->onDate($date)->first();
}

/**
 * The downloaded monthly export: one employee's day letters (by day number) and totals (by heading).
 *
 * @return array{days: array<int, string>, totals: array<string, mixed>}
 */
function le2eExport(TestResponse $response, string $code): array
{
    $directory = (string) config('excel.temporary_files.local_path');
    File::ensureDirectoryExists($directory);
    $path = $directory.'/t'.bin2hex(random_bytes(6)).'.xlsx';
    $base = $response->baseResponse;
    $base instanceof BinaryFileResponse ? copy($base->getFile()->getPathname(), $path) : file_put_contents($path, $response->streamedContent());

    $rows = IOFactory::createReader('Xlsx')->load($path)->getSheet(0)->toArray(null, false, false);
    $line = collect($rows)->first(fn (array $r) => $r[0] === $code);
    $headings = $rows[0];
    $days = [];

    foreach ($headings as $i => $heading) {
        if (is_string($heading) && preg_match('/^(\d{2}) /', $heading, $m) === 1) {
            $days[(int) $m[1]] = (string) $line[$i];
        }
    }

    return ['days' => $days, 'totals' => array_combine(array_slice($headings, -11), array_slice($line, -11))];
}

/** @return array{codes: array<int, string|null>, flags: array<int, int>, totals: array<string, int|float>} */
function le2eMonth(Employee $e, string $month, int $from, int $to): array
{
    $row = MonthGrid::for($month, app(AttendanceClock::class))->rows([$e->fresh()])[$e->id];
    $codes = [];
    $flags = [];

    for ($d = $from; $d <= $to; $d++) {
        $codes[$d] = $row['cells'][$d - 1][0] ?? null;
        $flags[$d] = (int) ($row['cells'][$d - 1][1] ?? 0);
    }

    return ['codes' => $codes, 'flags' => $flags, 'totals' => $row['totals']];
}

it('(1) refuses, at the year turn, approving a December request pending since December or applying backdated, when it would overdraw the new year', function () {
    [$user, $employee, $managerUser] = le2eTeam();
    $hr = userWithRole('hr');
    $annual = leaveType('Annual');

    // December: HR records 9 days used before go-live (5 left, all carried); the employee asks for 28–30 Dec.
    $this->travelTo(CarbonImmutable::parse('2026-12-15 11:00:00', 'Asia/Karachi'));
    $this->actingAs($hr)->put("/leave/balances/{$employee->id}/{$annual->id}/2026", [
        'allocated' => 14, 'adjustment' => 0, 'opening_used' => 9, 'reason' => 'Used before go-live',
    ])->assertSessionHasNoErrors();
    le2eApply($user, '2026-12-28', '2026-12-30')->assertSessionHasNoErrors();
    $december = le2eLatest($employee);
    le2eDecide($managerUser, $december, 'manager')->assertSessionHas('success');
    expect($december->fresh()->status)->toBe('manager_approved');

    // 2027 already holds 19 days of approved leave (written before this rule existed): all of 14 + 5 carried.
    app(LeaveBalances::class)->ensureYear($employee, 2027);
    approvedLeave($employee, '2027-02-01', '2027-02-19');

    // Tuesday 5 January 2027: HR's approval would take 3 days of what carried into 2027.
    $this->travelTo(CarbonImmutable::parse('2027-01-05 10:00:00', 'Asia/Karachi'));
    le2eDecide($hr, $december, 'final')
        ->assertSessionHas('error', 'Not enough Annual leave: these days would be taken from what carries into 2027, and 2027 would end 3 days short. Reject it, or ask for the extra days as Unpaid leave.');
    expect($december->fresh()->status)->toBe('manager_approved')
        ->and(LeaveDay::query()->where('leave_request_id', $december->id)->count())->toBe(0);

    // Applying backdated for 31 December is refused the same way.
    le2eApply($user, '2026-12-31')->assertSessionHasErrors(['days' => 'Not enough Annual leave: these days would be taken from what carries into 2027, and 2027 would end 4 days short. Apply for fewer days or as Unpaid leave.']);

    // Once the December request is rejected, 2027 is whole again.
    $this->actingAs($hr)->from('/leave/requests')->put("/leave/requests/{$december->id}/final-decide", ['decision' => 'reject', 'note' => 'No days left for this.'])
        ->assertSessionHas('success');
    $line = app(LeaveBalances::class)->line($employee, $annual, 2027);
    expect($line->carried)->toBe(5.0)->and($line->remaining)->toBe(0.0)->and($line->overdrawn)->toBeFalse();
});

it('(2) runs apply → manager → HR → minute rebuild into the monthly grid and the export', function () {
    [$user, $employee, $managerUser] = le2eTeam();
    $hr = userWithRole('hr');

    le2eApply($user, '2026-11-23', '2026-11-24')->assertSessionHasNoErrors();   // Mon–Tue, full days
    $full = le2eLatest($employee);
    le2eApply($user, '2026-11-20', null, 'am')->assertSessionHasNoErrors();     // Fri, first half
    $half = le2eLatest($employee);

    foreach ([$full, $half] as $r) {
        le2eDecide($managerUser, $r, 'manager')->assertSessionHas('success');
        le2eDecide($hr, $r, 'final')->assertSessionHas('success');
        expect($r->fresh()->status)->toBe('approved');
    }

    // The second half of Friday was worked: 13:00–18:00 (5 h, at least the 4 h half day).
    punch($employee, '2026-11-20 13:00:00');
    punch($employee, '2026-11-20 18:00:00');

    $this->artisan('hrm:attendance-rebuild')->assertSuccessful();

    expect(le2eRow($employee, '2026-11-23')->status)->toBe('on_leave')
        ->and(le2eRow($employee, '2026-11-24')->status)->toBe('on_leave')
        ->and(le2eRow($employee, '2026-11-20')->only(['status', 'leave_half', 'late_minutes']))->toBe(['status' => 'present', 'leave_half' => 'am', 'late_minutes' => 0]);

    $month = le2eMonth($employee, '2026-11', 20, 24);
    expect($month['codes'])->toBe([20 => 'P', 21 => null, 22 => null, 23 => 'V', 24 => 'V'])
        ->and($month['flags'][20] & MonthGrid::FLAG_HALF_LEAVE)->toBe(MonthGrid::FLAG_HALF_LEAVE)
        ->and($month['totals'])->toMatchArray(['present' => 0.5, 'on_leave' => 2.5, 'absent' => 0]);

    $export = le2eExport($this->actingAs(userWithRole('admin'))->get('/attendance/monthly/export?month=2026-11')->assertOk(), 'LE2E-01');
    expect($export['days'][20])->toBe('P½')
        ->and($export['days'][23])->toBe('LV')
        ->and($export['days'][24])->toBe('LV')
        ->and($export['totals'])->toMatchArray(['Present' => 0.5, 'Absent' => 0, 'On Leave' => 2.5]);

    expect(app(LeaveBalances::class)->line($employee, leaveType(), 2026)->used)->toBe(2.5);
});

it('(3) refunds approved leave when holidays are added on a closed past day and a future day, raising the balance and next year\'s carry', function () {
    [$user, $employee] = le2eTeam();
    $hr = userWithRole('hr');
    $annual = leaveType('Annual');
    LeaveBalance::query()->create(['employee_id' => $employee->id, 'leave_type_id' => $annual->id, 'year' => 2026, 'allocated' => 14, 'adjustment' => 0, 'opening_used' => 6]);

    // Mon 23 Nov – Tue 1 Dec: 7 counted days, approved by HR (the manager step skipped).
    le2eApply($user, '2026-11-23', '2026-12-01')->assertSessionHasNoErrors();
    $r = le2eLatest($employee);
    le2eDecide($hr, $r, 'final')->assertSessionHas('success');
    expect((float) $r->fresh()->days)->toBe(7.0);

    $this->artisan('hrm:attendance-rebuild')->assertSuccessful();
    $this->artisan('hrm:attendance-close')->assertSuccessful();
    expect(le2eRow($employee, '2026-11-23')->only(['status', 'closed']))->toBe(['status' => 'on_leave', 'closed' => true]);

    $balances = app(LeaveBalances::class);
    expect($balances->line($employee, $annual, 2026)->remaining)->toBe(1.0)     // 14 − 6 − 7
        ->and($balances->line($employee, $annual, 2027)->carried)->toBe(1.0);

    $this->actingAs($hr)->post('/leave/holidays', ['name' => 'Snow Day', 'date' => '2026-11-23'])->assertSessionHasNoErrors();
    $this->actingAs($hr)->post('/leave/holidays', ['name' => 'Founders Day', 'date' => '2026-12-01'])->assertSessionHasNoErrors();

    $this->artisan('hrm:attendance-rebuild')->assertSuccessful();

    $r->refresh();
    expect($r->status)->toBe('approved')
        ->and((float) $r->days)->toBe(5.0)
        ->and(LeaveDay::query()->where('leave_request_id', $r->id)->orderBy('date')->get()->map(fn ($d) => $d->date->format('Y-m-d'))->all())
        ->toBe(['2026-11-24', '2026-11-25', '2026-11-26', '2026-11-27', '2026-11-30'])
        ->and(le2eRow($employee, '2026-11-23')->only(['status', 'closed']))->toBe(['status' => 'holiday', 'closed' => true])
        ->and($balances->line($employee, $annual, 2026)->remaining)->toBe(3.0)
        ->and($balances->line($employee, $annual, 2027)->carried)->toBe(3.0);

    $month = le2eMonth($employee, '2026-11', 23, 24);
    expect($month['codes'])->toBe([23 => 'O', 24 => 'V']);
    Mail::assertQueued(LeaveRequestDecided::class, fn ($m) => $m->outcome === 'refunded' && $m->hasTo($user->email));
});

it('(5) trims leave when the employee resigns with a last working day inside it, and skips them in next year\'s allocation', function () {
    [$user, $employee] = le2eTeam();
    $admin = userWithRole('admin');
    $annual = leaveType('Annual');
    $this->artisan('hrm:leave-allocate')->assertSuccessful();

    // Mon 23 – Fri 27 Nov, approved; today (25th) and the days before have attendance rows.
    le2eApply($user, '2026-11-23', '2026-11-27')->assertSessionHasNoErrors();
    $r = le2eLatest($employee);
    le2eDecide($admin, $r, 'final')->assertSessionHas('success');
    $this->artisan('hrm:attendance-rebuild')->assertSuccessful();
    expect(le2eRow($employee, '2026-11-25')?->status)->toBe('on_leave');

    $this->actingAs($admin)->from("/employees/{$employee->id}")
        ->patch("/employees/{$employee->id}/status", ['status' => 'resigned', 'last_working_day' => '2026-11-24'])
        ->assertSessionHasNoErrors()->assertSessionHas('success');

    $r->refresh();
    expect($r->status)->toBe('approved')
        ->and($r->cancelled_from->format('Y-m-d'))->toBe('2026-11-25')
        ->and((float) $r->days)->toBe(2.0)
        ->and(app(LeaveBalances::class)->line($employee->fresh(), $annual, 2026)->used)->toBe(2.0);

    // The rebuild removes the attendance day after the last working day.
    $this->artisan('hrm:attendance-rebuild')->assertSuccessful();
    expect(le2eRow($employee, '2026-11-25'))->toBeNull()
        ->and(le2eRow($employee, '2026-11-24')->status)->toBe('on_leave');

    $this->artisan('hrm:leave-allocate', ['year' => '2027'])->assertSuccessful();
    expect(LeaveBalance::query()->where('employee_id', $employee->id)->where('year', 2027)->exists())->toBeFalse()
        ->and(LeaveBalance::query()->where('year', 2027)->exists())->toBeTrue(); // others (the manager, the admin) got theirs
});

it('(7) counts an unpaid half day with a worked half that ends absent as half unpaid leave and half absent', function () {
    Settings::set('attendance', ['start_date' => '2026-11-24']);
    [$user, $employee] = le2eTeam();
    $hr = userWithRole('hr');

    // Tuesday 24 Nov, first half unpaid; the second half only 1 h of work (14:00–15:00), short of the 4 h half day.
    le2eApply($user, '2026-11-24', null, 'am', 'Unpaid')->assertSessionHasNoErrors();
    $r = le2eLatest($employee);
    le2eDecide($hr, $r, 'final')->assertSessionHas('success');
    punch($employee, '2026-11-24 14:00:00');
    punch($employee, '2026-11-24 15:00:00');

    $this->artisan('hrm:attendance-rebuild')->assertSuccessful();
    $this->artisan('hrm:attendance-close')->assertSuccessful();

    expect(le2eRow($employee, '2026-11-24')->only(['status', 'leave_half', 'closed']))->toBe(['status' => 'absent', 'leave_half' => 'am', 'closed' => true])
        ->and(LeaveLedger::unpaidDays($employee->id, '2026-11-01', '2026-11-30'))->toBe(0.5);

    $month = le2eMonth($employee, '2026-11', 24, 24);
    expect($month['codes'])->toBe([24 => 'A'])
        ->and($month['totals'])->toMatchArray(['absent' => 0.5, 'on_leave' => 0.5, 'present' => 0]);
});
