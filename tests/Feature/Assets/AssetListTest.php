<?php

use App\Models\Employee;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

// The All Assets list: who sees what, the filters, the paging and the query count.

// The built Vite manifest does not list the new pages until the coordinator builds.
beforeEach(fn () => $this->withoutVite());

/** @param  array<string, mixed>  $query */
function listAs(string $role, array $query = [])
{
    return test()->actingAs(userWithRole($role))->get('/assets'.($query === [] ? '' : '?'.http_build_query($query)));
}

it('shows HR and Admin the whole register with costs and the add / import / export buttons', function (string $role) {
    $holder = Employee::factory()->create(['first_name' => 'Sara', 'last_name' => 'Ahmed', 'employee_code' => 'EMP-0007']);
    assignedAsset($holder, ['asset_code' => 'AST-0001', 'name' => 'Dell Latitude', 'cost' => 15000000, 'assigned_on' => '2026-11-20']);
    assetRow(['asset_code' => 'AST-0002', 'name' => 'Spare monitor', 'category' => 'Monitor', 'cost' => null]);

    listAs($role)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('assets/index')
        ->where('scope', 'all')
        ->where('can', ['create' => true, 'import' => true, 'export' => true])
        ->has('assets.data', 2)
        ->where('assets.total', 2)
        ->where('assets.data.0.asset_code', 'AST-0001')
        ->where('assets.data.0.cost', 15000000)
        ->where('assets.data.0.holder', ['id' => $holder->id, 'code' => 'EMP-0007', 'name' => 'Sara Ahmed'])
        ->where('assets.data.0.held_since', '2026-11-20')
        ->where('assets.data.0.category', 'Laptop')
        ->where('assets.data.1.holder', null)
        ->where('assets.data.1.held_since', null)
        ->where('assets.data.1.cost', null)
        ->where('options.statuses', ['available', 'assigned', 'in_repair', 'retired'])
        ->where('options.conditions', ['new', 'good', 'fair', 'poor', 'damaged'])
        ->has('options.categories'));
})->with(['admin', 'hr']);

it('gives a Manager the read-only team list: only what their reports and themself hold, no cost, can all false', function () {
    $manager = userWithRole('manager');
    $me = employeeFor($manager, ['first_name' => 'Mona', 'last_name' => 'Khan']);
    $report = Employee::factory()->create(['manager_id' => $me->id, 'first_name' => 'Ali', 'last_name' => 'Raza']);
    $other = Employee::factory()->create(['first_name' => 'Zed', 'last_name' => 'Outsider']);

    assignedAsset($report, ['asset_code' => 'AST-0001', 'cost' => 9900000]);
    assignedAsset($me, ['asset_code' => 'AST-0002', 'cost' => 1100000]);
    assignedAsset($other, ['asset_code' => 'AST-0003', 'cost' => 7700000, 'purchase_date' => '2024-01-01']);
    assetRow(['asset_code' => 'AST-0004', 'cost' => 5500000]);

    $response = $this->actingAs($manager)->get('/assets')->assertOk();

    $response->assertInertia(fn (Assert $page) => $page
        ->where('scope', 'team')
        ->where('can', ['create' => false, 'import' => false, 'export' => false])
        ->where('assets.total', 2)
        ->where('assets.data.0.asset_code', 'AST-0001')
        ->where('assets.data.1.asset_code', 'AST-0002')
        ->where('options.statuses', [])
        ->where('options.conditions', []));

    // The serialised props never carry a cost, a purchase date or anything about the outsider's asset.
    $json = json_encode($response->viewData('page')['props']);
    expect($json)->not->toContain('"cost"')
        ->and($json)->not->toContain('purchase_date')
        ->and($json)->not->toContain('AST-0003')
        ->and($json)->not->toContain('AST-0004')
        ->and($json)->not->toContain('Outsider');
});

it('walks every prop of the Manager list and finds no cost key at any depth', function () {
    $manager = userWithRole('manager');
    $me = employeeFor($manager);
    assignedAsset($me, ['cost' => 4200000]);

    $props = $this->actingAs($manager)->get('/assets')->assertOk()->viewData('page')['props'];

    $walk = function (mixed $node, string $path) use (&$walk): void {
        if (! is_array($node)) {
            return;
        }
        foreach ($node as $key => $value) {
            expect($key)->not->toBeIn(['cost', 'cost_formatted', 'cost_rupees', 'purchase_date'], "found {$key} at {$path}");
            $walk($value, $path.'.'.$key);
        }
    };
    $walk($props, 'props');
});

it('ignores the status and condition filters for a Manager', function () {
    $manager = userWithRole('manager');
    $me = employeeFor($manager);
    assignedAsset($me, ['asset_code' => 'AST-0001', 'condition' => 'good']);

    $this->actingAs($manager)->get('/assets?status=retired&condition=damaged')->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('assets.total', 1)
            ->where('filters.status', '')
            ->where('filters.condition', ''));
});

it('shows a Manager with no reports and no assets an empty list', function () {
    $manager = userWithRole('manager');
    employeeFor($manager);
    assetRow();

    $this->actingAs($manager)->get('/assets')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('assets.total', 0)->has('assets.data', 0));
});

