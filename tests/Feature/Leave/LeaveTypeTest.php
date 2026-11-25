<?php

use App\Http\Controllers\Leave\LeaveTypeController;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use Spatie\Activitylog\Models\Activity;

/** A complete valid payload for POST/PUT /leave/types, merged with $overrides. */
function ltPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Study',
        'yearly_quota' => 5,
        'is_paid' => true,
        'carry_forward' => false,
        'carry_forward_cap' => null,
        'is_active' => true,
        'colour' => 'violet',
    ], $overrides);
}

beforeEach(function () {
    $this->withoutVite(); // the page is not in the build manifest until the coordinator builds
});

function ltSystem(): LeaveType
{
    return LeaveType::query()->where('is_system', true)->firstOrFail();
}

// ---------------------------------------------------------------- access

it('lets admin and hr open the leave types page and add a type', function (string $role) {
    $user = userWithRole($role);

    $this->actingAs($user)->get('/leave/types')->assertOk()
        ->assertInertia(fn ($p) => $p->component('leave/types')->has('types', 4)
            ->where('colours', config('hrm.leave.colours')));

    $this->actingAs($user)->post('/leave/types', ltPayload())->assertSessionHasNoErrors()->assertRedirect();
    expect(LeaveType::where('name', 'Study')->exists())->toBeTrue();
})->with(['admin', 'hr']);

it('forbids managers and employees on every leave type route', function (string $role) {
    $user = userWithRole($role);
    $type = leaveType('Sick');

    $this->actingAs($user)->get('/leave/types')->assertForbidden();
    $this->actingAs($user)->post('/leave/types', ltPayload())->assertForbidden();
    $this->actingAs($user)->put("/leave/types/{$type->id}", ltPayload(['name' => 'Changed']))->assertForbidden();
    $this->actingAs($user)->delete("/leave/types/{$type->id}")->assertForbidden();

    expect(LeaveType::count())->toBe(4)
        ->and($type->fresh()->name)->toBe('Sick');
})->with(['manager', 'employee']);

it('redirects guests to the sign-in page', function () {
    $type = leaveType('Sick');

    $this->get('/leave/types')->assertRedirect('/login');
    $this->post('/leave/types', ltPayload())->assertRedirect('/login');
    $this->put("/leave/types/{$type->id}", ltPayload())->assertRedirect('/login');
    $this->delete("/leave/types/{$type->id}")->assertRedirect('/login');
});

// ---------------------------------------------------------------- list

it('lists the types in sort order with their rules and history flags', function () {
    $employee = Employee::factory()->create();
    leaveRequest($employee, ['leave_type_id' => leaveType('Sick')->id]);
    LeaveBalance::factory()->create(['employee_id' => $employee->id, 'leave_type_id' => leaveType('Casual')->id]);

    $this->actingAs(userWithRole('admin'))->get('/leave/types')
        ->assertInertia(fn ($p) => $p
            ->has('types', 4)
            ->where('types.0.name', 'Annual')
            ->where('types.0.yearly_quota', 14)
            ->where('types.0.is_paid', true)
            ->where('types.0.carry_forward', true)
            ->where('types.0.carry_forward_cap', 5)
            ->where('types.0.colour', 'emerald')
            ->where('types.0.is_system', false)
            ->where('types.0.has_history', false)
            ->where('types.1.name', 'Sick')
            ->where('types.1.has_requests', true)
            ->where('types.1.has_history', true)
            ->where('types.2.name', 'Casual')
            ->where('types.2.has_requests', false)
            ->where('types.2.has_history', true)
            ->where('types.3.name', 'Unpaid')
            ->where('types.3.is_paid', false)
            ->where('types.3.is_system', true));
});

// ---------------------------------------------------------------- create

it('creates a paid type at the end of the list and no balance rows', function () {
    Employee::factory()->count(2)->create();

    $this->actingAs(userWithRole('admin'))
        ->post('/leave/types', ltPayload(['yearly_quota' => 7.5, 'carry_forward' => true, 'carry_forward_cap' => 2.5]))
        ->assertSessionHas('success');

    $type = LeaveType::where('name', 'Study')->firstOrFail();
    expect($type->yearly_quota)->toBe(7.5)
        ->and($type->is_paid)->toBeTrue()
        ->and($type->carry_forward)->toBeTrue()
        ->and($type->carry_forward_cap)->toBe(2.5)
        ->and($type->is_system)->toBeFalse()
        ->and($type->sort_order)->toBe(5)
        ->and($type->colour)->toBe('violet')
        ->and(LeaveBalance::count())->toBe(0);
});

