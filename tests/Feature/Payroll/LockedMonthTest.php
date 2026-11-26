<?php

use App\Actions\Attendance\ApplyManualCorrection;
use App\Actions\Employees\ChangeEmployeeStatus;
use App\Actions\Employees\UpdateEmployee;
use App\Actions\Leave\HolidayLeaveRefund;
use App\Actions\Leave\WeeklyOffLeaveRefund;
use App\Http\Controllers\HolidayController;
use App\Http\Requests\HolidayRequest;
use App\Models\Attendance;
use App\Models\AttendanceCorrection;
use App\Models\Device;
use App\Models\DevicePunch;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\LeaveDay;
use App\Models\LeaveRequest;
use App\Models\Shift;
use App\Support\Attendance\AttendanceRebuilder;
use App\Support\Attendance\CorrectionEligibility;
use App\Support\Attendance\PunchIngestor;
use App\Support\Attendance\RebuildMarks;
use App\Support\Leave\LeaveDays;
use App\Support\PayrollLock;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/*
| Task 6: an approved (or paid) payroll month is frozen for attendance, holidays and employment dates. "Now" is
| 2026-12-03 (a Thursday) 14:00 in Karachi; attendance started on 2026-01-01; the default shift is 09:00-18:00 with
| Saturday and Sunday off. In most tests November 2026 is approved: lmLock(). 2026-11-20 is a Friday.
*/

const LM_MESSAGE = 'Payroll for November 2026 is approved, so this can no longer change.';

beforeEach(function () {
    $this->withoutVite();
    $this->travelTo(CarbonImmutable::parse('2026-12-03 14:00:00', 'Asia/Karachi'));
    Settings::set('attendance', ['start_date' => '2026-01-01']);
    Mail::fake();
});

function lmLock(string $status = 'approved'): void
{
    payrollRun('2026-11', $status);
}

/** @param  array<string, mixed>  $attrs */
function lmEmployee(array $attrs = []): Employee
{
    return Employee::factory()->create(['joining_date' => '2026-01-01', ...$attrs]);
}

/** @param  array<string, mixed>  $attrs */
function lmRow(Employee $e, string $date, array $attrs = []): Attendance
{
    return Attendance::factory()->create(['employee_id' => $e->id, 'date' => $date, 'closed' => $date < '2026-12-03', ...$attrs]);
}

function lmFind(Employee $e, string $date): ?Attendance
{
    return Attendance::query()->where('employee_id', $e->id)->onDate($date)->first();
}

/** The decided columns of a row, to compare before and after. */
function lmDecided(?Attendance $row): ?array
{
    return $row === null ? null : collect($row->fresh()->getAttributes())
        ->only(['clock_in', 'clock_out', 'status', 'worked_minutes', 'late_minutes', 'early_minutes', 'overtime_minutes', 'is_manual', 'closed', 'shift_rules', 'leave_half', 'needs_attention'])
        ->all();
}

// ---------------------------------------------------------------- manual corrections

