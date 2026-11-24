<?php

use App\Models\Department;
use App\Models\Designation;
use App\Models\Employee;
use App\Models\EmployeeBankAccount;
use App\Models\EmployeeContact;
use App\Models\EmployeeDocument;
use App\Models\User;
use App\Support\EmployeeRoles;
use Illuminate\Support\Facades\Notification;
use Spatie\Activitylog\Models\Activity;

// Distinctive values for everything a Manager must never see. Every one of them is searched for in the props.
const EPR_CNIC = '4220177665544';
const EPR_CNIC_FORMATTED = '42201-7766554-4';
const EPR_DOB = '1987-06-14';
const EPR_ADDRESS = 'Zebra Quarter 7421, Lahore';
const EPR_EMERGENCY_NAME = 'Quentin Emergencyname';
const EPR_EMERGENCY_PHONE = '0345-9988776';
const EPR_BANK = 'Zeta Bank Testing';
const EPR_ACCOUNT_TITLE = 'Zelda Accounttitle';
const EPR_ACCOUNT = 'PK99ZETA9988776655443322';
const EPR_DEVICE = 'MCH-88123-ZQ';
const EPR_DOC_TITLE = 'Secret Contract Scan';

const EPR_ALL_TABS = ['basic', 'employment', 'contact', 'banking', 'documents'];

/** A fully filled employee: every group has data, and the restricted data is distinctive. */
function eprRecord(array $attrs = [], ?User $user = null): Employee
{
    $dept = Department::where('name', 'Engineering')->first() ?? Department::factory()->create(['name' => 'Engineering']);
    $desig = Designation::where('department_id', $dept->id)->where('name', 'Engineer')->first()
        ?? Designation::factory()->create(['department_id' => $dept->id, 'name' => 'Engineer']);

    $defaults = [
        'first_name' => 'Ayesha',
        'last_name' => 'Khan',
        'department_id' => $dept->id,
        'designation_id' => $desig->id,
        'dob' => EPR_DOB,
        'gender' => 'female',
        'phone' => '0300-1112223',
        'joining_date' => '2024-03-01',
        'probation_end_date' => '2024-06-01',
    ];

    // The CNIC and the machine ID are unique: only the FIRST record of a test carries the distinctive ones.
    if (! Employee::where('cnic', EPR_CNIC)->exists()) {
        $defaults['cnic'] = EPR_CNIC;
        $defaults['device_user_id'] = EPR_DEVICE;
    }

    $attrs = array_merge($defaults, $attrs);

    $employee = $user !== null ? employeeFor($user, $attrs) : Employee::factory()->create($attrs);

    EmployeeContact::factory()->create([
        'employee_id' => $employee->id,
        'address' => EPR_ADDRESS,
        'emergency_name' => EPR_EMERGENCY_NAME,
        'emergency_phone' => EPR_EMERGENCY_PHONE,
    ]);
    EmployeeBankAccount::factory()->create([
        'employee_id' => $employee->id,
        'bank_name' => EPR_BANK,
        'account_title' => EPR_ACCOUNT_TITLE,
        'account_number' => EPR_ACCOUNT,
    ]);
    EmployeeDocument::factory()->create(['employee_id' => $employee->id, 'title' => EPR_DOC_TITLE]);

    return $employee;
}

/** A manager with an employee record, one direct report, and that report's own report (indirect for the manager). */
function eprTeam(): array
{
    $managerUser = userWithRole('manager');
    $manager = employeeFor($managerUser);
    $report = eprRecord(['manager_id' => $manager->id, 'first_name' => 'Direct', 'last_name' => 'Report']);
    $indirect = eprRecord(['manager_id' => $report->id, 'first_name' => 'Indirect', 'last_name' => 'Report']);
    $stranger = eprRecord(['first_name' => 'Total', 'last_name' => 'Stranger']);

    return [$managerUser, $manager, $report, $indirect, $stranger];
}

function eprProps($response): array
{
    return $response->viewData('page')['props'];
}

