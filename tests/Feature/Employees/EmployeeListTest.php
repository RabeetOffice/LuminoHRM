<?php

use App\Models\Department;
use App\Models\Designation;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The page props of GET /employees as the given user sees them.
 *
 * @return array<string, mixed>
 */
function employeeListProps(User $user, string $query = ''): array
{
    $response = test()->actingAs($user)->get('/employees'.($query === '' ? '' : '?'.$query))->assertOk();

    return $response->viewData('page')['props'];
}

/** @return list<string> */
function rowNames(array $props): array
{
    return array_column($props['employees']['data'], 'name');
}

/** Every key at any depth. */
function allKeys(mixed $value): array
{
    $keys = [];
    if (is_array($value)) {
        foreach ($value as $k => $v) {
            $keys[] = (string) $k;
            array_push($keys, ...allKeys($v));
        }
    }

    return $keys;
}

/** Ada Lovelace (Engineering / Engineer, active), Bob Stone (Sales / Rep, probation), Cy Young (Engineering / Lead, resigned). */
function sampleStaff(): array
{
    $eng = Department::factory()->create(['name' => 'Engineering']);
    $sales = Department::factory()->create(['name' => 'Sales']);
    $engineer = Designation::factory()->create(['department_id' => $eng->id, 'name' => 'Engineer']);
    $lead = Designation::factory()->create(['department_id' => $eng->id, 'name' => 'Lead']);
    $rep = Designation::factory()->create(['department_id' => $sales->id, 'name' => 'Rep']);

    $ada = Employee::factory()->create(['first_name' => 'Ada', 'last_name' => 'Lovelace', 'email' => 'ada@corp.test', 'employee_code' => 'LD-0101', 'department_id' => $eng->id, 'designation_id' => $engineer->id, 'status' => 'active']);
    $bob = Employee::factory()->create(['first_name' => 'Bob', 'last_name' => 'Stone', 'email' => 'bob@corp.test', 'employee_code' => 'LD-0102', 'department_id' => $sales->id, 'designation_id' => $rep->id, 'status' => 'probation']);
    $cy = Employee::factory()->create(['first_name' => 'Cy', 'last_name' => 'Young', 'email' => 'cy@corp.test', 'employee_code' => 'LD-0103', 'department_id' => $eng->id, 'designation_id' => $lead->id, 'status' => 'resigned']);

    return compact('eng', 'sales', 'engineer', 'lead', 'rep', 'ada', 'bob', 'cy');
}

// ---------------------------------------------------------------- access

it('redirects guests to the sign-in page', function () {
    $this->get('/employees')->assertRedirect('/login');
});

it('lets admin and hr see everyone', function (string $role) {
    sampleStaff();

    $props = employeeListProps(userWithRole($role));

    expect($props['employees']['total'])->toBe(3)
        ->and(rowNames($props))->toBe(['Ada Lovelace', 'Bob Stone', 'Cy Young']);
})->with(['admin', 'hr']);

it('shows a manager only themself and direct reports', function () {
    $s = sampleStaff();
    $manager = userWithRole('manager');
    $mine = employeeFor($manager, ['first_name' => 'Mia', 'last_name' => 'Boss']);
    $s['ada']->update(['manager_id' => $mine->id]);
    // Indirect report: reports to Ada, not to Mia.
    $s['bob']->update(['manager_id' => $s['ada']->id]);

    $props = employeeListProps($manager->refresh());

    expect(rowNames($props))->toBe(['Mia Boss', 'Ada Lovelace'])
        ->and($props['employees']['total'])->toBe(2);
});

it('answers 403 to a plain employee, and shows a manager without a record an empty list', function () {
    sampleStaff();

    $employee = userWithRole('employee');
    employeeFor($employee);
    $this->actingAs($employee)->get('/employees')->assertForbidden();

    $props = employeeListProps(userWithRole('manager'));
    expect($props['employees']['total'])->toBe(0)
        ->and($props['counts']['all'])->toBe(0);
});

it('gives each role its action permissions', function (string $role, array $expected) {
    sampleStaff();

    expect(employeeListProps(userWithRole($role))['can'])->toBe($expected);
})->with([
    ['admin', ['manage' => true, 'delete' => true, 'import' => true, 'export' => true]],
    ['hr', ['manage' => true, 'delete' => false, 'import' => true, 'export' => true]],
    ['manager', ['manage' => false, 'delete' => false, 'import' => false, 'export' => false]],
]);

// ---------------------------------------------------------------- what a row holds

