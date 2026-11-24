<?php

use App\Models\Department;
use App\Models\Designation;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The `nodes` prop of GET /organization-chart as $user sees it, keyed by name.
 *
 * @return array<string, array<string, mixed>>
 */
function orgNodes(User $user): array
{
    $props = test()->actingAs($user)->get('/organization-chart')->assertOk()->viewData('page')['props'];

    $byName = [];
    foreach ($props['nodes'] as $node) {
        $byName[$node['name']] = $node;
    }
    ksort($byName);

    return $byName;
}

/** One department and designation shared by the people of a test. */
function orgDept(): array
{
    $dept = Department::first() ?? Department::factory()->create(['name' => 'Engineering']);
    $desig = Designation::where('department_id', $dept->id)->first()
        ?? Designation::factory()->create(['department_id' => $dept->id, 'name' => 'Engineer']);

    return ['department_id' => $dept->id, 'designation_id' => $desig->id];
}

function orgPerson(string $first, ?Employee $manager = null, string $status = 'active', array $attrs = []): Employee
{
    return Employee::factory()->create(array_merge(orgDept(), [
        'first_name' => $first,
        'last_name' => 'Test',
        'manager_id' => $manager?->id,
        'status' => $status,
    ], $attrs));
}

/** Writes manager_id straight to the table (the app refuses loops on save; old or imported data may still hold one). */
function orgSetManager(Employee $employee, ?Employee $manager): void
{
    DB::table('employees')->where('id', $employee->id)->update(['manager_id' => $manager?->id]);
}

// ---------------------------------------------------------------- access

it('has the route organization-chart.index at /organization-chart', function () {
    expect(route('organization-chart.index', absolute: false))->toBe('/organization-chart');
});

it('redirects guests to the sign-in page', function () {
    $this->get('/organization-chart')->assertRedirect('/login');
});

it('refuses a plain employee', function () {
    $user = userWithRole('employee');
    employeeFor($user);

    $this->actingAs($user)->get('/organization-chart')->assertForbidden();
});

it('renders the organization chart page', function () {
    $this->actingAs(userWithRole('admin'))->get('/organization-chart')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('organization-chart/index')->where('nodes', []));
});

it('gives admin and hr the whole company', function (string $role) {
    $ceo = orgPerson('Ceo');
    $cto = orgPerson('Cto', $ceo);
    $dev = orgPerson('Dev', $cto, 'probation');

    $nodes = orgNodes(userWithRole($role));

    expect(array_keys($nodes))->toBe(['Ceo Test', 'Cto Test', 'Dev Test'])
        ->and($nodes['Ceo Test']['manager_id'])->toBeNull()
        ->and($nodes['Cto Test']['manager_id'])->toBe($ceo->id)
        ->and($nodes['Dev Test']['manager_id'])->toBe($cto->id);
})->with(['admin', 'hr']);

it('gives a manager themself and direct reports only, with the manager as the root', function () {
    $boss = orgPerson('Boss');
    $user = userWithRole('manager');
    $mia = employeeFor($user, ['first_name' => 'Mia', 'last_name' => 'Test', 'manager_id' => $boss->id, ...orgDept()]);
    $ann = orgPerson('Ann', $mia);
    $ben = orgPerson('Ben', $mia, 'probation');
    orgPerson('Indirect', $ann);
    orgPerson('Stranger', $boss);

    $nodes = orgNodes($user->refresh());

    expect(array_keys($nodes))->toBe(['Ann Test', 'Ben Test', 'Mia Test'])
        // Mia's own manager is outside her scope, so she is the root.
        ->and($nodes['Mia Test']['manager_id'])->toBeNull()
        ->and($nodes['Ann Test']['manager_id'])->toBe($mia->id)
        ->and($nodes['Ben Test']['manager_id'])->toBe($mia->id);
});

it('leaves out a manager\'s inactive, resigned and terminated reports', function () {
    $user = userWithRole('manager');
    $mia = employeeFor($user, ['first_name' => 'Mia', 'last_name' => 'Test', ...orgDept()]);
    orgPerson('Gone', $mia, 'resigned');

    expect(array_keys(orgNodes($user->refresh())))->toBe(['Mia Test']);
});

// ---------------------------------------------------------------- who is shown and where they attach

it('shows only active and probation employees', function () {
    orgPerson('Active');
    orgPerson('Probation', null, 'probation');
    orgPerson('Inactive', null, 'inactive');
    orgPerson('Resigned', null, 'resigned');
    orgPerson('Terminated', null, 'terminated');

    expect(array_keys(orgNodes(userWithRole('admin'))))->toBe(['Active Test', 'Probation Test']);
});

it('attaches a person past an inactive middle manager to the nearest shown ancestor', function () {
    $top = orgPerson('Top');
    $middle = orgPerson('Middle', $top, 'inactive');
    $lower = orgPerson('Lower', $middle, 'resigned');
    $dev = orgPerson('Dev', $lower);
    $orphan = orgPerson('Orphan', orgPerson('Gone', null, 'terminated'));

    $nodes = orgNodes(userWithRole('admin'));

    expect(array_keys($nodes))->toBe(['Dev Test', 'Orphan Test', 'Top Test'])
        ->and($nodes['Dev Test']['manager_id'])->toBe($top->id)
        // Nobody shown above them: a root.
        ->and($nodes['Orphan Test']['manager_id'])->toBeNull();
});

