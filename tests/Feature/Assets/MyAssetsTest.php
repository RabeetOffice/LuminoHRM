<?php

use App\Models\Employee;
use Inertia\Testing\AssertableInertia as Assert;

// My Assets: the signed-in person's own current and past assets, never anyone else's, never a cost.

// The built Vite manifest does not list the new pages until the coordinator builds.
beforeEach(fn () => $this->withoutVite());

it('shows the account its own current and past assets', function (string $role) {
    $user = userWithRole($role);
    $me = employeeFor($user);
    assignedAsset($me, ['asset_code' => 'AST-0001', 'name' => 'Dell Latitude', 'serial_number' => 'SN-777', 'assigned_on' => '2026-11-20', 'condition_out' => 'good', 'note' => 'With charger']);
    assignedAsset($me, ['asset_code' => 'AST-0002', 'name' => 'Old phone', 'category' => 'Mobile', 'assigned_on' => '2026-09-01', 'condition_out' => 'new', 'returned_on' => '2026-10-15', 'return_condition' => 'fair', 'return_note' => 'Cracked screen']);

    $this->actingAs($user)->get('/my/assets')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('my/assets')
        ->where('employee.name', $me->full_name)
        ->has('holding', 1)
        ->where('holding.0.code', 'AST-0001')
        ->where('holding.0.name', 'Dell Latitude')
        ->where('holding.0.category', 'Laptop')
        ->where('holding.0.serial_number', 'SN-777')
        ->where('holding.0.assigned_on', '2026-11-20')
        ->where('holding.0.returned_on', null)
        ->where('holding.0.condition_out', 'good')
        ->where('history.total', 1)
        ->where('history.data.0.code', 'AST-0002')
        ->where('history.data.0.assigned_on', '2026-09-01')
        ->where('history.data.0.returned_on', '2026-10-15')
        ->where('history.data.0.return_condition', 'fair'));
})->with(['employee', 'manager', 'hr', 'admin']);

it('never shows another person assets, even the same model', function () {
    $user = userWithRole('employee');
    $me = employeeFor($user);
    $other = Employee::factory()->create();
    assignedAsset($me, ['asset_code' => 'AST-0001', 'name' => 'Dell Latitude']);
    assignedAsset($other, ['asset_code' => 'AST-0002', 'name' => 'Dell Latitude']);
    assignedAsset($other, ['asset_code' => 'AST-0003', 'name' => 'Dell Latitude', 'returned_on' => '2026-11-10', 'return_condition' => 'good', 'assigned_on' => '2026-11-01']);

    $response = $this->actingAs($user)->get('/my/assets')->assertOk();

    $response->assertInertia(fn (Assert $page) => $page
        ->has('holding', 1)
        ->where('holding.0.code', 'AST-0001')
        ->where('history.total', 0));
    expect(json_encode($response->viewData('page')['props']))->not->toContain('AST-0002')->not->toContain('AST-0003');
});

it('ignores any employee id in the URL', function () {
    $user = userWithRole('employee');
    employeeFor($user);
    $other = Employee::factory()->create();
    assignedAsset($other, ['asset_code' => 'AST-0009']);

    $response = $this->actingAs($user)->get('/my/assets?employee_id='.$other->id.'&employee='.$other->id)->assertOk();

    expect(json_encode($response->viewData('page')['props']))->not->toContain('AST-0009');
});

it('lists what is held now through AssetHoldings, oldest hand-over first', function () {
    $user = userWithRole('employee');
    $me = employeeFor($user);
    assignedAsset($me, ['asset_code' => 'AST-0002', 'assigned_on' => '2026-11-20']);
    assignedAsset($me, ['asset_code' => 'AST-0001', 'assigned_on' => '2026-10-01']);

    $this->actingAs($user)->get('/my/assets')->assertInertia(fn (Assert $page) => $page
        ->where('holding.0.code', 'AST-0001')
        ->where('holding.1.code', 'AST-0002'));
});

it('lists the history newest return first', function () {
    $user = userWithRole('employee');
    $me = employeeFor($user);
    assignedAsset($me, ['asset_code' => 'AST-0001', 'assigned_on' => '2026-08-01', 'returned_on' => '2026-09-01', 'return_condition' => 'good']);
    assignedAsset($me, ['asset_code' => 'AST-0002', 'assigned_on' => '2026-08-01', 'returned_on' => '2026-10-01', 'return_condition' => 'good']);

    $this->actingAs($user)->get('/my/assets')->assertInertia(fn (Assert $page) => $page
        ->where('history.data.0.code', 'AST-0002')
        ->where('history.data.1.code', 'AST-0001'));
});

it('sends neither the hand-over note nor the return note, to any role', function (string $role) {
    $user = userWithRole($role);
    $me = employeeFor($user);
    assignedAsset($me, ['asset_code' => 'AST-0001', 'assigned_on' => '2026-11-20', 'note' => 'With charger and bag']);
    assignedAsset($me, ['asset_code' => 'AST-0002', 'assigned_on' => '2026-09-01', 'returned_on' => '2026-10-15', 'return_condition' => 'fair', 'return_note' => 'Cracked screen, discuss deduction']);

    $props = $this->actingAs($user)->get('/my/assets')->assertOk()->viewData('page')['props'];

    $keys = [];
    array_walk_recursive($props, function ($value, $key) use (&$keys): void {
        $keys[] = (string) $key;
    });
    $json = json_encode($props);

    expect($keys)->not->toContain('note')->not->toContain('return_note')
        ->and($json)->not->toContain('With charger')->not->toContain('Cracked screen')
        ->and($json)->toContain('AST-0001')->toContain('AST-0002');
})->with(['employee', 'manager', 'hr', 'admin']);

it('never sends a cost or a purchase date, to any role', function (string $role) {
    $user = userWithRole($role);
    $me = employeeFor($user);
    assignedAsset($me, ['asset_code' => 'AST-0001', 'cost' => 12345600]);
    assignedAsset($me, ['asset_code' => 'AST-0002', 'cost' => 65432100, 'returned_on' => '2026-11-10', 'return_condition' => 'good', 'assigned_on' => '2026-11-01']);

    $json = json_encode($this->actingAs($user)->get('/my/assets')->assertOk()->viewData('page')['props']);

    expect($json)->not->toContain('cost')->not->toContain('12345600')->not->toContain('65432100')->not->toContain('purchase_date');
})->with(['employee', 'manager', 'hr', 'admin']);

it('gives an account without an employee record empty lists', function () {
    $this->actingAs(userWithRole('employee'))->get('/my/assets')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('employee', null)
        ->where('holding', [])
        ->where('history.data', [])
        ->where('history.total', 0));
});

it('redirects a guest to login', function () {
    $this->get('/my/assets')->assertRedirect('/login');
});

it('pages the history 20 at a time', function () {
    $user = userWithRole('employee');
    $me = employeeFor($user);
    for ($i = 1; $i <= 22; $i++) {
        assignedAsset($me, ['assigned_on' => '2026-01-01', 'returned_on' => sprintf('2026-02-%02d', $i), 'return_condition' => 'good']);
    }

    $this->actingAs($user)->get('/my/assets')->assertInertia(fn (Assert $page) => $page
        ->has('history.data', 20)
        ->where('history.total', 22)
        ->where('history.last_page', 2));
    $this->actingAs($user)->get('/my/assets?page=2')->assertInertia(fn (Assert $page) => $page->has('history.data', 2));
});