it('builds each row from an explicit field list', function () {
    $s = sampleStaff();
    $s['ada']->update(['dob' => '1990-05-04', 'phone' => '0300-1234567', 'gender' => 'female']);

    $row = employeeListProps(userWithRole('admin'), 'search=ada')['employees']['data'][0];

    expect(array_keys($row))->toEqualCanonicalizing(['id', 'code', 'name', 'email', 'photo_url', 'department', 'designation', 'status', 'joining_date', 'login'])
        ->and($row['id'])->toBe($s['ada']->id)
        ->and($row['code'])->toBe('LD-0101')
        ->and($row['name'])->toBe('Ada Lovelace')
        ->and($row['email'])->toBe('ada@corp.test')
        ->and($row['photo_url'])->toBeNull()
        ->and($row['department'])->toBe('Engineering')
        ->and($row['designation'])->toBe('Engineer')
        ->and($row['status'])->toBe('active')
        ->and($row['joining_date'])->toBe($s['ada']->joining_date->format('Y-m-d'));
});

it('never puts restricted fields in the props, for any role', function (string $role) {
    $s = sampleStaff();
    $s['ada']->update(['dob' => '1990-05-04', 'phone' => '0300-1234567', 'gender' => 'female', 'photo_path' => 'employees/1/photo/secret.png']);
    $s['ada']->contact()->create(['address' => '12 Hidden Lane', 'emergency_name' => 'Zed Contact', 'emergency_phone' => '0311-0000000']);
    $s['ada']->bankAccount()->create(['bank_name' => 'Secret Bank', 'account_title' => 'Ada L', 'account_number' => 'PK00SECRET123']);
    $s['ada']->documents()->create(['type' => 'cnic', 'title' => 'My CNIC', 'file_path' => 'x/y.pdf', 'original_name' => 'cnic.pdf', 'mime' => 'application/pdf', 'size_bytes' => 10]);

    $user = userWithRole($role);
    $mine = employeeFor($user);
    $s['ada']->update(['manager_id' => $mine->id]);

    $props = employeeListProps($user->refresh());
    $keys = allKeys($props['employees']);
    $json = json_encode($props);

    foreach (['cnic', 'dob', 'gender', 'phone', 'bank', 'bank_account', 'account_number', 'address', 'emergency', 'contact', 'documents', 'photo_path', 'device_user_id', 'manager_id'] as $forbidden) {
        foreach ($keys as $key) {
            expect($key)->not->toContain($forbidden);
        }
    }
    foreach ([$s['ada']->cnic, '1990-05-04', '12 Hidden Lane', 'Zed Contact', 'Secret Bank', 'PK00SECRET123', 'My CNIC', 'secret.png', '0300-1234567'] as $secret) {
        expect($json)->not->toContain($secret);
    }
})->with(['admin', 'hr', 'manager']);

// ---------------------------------------------------------------- login pill

it('shows the login state: Active, Disabled or no account', function () {
    $s = sampleStaff();
    $active = User::factory()->create(['is_active' => true]);
    $off = User::factory()->create(['is_active' => false]);
    $active->forceFill(['employee_id' => $s['ada']->id])->save();
    $off->forceFill(['employee_id' => $s['bob']->id])->save();

    $rows = collect(employeeListProps(userWithRole('admin'), 'per_page=50')['employees']['data'])->keyBy('name');

    expect($rows['Ada Lovelace']['login'])->toBe('active')
        ->and($rows['Bob Stone']['login'])->toBe('disabled')
        ->and($rows['Cy Young']['login'])->toBe('none');
});

// ---------------------------------------------------------------- sorting

it('sorts by last name, then first name, then id', function () {
    Employee::factory()->create(['first_name' => 'Zoe', 'last_name' => 'Adams']);
    Employee::factory()->create(['first_name' => 'Amy', 'last_name' => 'Brown']);
    Employee::factory()->create(['first_name' => 'Ben', 'last_name' => 'Adams']);
    $first = Employee::factory()->create(['first_name' => 'Ben', 'last_name' => 'Brown']);
    $dupA = Employee::factory()->create(['first_name' => 'Dan', 'last_name' => 'Cole']);
    $dupB = Employee::factory()->create(['first_name' => 'Dan', 'last_name' => 'Cole']);

    $props = employeeListProps(userWithRole('admin'), 'per_page=50');

    expect(rowNames($props))->toBe(['Ben Adams', 'Zoe Adams', 'Amy Brown', 'Ben Brown', 'Dan Cole', 'Dan Cole'])
        ->and(array_column($props['employees']['data'], 'id'))->toBe([
            Employee::where('first_name', 'Ben')->where('last_name', 'Adams')->value('id'),
            Employee::where('first_name', 'Zoe')->value('id'),
            Employee::where('first_name', 'Amy')->value('id'),
            $first->id,
            $dupA->id,
            $dupB->id,
        ]);
});

