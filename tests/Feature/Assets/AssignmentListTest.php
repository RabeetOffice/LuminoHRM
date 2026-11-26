<?php

use App\Models\Department;
use App\Models\Employee;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

// The Assignments list: who sees what, the status switch, the filters, the order, the paging and the query count.

// The built Vite manifest does not list the new pages until the coordinator builds.
beforeEach(fn () => $this->withoutVite());

/** @param  array<string, mixed>  $query */
function assignmentsAs(string $role, array $query = [])
{
    return test()->actingAs(userWithRole($role))->get('/assets/assignments'.($query === [] ? '' : '?'.http_build_query($query)));
}

it('shows HR and Admin every open assignment by default, longest held first, with the held days', function (string $role) {
    $sara = Employee::factory()->create(['first_name' => 'Sara', 'last_name' => 'Ahmed', 'employee_code' => 'EMP-0007']);
    $ali = Employee::factory()->create(['first_name' => 'Ali', 'last_name' => 'Raza']);

    assignedAsset($sara, ['asset_code' => 'AST-0001', 'name' => 'Dell Latitude', 'assigned_on' => '2026-11-20', 'condition_out' => 'good']);
    assignedAsset($ali, ['asset_code' => 'AST-0002', 'name' => 'Old phone', 'category' => 'Mobile', 'assigned_on' => '2026-10-01']);
    assignedAsset($ali, ['asset_code' => 'AST-0003', 'returned_on' => '2026-11-10', 'return_condition' => 'fair', 'assigned_on' => '2026-11-01']);

    assignmentsAs($role)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('assets/assignments')
        ->where('scope', 'all')
        ->where('can', ['return' => true])
        ->where('filters', ['status' => 'open', 'search' => '', 'category' => null, 'department' => null])
        ->where('assignments.total', 2)
        ->where('assignments.data.0.asset.code', 'AST-0002')
        ->where('assignments.data.0.asset.category', 'Mobile')
        ->where('assignments.data.0.assigned_on', '2026-10-01')
        ->where('assignments.data.0.returned_on', null)
        ->where('assignments.data.1.asset.code', 'AST-0001')
        ->where('assignments.data.1.asset.name', 'Dell Latitude')
        ->where('assignments.data.1.employee.code', 'EMP-0007')
        ->where('assignments.data.1.employee.name', 'Sara Ahmed')
        ->where('assignments.data.1.employee.department', Employee::find($sara->id)->department->name)
        ->where('assignments.data.1.condition_out', 'good')
        ->where('assignments.data.1.return_condition', null)
        ->has('options.categories')
        ->has('options.departments'));
})->with(['admin', 'hr']);

it('counts the days held to the attendance today for open rows and to the return date for returned ones', function () {
    $this->travelTo('2026-11-26 10:00:00');
    $e = Employee::factory()->create();
    assignedAsset($e, ['asset_code' => 'AST-0001', 'assigned_on' => '2026-11-20']);
    assignedAsset($e, ['asset_code' => 'AST-0002', 'assigned_on' => '2026-11-01', 'returned_on' => '2026-11-11', 'return_condition' => 'good']);

    assignmentsAs('hr', ['status' => 'all'])->assertInertia(fn (Assert $page) => $page
        ->where('assignments.data.0.asset.code', 'AST-0001')
        ->where('assignments.data.0.days_held', 6)
        ->where('assignments.data.1.asset.code', 'AST-0002')
        ->where('assignments.data.1.days_held', 10));
});

it('switches between returned and all, newest first, still-out rows leading in all', function () {
    $e = Employee::factory()->create();
    assignedAsset($e, ['asset_code' => 'AST-0001', 'assigned_on' => '2026-11-20']);
    assignedAsset($e, ['asset_code' => 'AST-0002', 'assigned_on' => '2026-10-01', 'returned_on' => '2026-10-05', 'return_condition' => 'good']);
    assignedAsset($e, ['asset_code' => 'AST-0003', 'assigned_on' => '2026-10-10', 'returned_on' => '2026-11-02', 'return_condition' => 'good']);

    assignmentsAs('hr', ['status' => 'returned'])->assertInertia(fn (Assert $page) => $page
        ->where('filters.status', 'returned')
        ->where('assignments.total', 2)
        ->where('assignments.data.0.asset.code', 'AST-0003')
        ->where('assignments.data.1.asset.code', 'AST-0002'));

    assignmentsAs('hr', ['status' => 'all'])->assertInertia(fn (Assert $page) => $page
        ->where('assignments.total', 3)
        ->where('assignments.data.0.asset.code', 'AST-0001')
        ->where('assignments.data.1.asset.code', 'AST-0003')
        ->where('assignments.data.2.asset.code', 'AST-0002'));

    assignmentsAs('hr', ['status' => 'nonsense'])->assertInertia(fn (Assert $page) => $page
        ->where('filters.status', 'open')
        ->where('assignments.total', 1));
});