/** Every key at every depth, dotted. */
function eprKeys(mixed $value, string $prefix = ''): array
{
    $keys = [];

    if (is_array($value)) {
        foreach ($value as $key => $child) {
            $path = $prefix === '' ? (string) $key : "{$prefix}.{$key}";
            $keys[] = $path;
            array_push($keys, ...eprKeys($child, $path));
        }
    }

    return $keys;
}

beforeEach(function () {
    Notification::fake();
});

// ---------------------------------------------------------------- access

it('sends guests to the sign-in page', function () {
    $e = Employee::factory()->create();

    $this->get("/employees/{$e->id}")->assertRedirect('/login');
});

it('returns 404 for an unknown or non-numeric id, and does not swallow the static routes', function () {
    $admin = userWithRole('admin');

    $this->actingAs($admin)->get('/employees/99999')->assertNotFound();
    $this->actingAs($admin)->get('/employees/abc')->assertNotFound();
    $this->actingAs($admin)->get('/employees/create')->assertOk();
    expect(route('employees.show', 5, false))->toBe('/employees/5');
});

it('shows an admin any record with all six groups and every tab', function () {
    $admin = userWithRole('admin');
    $e = eprRecord();

    $props = eprProps($this->actingAs($admin)->get("/employees/{$e->id}")->assertOk());

    expect($props['tabs'])->toBe(EPR_ALL_TABS)
        ->and(array_keys($props['employee']))->toBe(['id', 'code', 'name', 'photo_url', 'basic', 'identity', 'employment', 'contact', 'banking', 'documents'])
        ->and($props['employee']['name'])->toBe('Ayesha Khan')
        ->and($props['employee']['code'])->toBe($e->employee_code)
        ->and($props['employee']['basic'])->toBe([
            'first_name' => 'Ayesha', 'last_name' => 'Khan', 'email' => $e->email, 'phone' => '0300-1112223', 'gender' => 'female',
        ])
        ->and($props['employee']['identity'])->toBe(['cnic' => EPR_CNIC_FORMATTED, 'dob' => EPR_DOB])
        ->and($props['employee']['contact'])->toBe(['address' => EPR_ADDRESS, 'emergency_name' => EPR_EMERGENCY_NAME, 'emergency_phone' => EPR_EMERGENCY_PHONE])
        ->and($props['employee']['banking'])->toBe(['bank_name' => EPR_BANK, 'account_title' => EPR_ACCOUNT_TITLE, 'account_number' => EPR_ACCOUNT])
        ->and($props['employee']['documents']['items'])->toHaveCount(1);
});

it('shows HR any record, including an Admin record, with every tab but no edit rights on it', function () {
    $hr = userWithRole('hr');
    $adminUser = userWithRole('admin');
    $adminEmployee = eprRecord([], $adminUser);
    $plainUser = userWithRole('employee');
    $plain = eprRecord([], $plainUser);

    $onAdmin = eprProps($this->actingAs($hr)->get("/employees/{$adminEmployee->id}")->assertOk());
    $onPlain = eprProps($this->actingAs($hr)->get("/employees/{$plain->id}")->assertOk());

    expect($onAdmin['tabs'])->toBe(EPR_ALL_TABS)
        ->and($onAdmin['can'])->toBe(['edit' => false, 'changeStatus' => false, 'delete' => false])
        ->and($onPlain['tabs'])->toBe(EPR_ALL_TABS)
        ->and($onPlain['can'])->toBe(['edit' => true, 'changeStatus' => true, 'delete' => false]);
});

it('gives an Admin the delete right only where the delete rules allow it', function () {
    $adminUser = userWithRole('admin');
    $own = eprRecord([], $adminUser);
    $deletable = eprRecord();
    $withReports = eprRecord();
    eprRecord(['manager_id' => $withReports->id]);
    $signedIn = eprRecord([], userWithRole('employee'));
    $signedIn->user->forceFill(['last_login_at' => now()])->save();

    $can = fn (Employee $e) => eprProps($this->actingAs($adminUser)->get("/employees/{$e->id}")->assertOk())['can'];

    expect($can($deletable))->toBe(['edit' => true, 'changeStatus' => true, 'delete' => true])
        ->and($can($withReports)['delete'])->toBeFalse()
        ->and($can($signedIn)['delete'])->toBeFalse()
        // The delete route refuses your own record, so the page does not offer it.
        ->and($can($own))->toBe(['edit' => true, 'changeStatus' => true, 'delete' => false]);
});