// ---------------------------------------------------------------- search and filters

it('searches by name, full name, code and email', function (string $term, array $expected) {
    sampleStaff();

    expect(rowNames(employeeListProps(userWithRole('admin'), 'search='.urlencode($term))))->toBe($expected);
})->with([
    'first name' => ['ada', ['Ada Lovelace']],
    'last name, any case' => ['STONE', ['Bob Stone']],
    'full name' => ['cy young', ['Cy Young']],
    'code' => ['LD-0102', ['Bob Stone']],
    'email' => ['cy@corp', ['Cy Young']],
    'no match' => ['nobody', []],
]);

it('treats percent and underscore in the search as literal characters', function () {
    sampleStaff();
    Employee::factory()->create(['first_name' => '100%', 'last_name' => 'Real', 'email' => 'pct@corp.test']);

    $admin = userWithRole('admin');

    expect(rowNames(employeeListProps($admin, 'search=%25')))->toBe(['100% Real'])
        ->and(rowNames(employeeListProps($admin, 'search=_')))->toBe([]);
});

it('filters by department and designation', function () {
    $s = sampleStaff();
    $admin = userWithRole('admin');

    expect(rowNames(employeeListProps($admin, 'department_id='.$s['eng']->id)))->toBe(['Ada Lovelace', 'Cy Young'])
        ->and(rowNames(employeeListProps($admin, 'designation_id='.$s['rep']->id)))->toBe(['Bob Stone'])
        ->and(rowNames(employeeListProps($admin, 'department_id='.$s['eng']->id.'&designation_id='.$s['lead']->id)))->toBe(['Cy Young']);
});

it('ignores a department or designation that does not exist, and a designation outside the chosen department', function () {
    $s = sampleStaff();
    $admin = userWithRole('admin');

    $bad = employeeListProps($admin, 'department_id=99999&designation_id=88888');
    expect($bad['employees']['total'])->toBe(3)
        ->and($bad['filters']['department_id'])->toBeNull()
        ->and($bad['filters']['designation_id'])->toBeNull();

    $mismatch = employeeListProps($admin, 'department_id='.$s['eng']->id.'&designation_id='.$s['rep']->id);
    expect($mismatch['filters']['department_id'])->toBe($s['eng']->id)
        ->and($mismatch['filters']['designation_id'])->toBeNull()
        ->and(rowNames($mismatch))->toBe(['Ada Lovelace', 'Cy Young']);

    expect(employeeListProps($admin, 'department_id=abc&designation_id=-1')['employees']['total'])->toBe(3);
});

it('lists only the chosen department\'s designations in the designation select', function () {
    $s = sampleStaff();
    $admin = userWithRole('admin');

    $all = employeeListProps($admin);
    expect(array_column($all['designations'], 'name'))->toEqualCanonicalizing(['Engineer', 'Lead', 'Rep'])
        ->and(array_column($all['departments'], 'name'))->toBe(['Engineering', 'Sales'])
        ->and(array_keys($all['departments'][0]))->toBe(['id', 'name'])
        ->and(array_keys($all['designations'][0]))->toBe(['id', 'name']);

    $eng = employeeListProps($admin, 'department_id='.$s['eng']->id);
    expect(array_column($eng['designations'], 'name'))->toEqualCanonicalizing(['Engineer', 'Lead']);
});

it('filters by status and ignores an unknown status', function () {
    sampleStaff();
    $admin = userWithRole('admin');

    expect(rowNames(employeeListProps($admin, 'status=probation')))->toBe(['Bob Stone'])
        ->and(employeeListProps($admin, 'status=probation')['filters']['status'])->toBe('probation');

    $unknown = employeeListProps($admin, 'status=bogus');
    expect($unknown['employees']['total'])->toBe(3)->and($unknown['filters']['status'])->toBe('');
});

it('remembers the view in the url only, list by default', function () {
    sampleStaff();
    $admin = userWithRole('admin');

    expect(employeeListProps($admin)['filters']['view'])->toBe('list')
        ->and(employeeListProps($admin, 'view=grid')['filters']['view'])->toBe('grid')
        ->and(employeeListProps($admin, 'view=cards')['filters']['view'])->toBe('list');
});

it('never errors on array-valued or odd parameters', function () {
    sampleStaff();
    $admin = userWithRole('admin');

    foreach (['search[]=a', 'department_id[]=1', 'designation_id[x]=1', 'status[]=active', 'view[]=grid', 'per_page[]=50', 'page[]=2', 'page=-4'] as $query) {
        $this->actingAs($admin)->get('/employees?'.$query)->assertSuccessful();
    }

    // A page number too long to be real is "past the end": sent to the real last page, not an error.
    $this->actingAs($admin)->get('/employees?page=99999999999999999999')->assertRedirect('/employees');
});

