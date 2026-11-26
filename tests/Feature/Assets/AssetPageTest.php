<?php

use App\Models\AssetAssignment;
use App\Models\Employee;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

// The asset page: props, the history, what each role may do, and the assign dialog's people.

beforeEach(function () {
    $this->withoutVite();
    $this->travelTo(CarbonImmutable::parse('2026-11-26 14:00:00', 'Asia/Karachi'));
});

it('shows HR and Admin the asset with cost, purchase date and formatted cost', function (string $role) {
    $asset = assetRow(['asset_code' => 'AST-0001', 'name' => 'Dell', 'cost' => 15050000, 'purchase_date' => '2026-03-05', 'notes' => 'Boxed']);

    $this->actingAs(userWithRole($role))->get("/assets/{$asset->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('assets/show')
        ->where('asset.asset_code', 'AST-0001')
        ->where('asset.cost', 15050000)
        ->where('asset.cost_formatted', 'Rs 150,500')
        ->where('asset.purchase_date', '2026-03-05')
        ->where('asset.category', 'Laptop')
        ->where('asset.notes', 'Boxed')
        ->where('holder', null)
        ->where('history', [])
        ->where('today', '2026-11-26'));
})->with(['admin', 'hr']);

it('refuses a Manager and an Employee, and redirects a guest', function () {
    $asset = assetRow();

    $this->actingAs(userWithRole('manager'))->get("/assets/{$asset->id}")->assertForbidden();
    $this->actingAs(userWithRole('employee'))->get("/assets/{$asset->id}")->assertForbidden();
});

it('sends a guest to log in', function () {
    $asset = assetRow();

    $this->get("/assets/{$asset->id}")->assertRedirect('/login');
});

it('answers 404 for an asset that does not exist and keeps the static paths out of the {asset} route', function () {
    $hr = userWithRole('hr');

    $this->actingAs($hr)->get('/assets/9999')->assertNotFound();
    $this->actingAs($hr)->get('/assets/employee-options')->assertOk()->assertJsonStructure(['employees']);
});

it('lists the whole history newest first, with returned rows, notes and who did it', function () {
    $hr = userWithRole('hr');
    $sara = Employee::factory()->create(['first_name' => 'Sara', 'last_name' => 'Ahmed', 'employee_code' => 'EMP-0001']);
    $ali = Employee::factory()->create(['first_name' => 'Ali', 'last_name' => 'Raza', 'employee_code' => 'EMP-0002']);
    $asset = assignedAsset($sara, [
        'assigned_on' => '2026-01-10', 'returned_on' => '2026-03-01', 'condition_out' => 'new', 'return_condition' => 'fair',
        'note' => 'First', 'return_note' => 'Scratched', 'assigned_by' => $hr->id, 'returned_by' => $hr->id,
    ]);
    AssetAssignment::query()->create([
        'asset_id' => $asset->id, 'employee_id' => $ali->id, 'assigned_on' => '2026-05-01', 'condition_out' => 'fair', 'assigned_by' => $hr->id,
    ]);
    $asset->update(['status' => 'assigned']);

    $this->actingAs($hr)->get("/assets/{$asset->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
        ->has('history', 2)
        ->where('history.0.employee.name', 'Ali Raza')
        ->where('history.0.assigned_on', '2026-05-01')
        ->where('history.0.returned_on', null)
        ->where('history.0.assigned_by', $hr->name)
        ->where('history.0.returned_by', null)
        ->where('history.1.employee.code', 'EMP-0001')
        ->where('history.1.returned_on', '2026-03-01')
        ->where('history.1.condition_out', 'new')
        ->where('history.1.return_condition', 'fair')
        ->where('history.1.note', 'First')
        ->where('history.1.return_note', 'Scratched')
        ->where('history.1.returned_by', $hr->name)
        ->where('holder.name', 'Ali Raza')
        ->where('holder.assigned_on', '2026-05-01')
        ->where('can.return', true)
        ->where('can.assign', false)
        ->where('can.status', false)
        ->where('status_options', []));
});