it('shows a manager their own record through this route with all six groups and no edit rights', function () {
    [$managerUser, $manager] = eprTeam();
    // eprTeam made the manager's record without the satellites; give it some.
    EmployeeContact::factory()->create(['employee_id' => $manager->id, 'address' => 'Own Street 1']);
    EmployeeBankAccount::factory()->create(['employee_id' => $manager->id]);

    $props = eprProps($this->actingAs($managerUser)->get("/employees/{$manager->id}")->assertOk());

    expect($props['tabs'])->toBe(EPR_ALL_TABS)
        ->and(array_keys($props['employee']))->toBe(['id', 'code', 'name', 'photo_url', 'basic', 'identity', 'employment', 'contact', 'banking', 'documents'])
        ->and($props['can'])->toBe(['edit' => false, 'changeStatus' => false, 'delete' => false]);
});

it('gives a manager basic and employment only for a direct report', function () {
    [$managerUser, , $report] = eprTeam();

    $props = eprProps($this->actingAs($managerUser)->get("/employees/{$report->id}")->assertOk());

    expect($props['tabs'])->toBe(['basic', 'employment'])
        ->and(array_keys($props['employee']))->toBe(['id', 'code', 'name', 'photo_url', 'basic', 'employment'])
        ->and($props['employee']['basic'])->toBe([
            'first_name' => 'Direct', 'last_name' => 'Report', 'email' => $report->email, 'phone' => '0300-1112223', 'gender' => 'female',
        ])
        ->and($props['can'])->toBe(['edit' => false, 'changeStatus' => false, 'delete' => false]);
});

it('refuses a manager an indirect report and a stranger with 403', function () {
    [$managerUser, , , $indirect, $stranger] = eprTeam();

    $this->actingAs($managerUser)->get("/employees/{$indirect->id}")->assertForbidden();
    $this->actingAs($managerUser)->get("/employees/{$stranger->id}")->assertForbidden();
});

it('refuses a manager who has no employee record every profile', function () {
    $managerUser = userWithRole('manager');
    $e = eprRecord();

    $this->actingAs($managerUser)->get("/employees/{$e->id}")->assertForbidden();
});

it('sends an employee who opens their own record to My Profile, and refuses everyone else', function () {
    $user = userWithRole('employee');
    $own = employeeFor($user);
    $other = eprRecord();

    $this->actingAs($user)->get("/employees/{$own->id}")->assertRedirect('/my/profile');
    $this->actingAs($user)->get("/employees/{$other->id}")->assertForbidden();
});

it('refuses an employee without a record and gives an employee-role user no body to read', function () {
    $user = userWithRole('employee');
    $other = eprRecord();

    $response = $this->actingAs($user)->get("/employees/{$other->id}");

    $response->assertForbidden();
    expect($response->getContent())->not->toContain(EPR_CNIC)->not->toContain(EPR_ACCOUNT)->not->toContain(EPR_ADDRESS);
});

// ---------------------------------------------------------------- what is in each group

it('leaves identity out of the props when it is not allowed', function () {
    [$managerUser, , $report] = eprTeam();

    $props = eprProps($this->actingAs($managerUser)->get("/employees/{$report->id}")->assertOk());

    expect($props['employee'])->not->toHaveKey('identity');
});

