<?php

use App\Models\Attendance;
use App\Models\Department;
use App\Models\Designation;
use App\Models\Employee;
use App\Models\Shift;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Spatie\Activitylog\Models\Activity;

/** A complete valid payload for POST/PUT /attendance/shifts, merged with $overrides. */
function shPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Early',
        'start_time' => '07:00',
        'end_time' => '15:00',
        'grace_minutes' => 10,
        'half_day_hours' => 4,
        'weekly_offs' => ['sunday'],
    ], $overrides);
}

/** Fixes "now" at 10:00 on 24 Nov 2026 in the company zone (Asia/Karachi). */
function shToday(): void
{
    test()->travelTo(CarbonImmutable::parse('2026-11-24 10:00:00', 'Asia/Karachi'));
}

// ---------------------------------------------------------------- access

it('lets admin and hr open the shifts page and add a shift', function (string $role) {
    $user = userWithRole($role);

    $this->actingAs($user)->get('/attendance/shifts')->assertOk()
        ->assertInertia(fn ($p) => $p->component('attendance/shifts')->has('shifts', 1));

    $this->actingAs($user)->post('/attendance/shifts', shPayload())->assertSessionHasNoErrors()->assertRedirect();
    expect(Shift::where('name', 'Early')->exists())->toBeTrue();
})->with(['admin', 'hr']);

it('forbids managers and employees on every shift route', function (string $role) {
    $user = userWithRole($role);
    $shift = Shift::factory()->create(['name' => 'Keep']);

    $this->actingAs($user)->get('/attendance/shifts')->assertForbidden();
    $this->actingAs($user)->post('/attendance/shifts', shPayload())->assertForbidden();
    $this->actingAs($user)->put("/attendance/shifts/{$shift->id}", shPayload(['name' => 'Changed']))->assertForbidden();
    $this->actingAs($user)->delete("/attendance/shifts/{$shift->id}")->assertForbidden();
    $this->actingAs($user)->post("/attendance/shifts/{$shift->id}/make-default")->assertForbidden();

    expect(Shift::count())->toBe(2)
        ->and($shift->fresh()->name)->toBe('Keep')
        ->and($shift->fresh()->is_default)->toBeFalse();
})->with(['manager', 'employee']);

it('redirects guests to the sign-in page', function () {
    $shift = Shift::factory()->create();

    $this->get('/attendance/shifts')->assertRedirect('/login');
    $this->post('/attendance/shifts', shPayload())->assertRedirect('/login');
    $this->put("/attendance/shifts/{$shift->id}", shPayload())->assertRedirect('/login');
    $this->delete("/attendance/shifts/{$shift->id}")->assertRedirect('/login');
    $this->post("/attendance/shifts/{$shift->id}/make-default")->assertRedirect('/login');
});

// ---------------------------------------------------------------- list

it('lists the default shift first, then by name, with employee counts', function () {
    $default = Shift::default();
    $late = Shift::factory()->create(['name' => 'Late', 'start_time' => '12:00', 'end_time' => '21:00']);
    Shift::factory()->create(['name' => 'Alpha']);
    Employee::factory()->count(2)->create(['shift_id' => $late->id]);
    Employee::factory()->create(); // no shift of their own: works the default

    $this->actingAs(userWithRole('admin'))->get('/attendance/shifts')
        ->assertInertia(fn ($p) => $p
            ->has('shifts', 3)
            ->where('shifts.0.id', $default->id)
            ->where('shifts.0.is_default', true)
            ->where('shifts.0.employees_count', 1)
            ->where('shifts.1.name', 'Alpha')
            ->where('shifts.2.name', 'Late')
            ->where('shifts.2.start_time', '12:00')
            ->where('shifts.2.end_time', '21:00')
            ->where('shifts.2.employees_count', 2)
            ->where('shifts.2.weekly_offs', ['saturday', 'sunday'])
            ->where('weekdays', config('hrm.attendance.weekdays')));
});

// ---------------------------------------------------------------- rules