describe('manual corrections', function () {
    it('refuses a correction on a locked date and allows one in December', function () {
        lmLock();
        $e = lmEmployee();
        $row = lmRow($e, '2026-11-20', ['status' => 'absent', 'clock_in' => null, 'clock_out' => null, 'worked_minutes' => 0]);
        $before = lmDecided($row);

        $this->actingAs(userWithRole('admin'))->from('/attendance/daily')
            ->put("/attendance/daily/{$e->id}/2026-11-20", ['clock_in' => '09:00', 'clock_out' => '18:00', 'status' => null, 'reason' => 'Forgot to punch'])
            ->assertSessionHasErrors(['date' => LM_MESSAGE]);

        expect(lmDecided($row))->toBe($before);

        $this->actingAs(userWithRole('admin'))->from('/attendance/daily')
            ->put("/attendance/daily/{$e->id}/2026-12-01", ['clock_in' => '09:00', 'clock_out' => '18:00', 'status' => null, 'reason' => 'Forgot to punch'])
            ->assertSessionHasNoErrors();

        expect(lmFind($e, '2026-12-01')->is_manual)->toBeTrue();
    });

    it('refuses the action itself, before anything is written', function () {
        lmLock('paid');
        $e = lmEmployee();

        expect(fn () => app(ApplyManualCorrection::class)->handle($e, '2026-11-20', '09:00', '18:00', null, 'Forgot to punch', userWithRole('admin')))
            ->toThrow(ValidationException::class, LM_MESSAGE);

        expect(lmFind($e, '2026-11-20'))->toBeNull();
    });

    it('refuses removing a manual correction on a locked date', function () {
        $e = lmEmployee();
        $row = lmRow($e, '2026-11-20', ['is_manual' => true, 'note' => 'Fixed by HR']);
        lmLock();

        $this->actingAs(userWithRole('admin'))->from('/attendance/daily')
            ->delete("/attendance/daily/{$e->id}/2026-11-20")
            ->assertSessionHasErrors(['date' => LM_MESSAGE]);

        expect($row->fresh()->is_manual)->toBeTrue()->and($row->fresh()->note)->toBe('Fixed by HR');
    });

    it('does not lock while the November run is a draft', function () {
        lmLock('draft');
        $e = lmEmployee();

        $this->actingAs(userWithRole('admin'))->from('/attendance/daily')
            ->put("/attendance/daily/{$e->id}/2026-11-20", ['clock_in' => '09:00', 'clock_out' => '18:00', 'status' => null, 'reason' => 'Forgot to punch'])
            ->assertSessionHasNoErrors();

        expect(lmFind($e, '2026-11-20')->is_manual)->toBeTrue();
    });
});

// ---------------------------------------------------------------- correction requests

describe('correction requests', function () {
    it('refuses a request for a locked date at submit', function () {
        lmLock();
        $user = userWithRole('employee');
        employeeFor($user, ['joining_date' => '2026-01-01']);

        $this->actingAs($user)->from('/my/attendance')
            ->post('/attendance/corrections', ['date' => '2026-11-20', 'clock_in' => '09:00', 'clock_out' => '18:00', 'reason' => 'The machine missed my punch.'])
            ->assertSessionHasErrors(['date' => LM_MESSAGE]);

        expect(AttendanceCorrection::query()->count())->toBe(0);

        $this->actingAs($user)->from('/my/attendance')
            ->post('/attendance/corrections', ['date' => '2026-12-01', 'clock_in' => '09:00', 'clock_out' => '18:00', 'reason' => 'The machine missed my punch.'])
            ->assertSessionHasNoErrors();
    });

    it('refuses to apply a request submitted before the lock and approved after it; the request stays open', function () {
        $user = userWithRole('employee');
        $employee = employeeFor($user, ['joining_date' => '2026-01-01']);
        $row = lmRow($employee, '2026-11-20', ['status' => 'absent', 'clock_in' => null, 'clock_out' => null, 'worked_minutes' => 0]);
        $before = lmDecided($row);

        $this->actingAs($user)->from('/my/attendance')
            ->post('/attendance/corrections', ['date' => '2026-11-20', 'clock_in' => '09:00', 'clock_out' => '18:00', 'reason' => 'The machine missed my punch.'])
            ->assertSessionHasNoErrors();
        $request = AttendanceCorrection::query()->firstOrFail();

        lmLock();

        $this->actingAs(userWithRole('admin'))->from('/attendance/corrections')
            ->put("/attendance/corrections/{$request->id}/hr-decide", ['decision' => 'approve', 'note' => null])
            ->assertSessionHas('error', LM_MESSAGE);

        expect($request->fresh()->status)->toBe('pending')
            ->and(lmDecided($row))->toBe($before);

        // Rejecting it is still possible (it changes no attendance).
        $this->actingAs(userWithRole('admin'))->from('/attendance/corrections')
            ->put("/attendance/corrections/{$request->id}/hr-decide", ['decision' => 'reject', 'note' => 'November payroll is already approved.'])
            ->assertSessionHasNoErrors();

        expect($request->fresh()->status)->toBe('rejected');
    });

    it('says why in the eligibility rule', function () {
        lmLock();
        $employee = lmEmployee();
        $eligibility = app(CorrectionEligibility::class);

        expect($eligibility->reasonRefused($employee, '2026-11-20'))->toBe(LM_MESSAGE)
            ->and($eligibility->reasonRefused($employee, '2026-12-01'))->toBeNull();
    });
});

// ---------------------------------------------------------------- screens