it('stores an unpaid type with no quota and no carry forward, whatever was sent', function () {
    $this->actingAs(userWithRole('admin'))
        ->post('/leave/types', ltPayload(['is_paid' => false, 'yearly_quota' => 'junk', 'carry_forward' => true, 'carry_forward_cap' => 9]))
        ->assertSessionHasNoErrors();

    $type = LeaveType::where('name', 'Study')->firstOrFail();
    expect($type->is_paid)->toBeFalse()
        ->and($type->yearly_quota)->toBe(0.0)
        ->and($type->carry_forward)->toBeFalse()
        ->and($type->carry_forward_cap)->toBe(0.0)
        ->and($type->is_system)->toBeFalse();
});

it('ignores the cap when carry forward is off', function () {
    $this->actingAs(userWithRole('admin'))
        ->post('/leave/types', ltPayload(['carry_forward' => false, 'carry_forward_cap' => 'nonsense']))
        ->assertSessionHasNoErrors();

    expect(LeaveType::where('name', 'Study')->value('carry_forward_cap'))->toEqual(0);
});

it('rejects invalid values', function (array $overrides, string $field) {
    $this->actingAs(userWithRole('admin'))->post('/leave/types', ltPayload($overrides))->assertSessionHasErrors($field);

    expect(LeaveType::count())->toBe(4);
})->with([
    'no name' => [['name' => '   '], 'name'],
    'long name' => [['name' => str_repeat('a', 61)], 'name'],
    'quota missing' => [['yearly_quota' => null], 'yearly_quota'],
    'quota negative' => [['yearly_quota' => -0.5], 'yearly_quota'],
    'quota too big' => [['yearly_quota' => 365.5], 'yearly_quota'],
    'quota not a half' => [['yearly_quota' => 1.25], 'yearly_quota'],
    'quota text' => [['yearly_quota' => 'ten'], 'yearly_quota'],
    'cap missing' => [['carry_forward' => true, 'carry_forward_cap' => null], 'carry_forward_cap'],
    'cap negative' => [['carry_forward' => true, 'carry_forward_cap' => -1], 'carry_forward_cap'],
    'cap too big' => [['carry_forward' => true, 'carry_forward_cap' => 366], 'carry_forward_cap'],
    'cap not a half' => [['carry_forward' => true, 'carry_forward_cap' => 0.3], 'carry_forward_cap'],
    'colour unknown' => [['colour' => 'pink'], 'colour'],
    'colour missing' => [['colour' => null], 'colour'],
    'paid missing' => [['is_paid' => null], 'is_paid'],
    'active not boolean' => [['is_active' => 'maybe'], 'is_active'],
]);

it('accepts the edges: 0 and 365 days, halves', function (float $quota) {
    $this->actingAs(userWithRole('admin'))
        ->post('/leave/types', ltPayload(['yearly_quota' => $quota, 'carry_forward' => true, 'carry_forward_cap' => max($quota, 0.5)]))
        ->assertSessionHasNoErrors();

    expect(LeaveType::where('name', 'Study')->value('yearly_quota'))->toEqual($quota);
})->with([0.0, 0.5, 365.0, 364.5]);

it('needs a cap of at least half a day when carry forward is on', function () {
    $admin = userWithRole('admin');

    $this->actingAs($admin)->post('/leave/types', ltPayload(['carry_forward' => true, 'carry_forward_cap' => 0]))
        ->assertSessionHasErrors('carry_forward_cap');
    $this->actingAs($admin)->post('/leave/types', ltPayload(['carry_forward' => true, 'carry_forward_cap' => 0.5]))
        ->assertSessionHasNoErrors();

    expect(LeaveType::where('name', 'Study')->value('carry_forward_cap'))->toEqual(0.5);
});

it('stores quota and cap rounded to the nearest half day', function () {
    $this->actingAs(userWithRole('admin'))
        ->post('/leave/types', ltPayload(['yearly_quota' => '7.4999999999', 'carry_forward' => true, 'carry_forward_cap' => '2.5000000001']))
        ->assertSessionHasNoErrors();

    $type = LeaveType::where('name', 'Study')->firstOrFail();
    expect($type->yearly_quota)->toBe(7.5)->and($type->carry_forward_cap)->toBe(2.5);
});