it('stores a valid shift exactly, never as the default, and audits it', function () {
    $this->actingAs(userWithRole('admin'))
        ->post('/attendance/shifts', shPayload(['name' => '  Early   Bird ', 'weekly_offs' => ['sunday', 'friday'], 'half_day_hours' => '4.5', 'is_default' => true]))
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success');

    $shift = Shift::where('name', 'Early Bird')->firstOrFail();
    expect($shift->start_time)->toBe('07:00')
        ->and($shift->end_time)->toBe('15:00')
        ->and($shift->grace_minutes)->toBe(10)
        ->and($shift->half_day_hours)->toBe('4.50')
        ->and($shift->weekly_offs)->toBe(['friday', 'sunday'])
        ->and($shift->is_default)->toBeFalse();

    expect(Activity::where('log_name', 'attendance')->where('description', 'Shift created')->count())->toBe(1);
});

it('rejects a duplicate shift name, ignoring case and spaces, but lets a shift keep its own name', function () {
    $admin = userWithRole('admin');
    $shift = Shift::factory()->create(['name' => 'Early']);

    $this->actingAs($admin)->post('/attendance/shifts', shPayload(['name' => ' early ']))->assertSessionHasErrors('name');
    $this->actingAs($admin)->post('/attendance/shifts', shPayload(['name' => 'general']))->assertSessionHasErrors('name');
    $this->actingAs($admin)->put("/attendance/shifts/{$shift->id}", shPayload(['name' => 'EARLY']))->assertSessionHasNoErrors();
    expect($shift->fresh()->name)->toBe('EARLY');
});

it('validates each field', function (array $overrides, string $field) {
    $this->actingAs(userWithRole('admin'))->post('/attendance/shifts', shPayload($overrides))->assertSessionHasErrors($field);
    expect(Shift::where('name', 'Early')->exists())->toBeFalse();
})->with([
    'missing name' => [['name' => ''], 'name'],
    'long name' => [['name' => str_repeat('a', 101)], 'name'],
    'array name' => [['name' => ['x']], 'name'],
    'bad start' => [['start_time' => '25:00'], 'start_time'],
    'missing end' => [['end_time' => ''], 'end_time'],
    'end equals start' => [['start_time' => '09:00', 'end_time' => '09:00'], 'end_time'],
    'end before start' => [['start_time' => '18:00', 'end_time' => '09:00'], 'end_time'],
    'negative grace' => [['grace_minutes' => -1], 'grace_minutes'],
    'grace over 120' => [['grace_minutes' => 121], 'grace_minutes'],
    'fractional grace' => [['grace_minutes' => 1.5], 'grace_minutes'],
    'half day under 1' => [['half_day_hours' => 0.5], 'half_day_hours'],
    'half day over 12' => [['half_day_hours' => 12.5], 'half_day_hours'],
    'half day not a number' => [['half_day_hours' => 'four'], 'half_day_hours'],
    'unknown day' => [['weekly_offs' => ['funday']], 'weekly_offs.0'],
    'capitalised day' => [['weekly_offs' => ['Sunday']], 'weekly_offs.0'],
    'repeated day' => [['weekly_offs' => ['sunday', 'sunday']], 'weekly_offs.0'],
    'offs not a list' => [['weekly_offs' => 'sunday'], 'weekly_offs'],
]);

it('rejects a half day longer than the shift, with the message on half_day_hours', function () {
    $this->actingAs(userWithRole('admin'))
        ->post('/attendance/shifts', shPayload(['start_time' => '09:00', 'end_time' => '12:00', 'half_day_hours' => 3.5]))
        ->assertSessionHasErrors(['half_day_hours' => 'The half day cannot be longer than the shift (3 hours).']);
    expect(Shift::where('name', 'Early')->exists())->toBeFalse();

    // Exactly the shift's length is allowed.
    $this->actingAs(userWithRole('admin'))
        ->post('/attendance/shifts', shPayload(['start_time' => '09:00', 'end_time' => '12:00', 'half_day_hours' => 3]))
        ->assertSessionHasNoErrors();

    $shift = Shift::where('name', 'Early')->firstOrFail();
    $this->actingAs(userWithRole('admin'))
        ->put("/attendance/shifts/{$shift->id}", shPayload(['start_time' => '09:00', 'end_time' => '10:30', 'half_day_hours' => 2]))
        ->assertSessionHasErrors(['half_day_hours' => 'The half day cannot be longer than the shift (1.5 hours).']);
});

