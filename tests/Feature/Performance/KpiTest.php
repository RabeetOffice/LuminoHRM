<?php

use App\Actions\Performance\SaveKpi;
use App\Models\Designation;
use App\Models\Employee;
use App\Models\Kpi;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->withoutVite(); // the page is built once by the coordinator, not by this task
});

function kpiAudit(string $description): Activity
{
    return Activity::query()->where('log_name', 'performance')->where('description', $description)->latest('id')->firstOrFail();
}

/** A valid payload for a designation KPI, merged with $overrides. */
function validKpi(Designation $designation, array $overrides = []): array
{
    return array_merge([
        'name' => 'Quality of work',
        'description' => 'Accuracy and care',
        'weight' => 30,
        'is_active' => true,
        'target' => 'designation',
        'designation_id' => $designation->id,
        'employee_id' => null,
    ], $overrides);
}

// ---------------------------------------------------------------- access

it('lets Admin and HR open the KPI page', function (string $role) {
    kpiRow(['name' => 'Delivery', 'weight' => 40]);

    $this->actingAs(userWithRole($role))->get('/performance/kpis')
        ->assertOk()
        ->assertInertia(fn ($p) => $p->component('performance/kpis')
            ->has('kpis.data', 1)
            ->where('kpis.data.0.name', 'Delivery')
            ->where('kpis.data.0.weight', 40)
            ->where('kpis.data.0.uses', 0)
            ->where('kpis.data.0.can_delete', true)
            ->where('kpis.data.0.target.type', 'designation')
            ->has('options.designations')
            ->has('options.employees'));
})->with(['admin', 'hr']);

it('keeps Manager and Employee out of all four routes', function (string $role) {
    $user = userWithRole($role);
    $kpi = kpiRow();

    $this->actingAs($user)->get('/performance/kpis')->assertForbidden();
    $this->actingAs($user)->post('/performance/kpis', validKpi($kpi->designation))->assertForbidden();
    $this->actingAs($user)->put("/performance/kpis/{$kpi->id}", validKpi($kpi->designation, ['name' => 'Changed']))->assertForbidden();
    $this->actingAs($user)->delete("/performance/kpis/{$kpi->id}")->assertForbidden();

    expect(Kpi::count())->toBe(1)->and($kpi->fresh()->name)->toBe($kpi->name);
})->with(['manager', 'employee']);

it('sends a guest to sign in', function () {
    $this->get('/performance/kpis')->assertRedirect('/login');
});

// ---------------------------------------------------------------- create

it('adds a KPI for a designation and audits it', function () {
    $hr = userWithRole('hr');
    $designation = Designation::factory()->create(['name' => 'Developer']);

    $this->actingAs($hr)->post('/performance/kpis', validKpi($designation, ['name' => '  Quality   of work ']))
        ->assertRedirect()->assertSessionHasNoErrors();

    $kpi = Kpi::query()->sole();
    $audit = kpiAudit('KPI created');

    expect($kpi->name)->toBe('Quality of work')
        ->and($kpi->weight)->toBe(30)
        ->and($kpi->is_active)->toBeTrue()
        ->and($kpi->designation_id)->toBe($designation->id)
        ->and($kpi->employee_id)->toBeNull()
        ->and($audit->subject_id)->toBe($kpi->id)
        ->and($audit->causer_id)->toBe($hr->id)
        ->and($audit->properties['attributes'])->toBe([
            'name' => 'Quality of work', 'description' => 'Accuracy and care', 'weight' => 30, 'active' => true,
            'target_type' => 'designation', 'target' => 'Developer',
        ]);
});

it('adds a KPI for one employee', function () {
    $employee = Employee::factory()->create(['first_name' => 'Ayesha', 'last_name' => 'Khan', 'status' => 'probation']);

    $this->actingAs(userWithRole('admin'))->post('/performance/kpis', [
        'name' => 'Onboarding goals', 'weight' => 15, 'is_active' => true, 'target' => 'employee', 'employee_id' => $employee->id,
    ])->assertSessionHasNoErrors();

    $kpi = Kpi::query()->sole();

    expect($kpi->employee_id)->toBe($employee->id)
        ->and($kpi->designation_id)->toBeNull()
        ->and($kpi->description)->toBeNull()
        ->and(kpiAudit('KPI created')->properties['attributes'])->toMatchArray(['target_type' => 'employee', 'target' => 'Ayesha Khan']);
});