it('rejects a duplicate name ignoring case and spaces, but lets a type keep its own name', function () {
    $admin = userWithRole('admin');

    $this->actingAs($admin)->post('/leave/types', ltPayload(['name' => '  annual ']))->assertSessionHasErrors('name');
    $this->actingAs($admin)->post('/leave/types', ltPayload(['name' => 'UNPAID']))->assertSessionHasErrors('name');

    $sick = leaveType('Sick');
    $this->actingAs($admin)->put("/leave/types/{$sick->id}", ltPayload(['name' => 'sick', 'yearly_quota' => 8, 'colour' => 'rose']))
        ->assertSessionHasNoErrors();
    expect($sick->fresh()->name)->toBe('sick');

    $this->actingAs($admin)->put("/leave/types/{$sick->id}", ltPayload(['name' => 'casual']))->assertSessionHasErrors('name');
    expect(LeaveType::count())->toBe(4);
});

it('collapses spaces in the name', function () {
    $this->actingAs(userWithRole('admin'))->post('/leave/types', ltPayload(['name' => "  Study \t  leave "]))->assertSessionHasNoErrors();

    expect(LeaveType::where('name', 'Study leave')->exists())->toBeTrue();
});

// ---------------------------------------------------------------- update

it('updates a type and audits old and new values', function () {
    $annual = leaveType('Annual');
    $before = Activity::max('id') ?? 0;

    $this->actingAs(userWithRole('hr'))
        ->put("/leave/types/{$annual->id}", ltPayload(['name' => 'Annual', 'yearly_quota' => 15, 'carry_forward' => true, 'carry_forward_cap' => 3, 'colour' => 'teal']))
        ->assertSessionHas('success');

    $annual->refresh();
    expect($annual->yearly_quota)->toBe(15.0)
        ->and($annual->carry_forward_cap)->toBe(3.0)
        ->and($annual->colour)->toBe('teal');

    $entries = Activity::where('id', '>', $before)->where('log_name', 'leave')->get();
    expect($entries)->toHaveCount(1)
        ->and($entries[0]->description)->toBe('Leave type updated')
        ->and($entries[0]->subject_id)->toBe($annual->id)
        ->and((float) $entries[0]->properties['old']['yearly_quota'])->toBe(14.0)
        ->and((float) $entries[0]->properties['attributes']['yearly_quota'])->toBe(15.0)
        ->and((float) $entries[0]->properties['old']['carry_forward_cap'])->toBe(5.0)
        ->and((float) $entries[0]->properties['attributes']['carry_forward_cap'])->toBe(3.0)
        ->and($entries[0]->properties['old']['colour'])->toBe('emerald')
        ->and($entries[0]->properties['attributes']['colour'])->toBe('teal');
});

it('audits creating and deleting a type', function () {
    $admin = userWithRole('admin');

    $this->actingAs($admin)->post('/leave/types', ltPayload())->assertSessionHas('success');
    $type = LeaveType::where('name', 'Study')->firstOrFail();
    $this->actingAs($admin)->delete("/leave/types/{$type->id}")->assertSessionHas('success');

    $created = Activity::where('log_name', 'leave')->where('description', 'Leave type created')->sole();
    $deleted = Activity::where('log_name', 'leave')->where('description', 'Leave type deleted')->sole();
    expect($created->subject_id)->toBe($type->id)
        ->and($created->properties['attributes']['name'])->toBe('Study')
        ->and($created->causer_id)->toBe($admin->id)
        ->and($deleted->subject_id)->toBe($type->id)
        ->and($deleted->properties['old']['name'])->toBe('Study');
});

it('writes no audit entry when nothing changed', function () {
    $sick = leaveType('Sick');
    $before = Activity::max('id') ?? 0;

    $this->actingAs(userWithRole('admin'))
        ->put("/leave/types/{$sick->id}", ltPayload(['name' => 'Sick', 'yearly_quota' => 8, 'colour' => 'rose']))
        ->assertSessionHas('success');

    expect(Activity::where('id', '>', $before)->count())->toBe(0);
});

it('deactivates a type and keeps its history', function () {
    $casual = leaveType('Casual');
    $request = leaveRequest(Employee::factory()->create(), ['leave_type_id' => $casual->id]);

    $this->actingAs(userWithRole('admin'))
        ->put("/leave/types/{$casual->id}", ltPayload(['name' => 'Casual', 'yearly_quota' => 10, 'colour' => 'sky', 'is_active' => false]))
        ->assertSessionHas('success');

    expect($casual->fresh()->is_active)->toBeFalse()
        ->and($request->fresh())->not->toBeNull()
        ->and(LeaveType::active()->pluck('name')->all())->not->toContain('Casual');
});