it('rejects renaming a shift to another shift\'s name in a different case', function () {
    $early = Shift::factory()->create(['name' => 'Early']);
    $late = Shift::factory()->create(['name' => 'Late']);

    $this->actingAs(userWithRole('admin'))->put("/attendance/shifts/{$late->id}", shPayload(['name' => 'eARLY']))
        ->assertSessionHasErrors(['name' => 'A shift with this name already exists.']);
    expect($late->fresh()->name)->toBe('Late')->and($early->fresh()->name)->toBe('Early');
});

it('audits a shift update with the changed fields', function () {
    $shift = Shift::factory()->create(['name' => 'Early', 'grace_minutes' => 15]);

    $this->actingAs(userWithRole('admin'))->put("/attendance/shifts/{$shift->id}", shPayload(['name' => 'Early', 'grace_minutes' => 20]))
        ->assertSessionHasNoErrors();

    $entries = Activity::where('log_name', 'attendance')->where('description', 'Shift updated')->get();
    expect($entries)->toHaveCount(1)
        ->and($entries[0]->subject_id)->toBe($shift->id)
        ->and($entries[0]->properties['attributes']['grace_minutes'])->toBe(20)
        ->and($entries[0]->properties['old']['grace_minutes'])->toBe(15);
});

it('audits make-default as one entry per shift whose flag moved', function () {
    $old = Shift::default();
    $shift = Shift::factory()->create(['name' => 'Ramadan']);
    $before = Activity::max('id') ?? 0;

    $this->actingAs(userWithRole('admin'))->post("/attendance/shifts/{$shift->id}/make-default")->assertSessionHas('success');

    $entries = Activity::where('id', '>', $before)->where('log_name', 'attendance')->orderBy('id')->get();
    expect($entries->pluck('description')->all())->toBe(['Shift updated', 'Shift updated'])
        ->and($entries->pluck('subject_id')->all())->toBe([$old->id, $shift->id])
        ->and($entries[0]->properties['attributes']['is_default'])->toBeFalse()
        ->and($entries[1]->properties['attributes']['is_default'])->toBeTrue();

    // Asking again changes nothing and writes nothing.
    $this->actingAs(userWithRole('admin'))->post("/attendance/shifts/{$shift->id}/make-default")->assertSessionHas('info');
    expect(Activity::where('id', '>', $before)->count())->toBe(2);
});

it('accepts the edges of each range and no weekly offs at all', function () {
    $this->actingAs(userWithRole('admin'))
        ->post('/attendance/shifts', shPayload(['grace_minutes' => 0, 'half_day_hours' => 1, 'weekly_offs' => []]))
        ->assertSessionHasNoErrors();
    $this->actingAs(userWithRole('admin'))
        ->post('/attendance/shifts', shPayload(['name' => 'Long', 'start_time' => '06:00', 'end_time' => '23:00', 'grace_minutes' => 120, 'half_day_hours' => 12]))
        ->assertSessionHasNoErrors();

    expect(Shift::where('name', 'Early')->firstOrFail()->weekly_offs)->toBe([])
        ->and(Shift::where('name', 'Long')->firstOrFail()->grace_minutes)->toBe(120);
});

// ---------------------------------------------------------------- delete

it('deletes an unused shift', function () {
    $shift = Shift::factory()->create();

    $this->actingAs(userWithRole('hr'))->delete("/attendance/shifts/{$shift->id}")->assertSessionHas('success');
    expect($shift->fresh())->toBeNull()
        ->and(Activity::where('log_name', 'attendance')->where('description', 'Shift deleted')->count())->toBe(1);
});

it('will not delete the default shift', function () {
    $default = Shift::default();

    $this->actingAs(userWithRole('admin'))->delete("/attendance/shifts/{$default->id}")
        ->assertSessionHas('error', 'The default shift cannot be deleted. Make another shift the default first.');
    expect($default->fresh())->not->toBeNull();
});

it('will not delete a shift that employees work', function () {
    $shift = Shift::factory()->create();
    $employee = Employee::factory()->create(['shift_id' => $shift->id]);

    $this->actingAs(userWithRole('admin'))->delete("/attendance/shifts/{$shift->id}")
        ->assertSessionHas('error', "Move this shift's employees first.");
    expect($shift->fresh())->not->toBeNull()
        ->and($employee->fresh()->shift_id)->toBe($shift->id);
});

