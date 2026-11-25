<?php

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\User;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

/*
| Hand-corrected attendance days keep their leave status when the leave goes (cancel, holiday refund, trim): HR is told
| in the flash, the audit entry and on the request page (final fix round I2). "Now" is Wednesday 2026-11-25 14:00 in
| Karachi.
*/

beforeEach(function () {
    $this->withoutVite();
    $this->travelTo(CarbonImmutable::parse('2026-11-25 14:00:00', 'Asia/Karachi'));
    Settings::set('attendance', ['start_date' => '2026-01-01']);
    Mail::fake();
});

/** @return array{0: User, 1: Employee} */
function mdPerson(): array
{
    $user = userWithRole('employee');

    return [$user, employeeFor($user, ['joining_date' => '2026-01-01'])];
}

function mdManual(Employee $e, string $date): Attendance
{
    return Attendance::factory()->create([
        'employee_id' => $e->id, 'date' => $date, 'status' => 'on_leave', 'is_manual' => true, 'closed' => true,
        'clock_in' => "{$date} 09:00:00", 'clock_out' => "{$date} 11:00:00", 'worked_minutes' => 120,
    ]);
}

it('names a hand-corrected day in the cancel flash, the audit entry and on the request page', function () {
    [, $employee] = mdPerson();
    $r = approvedLeave($employee, '2026-11-23', '2026-11-24');
    mdManual($employee, '2026-11-23');
    $hr = userWithRole('hr');

    $this->actingAs($hr)->from("/leave/requests/{$r->id}")
        ->post("/leave/requests/{$r->id}/cancel-approved", ['from_date' => '2026-11-23', 'note' => 'Came in to work after all.'])
        ->assertSessionHas('success', "The leave of {$employee->full_name} was cancelled; 2 days went back to the balance. 23 Nov was corrected by hand on the Daily screen and still shows leave — check it.");

    $entry = Activity::query()->where('description', 'Leave cancelled')->sole();
    expect($entry->properties['manual'])->toBe(['2026-11-23']);

    // Still on leave in attendance (manual wins), so the request page lists it.
    expect(Attendance::query()->where('employee_id', $employee->id)->onDate('2026-11-23')->first()->status)->toBe('on_leave');
    $this->actingAs($hr)->get("/leave/requests/{$r->id}")->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('removed_manual', ['2026-11-23'])->where('needs_attention', []));

    // Once HR clears the manual flag, it drops off.
    Attendance::query()->where('employee_id', $employee->id)->onDate('2026-11-23')->update(['is_manual' => false]);
    $this->actingAs($hr)->get("/leave/requests/{$r->id}")->assertInertia(fn (Assert $page) => $page->where('removed_manual', []));
});

it('names a hand-corrected day in the holiday flash and audit entry', function () {
    [, $employee] = mdPerson();
    $r = approvedLeave($employee, '2026-11-23', '2026-11-24');
    mdManual($employee, '2026-11-23');

    $this->actingAs(userWithRole('admin'))->post('/leave/holidays', ['name' => 'Snow Day', 'date' => '2026-11-23'])
        ->assertSessionHas('success', "Holiday added. 1 person · 1 day went back to their balance. {$employee->full_name}'s 23 Nov was corrected by hand on the Daily screen and still shows leave — check it.");

    $entry = Activity::query()->where('description', 'Leave day refunded (holiday)')->sole();
    expect($entry->properties['manual'])->toBe(['2026-11-23'])
        ->and($r->fresh()->days)->toBe(1.0);
});

it('says nothing extra when the removed days were not corrected by hand', function () {
    [, $employee] = mdPerson();
    $r = approvedLeave($employee, '2026-11-30');

    $this->actingAs(userWithRole('hr'))->post("/leave/requests/{$r->id}/cancel-approved", ['note' => 'Plans changed, not needed.'])
        ->assertSessionHas('success', "The leave of {$employee->full_name} was cancelled; 1 day went back to the balance.");
    expect(Activity::query()->where('description', 'Leave cancelled')->sole()->properties)->not->toHaveKey('manual');
});

it('names hand-corrected days the employment trim removed in the status flash', function () {
    [, $employee] = mdPerson();
    $r = approvedLeave($employee, '2026-11-20', '2026-11-24');
    mdManual($employee, '2026-11-23');
    $admin = userWithRole('admin');

    $this->actingAs($admin)->from("/employees/{$employee->id}")
        ->patch("/employees/{$employee->id}/status", ['status' => 'resigned', 'last_working_day' => '2026-11-20'])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('info', '23 Nov was corrected by hand on the Daily screen and still shows leave — check it.');

    $entry = Activity::query()->where('description', 'Leave trimmed to employment')->sole();
    expect($entry->properties['manual'])->toBe(['2026-11-23'])
        ->and($r->fresh()->days)->toBe(1.0);
});