describe('correction buttons', function () {
    it('hides the Daily screen edit on a locked date', function () {
        lmLock();
        $e = lmEmployee();
        lmRow($e, '2026-11-20');
        lmRow($e, '2026-12-01');
        $admin = userWithRole('admin');

        $this->actingAs($admin)->get('/attendance/daily?date=2026-11-20')->assertOk()
            ->assertInertia(fn ($p) => $p->where('rows.0.can_edit', false));

        $this->actingAs($admin)->get('/attendance/daily?date=2026-12-01')->assertOk()
            ->assertInertia(fn ($p) => $p->where('rows.0.can_edit', true));
    });

    it('hides the Monthly screen edit in a locked month', function () {
        lmLock();
        lmEmployee();
        $admin = userWithRole('admin');

        $this->actingAs($admin)->get('/attendance/monthly?month=2026-11')->assertOk()
            ->assertInertia(fn ($p) => $p->where('employees.data.0.can_edit', false));

        $this->actingAs($admin)->get('/attendance/monthly?month=2026-12')->assertOk()
            ->assertInertia(fn ($p) => $p->where('employees.data.0.can_edit', true));
    });

    it('offers no "Request correction" on a locked date in My Attendance', function () {
        $user = userWithRole('employee');
        employeeFor($user, ['joining_date' => '2026-01-01']);

        $this->actingAs($user)->get('/my/attendance?month=2026-11')->assertOk()
            ->assertInertia(fn ($p) => $p->where('correctable', fn ($dates) => collect($dates)->contains('2026-11-20')));

        lmLock();

        $this->actingAs($user)->get('/my/attendance?month=2026-11')->assertOk()
            ->assertInertia(fn ($p) => $p->where('correctable', []));

        $this->actingAs($user)->get('/my/attendance?month=2026-12')->assertOk()
            ->assertInertia(fn ($p) => $p->where('correctable', ['2026-12-01', '2026-12-02']));
    });
});

// ---------------------------------------------------------------- rebuild marks

describe('rebuild marks', function () {
    it('flags only days from today on after a shift edit, without reading the payroll lock', function () {
        // Days from the current attendance day on can never be in a locked month (approval refuses a month that has
        // not ended), so the shift paths take no shared lock on payroll_runs: that would close the cycle
        // shift row -> payroll_runs vs run write -> employees of that shift.
        lmLock();
        $e = lmEmployee();
        $nov = lmRow($e, '2026-11-30', ['closed' => false]);
        $today = lmRow($e, '2026-12-03', ['closed' => false]);

        DB::enableQueryLog();
        expect(RebuildMarks::forShift(Shift::default()))->toBe(1);
        Attendance::query()->whereKey($today->id)->update(['needs_rebuild' => false]);
        expect(RebuildMarks::forEmployee($e->id))->toBe(1);
        Attendance::query()->whereKey($today->id)->update(['needs_rebuild' => false]);
        expect(RebuildMarks::forEmployeesWithoutShift())->toBe(1);
        $sql = implode(' ; ', array_column(DB::getQueryLog(), 'query'));
        DB::disableQueryLog();

        expect($sql)->not->toContain('payroll_runs')
            ->and($nov->fresh()->needs_rebuild)->toBeFalse()
            ->and($today->fresh()->needs_rebuild)->toBeTrue();
    });

    it('flags nothing on a locked holiday date', function () {
        lmLock();
        $e = lmEmployee();
        $nov = lmRow($e, '2026-11-20');
        $dec = lmRow($e, '2026-12-01');

        expect(RebuildMarks::forDate('2026-11-20'))->toBe(0)
            ->and($nov->fresh()->needs_rebuild)->toBeFalse()
            ->and(RebuildMarks::forDate('2026-12-01'))->toBe(1)
            ->and($dec->fresh()->needs_rebuild)->toBeTrue();
    });

    it('flags only unlocked rows outside a new employment window', function () {
        lmLock();
        $e = lmEmployee();
        $nov = lmRow($e, '2026-11-20');
        $dec = lmRow($e, '2026-12-01');
        $e->forceFill(['joining_date' => '2026-12-02'])->save();

        expect(RebuildMarks::outsideEmployment($e->fresh()))->toBe(1)
            ->and($nov->fresh()->needs_rebuild)->toBeFalse()
            ->and($dec->fresh()->needs_rebuild)->toBeTrue();
    });

    it('skips locked dates for leave days: no flag, no new row', function () {
        lmLock();
        $e = lmEmployee();
        $nov = lmRow($e, '2026-11-20');

        expect(RebuildMarks::forLeaveDays($e->id, ['2026-11-20', '2026-11-19', '2026-12-01']))->toBe(1)
            ->and($nov->fresh()->needs_rebuild)->toBeFalse()
            ->and(lmFind($e, '2026-11-19'))->toBeNull()
            ->and(lmFind($e, '2026-12-01')->needs_rebuild)->toBeTrue();
    });
});