it('never makes the system Unpaid type paid', function () {
    $unpaid = ltSystem();

    $this->actingAs(userWithRole('admin'))
        ->put("/leave/types/{$unpaid->id}", ltPayload(['name' => 'Unpaid', 'is_paid' => true, 'yearly_quota' => 10, 'colour' => 'slate']))
        ->assertSessionHasErrors(['is_paid' => LeaveTypeController::SYSTEM_PAID_MESSAGE]);

    expect($unpaid->fresh()->is_paid)->toBeFalse()
        ->and($unpaid->fresh()->yearly_quota)->toBe(0.0);
});

it('never makes the system Unpaid type inactive', function () {
    $unpaid = ltSystem();

    $this->actingAs(userWithRole('admin'))
        ->put("/leave/types/{$unpaid->id}", ltPayload(['name' => 'Unpaid', 'is_paid' => false, 'is_active' => false, 'colour' => 'slate']))
        ->assertSessionHasErrors(['is_active' => LeaveTypeController::SYSTEM_ACTIVE_MESSAGE]);

    expect($unpaid->fresh()->is_active)->toBeTrue();
});

it('never takes the system flag from the request', function () {
    $admin = userWithRole('admin');

    $this->actingAs($admin)->post('/leave/types', ltPayload(['is_paid' => false, 'is_system' => true]))->assertSessionHasNoErrors();
    expect(LeaveType::where('name', 'Study')->value('is_system'))->toBeFalse();

    $sick = leaveType('Sick');
    $this->actingAs($admin)->put("/leave/types/{$sick->id}", ltPayload(['name' => 'Sick', 'is_system' => true]))->assertSessionHasNoErrors();
    expect($sick->fresh()->is_system)->toBeFalse()
        ->and(LeaveType::where('is_system', true)->count())->toBe(1);
});

it('lets the system Unpaid type be renamed and recoloured, staying unpaid', function () {
    $unpaid = ltSystem();

    $this->actingAs(userWithRole('admin'))
        ->put("/leave/types/{$unpaid->id}", ltPayload(['name' => 'Leave without pay', 'is_paid' => false, 'colour' => 'orange']))
        ->assertSessionHas('success');

    $unpaid->refresh();
    expect($unpaid->name)->toBe('Leave without pay')
        ->and($unpaid->colour)->toBe('orange')
        ->and($unpaid->is_paid)->toBeFalse()
        ->and($unpaid->is_system)->toBeTrue();
});

it('refuses to change the paid flag once the type has requests', function (string $from) {
    $type = $from === 'paid' ? leaveType('Sick') : LeaveType::factory()->unpaid()->create(['name' => 'Extra unpaid']);
    leaveRequest(Employee::factory()->create(), ['leave_type_id' => $type->id, 'status' => 'cancelled']);

    $this->actingAs(userWithRole('admin'))
        ->put("/leave/types/{$type->id}", ltPayload(['name' => $type->name, 'is_paid' => $from !== 'paid', 'yearly_quota' => 8]))
        ->assertSessionHasErrors(['is_paid' => LeaveTypeController::PAID_LOCKED_MESSAGE]);

    expect($type->fresh()->is_paid)->toBe($from === 'paid');
})->with(['paid', 'unpaid']);

it('refuses to change the paid flag when the type has only balance rows', function () {
    $study = LeaveType::factory()->create(['name' => 'Study', 'yearly_quota' => 4]);
    LeaveBalance::factory()->create(['employee_id' => Employee::factory()->create()->id, 'leave_type_id' => $study->id]);

    $this->actingAs(userWithRole('admin'))
        ->put("/leave/types/{$study->id}", ltPayload(['is_paid' => false]))
        ->assertSessionHasErrors(['is_paid' => LeaveTypeController::PAID_LOCKED_MESSAGE]);

    expect(LeaveTypeController::PAID_LOCKED_MESSAGE)->toContain('has leave history')
        ->and($study->fresh()->is_paid)->toBeTrue();
});

it('lets the paid flag change while the type has no requests', function () {
    $study = LeaveType::factory()->create(['name' => 'Study', 'yearly_quota' => 4]);

    $this->actingAs(userWithRole('admin'))
        ->put("/leave/types/{$study->id}", ltPayload(['is_paid' => false]))
        ->assertSessionHas('success');

    expect($study->fresh()->is_paid)->toBeFalse()
        ->and($study->fresh()->yearly_quota)->toBe(0.0);
});

it('returns 404 for an unknown type', function () {
    $admin = userWithRole('admin');

    $this->actingAs($admin)->put('/leave/types/999999', ltPayload())->assertNotFound();
    $this->actingAs($admin)->delete('/leave/types/999999')->assertNotFound();
});

