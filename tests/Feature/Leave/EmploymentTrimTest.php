<?php

use App\Actions\Employees\UpdateEmployee;
use App\Actions\Leave\LeaveEmploymentTrim;
use App\Mail\LeaveRequestDecided;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\LeaveDay;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Support\Leave\LeaveBalances;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Spatie\Activitylog\Models\Activity;

/*
| Leave trimmed to the employment window (Task 8, plan open decision 13). "Now" is Wednesday 2026-11-25 14:00 in
| Karachi. Status changes go through PATCH /employees/{id}/status (ChangeEmployeeStatus); joining-date changes through
| UpdateEmployee. approvedLeave() writes one leave day per calendar date.
*/

beforeEach(function () {
    $this->withoutVite();
    $this->travelTo(CarbonImmutable::parse('2026-11-25 14:00:00', 'Asia/Karachi'));
    Settings::set('attendance', ['start_date' => '2026-01-01']);
    Mail::fake();
});

/** @return array{0: User, 1: Employee} */
function etrPerson(array $attrs = []): array
{
    $user = userWithRole('employee');

    return [$user, employeeFor($user, ['joining_date' => '2026-01-01', ...$attrs])];
}

/** @return list<string> */
function etrDates(LeaveRequest $r): array
{
    return LeaveDay::query()->where('leave_request_id', $r->id)->orderBy('date')->get()->map(fn ($d) => $d->date->format('Y-m-d'))->all();
}

function etrStatus(User $by, Employee $e, string $status, ?string $lastDay = null)
{
    return test()->actingAs($by)->patch("/employees/{$e->id}/status", array_filter(['status' => $status, 'last_working_day' => $lastDay]));
}

it('removes approved days after a new last working day and cancels open requests after it', function () {
    [, $employee] = etrPerson();
    $hr = userWithRole('hr');
    $partly = approvedLeave($employee, '2026-12-03', '2026-12-08');
    $whole = approvedLeave($employee, '2026-12-14', '2026-12-15');
    $inside = approvedLeave($employee, '2026-11-26');
    $open = leaveRequest($employee, ['from_date' => '2026-12-21', 'to_date' => '2026-12-22', 'days' => 2]);
    $openInside = leaveRequest($employee, ['from_date' => '2026-12-02', 'to_date' => '2026-12-02']);

    etrStatus($hr, $employee, 'resigned', '2026-12-04')->assertSessionHasNoErrors()->assertSessionHas('success');

    $partly->refresh();
    expect($partly->status)->toBe('approved')
        ->and($partly->cancelled_from->format('Y-m-d'))->toBe('2026-12-05')
        ->and($partly->days)->toBe(2.0)
        ->and($partly->cancelled_by)->toBeNull()
        ->and($partly->cancel_reason)->toBe('Employment ends Fri 4 Dec 2026.')
        ->and(etrDates($partly))->toBe(['2026-12-03', '2026-12-04']);

    expect($whole->fresh()->status)->toBe('cancelled')->and(etrDates($whole))->toBe([])
        ->and($whole->fresh()->cancel_reason)->toBe('Employment ends Fri 4 Dec 2026.');

    // Days inside the window are untouched.
    expect(etrDates($inside->fresh()))->toBe(['2026-11-26'])->and($inside->fresh()->cancelled_at)->toBeNull();
    expect($openInside->fresh()->status)->toBe('pending');

    expect($open->fresh()->status)->toBe('cancelled')
        ->and($open->fresh()->cancel_reason)->toBe('Employment ends Fri 4 Dec 2026.')
        ->and($open->fresh()->cancelled_by)->toBeNull();

    expect(app(LeaveBalances::class)->line($employee->fresh(), leaveType(), 2026)->used)->toBe(3.0);

    $entries = Activity::where('log_name', 'leave')->where('description', 'Leave trimmed to employment')->orderBy('id')->get();
    expect($entries)->toHaveCount(3)
        ->and($entries->every(fn ($e) => $e->causer_id === $hr->id && $e->subject_id === $employee->id && $e->properties['automatic'] === true))->toBeTrue();
    $first = $entries->firstWhere('properties.request', $partly->id);
    expect($first->properties['removed'])->toBe(['2026-12-05', '2026-12-06', '2026-12-07', '2026-12-08'])
        ->and($first->properties['refunded'])->toEqual(4)
        ->and($first->properties['outcome'])->toBe('partly_cancelled');
    expect($entries->firstWhere('properties.request', $open->id)->properties['outcome'])->toBe('open_cancelled');

    // The login was disabled in the same change: no mail.
    Mail::assertNothingQueued();
});