// ---------------------------------------------------------------- the rebuilder

describe('rebuilder', function () {
    it('leaves a flagged row of a locked month as it is and clears its flag; the December row is rebuilt', function () {
        lmLock();
        $e = lmEmployee();
        punch($e, '2026-11-20 09:00:00');
        punch($e, '2026-11-20 18:00:00');
        punch($e, '2026-12-01 09:00:00');
        punch($e, '2026-12-01 18:00:00');
        $nov = lmRow($e, '2026-11-20', ['status' => 'absent', 'clock_in' => null, 'clock_out' => null, 'worked_minutes' => 0, 'needs_rebuild' => true]);
        $dec = lmRow($e, '2026-12-01', ['status' => 'absent', 'clock_in' => null, 'clock_out' => null, 'worked_minutes' => 0, 'needs_rebuild' => true]);
        $before = lmDecided($nov);

        app(AttendanceRebuilder::class)->rebuildPending();

        expect(lmDecided($nov))->toBe($before)
            ->and($nov->fresh()->needs_rebuild)->toBeFalse()
            ->and($dec->fresh()->status)->toBe('present')
            ->and($dec->fresh()->needs_rebuild)->toBeFalse();

        // Nothing is left pending, so the queue never loops on the locked row.
        expect(app(AttendanceRebuilder::class)->rebuildPending())->toBe(0);
    });

    it('stores a late punch for a locked date and leaves its day unchanged', function () {
        lmLock();
        $e = lmEmployee(['device_user_id' => '501']);
        $row = lmRow($e, '2026-11-20', ['status' => 'absent', 'clock_in' => null, 'clock_out' => null, 'worked_minutes' => 0]);
        $before = lmDecided($row);
        $device = Device::factory()->create();

        app(PunchIngestor::class)->ingest($device, "501\t2026-11-20 09:00:00\t0\t1\n501\t2026-11-20 18:00:00\t0\t1\n", null);
        app(AttendanceRebuilder::class)->rebuildPending();

        expect(DevicePunch::query()->where('employee_id', $e->id)->count())->toBe(2)
            ->and(lmDecided($row))->toBe($before)
            ->and($row->fresh()->needs_rebuild)->toBeFalse();
    });

    it('stores a punch for a locked date with no row, and creates no attendance row there', function () {
        lmLock();
        $e = lmEmployee(['device_user_id' => '502']);
        $device = Device::factory()->create();

        app(PunchIngestor::class)->ingest($device, "502\t2026-11-20 09:00:00\t0\t1\n502\t2026-12-01 09:00:00\t0\t1\n", null);

        expect(DevicePunch::query()->where('employee_id', $e->id)->count())->toBe(2)
            ->and(lmFind($e, '2026-11-20'))->toBeNull()
            ->and(lmFind($e, '2026-12-01')?->needs_rebuild)->toBeTrue();
    });

    it('relinks punches on a locked date without creating or flagging its day', function () {
        lmLock();
        $e = lmEmployee();
        $nov = lmRow($e, '2026-11-19', ['status' => 'absent', 'clock_in' => null, 'clock_out' => null, 'worked_minutes' => 0, 'closed' => false]);
        $device = Device::factory()->create();
        // Unknown punches under machine ID 503 (nobody had it yet).
        app(PunchIngestor::class)->ingest($device, "503\t2026-11-20 09:00:00\t0\t1\n503\t2026-11-19 09:00:00\t0\t1\n503\t2026-12-01 09:00:00\t0\t1\n", null);

        $e->forceFill(['device_user_id' => '503'])->save();
        app(PunchIngestor::class)->relink($e->fresh(), null);

        expect(DevicePunch::query()->where('employee_id', $e->id)->count())->toBe(3)
            ->and(lmFind($e, '2026-11-20'))->toBeNull()
            ->and($nov->fresh()->needs_rebuild)->toBeFalse()
            ->and(lmFind($e, '2026-12-01')?->needs_rebuild)->toBeTrue();
    });

    it('writes nothing for a locked date in an explicit range rebuild, and creates no row there', function () {
        lmLock();
        $e = lmEmployee();
        $nov = lmRow($e, '2026-11-20', ['status' => 'absent', 'clock_in' => null, 'clock_out' => null, 'worked_minutes' => 0]);
        $before = lmDecided($nov);
        punch($e, '2026-11-20 09:00:00');

        app(AttendanceRebuilder::class)->rebuildRange('2026-11-19', '2026-12-01', [$e->id]);

        expect(lmDecided($nov))->toBe($before)
            ->and(lmFind($e, '2026-11-19'))->toBeNull()
            ->and(lmFind($e, '2026-12-01'))->not->toBeNull();
    });

    it('re-checks the lock before finalising manual rows and the safety net when the month is approved mid-close', function () {
        // closeDay() read November unlocked; the approval commits while the day is being processed (simulated: the
        // run is approved right after the close inserts its first row). finaliseManual and the safety-net updates
        // re-read the lock in their own transaction and leave the day alone.
        $run = payrollRun('2026-11');
        lmEmployee();
        $manual = lmRow(lmEmployee(), '2026-11-27', [
            'is_manual' => true, 'closed' => false, 'status' => 'present',
            'clock_in' => '2026-11-27 09:00:00', 'clock_out' => '2026-11-27 18:00:00',
        ]);
        $approved = false;
        $after = [];

        DB::listen(function ($query) use ($run, &$approved, &$after): void {
            if ($approved) {
                $after[] = $query->sql;
            } elseif (str_contains($query->sql, 'into "attendances"')) {
                $approved = true;
                DB::table('payroll_runs')->where('id', $run->id)->update(['status' => 'approved']);
            }
        });

        app(AttendanceRebuilder::class)->closeDay('2026-11-27');

        expect($approved)->toBeTrue()
            ->and($manual->fresh()->closed)->toBeFalse()
            ->and(collect($after)->filter(fn (string $sql) => str_starts_with($sql, 'update "attendances"'))->values()->all())->toBe([]);
    });

    it('does nothing when closing a locked date, and the backfill skips locked days', function () {
        lmLock();
        $e = lmEmployee();
        $open = lmRow($e, '2026-11-27', ['closed' => false, 'status' => null, 'clock_in' => null, 'clock_out' => null]);
        $rebuilder = app(AttendanceRebuilder::class);

        expect($rebuilder->closeDay('2026-11-26'))->toBe(0)
            ->and(lmFind($e, '2026-11-26'))->toBeNull()
            ->and($rebuilder->closeDay('2026-11-27'))->toBe(0)
            ->and($open->fresh()->closed)->toBeFalse()
            ->and($open->fresh()->status)->toBeNull();

        $missing = $rebuilder->daysWithMissingRows('2026-11-20', '2026-12-02');
        expect($missing)->toBe(['2026-12-01', '2026-12-02']);

        expect($rebuilder->closeDay('2026-12-01'))->toBe(1)
            ->and(lmFind($e, '2026-12-01')->status)->toBe('absent');
    });
});