// ---------------------------------------------------------------- counts

it('counts per status within the viewer\'s scope, ignoring the status filter but honouring the others', function () {
    $s = sampleStaff();
    Employee::factory()->count(2)->create(['status' => 'active', 'department_id' => $s['eng']->id, 'designation_id' => $s['engineer']->id]);
    Employee::factory()->create(['status' => 'terminated', 'department_id' => $s['sales']->id, 'designation_id' => $s['rep']->id]);
    $admin = userWithRole('admin');

    $all = employeeListProps($admin)['counts'];
    expect($all)->toBe(['all' => 6, 'active' => 3, 'probation' => 1, 'inactive' => 0, 'resigned' => 1, 'terminated' => 1]);

    // A status filter does not change the tab counts.
    expect(employeeListProps($admin, 'status=active')['counts'])->toBe($all);

    // Department and search do.
    expect(employeeListProps($admin, 'department_id='.$s['eng']->id)['counts'])
        ->toBe(['all' => 4, 'active' => 3, 'probation' => 0, 'inactive' => 0, 'resigned' => 1, 'terminated' => 0])
        ->and(employeeListProps($admin, 'search=bob&status=active')['counts'])
        ->toBe(['all' => 1, 'active' => 0, 'probation' => 1, 'inactive' => 0, 'resigned' => 0, 'terminated' => 0]);
});

it('counts only what a manager may see', function () {
    $s = sampleStaff();
    $manager = userWithRole('manager');
    $mine = employeeFor($manager, ['status' => 'active']);
    $s['ada']->update(['manager_id' => $mine->id]);
    $s['bob']->update(['manager_id' => $s['ada']->id]);

    expect(employeeListProps($manager->refresh())['counts'])
        ->toBe(['all' => 2, 'active' => 2, 'probation' => 0, 'inactive' => 0, 'resigned' => 0, 'terminated' => 0]);
});

it('counts statuses with one grouped query', function () {
    sampleStaff();
    $admin = userWithRole('admin');
    $statements = [];
    DB::listen(function ($q) use (&$statements) {
        $statements[] = $q->sql;
    });

    employeeListProps($admin);

    $grouped = array_filter($statements, fn ($sql) => str_contains(strtolower($sql), 'group by') && str_contains($sql, 'employees'));
    expect($grouped)->toHaveCount(1);
});

// ---------------------------------------------------------------- paging

it('pages with per_page and clamps a page past the end', function () {
    Employee::factory()->count(12)->create();
    $admin = userWithRole('admin');

    $first = employeeListProps($admin);
    expect($first['employees']['per_page'])->toBe(10)
        ->and($first['employees']['data'])->toHaveCount(10)
        ->and($first['employees']['last_page'])->toBe(2)
        ->and($first['employees']['from'])->toBe(1)
        ->and($first['employees']['to'])->toBe(10);

    expect(employeeListProps($admin, 'per_page=25')['employees']['data'])->toHaveCount(12)
        ->and(employeeListProps($admin, 'per_page=7')['employees']['per_page'])->toBe(10)
        ->and(employeeListProps($admin, 'page=2')['employees']['data'])->toHaveCount(2);

    $this->actingAs($admin)->get('/employees?page=9')->assertRedirect('/employees?page=2');
    $this->actingAs($admin)->get('/employees?status=active&page=9')->assertRedirect('/employees?status=active&page=2');
});

// ---------------------------------------------------------------- queries

it('runs the same number of queries for 10 rows and for 50', function () {
    $admin = userWithRole('admin');
    $department = Department::factory()->create();
    $designation = Designation::factory()->create(['department_id' => $department->id]);
    $make = fn (int $n) => Employee::factory()->count($n)->create(['department_id' => $department->id, 'designation_id' => $designation->id])
        ->each(fn ($e) => User::factory()->create()->forceFill(['employee_id' => $e->id])->save());

    $queries = function () use ($admin): int {
        $count = 0;
        DB::listen(function () use (&$count) {
            $count++;
        });
        employeeListProps($admin, 'per_page=50');

        return $count;
    };

    $make(10);
    employeeListProps($admin); // the first request also loads the permission cache
    $small = $queries();
    $make(40);
    $large = $queries();

    expect(employeeListProps($admin, 'per_page=50')['employees']['data'])->toHaveCount(50)
        ->and($large)->toBe($small);
});