it('gives a never-assigned asset an empty history and lets only an Admin delete it', function () {
    $asset = assetRow();

    $this->actingAs(userWithRole('admin'))->get("/assets/{$asset->id}")->assertInertia(fn (Assert $page) => $page
        ->where('history', [])->where('can.delete', true)->where('can.retire', true)->where('can.edit', true)->where('can.assign', true));
    $this->actingAs(userWithRole('hr'))->get("/assets/{$asset->id}")->assertInertia(fn (Assert $page) => $page
        ->where('can.delete', false)->where('can.retire', false)->where('can.edit', true)->where('can.assign', true));
});

it('turns delete off once an asset has any history, even a returned one', function () {
    $asset = assignedAsset(Employee::factory()->create(), ['assigned_on' => '2026-10-01', 'returned_on' => '2026-10-30', 'return_condition' => 'good']);

    $this->actingAs(userWithRole('admin'))->get("/assets/{$asset->id}")->assertInertia(fn (Assert $page) => $page
        ->has('history', 1)->where('can.delete', false)->where('can.assign', true)->where('can.return', false));
});

it('offers the status targets the viewer may choose', function (string $role, string $status, array $expected) {
    $asset = assetRow(['status' => $status]);

    $this->actingAs(userWithRole($role))->get("/assets/{$asset->id}")->assertInertia(fn (Assert $page) => $page
        ->where('status_options', $expected)->where('can.status', $expected !== []));
})->with([
    'hr available' => ['hr', 'available', ['in_repair']],
    'admin available' => ['admin', 'available', ['in_repair', 'retired']],
    'hr in repair' => ['hr', 'in_repair', ['available']],
    'admin in repair' => ['admin', 'in_repair', ['available', 'retired']],
    'hr retired' => ['hr', 'retired', []],
    'admin retired' => ['admin', 'retired', ['available']],
]);

it('lists active and probation people for the assign dialog, not the others, and says when it is cut', function () {
    Employee::factory()->create(['first_name' => 'Active', 'last_name' => 'One', 'status' => 'active']);
    Employee::factory()->create(['first_name' => 'Probie', 'last_name' => 'Two', 'status' => 'probation']);
    Employee::factory()->create(['first_name' => 'Gone', 'last_name' => 'Three', 'status' => 'resigned']);
    Employee::factory()->create(['first_name' => 'Idle', 'last_name' => 'Four', 'status' => 'inactive']);
    $asset = assetRow();

    $this->actingAs(userWithRole('hr'))->get("/assets/{$asset->id}")->assertInertia(fn (Assert $page) => $page
        ->has('employees', 2)
        ->where('employees.0.name', 'Active One')
        ->where('employees.1.name', 'Probie Two')
        ->where('employees_truncated', false)
        ->has('categories'));
});

it('searches people as you type for the assign dialog, active and probation only, at most 20', function () {
    Employee::factory()->create(['first_name' => 'Sara', 'last_name' => 'Ahmed', 'status' => 'active']);
    Employee::factory()->create(['first_name' => 'Sara', 'last_name' => 'Gone', 'status' => 'terminated']);
    foreach (range(1, 25) as $i) {
        Employee::factory()->create(['first_name' => 'Tariq', 'last_name' => "N{$i}", 'status' => 'active']);
    }
    $hr = userWithRole('hr');

    $this->actingAs($hr)->getJson('/assets/employee-options?q=sara')->assertOk()
        ->assertJsonCount(1, 'employees')->assertJsonPath('employees.0.name', 'Sara Ahmed');
    $this->actingAs($hr)->getJson('/assets/employee-options?q=tariq')->assertOk()->assertJsonCount(20, 'employees');
    $this->actingAs(userWithRole('manager'))->getJson('/assets/employee-options?q=sara')->assertForbidden();
});

it('redirects to the list after the Admin deletes an asset', function () {
    $asset = assetRow();

    $this->actingAs(userWithRole('admin'))->delete("/assets/{$asset->id}")->assertRedirect('/assets');
});