it('puts the employment facts in the employment group', function () {
    $admin = userWithRole('admin');
    $boss = eprRecord(['first_name' => 'Big', 'last_name' => 'Boss', 'cnic' => '4220100000009', 'device_user_id' => null]);
    $e = eprRecord(['manager_id' => $boss->id, 'status' => 'resigned', 'last_working_day' => '2025-01-31', 'employment_type' => 'contract']);
    $e->user()->save(userWithRole('manager'));

    $employment = eprProps($this->actingAs($admin)->get("/employees/{$e->id}")->assertOk())['employee']['employment'];

    expect($employment)->toMatchArray([
        'department' => 'Engineering',
        'designation' => 'Engineer',
        'status' => 'resigned',
        'joining_date' => '2024-03-01',
        'probation_end_date' => '2024-06-01',
        'employment_type' => 'contract',
        'last_working_day' => '2025-01-31',
        'device_user_id' => EPR_DEVICE,
        'role' => 'manager',
        'login' => 'active',
    ])->and($employment['manager'])->toBe(['id' => $boss->id, 'name' => 'Big Boss']);
});

it('shows role, login and machine user ID to Admin and HR only', function () {
    [$managerUser, $manager, $report] = eprTeam();
    $hr = userWithRole('hr');
    $report->user()->save(userWithRole('employee'));

    $asHr = eprProps($this->actingAs($hr)->get("/employees/{$report->id}")->assertOk())['employee']['employment'];
    $asManager = eprProps($this->actingAs($managerUser)->get("/employees/{$report->id}")->assertOk())['employee']['employment'];
    $ownAsManager = eprProps($this->actingAs($managerUser)->get("/employees/{$manager->id}")->assertOk())['employee']['employment'];

    expect($asHr)->toHaveKeys(['device_user_id', 'role', 'login'])
        ->and($asManager)->not->toHaveKeys(['device_user_id', 'role', 'login'])
        ->and($ownAsManager)->not->toHaveKeys(['device_user_id', 'role', 'login']);
});

it('shows "no account" as the login state when there is no login', function () {
    $e = eprRecord();

    $employment = eprProps($this->actingAs(userWithRole('admin'))->get("/employees/{$e->id}")->assertOk())['employee']['employment'];

    expect($employment['login'])->toBe('none')->and($employment['role'])->toBeNull();
});

it('links the reporting manager only when the viewer may view that manager', function () {
    $outsideBossUser = userWithRole('manager');
    $outsideBoss = employeeFor($outsideBossUser, ['first_name' => 'Outside', 'last_name' => 'Boss']);
    $managerUser = userWithRole('manager');
    $manager = employeeFor($managerUser, ['manager_id' => $outsideBoss->id]);
    $report = eprRecord(['manager_id' => $manager->id]);

    $viewer = fn (User $user, Employee $target) => eprProps($this->actingAs($user)->get("/employees/{$target->id}")->assertOk())['employee']['employment']['manager'];

    // The manager's own manager is a stranger to them: the name only, no id to link to.
    expect($viewer($managerUser, $manager))->toBe(['id' => null, 'name' => 'Outside Boss'])
        // A report's manager is the viewer themselves: linked.
        ->and($viewer($managerUser, $report))->toBe(['id' => $manager->id, 'name' => $manager->full_name])
        // HR may view everyone.
        ->and($viewer(userWithRole('hr'), $manager))->toBe(['id' => $outsideBoss->id, 'name' => 'Outside Boss']);
});

it('has no manager entry when the person reports to nobody', function () {
    $e = eprRecord();

    expect(eprProps($this->actingAs(userWithRole('admin'))->get("/employees/{$e->id}")->assertOk())['employee']['employment']['manager'])->toBeNull();
});

it('lists direct reports with id, name, code and designation, limited to people the viewer may view', function () {
    [$managerUser, $manager, $report, $indirect] = eprTeam();
    $second = eprRecord(['manager_id' => $manager->id, 'first_name' => 'Zed', 'last_name' => 'Second']);

    $row = fn (Employee $e) => ['id' => $e->id, 'name' => $e->full_name, 'code' => $e->employee_code, 'designation' => $e->designation->name];

    $own = eprProps($this->actingAs($managerUser)->get("/employees/{$manager->id}")->assertOk())['employee']['employment']['reports'];
    // The manager looks at their report, whose own report (the indirect one) is outside the manager's view.
    $report_ = eprProps($this->actingAs($managerUser)->get("/employees/{$report->id}")->assertOk())['employee']['employment']['reports'];
    $asHr = eprProps($this->actingAs(userWithRole('hr'))->get("/employees/{$report->id}")->assertOk())['employee']['employment']['reports'];

    expect($own)->toBe([$row($report), $row($second)])
        ->and($report_)->toBe([])
        ->and($asHr)->toBe([$row($indirect)]);
});