// ---------------------------------------------------------------- delete

it('deletes a type without history', function () {
    $study = LeaveType::factory()->create(['name' => 'Study']);

    $this->actingAs(userWithRole('hr'))->delete("/leave/types/{$study->id}")->assertSessionHas('success');

    expect(LeaveType::find($study->id))->toBeNull();
});

it('never deletes the system Unpaid type', function () {
    $unpaid = ltSystem();
    $before = Activity::max('id') ?? 0;

    $this->actingAs(userWithRole('admin'))->delete("/leave/types/{$unpaid->id}")
        ->assertSessionHas('error', LeaveTypeController::SYSTEM_DELETE_MESSAGE);

    expect(LeaveType::find($unpaid->id))->not->toBeNull()
        ->and(Activity::where('id', '>', $before)->count())->toBe(0);
});

it('refuses to delete a type with leave history', function (string $history) {
    $sick = leaveType('Sick');
    $employee = Employee::factory()->create();

    if ($history === 'request') {
        leaveRequest($employee, ['leave_type_id' => $sick->id, 'status' => 'rejected']);
    } else {
        LeaveBalance::factory()->create(['employee_id' => $employee->id, 'leave_type_id' => $sick->id]);
    }

    $this->actingAs(userWithRole('admin'))->delete("/leave/types/{$sick->id}")
        ->assertSessionHas('error', LeaveTypeController::HISTORY_MESSAGE);

    expect(LeaveType::find($sick->id))->not->toBeNull();
})->with(['request', 'balance']);

it('answers a foreign-key violation on delete with the history message, not an error page', function () {
    $study = LeaveType::factory()->create(['name' => 'Study']);
    $employee = Employee::factory()->create();

    // History written after the check, just before the DELETE (the race the foreign keys backstop).
    LeaveType::deleting(function (LeaveType $type) use ($employee): void {
        LeaveBalance::factory()->create(['employee_id' => $employee->id, 'leave_type_id' => $type->id]);
    });

    $this->actingAs(userWithRole('admin'))->delete("/leave/types/{$study->id}")
        ->assertRedirect()
        ->assertSessionHas('error', LeaveTypeController::HISTORY_MESSAGE);

    expect(LeaveType::find($study->id))->not->toBeNull();
});

// ---------------------------------------------------------------- make active / inactive

it('makes a type inactive and active again without touching other fields', function () {
    $casual = leaveType('Casual');
    leaveRequest(Employee::factory()->create(), ['leave_type_id' => $casual->id]);
    $before = $casual->only(['name', 'yearly_quota', 'is_paid', 'carry_forward', 'carry_forward_cap', 'colour']);
    $admin = userWithRole('hr');

    $this->actingAs($admin)->patch("/leave/types/{$casual->id}/active", ['is_active' => false])
        ->assertSessionHas('success', 'Leave type made inactive.');
    expect($casual->fresh()->is_active)->toBeFalse()
        ->and($casual->fresh()->only(array_keys($before)))->toBe($before);

    $this->actingAs($admin)->patch("/leave/types/{$casual->id}/active", ['is_active' => true])
        ->assertSessionHas('success', 'Leave type made active.');
    expect($casual->fresh()->is_active)->toBeTrue();

    $entry = Activity::where('log_name', 'leave')->where('subject_id', $casual->id)->latest('id')->first();
    expect($entry->properties['attributes'])->toBe(['is_active' => true]);
});

it('never makes the system type inactive through the active action', function () {
    $unpaid = ltSystem();

    $this->actingAs(userWithRole('admin'))->patch("/leave/types/{$unpaid->id}/active", ['is_active' => false])
        ->assertSessionHasErrors(['is_active' => LeaveTypeController::SYSTEM_ACTIVE_MESSAGE]);

    expect($unpaid->fresh()->is_active)->toBeTrue();
});

it('validates the active flag and guards the active action', function () {
    $sick = leaveType('Sick');

    $this->actingAs(userWithRole('admin'))->patch("/leave/types/{$sick->id}/active", ['is_active' => 'maybe'])
        ->assertSessionHasErrors('is_active');
    $this->actingAs(userWithRole('manager'))->patch("/leave/types/{$sick->id}/active", ['is_active' => false])
        ->assertForbidden();
    $this->actingAs(userWithRole('admin'))->patch('/leave/types/999999/active', ['is_active' => false])
        ->assertNotFound();

    expect($sick->fresh()->is_active)->toBeTrue();
});