// ---------------------------------------------------------------- holidays

describe('holidays', function () {
    it('refuses adding a holiday on a locked date', function () {
        lmLock();

        $this->actingAs(userWithRole('admin'))->from('/leave/holidays')
            ->post('/leave/holidays', ['name' => 'Extra day', 'date' => '2026-11-27'])
            ->assertSessionHasErrors(['date' => LM_MESSAGE]);

        expect(Holiday::query()->count())->toBe(0);

        $this->actingAs(userWithRole('admin'))->post('/leave/holidays', ['name' => 'Christmas', 'date' => '2026-12-25'])
            ->assertSessionHasNoErrors();
    });

    it('refuses moving a holiday into or out of a locked month, and deleting one there', function () {
        $dec = Holiday::query()->create(['name' => 'Christmas', 'date' => '2026-12-25']);
        $nov = Holiday::query()->create(['name' => 'Iqbal Day', 'date' => '2026-11-09']);
        lmLock();
        $admin = userWithRole('admin');

        $this->actingAs($admin)->from('/leave/holidays')
            ->put("/leave/holidays/{$dec->id}", ['name' => 'Christmas', 'date' => '2026-11-27'])
            ->assertSessionHasErrors(['date' => LM_MESSAGE]);
        $this->actingAs($admin)->from('/leave/holidays')
            ->put("/leave/holidays/{$nov->id}", ['name' => 'Iqbal Day', 'date' => '2026-12-09'])
            ->assertSessionHasErrors(['date' => LM_MESSAGE]);
        $this->actingAs($admin)->from('/leave/holidays')
            ->delete("/leave/holidays/{$nov->id}")
            ->assertSessionHasErrors(['date' => LM_MESSAGE]);

        expect($dec->fresh()->date->format('Y-m-d'))->toBe('2026-12-25')
            ->and($nov->fresh()?->date->format('Y-m-d'))->toBe('2026-11-09');

        $this->actingAs($admin)->put("/leave/holidays/{$dec->id}", ['name' => 'Christmas Day', 'date' => '2026-12-24'])
            ->assertSessionHasNoErrors();
    });
});