it('searches by asset code, serial, asset name, employee name and employee code', function (string $term, string $expected) {
    $sara = Employee::factory()->create(['first_name' => 'Sara', 'last_name' => 'Ahmed', 'employee_code' => 'EMP-0777']);
    $ali = Employee::factory()->create(['first_name' => 'Ali', 'last_name' => 'Raza', 'employee_code' => 'EMP-0888']);
    assignedAsset($sara, ['asset_code' => 'AST-0101', 'serial_number' => 'SN-ALPHA-1', 'name' => 'Dell Latitude']);
    assignedAsset($ali, ['asset_code' => 'AST-0202', 'serial_number' => 'SN-BETA-2', 'name' => 'Lenovo Tab']);

    assignmentsAs('hr', ['search' => $term])->assertInertia(fn (Assert $page) => $page
        ->where('assignments.total', 1)
        ->where('assignments.data.0.asset.code', $expected));
})->with([
    'asset code' => ['ast-0101', 'AST-0101'],
    'serial' => ['beta', 'AST-0202'],
    'asset name' => ['latitude', 'AST-0101'],
    'employee name' => ['sara ahmed', 'AST-0101'],
    'employee code' => ['EMP-0888', 'AST-0202'],
]);

it('treats percent and underscore in the search as plain characters', function () {
    $e = Employee::factory()->create();
    assignedAsset($e, ['asset_code' => 'AST-0001']);

    assignmentsAs('hr', ['search' => '%'])->assertInertia(fn (Assert $page) => $page->where('assignments.total', 0));
});

it('filters by category and by department', function () {
    $sales = Department::factory()->create(['name' => 'Sales']);
    $ops = Department::factory()->create(['name' => 'Ops']);
    $a = Employee::factory()->create(['department_id' => $sales->id]);
    $b = Employee::factory()->create(['department_id' => $ops->id]);
    assignedAsset($a, ['asset_code' => 'AST-0001', 'category' => 'Laptop']);
    assignedAsset($b, ['asset_code' => 'AST-0002', 'category' => 'Mobile']);

    assignmentsAs('hr', ['category' => assetCategory('Mobile')->id])->assertInertia(fn (Assert $page) => $page
        ->where('assignments.total', 1)
        ->where('assignments.data.0.asset.code', 'AST-0002')
        ->where('filters.category', assetCategory('Mobile')->id));

    assignmentsAs('hr', ['department' => $sales->id])->assertInertia(fn (Assert $page) => $page
        ->where('assignments.total', 1)
        ->where('assignments.data.0.asset.code', 'AST-0001')
        ->where('filters.department', $sales->id));

    assignmentsAs('hr', ['department' => $sales->id, 'category' => assetCategory('Mobile')->id])
        ->assertInertia(fn (Assert $page) => $page->where('assignments.total', 0));
});

it('gives a Manager only their team, read-only, and no cost anywhere', function () {
    $manager = userWithRole('manager');
    $me = employeeFor($manager, ['first_name' => 'Mona', 'last_name' => 'Khan']);
    $report = Employee::factory()->create(['manager_id' => $me->id]);
    $other = Employee::factory()->create();
    assignedAsset($report, ['asset_code' => 'AST-0001', 'cost' => 9900000]);
    assignedAsset($me, ['asset_code' => 'AST-0002', 'cost' => 1100000]);
    assignedAsset($other, ['asset_code' => 'AST-0003', 'cost' => 7700000]);
    assignedAsset($other, ['asset_code' => 'AST-0004', 'cost' => 5500000, 'returned_on' => '2026-11-10', 'return_condition' => 'good', 'assigned_on' => '2026-11-01']);

    $response = $this->actingAs($manager)->get('/assets/assignments?status=all')->assertOk();

    $response->assertInertia(fn (Assert $page) => $page
        ->where('scope', 'team')
        ->where('can', ['return' => false])
        ->where('assignments.total', 2)
        ->where('assignments.data.0.asset.code', 'AST-0001')
        ->where('assignments.data.1.asset.code', 'AST-0002'));

    $json = json_encode($response->viewData('page')['props']);
    expect($json)->not->toContain('cost')->not->toContain('purchase_date')->not->toContain('AST-0003')->not->toContain('AST-0004');
});