it('shows the documents group as an empty list when there are none, and never an upload control prop', function () {
    $e = Employee::factory()->create();

    $props = eprProps($this->actingAs(userWithRole('admin'))->get("/employees/{$e->id}")->assertOk());

    expect($props['employee']['documents'])->toBe(['items' => []])
        ->and($props['employee']['contact'])->toBe(['address' => null, 'emergency_name' => null, 'emergency_phone' => null])
        ->and($props['employee']['banking'])->toBe(['bank_name' => null, 'account_title' => null, 'account_number' => null]);
});

// ---------------------------------------------------------------- nothing restricted leaks

it('never lets a restricted key or value reach a manager looking at a report, in props, body or title', function () {
    [$managerUser, , $report] = eprTeam();

    $response = $this->actingAs($managerUser)->get("/employees/{$report->id}")->assertOk();
    $props = eprProps($response);
    $json = json_encode($props);
    $body = $response->getContent();

    $restrictedValues = [
        EPR_CNIC, EPR_CNIC_FORMATTED, EPR_DOB, EPR_ADDRESS, EPR_EMERGENCY_NAME, EPR_EMERGENCY_PHONE,
        EPR_BANK, EPR_ACCOUNT_TITLE, EPR_ACCOUNT, EPR_DEVICE, EPR_DOC_TITLE, $report->cnic,
    ];

    foreach ($restrictedValues as $value) {
        expect($json)->not->toContain($value)->and($body)->not->toContain($value);
    }

    // The unrelated people around the report are also restricted: strangers' data is never in the page.
    foreach (Employee::where('id', '!=', $report->id)->get() as $other) {
        expect($json)->not->toContain($other->cnic);
    }

    $keys = collect(eprKeys($props['employee']))->map(fn ($k) => strtolower($k))->all();
    foreach (['cnic', 'dob', 'address', 'emergency', 'bank', 'account', 'identity', 'contact', 'banking', 'documents', 'device', 'salary', 'user', 'roles'] as $word) {
        expect(collect($keys)->contains(fn ($k) => str_contains($k, $word)))->toBeFalse("a key containing [{$word}] reached a manager");
    }

    expect($props['tabs'])->not->toContain('contact')->not->toContain('banking')->not->toContain('documents');
});

it('keeps relations and the model itself out of the props for every role', function (string $role) {
    $viewer = userWithRole($role);
    $own = employeeFor($viewer);
    $target = $role === 'manager' ? eprRecord(['manager_id' => $own->id]) : eprRecord();

    $props = eprProps($this->actingAs($viewer)->get("/employees/{$target->id}")->assertOk());

    $keys = eprKeys($props['employee']);
    foreach (['user', 'roles', 'password', 'remember_token', 'created_at', 'updated_at', 'photo_path', 'department_id', 'designation_id', 'manager_id'] as $word) {
        expect(collect($keys)->contains(fn ($k) => str_ends_with($k, ".{$word}") || $k === $word))->toBeFalse("[{$word}] is in the props");
    }
    // The page-level props carry only the three the page needs.
    expect(array_intersect(['employee', 'tabs', 'can'], array_keys($props)))->toBe(['employee', 'tabs', 'can'])
        ->and(array_keys($props))->not->toContain('manager')
        ->and(array_keys($props))->not->toContain('reports');
})->with(['admin', 'hr', 'manager']);

it('puts no restricted data in the shared props or the page title for a manager', function () {
    [$managerUser, , $report] = eprTeam();

    $props = eprProps($this->actingAs($managerUser)->get("/employees/{$report->id}")->assertOk());
    $shared = json_encode(array_diff_key($props, array_flip(['employee', 'tabs', 'can'])));

    expect($shared)->not->toContain(EPR_CNIC)->not->toContain(EPR_ADDRESS)->not->toContain($report->email);
});