it('refuses a person who is not active or on probation', function (string $status) {
    $employee = Employee::factory()->create(['first_name' => 'Sara', 'last_name' => 'Ali', 'status' => $status]);

    $this->actingAs(userWithRole('hr'))->post('/performance/kpis', [
        'name' => 'Goals', 'weight' => 10, 'is_active' => true, 'target' => 'employee', 'employee_id' => $employee->id,
    ])->assertSessionHasErrors(['employee_id' => 'Sara Ali cannot be chosen: only active or probation employees can have KPIs.']);

    expect(Kpi::count())->toBe(0);
})->with(['inactive', 'resigned', 'terminated']);

it('refuses a weight that is not a whole number from 1 to 100 with the exact message', function (mixed $weight) {
    $designation = Designation::factory()->create();

    $this->actingAs(userWithRole('hr'))->post('/performance/kpis', validKpi($designation, ['weight' => $weight]))
        ->assertSessionHasErrors(['weight' => 'Weight must be a whole number from 1 to 100.']);

    expect(Kpi::count())->toBe(0);
})->with([0, 101, 12.5, '12.5', -3, 'abc', null]);

it('accepts the weights 1 and 100', function (int $weight) {
    $designation = Designation::factory()->create();

    $this->actingAs(userWithRole('hr'))->post('/performance/kpis', validKpi($designation, ['weight' => $weight]))->assertSessionHasNoErrors();

    expect(Kpi::query()->sole()->weight)->toBe($weight);
})->with([1, 100]);

it('refuses a name that is empty or too long and a description that is too long', function () {
    $designation = Designation::factory()->create();
    $hr = userWithRole('hr');

    $this->actingAs($hr)->post('/performance/kpis', validKpi($designation, ['name' => '   ']))->assertSessionHasErrors('name');
    $this->actingAs($hr)->post('/performance/kpis', validKpi($designation, ['name' => str_repeat('a', 121)]))->assertSessionHasErrors('name');
    $this->actingAs($hr)->post('/performance/kpis', validKpi($designation, ['description' => str_repeat('a', 501)]))->assertSessionHasErrors('description');

    expect(Kpi::count())->toBe(0);
});

it('refuses both targets and neither through the action, with the exact messages', function () {
    $by = userWithRole('hr');
    $employee = Employee::factory()->create();
    $save = app(SaveKpi::class);

    $call = fn (array $data) => fn () => $save->create($by, array_merge(
        ['name' => 'X', 'weight' => 10, 'is_active' => true], $data,
    ));

    expect($call(['target' => 'designation', 'designation_id' => $employee->designation_id, 'employee_id' => $employee->id]))
        ->toThrow(fn (ValidationException $e) => expect($e->errors())->toBe(['target' => [SaveKpi::TARGET_BOTH]]))
        ->and($call(['target' => 'designation']))
        ->toThrow(fn (ValidationException $e) => expect($e->errors())->toBe(['target' => [SaveKpi::TARGET_NONE]]))
        ->and($call(['target' => 'employee', 'designation_id' => $employee->designation_id]))
        ->toThrow(fn (ValidationException $e) => expect($e->errors())->toBe(['target' => [SaveKpi::TARGET_NONE]]))
        ->and($call([]))
        ->toThrow(ValidationException::class)
        ->and(Kpi::count())->toBe(0);
});

it('refuses a request with no target at all', function () {
    $this->actingAs(userWithRole('hr'))->post('/performance/kpis', ['name' => 'X', 'weight' => 10, 'is_active' => true])
        ->assertSessionHasErrors(['target' => 'Choose a designation or an employee.']);
});

// ---------------------------------------------------------------- update

it('changes a KPI and audits old and new values', function () {
    $hr = userWithRole('hr');
    $kpi = kpiRow(['name' => 'Old name', 'description' => 'Old', 'weight' => 20]);

    $this->actingAs($hr)->put("/performance/kpis/{$kpi->id}", validKpi($kpi->designation, [
        'name' => 'New name', 'description' => 'Old', 'weight' => 40,
    ]))->assertSessionHasNoErrors();

    $audit = kpiAudit('KPI updated');

    expect($kpi->fresh()->name)->toBe('New name')
        ->and($kpi->fresh()->weight)->toBe(40)
        ->and($audit->properties['old'])->toBe(['name' => 'Old name', 'weight' => 20])
        ->and($audit->properties['attributes'])->toBe(['name' => 'New name', 'weight' => 40])
        ->and($audit->causer_id)->toBe($hr->id);
});