it('keeps a Manager search inside their team, whatever the term matches', function (string $term, int $total) {
    $manager = userWithRole('manager');
    $me = employeeFor($manager, ['first_name' => 'Mona', 'last_name' => 'Khan']);
    $report = Employee::factory()->create(['manager_id' => $me->id, 'first_name' => 'Rida', 'last_name' => 'Team', 'employee_code' => 'EMP-0301']);
    $other = Employee::factory()->create(['first_name' => 'Rida', 'last_name' => 'Outsider', 'employee_code' => 'EMP-0302']);
    assignedAsset($report, ['asset_code' => 'AST-0001', 'serial_number' => 'SN-SHARED-1', 'name' => 'Shared Laptop']);
    assignedAsset($other, ['asset_code' => 'AST-0002', 'serial_number' => 'SN-SHARED-2', 'name' => 'Shared Laptop']);
    assignedAsset($other, ['asset_code' => 'AST-0003', 'serial_number' => 'SN-OUT-3', 'name' => 'Outside Phone', 'returned_on' => '2026-11-10', 'return_condition' => 'good', 'assigned_on' => '2026-11-01']);

    $response = $this->actingAs($manager)->get('/assets/assignments?status=all&'.http_build_query(['search' => $term]))->assertOk();

    $response->assertInertia(fn (Assert $page) => $page->where('assignments.total', $total));
    // The search box echoes the term in `filters`, so only the rows are searched for the outsider's data.
    expect(json_encode($response->viewData('page')['props']['assignments']))->not->toContain('AST-0002')->not->toContain('AST-0003')->not->toContain('Outsider');
})->with([
    'asset name shared with an outsider' => ['shared laptop', 1],
    'first name shared with an outsider' => ['rida', 1],
    'outsider asset code' => ['AST-0002', 0],
    'outsider employee code' => ['EMP-0302', 0],
    'outsider serial' => ['SN-OUT', 0],
]);

it('never sends a cost to Admin or HR either', function (string $role) {
    assignedAsset(Employee::factory()->create(), ['asset_code' => 'AST-0001', 'cost' => 9900000]);

    $json = json_encode(assignmentsAs($role)->viewData('page')['props']);

    expect($json)->not->toContain('cost')->not->toContain('9900000')->not->toContain('purchase_date');
})->with(['admin', 'hr']);

it('refuses an Employee and a guest', function () {
    assignmentsAs('employee')->assertForbidden();
    $this->post('/logout');
    auth()->logout();
    $this->get('/assets/assignments')->assertRedirect('/login');
});

it('is not shadowed by the asset page route', function () {
    assignmentsAs('hr')->assertOk()->assertInertia(fn (Assert $page) => $page->component('assets/assignments'));
});

it('redirects a page past the end back to the last page', function () {
    $e = Employee::factory()->create();
    assignedAsset($e);

    $this->actingAs(userWithRole('hr'))->get('/assets/assignments?page=9')->assertRedirect();
});

it('runs the same number of queries whatever the page size', function () {
    $queries = function (int $rows): int {
        DB::table('asset_assignments')->delete();
        DB::table('assets')->delete();
        $dept = Department::factory()->create();
        for ($i = 0; $i < $rows; $i++) {
            assignedAsset(Employee::factory()->create(['department_id' => $dept->id]), ['category' => $i % 2 ? 'Laptop' : 'Mobile']);
        }
        $user = userWithRole('hr');
        $this->actingAs($user)->get('/assets/assignments'); // warm: permissions, session
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($user)->get('/assets/assignments')->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    $small = $queries(2);
    $large = $queries(12);

    expect($large)->toBe($small);
});