it('attaches a manager\'s report past nobody and makes the manager the root when the manager\'s own manager is hidden', function () {
    $hiddenBoss = orgPerson('Hidden', null, 'inactive');
    $user = userWithRole('manager');
    $mia = employeeFor($user, ['first_name' => 'Mia', 'last_name' => 'Test', 'manager_id' => $hiddenBoss->id, ...orgDept()]);
    $ann = orgPerson('Ann', $mia);

    $nodes = orgNodes($user->refresh());

    expect($nodes['Mia Test']['manager_id'])->toBeNull()->and($nodes['Ann Test']['manager_id'])->toBe($mia->id);
});

// ---------------------------------------------------------------- what a node holds

it('sends exactly the node fields and nothing restricted', function (string $role) {
    $ceo = orgPerson('Ceo');
    orgPerson('Secret', $ceo, 'active', [
        'cnic' => '4220199887766',
        'email' => 'secret.person.zq@corp.test',
        'phone' => '+92-300-7654321-ZQ',
        'dob' => '1981-07-19',
        'device_user_id' => 'DEV-ZQ-991',
        'joining_date' => '2019-03-17',
    ]);

    if ($role === 'manager') {
        $user = userWithRole('manager');
        $mia = employeeFor($user, ['first_name' => 'Mia', 'last_name' => 'Test', ...orgDept()]);
        Employee::where('first_name', 'Secret')->update(['manager_id' => $mia->id]);
        $user->refresh();
    } else {
        $user = userWithRole($role);
    }

    $response = $this->actingAs($user)->get('/organization-chart')->assertOk();
    $nodes = $response->viewData('page')['props']['nodes'];

    expect($nodes)->not->toBeEmpty();
    foreach ($nodes as $node) {
        expect(array_keys($node))->toBe(['id', 'name', 'code', 'designation', 'department', 'photo_url', 'manager_id']);
    }

    $body = $response->getContent();
    foreach (['4220199887766', '42201-9988776-6', 'secret.person.zq', '7654321', '1981-07-19', 'DEV-ZQ-991', '2019-03-17'] as $value) {
        expect($body)->not->toContain($value);
    }
})->with(['admin', 'hr', 'manager']);

it('fills each node from the record', function () {
    $dept = Department::factory()->create(['name' => 'Finance']);
    $desig = Designation::factory()->create(['department_id' => $dept->id, 'name' => 'Accountant']);
    $e = Employee::factory()->create(['first_name' => 'Zara', 'last_name' => 'Khan', 'employee_code' => 'LD-0777', 'department_id' => $dept->id, 'designation_id' => $desig->id]);

    $node = orgNodes(userWithRole('admin'))['Zara Khan'];

    expect($node)->toBe([
        'id' => $e->id,
        'name' => 'Zara Khan',
        'code' => 'LD-0777',
        'designation' => 'Accountant',
        'department' => 'Finance',
        'photo_url' => null,
        'manager_id' => null,
    ]);
});

// ---------------------------------------------------------------- bad data and cost

it('answers when reporting lines in the database form a loop', function () {
    $a = orgPerson('Alpha');
    $b = orgPerson('Bravo');
    $c = orgPerson('Charlie');
    $self = orgPerson('Self');
    $hidden = orgPerson('Hidden', null, 'inactive');
    $d = orgPerson('Delta');
    // Alpha -> Bravo -> Charlie -> Alpha (all shown); Self -> Self; Delta -> Hidden -> Delta (loop through a hidden person).
    orgSetManager($a, $b);
    orgSetManager($b, $c);
    orgSetManager($c, $a);
    orgSetManager($self, $self);
    orgSetManager($hidden, $d);
    orgSetManager($d, $hidden);

    $nodes = orgNodes(userWithRole('admin'));

    expect($nodes['Alpha Test']['manager_id'])->toBe($b->id)
        ->and($nodes['Bravo Test']['manager_id'])->toBe($c->id)
        ->and($nodes['Charlie Test']['manager_id'])->toBe($a->id)
        // Nobody is sent as their own manager.
        ->and($nodes['Self Test']['manager_id'])->toBeNull()
        ->and($nodes['Delta Test']['manager_id'])->toBeNull();
});

it('answers when a long hidden chain loops without reaching anyone shown', function () {
    $people = [];
    for ($i = 0; $i < 30; $i++) {
        $people[] = orgPerson('Hidden'.$i, null, 'inactive');
    }
    foreach ($people as $i => $p) {
        orgSetManager($p, $people[($i + 1) % 30]);
    }
    $shown = orgPerson('Shown');
    orgSetManager($shown, $people[0]);

    expect(orgNodes(userWithRole('admin'))['Shown Test']['manager_id'])->toBeNull();
});

it('uses the same number of queries for 10 and 100 people', function () {
    $admin = userWithRole('admin');
    $count = function (int $people) use ($admin): int {
        DB::table('users')->whereNotNull('employee_id')->update(['employee_id' => null]);
        Employee::query()->update(['manager_id' => null]);
        Employee::query()->delete();
        $previous = null;
        for ($i = 0; $i < $people; $i++) {
            // Every third person is hidden, so re-attachment is exercised too.
            $previous = orgPerson('P'.$i, $previous, $i % 3 === 1 ? 'inactive' : 'active', [
                'department_id' => Department::factory()->create()->id,
            ]);
        }
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($admin)->get('/organization-chart')->assertOk();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    };

    $count(1); // warm up: the first request also loads roles and permissions into the cache
    $ten = $count(10);
    $hundred = $count(100);

    // people, departments, designations, the manager map, plus the request's own (session, user, permissions).
    expect($hundred)->toBe($ten)->and($ten)->toBeLessThanOrEqual(8);
});