it('writes nothing for an update that changes nothing', function () {
    $kpi = kpiRow(['name' => 'Same', 'description' => null, 'weight' => 20]);

    $this->actingAs(userWithRole('hr'))->put("/performance/kpis/{$kpi->id}", validKpi($kpi->designation, [
        'name' => 'Same', 'description' => '', 'weight' => 20,
    ]))->assertSessionHasNoErrors();

    expect(Activity::query()->where('log_name', 'performance')->count())->toBe(0);
});

it('writes KPI deactivated when the active flag goes off, and an ordinary update when it comes back', function () {
    $hr = userWithRole('hr');
    $kpi = kpiRow(['name' => 'Same', 'weight' => 20]);
    $payload = validKpi($kpi->designation, ['name' => 'Same', 'description' => null, 'weight' => 20]);

    $this->actingAs($hr)->put("/performance/kpis/{$kpi->id}", [...$payload, 'is_active' => false])->assertSessionHasNoErrors();

    expect($kpi->fresh()->is_active)->toBeFalse()
        ->and(Activity::query()->where('log_name', 'performance')->pluck('description')->all())->toBe(['KPI deactivated'])
        ->and(kpiAudit('KPI deactivated')->properties->only(['old', 'attributes'])->toArray())->toBe(['old' => ['active' => true], 'attributes' => ['active' => false]]);

    $this->actingAs($hr)->put("/performance/kpis/{$kpi->id}", [...$payload, 'is_active' => true])->assertSessionHasNoErrors();

    expect($kpi->fresh()->is_active)->toBeTrue()
        ->and(kpiAudit('KPI updated')->properties->only(['old', 'attributes'])->toArray())->toBe(['old' => ['active' => false], 'attributes' => ['active' => true]]);
});

it('writes both entries when a KPI is deactivated and renamed together', function () {
    $kpi = kpiRow(['name' => 'Before', 'weight' => 20]);

    $this->actingAs(userWithRole('hr'))->put("/performance/kpis/{$kpi->id}", validKpi($kpi->designation, [
        'name' => 'After', 'description' => null, 'weight' => 20, 'is_active' => false,
    ]))->assertSessionHasNoErrors();

    expect(Activity::query()->where('log_name', 'performance')->orderBy('id')->pluck('description')->all())->toBe(['KPI updated', 'KPI deactivated'])
        ->and(kpiAudit('KPI updated')->properties->only(['old', 'attributes'])->toArray())->toBe(['old' => ['name' => 'Before'], 'attributes' => ['name' => 'After']]);
});

it('moves a KPI to an employee and records the target change', function () {
    $employee = Employee::factory()->create(['first_name' => 'Omar', 'last_name' => 'Raza']);
    $designation = Designation::factory()->create(['name' => 'Analyst']);
    $kpi = kpiRow(['designation' => $designation, 'name' => 'Goal', 'description' => null, 'weight' => 20]);

    $this->actingAs(userWithRole('admin'))->put("/performance/kpis/{$kpi->id}", [
        'name' => 'Goal', 'description' => null, 'weight' => 20, 'is_active' => true, 'target' => 'employee',
        'designation_id' => null, 'employee_id' => $employee->id,
    ])->assertSessionHasNoErrors();

    $audit = kpiAudit('KPI updated');

    expect($kpi->fresh()->designation_id)->toBeNull()
        ->and($kpi->fresh()->employee_id)->toBe($employee->id)
        ->and($audit->properties['old'])->toBe(['target_type' => 'designation', 'target' => 'Analyst'])
        ->and($audit->properties['attributes'])->toBe(['target_type' => 'employee', 'target' => 'Omar Raza']);
});

it('lets an old KPI of a person who has since left stay editable but not be given to a new leaver', function () {
    $employee = Employee::factory()->create(['status' => 'active']);
    $kpi = kpiRow(['employee' => $employee, 'name' => 'Old', 'description' => null, 'weight' => 20]);
    $employee->update(['status' => 'resigned']);
    $payload = ['name' => 'Renamed', 'description' => null, 'weight' => 20, 'is_active' => true, 'target' => 'employee', 'designation_id' => null, 'employee_id' => $employee->id];

    $this->actingAs(userWithRole('hr'))->put("/performance/kpis/{$kpi->id}", $payload)->assertSessionHasNoErrors();

    expect($kpi->fresh()->name)->toBe('Renamed');

    $other = Employee::factory()->create(['status' => 'terminated']);

    $this->actingAs(userWithRole('hr'))->put("/performance/kpis/{$kpi->id}", [...$payload, 'employee_id' => $other->id])->assertSessionHasErrors('employee_id');

    expect($kpi->fresh()->employee_id)->toBe($employee->id);
});