it('will not delete a shift recorded on past attendance days, keeping the record', function () {
    $shift = Shift::factory()->create();
    $employee = Employee::factory()->create(); // since moved to the default shift
    $day = Attendance::factory()->create(['employee_id' => $employee->id, 'date' => '2026-11-20', 'shift_id' => $shift->id, 'closed' => true]);

    $this->actingAs(userWithRole('admin'))->delete("/attendance/shifts/{$shift->id}")
        ->assertSessionHas('error', 'This shift is recorded on past attendance days and cannot be deleted.');
    expect($shift->fresh())->not->toBeNull()
        ->and($day->fresh()->shift_id)->toBe($shift->id);
});

it('returns 404 for an unknown shift', function () {
    $admin = userWithRole('admin');
    $this->actingAs($admin)->put('/attendance/shifts/999999', shPayload())->assertNotFound();
    $this->actingAs($admin)->delete('/attendance/shifts/999999')->assertNotFound();
    $this->actingAs($admin)->post('/attendance/shifts/999999/make-default')->assertNotFound();
});

// ---------------------------------------------------------------- default

it('makes another shift the default, leaving exactly one', function () {
    $old = Shift::default();
    $shift = Shift::factory()->create(['name' => 'Ramadan']);

    $this->actingAs(userWithRole('hr'))->post("/attendance/shifts/{$shift->id}/make-default")->assertSessionHas('success');

    expect($shift->fresh()->is_default)->toBeTrue()
        ->and($old->fresh()->is_default)->toBeFalse()
        ->and(Shift::where('is_default', true)->count())->toBe(1);

    // The old default is an ordinary shift now and may be deleted.
    $this->actingAs(userWithRole('hr'))->delete("/attendance/shifts/{$old->id}")->assertSessionHas('success');
});

it('ignores an is_default flag posted with an update', function () {
    $shift = Shift::factory()->create();

    $this->actingAs(userWithRole('admin'))->put("/attendance/shifts/{$shift->id}", shPayload(['is_default' => true]))->assertSessionHasNoErrors();
    expect($shift->fresh()->is_default)->toBeFalse()
        ->and(Shift::default()->id)->not->toBe($shift->id);
});

// ---------------------------------------------------------------- rebuild marking

it('marks today and future pending days of the shift\'s employees for rebuild, not closed or manual days', function () {
    shToday();
    $shift = Shift::factory()->create();
    $own = Employee::factory()->create(['shift_id' => $shift->id]);
    $other = Employee::factory()->create(); // default shift

    $yesterday = Attendance::factory()->create(['employee_id' => $own->id, 'date' => '2026-11-23', 'closed' => true]);
    $today = Attendance::factory()->create(['employee_id' => $own->id, 'date' => '2026-11-24']);
    $tomorrow = Attendance::factory()->create(['employee_id' => $own->id, 'date' => '2026-11-25']);
    $manual = Attendance::factory()->create(['employee_id' => $own->id, 'date' => '2026-11-26', 'is_manual' => true]);
    $notMine = Attendance::factory()->create(['employee_id' => $other->id, 'date' => '2026-11-24']);

    $this->actingAs(userWithRole('admin'))->put("/attendance/shifts/{$shift->id}", shPayload(['name' => $shift->name, 'start_time' => '10:00', 'end_time' => '19:00']))
        ->assertSessionHasNoErrors();

    expect($yesterday->fresh()->needs_rebuild)->toBeFalse()
        ->and($today->fresh()->needs_rebuild)->toBeTrue()
        ->and($tomorrow->fresh()->needs_rebuild)->toBeTrue()
        ->and($manual->fresh()->needs_rebuild)->toBeFalse()
        ->and($notMine->fresh()->needs_rebuild)->toBeFalse();
});

it('marks the days of employees without a shift when the default shift changes', function () {
    shToday();
    $default = Shift::default();
    $plain = Employee::factory()->create();
    $day = Attendance::factory()->create(['employee_id' => $plain->id, 'date' => '2026-11-24']);

    $this->actingAs(userWithRole('admin'))->put("/attendance/shifts/{$default->id}", shPayload(['name' => $default->name, 'grace_minutes' => 30]))
        ->assertSessionHasNoErrors();

    expect($day->fresh()->needs_rebuild)->toBeTrue();
});