it('shows a Manager without an employee record nothing', function () {
    assetRow();
    $this->actingAs(userWithRole('manager'))->get('/assets')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('assets.total', 0));
});

it('refuses an Employee', function () {
    listAs('employee')->assertForbidden();
});

it('sends a guest to log in', function () {
    $this->get('/assets')->assertRedirect('/login');
});

it('filters by category, status and condition', function () {
    assetRow(['asset_code' => 'AST-0001', 'category' => 'Laptop', 'status' => 'available', 'condition' => 'good']);
    assetRow(['asset_code' => 'AST-0002', 'category' => 'Phone', 'status' => 'available', 'condition' => 'poor']);
    assetRow(['asset_code' => 'AST-0003', 'category' => 'Phone', 'status' => 'in_repair', 'condition' => 'poor']);
    $phone = assetCategory('Phone')->id;
    $hr = userWithRole('hr');
    $codes = fn (array $query) => collect($this->actingAs($hr)->get('/assets?'.http_build_query($query))->viewData('page')['props']['assets']['data'])->pluck('asset_code')->all();

    expect($codes(['category' => $phone]))->toBe(['AST-0002', 'AST-0003'])
        ->and($codes(['status' => 'in_repair']))->toBe(['AST-0003'])
        ->and($codes(['condition' => 'poor', 'status' => 'available']))->toBe(['AST-0002'])
        ->and($codes(['condition' => 'bogus']))->toBe(['AST-0001', 'AST-0002', 'AST-0003'])
        ->and($codes(['category' => 'x']))->toBe(['AST-0001', 'AST-0002', 'AST-0003']);
});

it('searches code, name, serial, and the holder name or code', function () {
    $sara = Employee::factory()->create(['first_name' => 'Sara', 'last_name' => 'Ahmed', 'employee_code' => 'EMP-0042']);
    assignedAsset($sara, ['asset_code' => 'AST-0001', 'name' => 'Dell', 'serial_number' => 'S1']);
    assetRow(['asset_code' => 'AST-0002', 'name' => 'Lenovo ThinkPad', 'serial_number' => 'XYZ-99']);
    assetRow(['asset_code' => 'AST-0003', 'name' => 'Pixel', 'serial_number' => 'P7']);
    $hr = userWithRole('hr');
    $codes = fn (string $term) => collect($this->actingAs($hr)->get('/assets?'.http_build_query(['search' => $term]))->viewData('page')['props']['assets']['data'])->pluck('asset_code')->all();

    expect($codes('thinkpad'))->toBe(['AST-0002'])
        ->and($codes('xyz-99'))->toBe(['AST-0002'])
        ->and($codes('ast-0003'))->toBe(['AST-0003'])
        ->and($codes('emp-0042'))->toBe(['AST-0001'])
        ->and($codes('sara ahmed'))->toBe(['AST-0001'])
        ->and($codes('ahmed'))->toBe(['AST-0001'])
        ->and($codes('50%'))->toBe([]);
});

it('does not match the previous holder of a returned asset', function () {
    $sara = Employee::factory()->create(['first_name' => 'Sara', 'last_name' => 'Ahmed']);
    assignedAsset($sara, ['asset_code' => 'AST-0001', 'assigned_on' => '2026-10-01', 'returned_on' => '2026-10-30', 'return_condition' => 'good']);

    expect(listAs('hr', ['search' => 'Sara'])->viewData('page')['props']['assets']['total'])->toBe(0);
});

it('searches inside the team for a Manager only', function () {
    $manager = userWithRole('manager');
    $me = employeeFor($manager);
    $report = Employee::factory()->create(['manager_id' => $me->id, 'first_name' => 'Ali', 'last_name' => 'Raza']);
    $other = Employee::factory()->create(['first_name' => 'Ali', 'last_name' => 'Outsider']);
    assignedAsset($report, ['asset_code' => 'AST-0001']);
    assignedAsset($other, ['asset_code' => 'AST-0002']);

    $this->actingAs($manager)->get('/assets?search=ali')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('assets.total', 1)->where('assets.data.0.asset_code', 'AST-0001'));
});

it('pages 25 at a time and sends an out-of-range page to the last one', function () {
    foreach (range(1, 30) as $i) {
        assetRow();
    }
    $hr = userWithRole('hr');

    $this->actingAs($hr)->get('/assets')->assertInertia(fn (Assert $page) => $page->has('assets.data', 25)->where('assets.last_page', 2)->where('assets.total', 30));
    $this->actingAs($hr)->get('/assets?page=2')->assertInertia(fn (Assert $page) => $page->has('assets.data', 5));
    $this->actingAs($hr)->get('/assets?page=9')->assertRedirect('/assets?page=2');
});

it('uses the same number of queries for 5 and 25 rows', function (string $role) {
    $user = userWithRole($role);
    $me = employeeFor($user);
    $count = function (int $rows) use ($user, $me) {
        foreach (range(1, $rows) as $i) {
            assignedAsset(Employee::factory()->create(['manager_id' => $me->id]));
        }
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($user)->get('/assets')->assertOk();

        return count(DB::getQueryLog());
    };

    $this->actingAs($user)->get('/assets')->assertOk(); // warm the one-time caches (permissions, settings)
    $five = $count(5);
    $more = $count(20);

    expect($more)->toBe($five);
})->with(['hr', 'manager']);