// ---------------------------------------------------------------- redirects after a save

it('redirects a create to the new profile', function () {
    $dept = Department::factory()->create();
    $desig = Designation::factory()->create(['department_id' => $dept->id]);

    $response = $this->actingAs(userWithRole('hr'))->post('/employees', [
        'first_name' => 'New', 'last_name' => 'Person', 'email' => 'new.person@corp.test',
        'department_id' => $dept->id, 'designation_id' => $desig->id, 'joining_date' => '2024-03-01',
        'employment_type' => 'full_time', 'status' => 'active', 'role' => 'employee',
    ]);

    $created = Employee::where('email', 'new.person@corp.test')->firstOrFail();
    $response->assertRedirect("/employees/{$created->id}");
});

it('redirects an update to the profile', function () {
    $e = eprRecord();

    $this->actingAs(userWithRole('hr'))->put("/employees/{$e->id}", [
        'employee_code' => $e->employee_code, 'first_name' => 'Renamed', 'last_name' => $e->last_name, 'email' => $e->email,
        'department_id' => $e->department_id, 'designation_id' => $e->designation_id,
        'joining_date' => '2024-03-01', 'employment_type' => 'full_time', 'role' => 'employee',
    ])->assertRedirect("/employees/{$e->id}");
});

// ---------------------------------------------------------------- Task 3 review items

it('treats a user who holds both employee and admin as privileged', function () {
    $hr = userWithRole('hr');
    $account = userWithRole('employee');
    $account->assignRole('admin');
    $account = $account->fresh();

    expect(EmployeeRoles::mayTouchAccount($hr, $account))->toBeFalse()
        ->and(EmployeeRoles::mayTouchAccount(userWithRole('admin'), $account))->toBeTrue()
        ->and(EmployeeRoles::mayTouchAccount($hr, userWithRole('employee')))->toBeTrue()
        ->and(EmployeeRoles::currentRole($account))->toBe('admin');
});

it('also refuses HR on a two-role account through the status route and shows no edit rights on the profile', function () {
    $hr = userWithRole('hr');
    $account = userWithRole('employee');
    $account->assignRole('admin');
    $e = eprRecord([], $account);

    $props = eprProps($this->actingAs($hr)->get("/employees/{$e->id}")->assertOk());
    $this->actingAs($hr)->patch("/employees/{$e->id}/status", ['status' => 'inactive'])
        ->assertSessionHas('error', EmployeeRoles::PRIVILEGED_MESSAGE);

    expect($props['can']['edit'])->toBeFalse()->and($props['can']['changeStatus'])->toBeFalse()
        ->and($e->fresh()->status)->toBe('active')->and($account->fresh()->is_active)->toBeTrue();
});

it('says "No change." and writes nothing when the status is re-posted', function () {
    $e = eprRecord(['status' => 'probation']);
    $before = Activity::where('log_name', 'employees')->count();

    $response = $this->actingAs(userWithRole('hr'))->patch("/employees/{$e->id}/status", ['status' => 'probation']);

    $response->assertSessionHas('info', 'No change.')->assertSessionMissing('success');
    expect($e->fresh()->status)->toBe('probation')->and(Activity::where('log_name', 'employees')->count())->toBe($before);
});

it('still says the status changed on a real change', function () {
    $e = eprRecord();

    $this->actingAs(userWithRole('hr'))->patch("/employees/{$e->id}/status", ['status' => 'probation'])
        ->assertSessionHas('success', 'Status changed to Probation.')->assertSessionMissing('info');
});

it('shares the info flash message with the page', function () {
    $e = eprRecord(['status' => 'probation']);
    $hr = userWithRole('hr');

    $this->actingAs($hr)->patch("/employees/{$e->id}/status", ['status' => 'probation']);
    $props = eprProps($this->actingAs($hr)->get("/employees/{$e->id}"));

    expect($props['flash'])->toHaveKey('info');
});