describe('holiday rename', function () {
    it('allows renaming a holiday on a locked date when its date stays the same', function () {
        $nov = Holiday::query()->create(['name' => 'Iqbal Day', 'date' => '2026-11-09']);
        lmLock();
        $admin = userWithRole('admin');

        $this->actingAs($admin)->from('/leave/holidays')
            ->put("/leave/holidays/{$nov->id}", ['name' => 'Allama Iqbal Day', 'date' => '2026-11-09'])
            ->assertSessionHasNoErrors();

        expect($nov->fresh()->name)->toBe('Allama Iqbal Day')
            ->and($nov->fresh()->date->format('Y-m-d'))->toBe('2026-11-09');

        // A date change inside the locked month is still refused.
        $this->actingAs($admin)->from('/leave/holidays')
            ->put("/leave/holidays/{$nov->id}", ['name' => 'Allama Iqbal Day', 'date' => '2026-11-10'])
            ->assertSessionHasErrors(['date' => LM_MESSAGE]);
        expect($nov->fresh()->date->format('Y-m-d'))->toBe('2026-11-09');
    });

    it('refuses a "rename" that would move a holiday someone else moved out of the locked month back into it', function () {
        // The page was read while the holiday sat on 2026-11-09; meanwhile it was moved to December. Saving the page
        // with the old date unchanged would move it back into November: refused.
        $holiday = Holiday::query()->create(['name' => 'Iqbal Day', 'date' => '2026-11-09']);
        lmLock();
        $bound = $holiday->fresh();
        Holiday::query()->whereKey($holiday->id)->update(['date' => '2026-12-09']);
        $request = HolidayRequest::create('/', 'PUT', ['name' => 'Iqbal Day', 'date' => '2026-11-09']);
        $request->setUserResolver(fn () => userWithRole('admin'));
        $request->setContainer(app())->setRedirector(app('redirect'))->validateResolved();

        expect(fn () => app(HolidayController::class)->update($request, $bound, app(HolidayLeaveRefund::class)))
            ->toThrow(ValidationException::class, LM_MESSAGE);
        expect($holiday->fresh()->date->format('Y-m-d'))->toBe('2026-12-09');
    });
});

// ---------------------------------------------------------------- employment dates