it('marks nothing when only the name changes', function () {
    shToday();
    $shift = Shift::factory()->create(['name' => 'Old', 'start_time' => '09:00', 'end_time' => '18:00', 'grace_minutes' => 15, 'half_day_hours' => 4, 'weekly_offs' => ['saturday', 'sunday']]);
    $employee = Employee::factory()->create(['shift_id' => $shift->id]);
    $day = Attendance::factory()->create(['employee_id' => $employee->id, 'date' => '2026-11-24']);

    $this->actingAs(userWithRole('admin'))->put("/attendance/shifts/{$shift->id}", shPayload([
        'name' => 'New', 'start_time' => '09:00', 'end_time' => '18:00', 'grace_minutes' => 15, 'half_day_hours' => 4, 'weekly_offs' => ['saturday', 'sunday'],
    ]))->assertSessionHasNoErrors();

    expect($shift->fresh()->name)->toBe('New')
        ->and($day->fresh()->needs_rebuild)->toBeFalse();
});

it('marks the pending days of employees without a shift when the default moves', function () {
    shToday();
    $plain = Employee::factory()->create();
    $day = Attendance::factory()->create(['employee_id' => $plain->id, 'date' => '2026-11-24']);
    $closed = Attendance::factory()->create(['employee_id' => $plain->id, 'date' => '2026-11-20', 'closed' => true]);
    $shift = Shift::factory()->create();

    $this->actingAs(userWithRole('admin'))->post("/attendance/shifts/{$shift->id}/make-default")->assertSessionHas('success');

    expect($day->fresh()->needs_rebuild)->toBeTrue()
        ->and($closed->fresh()->needs_rebuild)->toBeFalse();
});

// ---------------------------------------------------------------- employee form

it('offers the shifts on the employee form and shows the stored shift and exempt flag', function () {
    $dept = Department::factory()->create();
    $desig = Designation::factory()->create(['department_id' => $dept->id]);
    $shift = Shift::factory()->create(['name' => 'Early', 'start_time' => '07:00', 'end_time' => '15:00']);
    $employee = Employee::factory()->create([
        'department_id' => $dept->id, 'designation_id' => $desig->id, 'shift_id' => $shift->id, 'attendance_exempt' => true,
    ]);

    $props = $this->actingAs(userWithRole('hr'))->get("/employees/{$employee->id}/edit")->assertOk()->viewData('page')['props'];

    expect($props['employee'])->toMatchArray(['shift_id' => $shift->id, 'attendance_exempt' => true])
        ->and(array_column($props['shifts'], 'name'))->toBe(['General', 'Early'])
        ->and($props['shifts'][1])->toMatchArray(['id' => $shift->id, 'start_time' => '07:00', 'end_time' => '15:00', 'is_default' => false])
        ->and($props['shifts'][0]['is_default'])->toBeTrue();

    $create = $this->actingAs(userWithRole('admin'))->get('/employees/create')->assertOk()->viewData('page')['props'];
    expect($create['shifts'])->toHaveCount(2);
});

it('lets HR save the shift and exempt flag from the employee form, but not a manager', function () {
    $dept = Department::factory()->create();
    $desig = Designation::factory()->create(['department_id' => $dept->id]);
    $shift = Shift::factory()->create();
    $employee = Employee::factory()->create(['department_id' => $dept->id, 'designation_id' => $desig->id, 'joining_date' => '2024-03-01']);

    $payload = [
        'first_name' => $employee->first_name, 'last_name' => $employee->last_name, 'email' => $employee->email,
        'department_id' => $dept->id, 'designation_id' => $desig->id, 'joining_date' => '2024-03-01',
        'employment_type' => $employee->employment_type, 'status' => $employee->status,
        'shift_id' => $shift->id, 'attendance_exempt' => true,
    ];

    $manager = userWithRole('manager');
    employeeFor($manager);
    $employee->forceFill(['manager_id' => $manager->employee_id])->save();

    $this->actingAs($manager)->put("/employees/{$employee->id}", $payload)->assertForbidden();
    expect($employee->fresh()->shift_id)->toBeNull()->and($employee->fresh()->attendance_exempt)->toBeFalse();

    $this->actingAs(userWithRole('hr'))->put("/employees/{$employee->id}", $payload)->assertSessionHasNoErrors();
    expect($employee->fresh()->shift_id)->toBe($shift->id)->and($employee->fresh()->attendance_exempt)->toBeTrue();
});