it('leaves reviews alone when a KPI is edited or deactivated afterwards', function () {
    $employee = Employee::factory()->create();
    $kpi = kpiRow(['designation' => $employee->designation, 'name' => 'Original', 'weight' => 25]);
    $review = reviewRow(reviewCycleRow(), $employee);

    $this->actingAs(userWithRole('hr'))->put("/performance/kpis/{$kpi->id}", validKpi($employee->designation, [
        'name' => 'Edited', 'weight' => 90, 'is_active' => false,
    ]))->assertSessionHasNoErrors();

    $score = $review->scores()->sole();

    expect($score->name)->toBe('Original')->and($score->weight)->toBe(25)->and($score->kpi_id)->toBe($kpi->id);
});

// ---------------------------------------------------------------- delete

it('deletes an unused KPI and audits it', function () {
    $hr = userWithRole('hr');
    $designation = Designation::factory()->create(['name' => 'Tester']);
    $kpi = kpiRow(['designation' => $designation, 'name' => 'Gone', 'description' => 'Bye', 'weight' => 12]);

    $this->actingAs($hr)->delete("/performance/kpis/{$kpi->id}")->assertRedirect()->assertSessionHasNoErrors();

    $audit = kpiAudit('KPI deleted');

    expect(Kpi::count())->toBe(0)
        ->and($audit->causer_id)->toBe($hr->id)
        ->and($audit->properties['old'])->toBe([
            'name' => 'Gone', 'description' => 'Bye', 'weight' => 12, 'active' => true, 'target_type' => 'designation', 'target' => 'Tester',
        ]);
});

it('refuses to delete a KPI that reviews use and deletes nothing', function () {
    $employee = Employee::factory()->create();
    $kpi = kpiRow(['designation' => $employee->designation]);
    reviewRow(reviewCycleRow(), $employee);
    reviewRow(reviewCycleRow(), Employee::factory()->create(['designation_id' => $employee->designation_id, 'department_id' => $employee->department_id]));

    $this->actingAs(userWithRole('admin'))->delete("/performance/kpis/{$kpi->id}")
        ->assertSessionHasErrors(['kpi' => '2 reviews use this KPI, so it cannot be deleted. Deactivate it instead.']);

    expect(Kpi::query()->whereKey($kpi->id)->exists())->toBeTrue()
        ->and(Activity::query()->where('log_name', 'performance')->count())->toBe(0);
});

it('refuses with the same message when a review copies the KPI after the count (foreign key backstop)', function () {
    $employee = Employee::factory()->create();
    $kpi = kpiRow(['designation' => $employee->designation]);
    $stale = Kpi::query()->find($kpi->id);

    reviewRow(reviewCycleRow(), $employee);

    expect(fn () => app(SaveKpi::class)->delete(userWithRole('hr'), $stale))
        ->toThrow(fn (ValidationException $e) => expect($e->errors())->toBe(['kpi' => [SaveKpi::inUseMessage(1)]]));
});

// ---------------------------------------------------------------- list

it('lists with the usage count and filters by search, target and active', function () {
    $employee = Employee::factory()->create(['first_name' => 'Zainab', 'last_name' => 'Noor']);
    kpiRow(['name' => 'Alpha delivery', 'designation' => $employee->designation]);
    kpiRow(['name' => 'Beta personal', 'employee' => $employee]);
    kpiRow(['name' => 'Gamma off', 'is_active' => false]);
    reviewRow(reviewCycleRow(), $employee);
    $admin = userWithRole('admin');

    $names = function (string $query) use ($admin) {
        $rows = [];
        $this->actingAs($admin)->get("/performance/kpis{$query}")->assertInertia(function ($p) use (&$rows) {
            $rows = $p->toArray()['props']['kpis']['data'];
        });

        return collect($rows)->pluck('name')->all();
    };

    expect($names(''))->toBe(['Alpha delivery', 'Beta personal', 'Gamma off'])
        ->and($names('?search=ALPHA'))->toBe(['Alpha delivery'])
        ->and($names('?search=zainab'))->toBe(['Beta personal'])
        ->and($names('?target=employee'))->toBe(['Beta personal'])
        ->and($names('?target=designation'))->toBe(['Alpha delivery', 'Gamma off'])
        ->and($names('?active=0'))->toBe(['Gamma off'])
        ->and($names('?active=1'))->toBe(['Alpha delivery', 'Beta personal'])
        ->and($names('?target[]=x&active[]=1&search[]=a'))->toBe(['Alpha delivery', 'Beta personal', 'Gamma off']);

    $this->actingAs($admin)->get('/performance/kpis')->assertInertia(fn ($p) => $p
        ->where('kpis.data.0.uses', 1)->where('kpis.data.0.can_delete', false)
        ->where('kpis.data.1.uses', 1)->where('kpis.data.1.target.type', 'employee')->where('kpis.data.1.target.name', 'Zainab Noor')
        ->where('kpis.data.2.uses', 0)->where('kpis.data.2.can_delete', true));
});