describe('employment dates', function () {
    it('refuses a joining date change that crosses a locked month', function () {
        lmLock();
        $admin = userWithRole('admin');
        $e = lmEmployee(['joining_date' => '2026-11-10']);

        expect(fn () => app(UpdateEmployee::class)->handle($e, ['joining_date' => '2026-12-05'], $admin, false))
            ->toThrow(ValidationException::class, LM_MESSAGE);
        expect($e->fresh()->joining_date->format('Y-m-d'))->toBe('2026-11-10');

        $later = lmEmployee(['joining_date' => '2026-12-10']);
        app(UpdateEmployee::class)->handle($later, ['joining_date' => '2026-12-05'], $admin, false);
        expect($later->fresh()->joining_date->format('Y-m-d'))->toBe('2026-12-05');

        $earlier = lmEmployee(['joining_date' => '2026-12-10']);
        expect(fn () => app(UpdateEmployee::class)->handle($earlier, ['joining_date' => '2026-10-01'], $admin, false))
            ->toThrow(ValidationException::class, LM_MESSAGE);
    });

    it('refuses a last working day in or across a locked month', function () {
        lmLock();
        $admin = userWithRole('admin');
        $status = app(ChangeEmployeeStatus::class);

        $e = lmEmployee();
        try {
            $status->handle($e, 'resigned', '2026-11-25', $admin);
            $this->fail('A last working day in a locked month was accepted.');
        } catch (ValidationException $ex) {
            expect($ex->errors())->toBe(['last_working_day' => [LM_MESSAGE]]);
        }
        expect($e->fresh()->status)->toBe('active')->and($e->fresh()->last_working_day)->toBeNull();

        // Moved from December back into November: refused. Within December: allowed.
        $leaver = lmEmployee(['status' => 'resigned', 'last_working_day' => '2026-12-20']);
        expect(fn () => $status->handle($leaver, 'resigned', '2026-11-28', $admin))->toThrow(ValidationException::class, LM_MESSAGE);
        $status->handle($leaver, 'resigned', '2026-12-15', $admin);
        expect($leaver->fresh()->last_working_day->format('Y-m-d'))->toBe('2026-12-15');

        // Cleared (back to active): refused when the old day is in the locked month, allowed otherwise.
        $novLeaver = lmEmployee(['status' => 'resigned', 'last_working_day' => '2026-11-25']);
        expect(fn () => $status->handle($novLeaver, 'active', null, $admin))->toThrow(ValidationException::class, LM_MESSAGE);
        $status->handle($leaver, 'active', null, $admin);
        expect($leaver->fresh()->status)->toBe('active');

        // A status change that keeps no last working day touches no locked month.
        $status->handle($e, 'probation', null, $admin);
        expect($e->fresh()->status)->toBe('probation');
    });

    it('allows a last working day on the last day of a locked month, and refuses one before it', function () {
        // The window is [joining date, last working day], an open end being 9999-12-31. Setting the last working day
        // to 2026-11-30 changes only days from December on (the person still works all of November): allowed. Setting
        // it to 2026-10-31 removes November from the window: refused.
        lmLock();
        $admin = userWithRole('admin');
        $status = app(ChangeEmployeeStatus::class);

        $e = lmEmployee();
        $status->handle($e, 'resigned', '2026-11-30', $admin);
        expect($e->fresh()->status)->toBe('resigned')
            ->and($e->fresh()->last_working_day->format('Y-m-d'))->toBe('2026-11-30');

        $other = lmEmployee();
        try {
            $status->handle($other, 'resigned', '2026-10-31', $admin);
            $this->fail('A last working day before the locked month was accepted.');
        } catch (ValidationException $ex) {
            expect($ex->errors())->toBe(['last_working_day' => [LM_MESSAGE]]);
        }
        expect($other->fresh()->last_working_day)->toBeNull();

        // Moved from 2026-11-30 to December: November keeps every day, allowed. Back from December to 2026-11-30:
        // also allowed (only December days change).
        $status->handle($e, 'resigned', '2026-12-10', $admin);
        $status->handle($e, 'resigned', '2026-11-30', $admin);
        expect($e->fresh()->last_working_day->format('Y-m-d'))->toBe('2026-11-30');
    });

    it('judges a joining date move by the days it changes', function () {
        lmLock();
        $admin = userWithRole('admin');

        // 2026-12-01 to 2026-11-30 adds 2026-11-30: refused. 2026-11-30 to 2026-12-01 would remove it: refused.
        $dec = lmEmployee(['joining_date' => '2026-12-01']);
        expect(fn () => app(UpdateEmployee::class)->handle($dec, ['joining_date' => '2026-11-30'], $admin, false))
            ->toThrow(ValidationException::class, LM_MESSAGE);
        $nov = lmEmployee(['joining_date' => '2026-11-30']);
        expect(fn () => app(UpdateEmployee::class)->handle($nov, ['joining_date' => '2026-12-01'], $admin, false))
            ->toThrow(ValidationException::class, LM_MESSAGE);

        // 2026-12-02 to 2026-12-01 changes only 2026-12-01: allowed.
        $later = lmEmployee(['joining_date' => '2026-12-02']);
        app(UpdateEmployee::class)->handle($later, ['joining_date' => '2026-12-01'], $admin, false);
        expect($later->fresh()->joining_date->format('Y-m-d'))->toBe('2026-12-01');
    });

    it('shows the refusal on the status form', function () {
        lmLock();
        $e = lmEmployee();

        $this->actingAs(userWithRole('admin'))->from("/employees/{$e->id}")
            ->patch("/employees/{$e->id}/status", ['status' => 'resigned', 'last_working_day' => '2026-11-25'])
            ->assertSessionHasErrors(['last_working_day' => LM_MESSAGE]);
    });
});