it('marks the employee\'s open days from today when their shift or exempt flag changes, and nothing otherwise', function (array $change) {
    shToday();
    $dept = Department::factory()->create();
    $desig = Designation::factory()->create(['department_id' => $dept->id]);
    $shift = Shift::factory()->create();
    $employee = Employee::factory()->create(['department_id' => $dept->id, 'designation_id' => $desig->id, 'joining_date' => '2024-03-01']);
    $other = Employee::factory()->create();

    $yesterday = Attendance::factory()->create(['employee_id' => $employee->id, 'date' => '2026-11-23']);
    $closed = Attendance::factory()->create(['employee_id' => $employee->id, 'date' => '2026-11-24', 'closed' => true]);
    $tomorrow = Attendance::factory()->create(['employee_id' => $employee->id, 'date' => '2026-11-25']);
    $manual = Attendance::factory()->create(['employee_id' => $employee->id, 'date' => '2026-11-26', 'is_manual' => true]);
    $notMine = Attendance::factory()->create(['employee_id' => $other->id, 'date' => '2026-11-25']);

    $payload = fn (array $overrides) => array_merge([
        'first_name' => $employee->first_name, 'last_name' => $employee->last_name, 'email' => $employee->email,
        'department_id' => $dept->id, 'designation_id' => $desig->id, 'joining_date' => '2024-03-01',
        'employment_type' => $employee->employment_type, 'status' => $employee->status,
        'shift_id' => null, 'attendance_exempt' => false,
    ], $overrides);

    $hr = userWithRole('hr');

    // An unrelated change marks nothing.
    $this->actingAs($hr)->put("/employees/{$employee->id}", $payload(['last_name' => 'Renamed']))->assertSessionHasNoErrors();
    expect($tomorrow->fresh()->needs_rebuild)->toBeFalse();

    $values = array_map(fn ($v) => $v === 'shift' ? $shift->id : $v, $change);
    $this->actingAs($hr)->put("/employees/{$employee->id}", $payload(['last_name' => 'Renamed', ...$values]))->assertSessionHasNoErrors();

    expect($tomorrow->fresh()->needs_rebuild)->toBeTrue()
        ->and($yesterday->fresh()->needs_rebuild)->toBeFalse()
        ->and($closed->fresh()->needs_rebuild)->toBeFalse()
        ->and($manual->fresh()->needs_rebuild)->toBeFalse()
        ->and($notMine->fresh()->needs_rebuild)->toBeFalse();
})->with([
    'shift' => [['shift_id' => 'shift']],
    'exempt flag' => [['attendance_exempt' => true]],
]);

// ---------------------------------------------------------------- day cutoff margin

it('requires a shift to start at least 2 hours after the day cutoff', function () {
    $admin = userWithRole('admin');

    // Default cutoff 04:00: 05:59 is too early, 06:00 is the earliest start.
    $this->actingAs($admin)->post('/attendance/shifts', shPayload(['name' => 'Dawn', 'start_time' => '05:59', 'end_time' => '14:00']))
        ->assertSessionHasErrors(['start_time' => 'The shift must start at 06:00 or later: at least 2 hours after the attendance day cutoff (04:00), so early clock-ins are not counted on the previous day.']);
    $this->actingAs($admin)->post('/attendance/shifts', shPayload(['name' => 'Dawn', 'start_time' => '06:00', 'end_time' => '14:00']))
        ->assertSessionHasNoErrors();

    // A later cutoff moves the margin: with 06:00, a 05:00 shift (the reviewer's case) and a 07:59 start are refused.
    Settings::set('attendance', ['day_cutoff_time' => '06:00']);
    $this->actingAs($admin)->post('/attendance/shifts', shPayload(['name' => 'Five', 'start_time' => '05:00', 'end_time' => '14:00']))
        ->assertSessionHasErrors('start_time');
    $shift = Shift::query()->where('name', 'Dawn')->firstOrFail();
    $this->actingAs($admin)->put("/attendance/shifts/{$shift->id}", shPayload(['name' => 'Dawn', 'start_time' => '07:59', 'end_time' => '14:00']))
        ->assertSessionHasErrors('start_time');
    $this->actingAs($admin)->put("/attendance/shifts/{$shift->id}", shPayload(['name' => 'Dawn', 'start_time' => '08:00', 'end_time' => '14:00']))
        ->assertSessionHasNoErrors();
});