it('removes a leaver\'s days from today when there is no last working day', function () {
    [, $employee] = etrPerson();
    $r = approvedLeave($employee, '2026-11-23', '2026-11-27');
    Attendance::factory()->create(['employee_id' => $employee->id, 'date' => '2026-11-24', 'status' => 'on_leave', 'closed' => true]);

    etrStatus(userWithRole('hr'), $employee, 'inactive')->assertSessionHasNoErrors();

    $r->refresh();
    expect(etrDates($r))->toBe(['2026-11-23', '2026-11-24'])
        ->and($r->cancelled_from->format('Y-m-d'))->toBe('2026-11-25')
        ->and($r->days)->toBe(2.0)
        ->and($r->cancel_reason)->toBe('Employment ends Tue 24 Nov 2026.');
    // Today's removed day is marked for the rebuild (which then drops it: the leaver has no day from today).
    expect(Attendance::query()->where('employee_id', $employee->id)->onDate('2026-11-25')->value('needs_rebuild'))->toBeTruthy();
    expect(Attendance::query()->where('employee_id', $employee->id)->onDate('2026-11-24')->value('needs_rebuild'))->toBeFalsy();
});

it('trims days before a joining date moved later, keeps the rest approved and mails the active employee', function () {
    [$user, $employee] = etrPerson(['joining_date' => '2026-11-02']);
    $admin = userWithRole('admin');
    $r = approvedLeave($employee, '2026-11-09', '2026-11-12');
    $before = approvedLeave($employee, '2026-11-04');
    $open = leaveRequest($employee, ['from_date' => '2026-11-05', 'to_date' => '2026-11-06', 'days' => 2]);
    $openOffOnly = leaveRequest($employee, ['from_date' => '2026-11-08', 'to_date' => '2026-11-10', 'days' => 1]); // Sun 8 is an off

    app(UpdateEmployee::class)->handle($employee, ['joining_date' => '2026-11-10'], $admin, syncAccount: false);

    $r->refresh();
    expect(etrDates($r))->toBe(['2026-11-10', '2026-11-11', '2026-11-12'])
        ->and($r->status)->toBe('approved')
        ->and($r->days)->toBe(3.0)
        ->and($r->cancelled_from)->toBeNull();
    expect($before->fresh()->status)->toBe('cancelled')
        ->and($before->fresh()->cancel_reason)->toBe('Joining date moved to Tue 10 Nov 2026.');
    expect($open->fresh()->status)->toBe('cancelled')
        ->and($open->fresh()->cancel_reason)->toBe('Joining date moved to Tue 10 Nov 2026.');
    // Mon 9 Nov counts and is before the new joining date: cancelled too.
    expect($openOffOnly->fresh()->status)->toBe('cancelled');

    $entry = Activity::where('log_name', 'leave')->where('properties->request', $r->id)->sole();
    expect($entry->properties['outcome'])->toBe('trimmed_start')->and($entry->properties['removed'])->toBe(['2026-11-09']);

    Mail::assertQueued(LeaveRequestDecided::class, fn ($m) => $m->hasTo($user->email) && $m->request->is($r) && $m->outcome === 'refunded');
    Mail::assertQueued(LeaveRequestDecided::class, fn ($m) => $m->request->is($before) && $m->outcome === 'cancelled');
    Mail::assertQueued(LeaveRequestDecided::class, 2); // open requests cancelled this way are not mailed
});

it('keeps an open request whose only days outside the window are weekly offs', function () {
    [, $employee] = etrPerson();
    // Fri 4 Dec to Sun 6 Dec: only the Friday counts.
    $open = leaveRequest($employee, ['from_date' => '2026-12-04', 'to_date' => '2026-12-06', 'days' => 1]);

    etrStatus(userWithRole('hr'), $employee, 'resigned', '2026-12-04')->assertSessionHasNoErrors();

    expect($open->fresh()->status)->toBe('pending');
});

it('changes nothing when the window does not shrink, and is idempotent', function () {
    [, $employee] = etrPerson();
    $hr = userWithRole('hr');
    $r = approvedLeave($employee, '2026-12-01', '2026-12-03');

    etrStatus($hr, $employee, 'probation')->assertSessionHasNoErrors();
    expect(etrDates($r->fresh()))->toHaveCount(3);

    etrStatus($hr, $employee, 'resigned', '2026-12-01')->assertSessionHasNoErrors();
    expect(etrDates($r->fresh()))->toBe(['2026-12-01']);

    expect(app(LeaveEmploymentTrim::class)->apply($employee->fresh(), $hr))->toBe(0);
    expect(Activity::where('log_name', 'leave')->count())->toBe(1);
});

it('trims when only the last working day moves earlier', function () {
    [, $employee] = etrPerson(['status' => 'resigned', 'last_working_day' => '2026-12-31']);
    $hr = userWithRole('hr');
    $r = approvedLeave($employee, '2026-12-14', '2026-12-16');

    etrStatus($hr, $employee, 'resigned', '2026-12-14')->assertSessionHasNoErrors();

    expect(etrDates($r->fresh()))->toBe(['2026-12-14'])->and($r->fresh()->cancelled_from->format('Y-m-d'))->toBe('2026-12-15');
});