// ---------------------------------------------------------------- leave (Phase 4 checks, now live)

describe('leave', function () {
    it('refuses applying for leave in a locked month', function () {
        lmLock();
        $user = userWithRole('employee');
        employeeFor($user, ['joining_date' => '2026-01-01']);

        $this->actingAs($user)->from('/my/leaves')->post('/leave/requests', [
            'leave_type_id' => leaveType('Annual')->id, 'from_date' => '2026-11-23', 'to_date' => '2026-11-24', 'half' => null,
            'reason' => 'Family wedding out of town.',
        ])->assertSessionHasErrors(['from_date' => LM_MESSAGE]);
    });

    it('refuses approving leave in a locked month', function () {
        $hr = userWithRole('hr');
        employeeFor($hr, ['joining_date' => '2026-01-01']);
        $employee = lmEmployee();
        $request = leaveRequest($employee, ['from_date' => '2026-11-23', 'to_date' => '2026-11-24',
            'days' => LeaveDays::plan($employee, '2026-11-23', '2026-11-24', null)->total, 'reason' => 'Family wedding out of town.']);
        lmLock();

        $this->actingAs($hr)->from('/leave/requests')
            ->put("/leave/requests/{$request->id}/final-decide", ['decision' => 'approve', 'note' => null])
            ->assertSessionHas('error', LM_MESSAGE);
    });

    it('cancels no day of approved leave in a locked month', function () {
        $hr = userWithRole('hr');
        employeeFor($hr, ['joining_date' => '2026-01-01']);
        $request = approvedLeave(lmEmployee(), '2026-11-23', '2026-11-24');
        lmLock();

        $this->actingAs($hr)->from("/leave/requests/{$request->id}")
            ->post("/leave/requests/{$request->id}/cancel-approved", ['note' => 'Came back early.'])
            ->assertSessionHas('error', 'No day of this leave can be cancelled any more.');

        expect(LeaveRequest::query()->find($request->id)->status)->toBe('approved');
    });

    it('keeps the leave days of a locked month when weekly offs change', function () {
        // Monday and Tuesday become weekly offs. The person's last closed day is 2026-11-10, so the refund recounts
        // from 2026-11-11: the November leave (locked) keeps both days; the December leave loses both and is cancelled.
        $e = lmEmployee();
        lmRow($e, '2026-11-10', ['closed' => true]);
        $nov = approvedLeave($e, '2026-11-23', '2026-11-24');
        $dec = approvedLeave($e, '2026-12-07', '2026-12-08');
        lmLock();
        $shift = Shift::default();
        $shift->update(['weekly_offs' => ['monday', 'tuesday', 'saturday', 'sunday']]);

        app(WeeklyOffLeaveRefund::class)->forShift($shift->fresh(), userWithRole('admin'));

        expect(LeaveDay::query()->where('leave_request_id', $nov->id)->count())->toBe(2)
            ->and($nov->fresh()->status)->toBe('approved')
            ->and(LeaveDay::query()->where('leave_request_id', $dec->id)->count())->toBe(0)
            ->and($dec->fresh()->status)->toBe('cancelled');
    });

    it('refunds nothing for a holiday on a locked date', function () {
        $request = approvedLeave(lmEmployee(), '2026-11-23', '2026-11-24');
        lmLock();

        expect(app(HolidayLeaveRefund::class)->forDate('2026-11-23', 'Extra day', userWithRole('admin')))->toBe([])
            ->and(LeaveDay::query()->where('leave_request_id', $request->id)->count())->toBe(2);
    });
});

it('keeps the message in one place', function () {
    expect(PayrollLock::message('2026-11'))->toBe(LM_MESSAGE);
});