it('offers designations and only active or probation people', function () {
    Employee::factory()->create(['first_name' => 'Active', 'status' => 'active']);
    Employee::factory()->create(['first_name' => 'Probie', 'status' => 'probation']);
    Employee::factory()->create(['first_name' => 'Gone', 'status' => 'resigned']);

    $this->actingAs(userWithRole('hr'))->get('/performance/kpis')->assertInertia(fn ($p) => $p
        ->has('options.employees', 2)
        ->where('options.employees', fn ($list) => collect($list)->pluck('name')->sort()->values()->all() === ['Active '.Employee::query()->where('first_name', 'Active')->value('last_name'), 'Probie '.Employee::query()->where('first_name', 'Probie')->value('last_name')])
        ->has('options.designations', 3));
});

// ---------------------------------------------------------------- designations

it('refuses to delete a designation that has KPIs, and deletes it again once they are gone', function () {
    $designation = Designation::factory()->create();
    $kpi = kpiRow(['designation' => $designation]);
    kpiRow(['designation' => $designation]);
    $admin = userWithRole('admin');

    expect($designation->deleteBlocker())->toBe('2 KPIs use this designation. Remove or move them first.');

    $this->actingAs($admin)->delete("/organization/designations/{$designation->id}")
        ->assertSessionHas('error', '2 KPIs use this designation. Remove or move them first.');

    expect(Designation::query()->whereKey($designation->id)->exists())->toBeTrue();

    Kpi::query()->where('designation_id', $designation->id)->delete();

    expect($designation->fresh()->deleteBlocker())->toBeNull();
});

// ---------------------------------------------------------------- wording (F1) and the action's own checks (F5)

it('words the refusals for one and for several, with no "(s)"', function () {
    expect(SaveKpi::inUseMessage(1))->toBe('1 review uses this KPI, so it cannot be deleted. Deactivate it instead.')
        ->and(SaveKpi::inUseMessage(2))->toBe('2 reviews use this KPI, so it cannot be deleted. Deactivate it instead.');

    $employee = Employee::factory()->create();
    $kpi = kpiRow(['designation' => $employee->designation]);
    reviewRow(reviewCycleRow(), $employee);

    $this->actingAs(userWithRole('admin'))->delete("/performance/kpis/{$kpi->id}")
        ->assertSessionHasErrors(['kpi' => '1 review uses this KPI, so it cannot be deleted. Deactivate it instead.']);

    $designation = Designation::factory()->create();
    kpiRow(['designation' => $designation]);

    expect($designation->deleteBlocker())->toBe('1 KPI uses this designation. Remove or move it first.');

    $this->actingAs(userWithRole('admin'))->delete("/organization/designations/{$designation->id}")
        ->assertSessionHas('error', '1 KPI uses this designation. Remove or move it first.');
});

it('checks the name and description in the action itself, not only in the request', function (array $override, string $field, string $message) {
    $designation = Designation::factory()->create();
    $save = app(SaveKpi::class);

    expect(fn () => $save->create(userWithRole('hr'), validKpi($designation, $override)))
        ->toThrow(fn (ValidationException $e) => expect($e->errors())->toBe([$field => [$message]]))
        ->and(Kpi::count())->toBe(0);
})->with([
    'empty name' => [['name' => ''], 'name', SaveKpi::NAME_REQUIRED],
    'blank name' => [['name' => '  	 '], 'name', SaveKpi::NAME_REQUIRED],
    'missing name' => [['name' => null], 'name', SaveKpi::NAME_REQUIRED],
    'name over 120' => [['name' => str_repeat('a', 121)], 'name', SaveKpi::NAME_TOO_LONG],
    'description over 500' => [['description' => str_repeat('b', 501)], 'description', SaveKpi::DESCRIPTION_TOO_LONG],
]);

it('accepts a name of exactly 120 characters and a description of exactly 500', function () {
    $designation = Designation::factory()->create();

    $kpi = app(SaveKpi::class)->create(userWithRole('hr'), validKpi($designation, ['name' => str_repeat('a', 120), 'description' => str_repeat('b', 500)]));

    expect(mb_strlen($kpi->name))->toBe(120)->and(mb_strlen($kpi->description))->toBe(500);
});
