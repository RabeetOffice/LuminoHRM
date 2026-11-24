<?php

use App\Actions\CreateUserAccount;
use App\Actions\Employees\CreateEmployee;
use App\Models\Department;
use App\Models\Designation;
use App\Models\Employee;
use App\Models\EmployeeBankAccount;
use App\Models\EmployeeContact;
use App\Models\User;
use App\Notifications\SetPasswordNotification;
use App\Support\EmployeeRoles;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Spatie\Activitylog\Models\Activity;

/** One department with one designation, created on first use within a test (the database is fresh per test). */
function ewDeps(): array
{
    $dept = Department::where('name', 'Engineering')->first() ?? Department::factory()->create(['name' => 'Engineering']);
    $desig = Designation::where('department_id', $dept->id)->where('name', 'Engineer')->first()
        ?? Designation::factory()->create(['department_id' => $dept->id, 'name' => 'Engineer']);

    return [$dept, $desig];
}

/** A complete valid payload for POST /employees, merged with $overrides. */
function ewPayload(array $overrides = []): array
{
    [$dept, $desig] = ewDeps();

    return array_merge([
        'first_name' => 'Ayesha',
        'last_name' => 'Khan',
        'email' => 'ayesha.khan@corp.test',
        'phone' => '0300-1234567',
        'dob' => '1990-05-17',
        'gender' => 'female',
        'cnic' => '42201-1234567-1',
        'department_id' => $dept->id,
        'designation_id' => $desig->id,
        'manager_id' => null,
        'joining_date' => '2024-03-01',
        'probation_end_date' => '2024-06-01',
        'employment_type' => 'full_time',
        'status' => 'probation',
        'device_user_id' => '77',
        'role' => 'employee',
        'address' => '12 Garden Road, Karachi',
        'emergency_name' => 'Sara Khan',
        'emergency_phone' => '0300-7654321',
        'bank_name' => 'Meezan Bank',
        'account_title' => 'Ayesha Khan',
        'account_number' => 'PK36MEZN0000001234567890',
    ], $overrides);
}

/** An existing employee with the given user role and a linked login, for edit/status/delete tests. */
function ewExisting(string $role = 'employee', array $attrs = []): array
{
    [$dept, $desig] = ewDeps();
    $user = userWithRole($role);
    $employee = employeeFor($user, array_merge(['department_id' => $dept->id, 'designation_id' => $desig->id, 'joining_date' => '2024-03-01'], $attrs));

    return [$employee, $user];
}

/** The payload an edit form would post for $employee (all fields as stored), merged with $overrides. */
function ewEditPayload(Employee $employee, array $overrides = []): array
{
    $employee->loadMissing('contact', 'bankAccount', 'user.roles');
    $date = fn ($d) => $d?->format('Y-m-d');

    return array_merge([
        'employee_code' => $employee->employee_code, 'first_name' => $employee->first_name, 'last_name' => $employee->last_name, 'email' => $employee->email,
        'phone' => $employee->phone, 'dob' => $date($employee->dob), 'gender' => $employee->gender, 'cnic' => $employee->cnic,
        'department_id' => $employee->department_id, 'designation_id' => $employee->designation_id, 'manager_id' => $employee->manager_id,
        'joining_date' => $date($employee->joining_date), 'probation_end_date' => $date($employee->probation_end_date),
        'employment_type' => $employee->employment_type, 'status' => $employee->status, 'device_user_id' => $employee->device_user_id,
        'role' => $employee->user?->roles->first()?->name ?? 'employee',
        'address' => $employee->contact?->address, 'emergency_name' => $employee->contact?->emergency_name, 'emergency_phone' => $employee->contact?->emergency_phone,
        'bank_name' => $employee->bankAccount?->bank_name, 'account_title' => $employee->bankAccount?->account_title, 'account_number' => $employee->bankAccount?->account_number,
    ], $overrides);
}

beforeEach(function () {
    Notification::fake();
});

// ---------------------------------------------------------------- access

it('sends guests to the sign-in page for every write route', function () {
    $e = Employee::factory()->create();

    $this->get('/employees/create')->assertRedirect('/login');
    $this->post('/employees', [])->assertRedirect('/login');
    $this->get("/employees/{$e->id}/edit")->assertRedirect('/login');
    $this->put("/employees/{$e->id}", [])->assertRedirect('/login');
    $this->patch("/employees/{$e->id}/status", [])->assertRedirect('/login');
    $this->delete("/employees/{$e->id}")->assertRedirect('/login');
    $this->get('/employees/manager-options')->assertRedirect('/login');
});

it('gives managers and employees 403 on every write route', function (string $role) {
    $user = userWithRole($role);
    employeeFor($user);
    $e = Employee::factory()->create();
    $before = Employee::count();

    $this->actingAs($user)->get('/employees/create')->assertForbidden();
    $this->actingAs($user)->post('/employees', ewPayload())->assertForbidden();
    $this->actingAs($user)->get("/employees/{$e->id}/edit")->assertForbidden();
    $this->actingAs($user)->put("/employees/{$e->id}", ewEditPayload($e))->assertForbidden();
    $this->actingAs($user)->patch("/employees/{$e->id}/status", ['status' => 'inactive'])->assertForbidden();
    $this->actingAs($user)->delete("/employees/{$e->id}")->assertForbidden();
    $this->actingAs($user)->get('/employees/manager-options')->assertForbidden();

    expect(Employee::count())->toBe($before)->and($e->fresh()->status)->toBe('active');
})->with(['manager', 'employee']);

it('lets HR open the forms but not delete; admin may delete', function () {
    $hr = userWithRole('hr');
    $e = Employee::factory()->create();

    $this->actingAs($hr)->get('/employees/create')->assertOk();
    $this->actingAs($hr)->get("/employees/{$e->id}/edit")->assertOk();
    $this->actingAs($hr)->delete("/employees/{$e->id}")->assertForbidden();
    expect(Employee::find($e->id))->not->toBeNull();

    $this->actingAs(userWithRole('admin'))->delete("/employees/{$e->id}")->assertRedirect('/employees');
    expect(Employee::find($e->id))->toBeNull();
});

it('returns 404 for an unknown or non-numeric id and keeps static routes ahead of the wildcard', function () {
    $admin = userWithRole('admin');

    $this->actingAs($admin)->get('/employees/99999/edit')->assertNotFound();
    $this->actingAs($admin)->get('/employees/abc/edit')->assertNotFound();
    $this->actingAs($admin)->put('/employees/99999', ewPayload())->assertNotFound();
    $this->actingAs($admin)->get('/employees/create')->assertOk();
    $this->actingAs($admin)->get('/employees/manager-options')->assertOk();
});

// ---------------------------------------------------------------- create form page

it('renders the create form with options and role choices per viewer', function () {
    [$dept, $desig] = ewDeps();

    $admin = test()->actingAs(userWithRole('admin'))->get('/employees/create')->assertOk()->viewData('page')['props'];
    expect($admin['employee'])->toBeNull()
        ->and(array_column($admin['roleOptions'], 'value'))->toBe(['employee', 'manager', 'hr', 'admin'])
        ->and($admin['can']['editCode'])->toBeTrue()
        ->and($admin['departments'][0]['id'])->toBe($dept->id)
        ->and($admin['designations'][0])->toMatchArray(['id' => $desig->id, 'department_id' => $dept->id])
        ->and($admin['statusOptions'])->toBe(['active', 'probation']);

    $hr = test()->actingAs(userWithRole('hr'))->get('/employees/create')->assertOk()->viewData('page')['props'];
    expect(array_column($hr['roleOptions'], 'value'))->toBe(['employee', 'manager'])
        ->and($hr['can']['editCode'])->toBeFalse();
});

it('renders the edit form with the full record for HR', function () {
    [$employee] = ewExisting('employee', ['dob' => '1992-02-02', 'phone' => '0300']);
    EmployeeContact::factory()->create(['employee_id' => $employee->id, 'address' => '1 Road', 'emergency_name' => 'Zed', 'emergency_phone' => '1']);
    EmployeeBankAccount::factory()->create(['employee_id' => $employee->id, 'bank_name' => 'HBL', 'account_title' => 'T', 'account_number' => '123']);

    $props = test()->actingAs(userWithRole('hr'))->get("/employees/{$employee->id}/edit")->assertOk()->viewData('page')['props'];

    expect($props['employee'])->toMatchArray([
        'id' => $employee->id, 'code' => $employee->employee_code, 'email' => $employee->email, 'dob' => '1992-02-02',
        'address' => '1 Road', 'emergency_name' => 'Zed', 'bank_name' => 'HBL', 'account_number' => '123', 'role' => 'employee', 'has_login' => true,
    ])->and($props['roleLocked'])->toBeFalse();
});

it('locks the role field for HR when the person is an admin or HR, and for your own record', function () {
    [$adminEmployee] = ewExisting('admin');
    [$hrEmployee] = ewExisting('hr');
    $hr = userWithRole('hr');
    $hrOwn = employeeFor($hr);

    foreach ([$adminEmployee, $hrEmployee, $hrOwn] as $target) {
        expect(test()->actingAs($hr)->get("/employees/{$target->id}/edit")->viewData('page')['props']['roleLocked'])->toBeTrue();
    }

    $admin = userWithRole('admin');
    expect(test()->actingAs($admin)->get("/employees/{$hrEmployee->id}/edit")->viewData('page')['props']['roleLocked'])->toBeFalse();

    $adminOwn = employeeFor($admin);
    expect(test()->actingAs($admin)->get("/employees/{$adminOwn->id}/edit")->viewData('page')['props']['roleLocked'])->toBeTrue();
});

// ---------------------------------------------------------------- create

it('creates the employee, satellites and a login account in one go', function () {
    $hr = userWithRole('hr');

    $response = $this->actingAs($hr)->post('/employees', ewPayload());

    $employee = Employee::where('email', 'ayesha.khan@corp.test')->firstOrFail();
    $response->assertRedirect("/employees/{$employee->id}/edit")->assertSessionHas('success');

    expect($employee->employee_code)->toBe('LD-0001')
        ->and($employee->first_name)->toBe('Ayesha')
        ->and($employee->cnic)->toBe('4220112345671')
        ->and($employee->status)->toBe('probation')
        ->and($employee->dob->format('Y-m-d'))->toBe('1990-05-17')
        ->and($employee->contact->address)->toBe('12 Garden Road, Karachi')
        ->and($employee->contact->emergency_name)->toBe('Sara Khan')
        ->and($employee->bankAccount->account_number)->toBe('PK36MEZN0000001234567890');

    $user = $employee->user;
    expect($user)->not->toBeNull()
        ->and($user->email)->toBe('ayesha.khan@corp.test')
        ->and($user->name)->toBe('Ayesha Khan')
        ->and($user->hasRole('employee'))->toBeTrue()
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($user->is_active)->toBeTrue();

    Notification::assertSentTo($user, SetPasswordNotification::class);
});

it('normalises names and the email, and accepts a CNIC with or without dashes', function () {
    $this->actingAs(userWithRole('hr'))->post('/employees', ewPayload([
        'first_name' => "  Ayesha\t ", 'last_name' => '  Bint   Khan ', 'email' => '  Ayesha.Khan@Corp.TEST ', 'cnic' => '4220112345671',
    ]))->assertSessionHasNoErrors();

    $e = Employee::firstOrFail();
    expect($e->first_name)->toBe('Ayesha')->and($e->last_name)->toBe('Bint Khan')
        ->and($e->email)->toBe('ayesha.khan@corp.test')->and($e->cnic)->toBe('4220112345671')
        ->and($e->user->name)->toBe('Ayesha Bint Khan');
});

it('treats empty optional fields as null and creates no empty satellite rows', function () {
    $this->actingAs(userWithRole('hr'))->post('/employees', ewPayload([
        'phone' => '', 'dob' => '', 'gender' => '', 'cnic' => '', 'probation_end_date' => '', 'device_user_id' => '',
        'address' => '', 'emergency_name' => '', 'emergency_phone' => '', 'bank_name' => '', 'account_title' => '', 'account_number' => '',
    ]))->assertSessionHasNoErrors();

    $e = Employee::firstOrFail();
    expect($e->phone)->toBeNull()->and($e->dob)->toBeNull()->and($e->cnic)->toBeNull()->and($e->device_user_id)->toBeNull()
        ->and(EmployeeContact::count())->toBe(0)->and(EmployeeBankAccount::count())->toBe(0);
});

it('hands out the next code and keeps it', function () {
    Employee::factory()->create(['employee_code' => 'LD-0041']);
    $hr = userWithRole('hr');

    $this->actingAs($hr)->post('/employees', ewPayload())->assertSessionHasNoErrors();
    expect(Employee::where('email', 'ayesha.khan@corp.test')->value('employee_code'))->toBe('LD-0042');
});

it('keeps the code stable on update', function () {
    $hr = userWithRole('hr');
    $this->actingAs($hr)->post('/employees', ewPayload());
    $e = Employee::where('email', 'ayesha.khan@corp.test')->firstOrFail();

    $this->actingAs($hr)->put("/employees/{$e->id}", ewEditPayload($e, ['first_name' => 'Aisha']))->assertSessionHasNoErrors();

    expect($e->fresh()->employee_code)->toBe($e->employee_code)->and($e->fresh()->first_name)->toBe('Aisha');
});

it('lets an admin choose the code but ignores a code posted by HR', function () {
    $this->actingAs(userWithRole('admin'))->post('/employees', ewPayload(['employee_code' => 'LD-0500']))->assertSessionHasNoErrors();
    expect(Employee::where('email', 'ayesha.khan@corp.test')->value('employee_code'))->toBe('LD-0500');

    $this->actingAs(userWithRole('hr'))->post('/employees', ewPayload(['email' => 'b@corp.test', 'cnic' => '', 'device_user_id' => '', 'employee_code' => 'LD-0900']))->assertSessionHasNoErrors();
    expect(Employee::where('email', 'b@corp.test')->value('employee_code'))->toBe('LD-0501');
});

it('validates the code an admin types: format and uniqueness', function () {
    $admin = userWithRole('admin');
    Employee::factory()->create(['employee_code' => 'LD-0007']);

    foreach (['LD-12', 'ld-0100', 'XX-0100', 'LD-01A0', 'LD-0007'] as $bad) {
        $this->actingAs($admin)->post('/employees', ewPayload(['employee_code' => $bad]))->assertSessionHasErrors('employee_code');
    }

    expect(Employee::count())->toBe(1);
});

it('lets an admin change the code on edit but not HR', function () {
    [$employee] = ewExisting('employee', ['employee_code' => 'LD-0010']);

    $this->actingAs(userWithRole('hr'))->put("/employees/{$employee->id}", ewEditPayload($employee, ['employee_code' => 'LD-0099']))->assertSessionHasNoErrors();
    expect($employee->fresh()->employee_code)->toBe('LD-0010');

    $this->actingAs(userWithRole('admin'))->put("/employees/{$employee->id}", ewEditPayload($employee, ['employee_code' => 'LD-0099']))->assertSessionHasNoErrors();
    expect($employee->fresh()->employee_code)->toBe('LD-0099');
});

it('retries once when the code was taken in between, and reports other duplicates as field errors', function () {
    $hr = userWithRole('hr');
    $attempts = 0;
    Employee::creating(function (Employee $e) use (&$attempts) {
        $attempts++;
        if ($attempts === 1) {
            Employee::withoutEvents(fn () => Employee::factory()->create(['employee_code' => $e->employee_code]));
        }
    });

    $this->actingAs($hr)->post('/employees', ewPayload())->assertSessionHasNoErrors();
    expect($attempts)->toBe(2)->and(Employee::where('email', 'ayesha.khan@corp.test')->exists())->toBeTrue();
});

it('turns a race on another unique column into an error on that field and saves nothing', function () {
    $hr = userWithRole('hr');
    Employee::creating(function (Employee $e) {
        Employee::withoutEvents(fn () => Employee::factory()->create(['email' => $e->email]));
    });

    $this->actingAs($hr)->post('/employees', ewPayload())->assertSessionHasErrors('email');
    expect(Employee::where('email', 'ayesha.khan@corp.test')->count())->toBe(0)
        ->and(User::where('email', 'ayesha.khan@corp.test')->exists())->toBeFalse();
});

it('rolls everything back when creating the account fails', function () {
    $hr = userWithRole('hr');
    $this->app->bind(CreateUserAccount::class, fn () => new class extends CreateUserAccount
    {
        public function create(string $name, string $email, string $role, ?User $actor = null): User
        {
            throw new RuntimeException('boom');
        }
    });
    $this->withoutExceptionHandling();

    expect(fn () => $this->actingAs($hr)->post('/employees', ewPayload()))->toThrow(RuntimeException::class);
    expect(Employee::count())->toBe(0)->and(EmployeeContact::count())->toBe(0)
        ->and(Activity::where('log_name', 'employees')->count())->toBe(0);
});

it('writes one audit entry for a create, with masked identity fields and the creator', function () {
    $hr = userWithRole('hr');

    $this->actingAs($hr)->post('/employees', ewPayload());

    $e = Employee::firstOrFail();
    $entries = Activity::where('log_name', 'employees')->where('subject_id', $e->id)->where('subject_type', $e->getMorphClass())->get();
    expect($entries)->toHaveCount(1);
    $entry = $entries->first();
    expect($entry->description)->toBe('Employee created')
        ->and($entry->causer_id)->toBe($hr->id)
        ->and($entry->properties['attributes']['first_name'])->toBe('Ayesha')
        ->and($entry->properties['attributes']['cnic'])->toBe('[changed]')
        ->and($entry->properties['attributes']['dob'])->toBe('[changed]')
        ->and($entry->properties['attributes']['account_number'])->toBe('[changed]');
    expect(json_encode($entry->properties))->not->toContain('4220112345671')->not->toContain('1990-05-17')->not->toContain('PK36MEZN');
});

// ---------------------------------------------------------------- the login account

it('links an existing account that has no employee, keeping its role and sending no link', function () {
    $hr = userWithRole('hr');
    $existing = User::factory()->create(['email' => 'ayesha.khan@corp.test', 'name' => 'Old Name']);
    $existing->assignRole('manager');
    $users = User::count();

    $this->actingAs($hr)->post('/employees', ewPayload(['role' => 'employee']))->assertSessionHasNoErrors()->assertSessionHas('success');

    $e = Employee::firstOrFail();
    expect(User::count())->toBe($users)
        ->and($existing->fresh()->employee_id)->toBe($e->id)
        ->and($existing->fresh()->hasRole('manager'))->toBeTrue()
        ->and($existing->fresh()->name)->toBe('Ayesha Khan');
    Notification::assertNothingSent();
});

it('lets an Admin link the first admin account (no employee record), keeping its role when the default is posted', function () {
    $first = userWithRole('admin');
    $first->forceFill(['email' => 'boss@corp.test'])->save();
    $actor = userWithRole('admin');

    $this->actingAs($actor)->post('/employees', ewPayload(['email' => 'boss@corp.test', 'role' => 'employee']))->assertSessionHasNoErrors();

    expect($first->fresh()->employee_id)->toBe(Employee::where('email', 'boss@corp.test')->value('id'))
        ->and($first->fresh()->hasRole('admin'))->toBeTrue();
});

it('changes the role of a linked account only when the actor is allowed to', function () {
    $existing = User::factory()->create(['email' => 'ayesha.khan@corp.test']);
    $existing->assignRole('employee');

    $this->actingAs(userWithRole('admin'))->post('/employees', ewPayload(['role' => 'hr']))->assertSessionHasNoErrors();
    expect($existing->fresh()->hasRole('hr'))->toBeTrue();
});

it('refuses HR changing the role of an existing admin account while linking, saving nothing', function () {
    $existing = User::factory()->create(['email' => 'ayesha.khan@corp.test']);
    $existing->assignRole('admin');

    $this->actingAs(userWithRole('hr'))->post('/employees', ewPayload(['role' => 'manager']))->assertForbidden();

    expect(Employee::count())->toBe(0)->and($existing->fresh()->hasRole('admin'))->toBeTrue()->and($existing->fresh()->employee_id)->toBeNull();
});

it('refuses an email whose account already belongs to another employee', function () {
    $other = Employee::factory()->create(['email' => 'other@corp.test']);
    $user = User::factory()->create(['email' => 'ayesha.khan@corp.test']);
    $user->forceFill(['employee_id' => $other->id])->save();

    $this->actingAs(userWithRole('hr'))->post('/employees', ewPayload())->assertSessionHasErrors('email');

    expect(Employee::where('email', 'ayesha.khan@corp.test')->exists())->toBeFalse()->and($user->fresh()->employee_id)->toBe($other->id);
});

it('keeps the employee and flashes the Phase 1 message when the link cannot be sent', function () {
    Password::shouldReceive('broker')->andThrow(new RuntimeException('mail down: secret-detail'));
    Log::spy();

    $response = $this->actingAs(userWithRole('hr'))->post('/employees', ewPayload());

    $e = Employee::where('email', 'ayesha.khan@corp.test')->firstOrFail();
    $response->assertRedirect("/employees/{$e->id}/edit")
        ->assertSessionHas('error', 'Account created, but the link could not be sent. Use the reset icon.');
    expect($e->user)->not->toBeNull();
});

it('creates the account without sending a link when told not to (used by the import)', function () {
    $admin = userWithRole('admin');
    $action = app(CreateEmployee::class);

    $employee = $action->handle(ewPayload(['role' => 'employee']), $admin, sendLink: false);

    expect($employee->user)->not->toBeNull()->and($action->linkSent())->toBeFalse()->and($action->linkedExistingUser())->toBeFalse();
    Notification::assertNothingSent();
});

it('reports that a link was sent and that an account was linked', function () {
    $admin = userWithRole('admin');
    $action = app(CreateEmployee::class);
    $action->handle(ewPayload(), $admin);
    expect($action->linkSent())->toBeTrue()->and($action->linkedExistingUser())->toBeFalse();

    User::factory()->create(['email' => 'second@corp.test'])->assignRole('employee');
    $linking = app(CreateEmployee::class);
    $linking->handle(ewPayload(['email' => 'second@corp.test', 'cnic' => '', 'device_user_id' => '']), $admin);
    expect($linking->linkedExistingUser())->toBeTrue()->and($linking->linkSent())->toBeFalse();
});

// ---------------------------------------------------------------- roles

it('lets an admin pick any role and HR only employee or manager', function (string $actor, string $role, bool $allowed) {
    $this->actingAs(userWithRole($actor))->post('/employees', ewPayload(['role' => $role]));

    $e = Employee::first();
    if ($allowed) {
        expect($e?->user->hasRole($role))->toBeTrue();
    } else {
        expect($e)->toBeNull();
    }
})->with([
    ['admin', 'admin', true], ['admin', 'hr', true], ['admin', 'manager', true], ['admin', 'employee', true],
    ['hr', 'employee', true], ['hr', 'manager', true], ['hr', 'hr', false], ['hr', 'admin', false],
]);

it('answers 403, not a silent downgrade, when HR posts admin or hr', function () {
    $this->actingAs(userWithRole('hr'))->post('/employees', ewPayload(['role' => 'admin']))->assertForbidden();
});

it('defaults the role to employee when none is posted', function () {
    $payload = ewPayload();
    unset($payload['role']);

    $this->actingAs(userWithRole('hr'))->post('/employees', $payload)->assertSessionHasNoErrors();
    expect(Employee::firstOrFail()->user->hasRole('employee'))->toBeTrue();
});

it('rejects an unknown role as a validation error', function () {
    $this->actingAs(userWithRole('admin'))->post('/employees', ewPayload(['role' => 'superuser']))->assertSessionHasErrors('role');
});

it('refuses HR changing the role of an admin or HR user on edit, with 403', function (string $current) {
    [$employee, $user] = ewExisting($current);

    $this->actingAs(userWithRole('hr'))->put("/employees/{$employee->id}", ewEditPayload($employee, ['role' => 'employee', 'first_name' => 'Changed']))->assertForbidden();

    expect($user->fresh()->hasRole($current))->toBeTrue()->and($employee->fresh()->first_name)->not->toBe('Changed');
})->with(['admin', 'hr']);

it('refuses HR any edit of an admin or HR person, even with the unchanged role, and stores nothing', function (string $current) {
    [$employee, $user] = ewExisting($current);

    $this->actingAs(userWithRole('hr'))->put("/employees/{$employee->id}", ewEditPayload($employee, ['role' => $current, 'first_name' => 'Changed']))
        ->assertSessionHasErrors(['email' => EmployeeRoles::PRIVILEGED_MESSAGE]);

    expect($employee->fresh()->first_name)->toBe($employee->first_name)->and($user->fresh()->name)->toBe($user->name);
})->with(['admin', 'hr']);

it('lets HR change employee to manager and an admin change any role on edit', function () {
    [$employee, $user] = ewExisting('employee');

    $this->actingAs(userWithRole('hr'))->put("/employees/{$employee->id}", ewEditPayload($employee, ['role' => 'manager']))->assertSessionHasNoErrors();
    expect($user->fresh()->hasRole('manager'))->toBeTrue();

    $this->actingAs(userWithRole('admin'))->put("/employees/{$employee->id}", ewEditPayload($employee, ['role' => 'hr']))->assertSessionHasNoErrors();
    expect($user->fresh()->hasRole('hr'))->toBeTrue();
});

it('never lets you change your own role through the form, and saves nothing', function () {
    $admin = userWithRole('admin');
    userWithRole('admin'); // a second Admin, so the last-admin rule is not what answers
    $own = employeeFor($admin, ['joining_date' => '2024-03-01']);

    $this->actingAs($admin)->put("/employees/{$own->id}", ewEditPayload($own, ['role' => 'employee', 'first_name' => 'Changed']))
        ->assertSessionHas('error', 'You cannot change your own role or disable your own account.');

    expect($admin->fresh()->hasRole('admin'))->toBeTrue()->and($own->fresh()->first_name)->not->toBe('Changed');
});

// ---------------------------------------------------------------- validation

it('validates the rules', function (array $override, string $field) {
    $this->actingAs(userWithRole('hr'))->post('/employees', ewPayload($override))->assertSessionHasErrors($field);
    expect(Employee::count())->toBe(0);
})->with([
    'first name required' => [['first_name' => ''], 'first_name'],
    'first name too long' => [['first_name' => str_repeat('a', 101)], 'first_name'],
    'last name required' => [['last_name' => ' '], 'last_name'],
    'last name too long' => [['last_name' => str_repeat('a', 101)], 'last_name'],
    'email required' => [['email' => ''], 'email'],
    'email malformed' => [['email' => 'not-an-email'], 'email'],
    'cnic 12 digits' => [['cnic' => '42201-1234567'], 'cnic'],
    'cnic letters' => [['cnic' => '4220112345abc'], 'cnic'],
    'cnic 14 digits' => [['cnic' => '42201123456712'], 'cnic'],
    'phone too long' => [['phone' => str_repeat('1', 31)], 'phone'],
    'dob in the future' => [['dob' => '2999-01-01'], 'dob'],
    'dob under 16 at joining' => [['dob' => '2010-03-02', 'joining_date' => '2024-03-01'], 'dob'],
    'joining required' => [['joining_date' => ''], 'joining_date'],
    'joining not a date' => [['joining_date' => 'soon'], 'joining_date'],
    'probation end before joining' => [['probation_end_date' => '2024-02-01'], 'probation_end_date'],
    'probation end same day' => [['probation_end_date' => '2024-03-01'], 'probation_end_date'],
    'department required' => [['department_id' => ''], 'department_id'],
    'department unknown' => [['department_id' => 99999], 'department_id'],
    'designation required' => [['designation_id' => ''], 'designation_id'],
    'designation unknown' => [['designation_id' => 99999], 'designation_id'],
    'manager unknown' => [['manager_id' => 99999], 'manager_id'],
    'device id too long' => [['device_user_id' => str_repeat('1', 21)], 'device_user_id'],
    'gender unknown' => [['gender' => 'robot'], 'gender'],
    'employment type unknown' => [['employment_type' => 'slave'], 'employment_type'],
    'employment type required' => [['employment_type' => ''], 'employment_type'],
    'create status resigned' => [['status' => 'resigned'], 'status'],
    'create status inactive' => [['status' => 'inactive'], 'status'],
    'status required on create' => [['status' => ''], 'status'],
    'bank name too long' => [['bank_name' => str_repeat('b', 101)], 'bank_name'],
    'account number too long' => [['account_number' => str_repeat('1', 51)], 'account_number'],
    'emergency phone too long' => [['emergency_phone' => str_repeat('1', 31)], 'emergency_phone'],
    'array field' => [['first_name' => ['x']], 'first_name'],
]);

it('accepts a person exactly 16 years old at joining', function () {
    $this->actingAs(userWithRole('hr'))->post('/employees', ewPayload(['dob' => '2008-03-01', 'joining_date' => '2024-03-01', 'probation_end_date' => null]))
        ->assertSessionHasNoErrors();
});

it('rejects a designation that belongs to another department', function () {
    [$dept] = ewDeps();
    $other = Department::factory()->create();
    $foreign = Designation::factory()->create(['department_id' => $other->id]);

    $this->actingAs(userWithRole('hr'))->post('/employees', ewPayload(['department_id' => $dept->id, 'designation_id' => $foreign->id]))
        ->assertSessionHasErrors('designation_id');
});

it('rejects a duplicate email in any letter case, and an email already used by an account that is not free', function () {
    Employee::factory()->create(['email' => 'taken@corp.test']);
    $hr = userWithRole('hr');

    $this->actingAs($hr)->post('/employees', ewPayload(['email' => 'TAKEN@corp.test']))->assertSessionHasErrors('email');
});

it('rejects a duplicate CNIC and a duplicate machine user id', function () {
    Employee::factory()->create(['cnic' => '4220112345671', 'device_user_id' => '77']);
    $hr = userWithRole('hr');

    $this->actingAs($hr)->post('/employees', ewPayload(['cnic' => '42201-1234567-1']))->assertSessionHasErrors('cnic');
    $this->actingAs($hr)->post('/employees', ewPayload(['device_user_id' => '77', 'cnic' => '']))->assertSessionHasErrors('device_user_id');
});

it('does not count the record being edited as a duplicate', function () {
    [$employee] = ewExisting('employee', ['device_user_id' => '55']);

    $this->actingAs(userWithRole('hr'))->put("/employees/{$employee->id}", ewEditPayload($employee, ['device_user_id' => '55']))->assertSessionHasNoErrors();
});

it('keeps the date of birth rule when the dob is today', function () {
    $today = Carbon::today()->format('Y-m-d');

    $this->actingAs(userWithRole('hr'))->post('/employees', ewPayload(['dob' => $today]))->assertSessionHasErrors('dob');
});

// ---------------------------------------------------------------- update

it('syncs the linked account name and email, keeps it verified, and sends no link', function () {
    [$employee, $user] = ewExisting('employee');
    $user->forceFill(['email_verified_at' => null])->save();

    $this->actingAs(userWithRole('hr'))->put("/employees/{$employee->id}", ewEditPayload($employee, [
        'first_name' => 'Zara', 'last_name' => 'Ali', 'email' => 'Zara.Ali@Corp.test',
    ]))->assertSessionHasNoErrors()->assertRedirect("/employees/{$employee->id}/edit");

    $user->refresh();
    expect($user->name)->toBe('Zara Ali')->and($user->email)->toBe('zara.ali@corp.test')->and($user->email_verified_at)->not->toBeNull()
        ->and($employee->fresh()->email)->toBe('zara.ali@corp.test');
    Notification::assertNothingSent();
});

it('refuses a new work email already used by an unrelated account', function () {
    [$employee, $user] = ewExisting('employee');
    User::factory()->create(['email' => 'busy@corp.test']);

    $this->actingAs(userWithRole('hr'))->put("/employees/{$employee->id}", ewEditPayload($employee, ['email' => 'busy@corp.test']))->assertSessionHasErrors('email');

    expect($user->fresh()->email)->toBe($employee->email);
});

it('creates or updates the contact and bank rows', function () {
    [$employee] = ewExisting('employee');
    $hr = userWithRole('hr');

    $this->actingAs($hr)->put("/employees/{$employee->id}", ewEditPayload($employee, ['address' => 'New Street', 'bank_name' => 'HBL', 'account_number' => '999']))->assertSessionHasNoErrors();
    expect($employee->fresh()->contact->address)->toBe('New Street')->and($employee->fresh()->bankAccount->account_number)->toBe('999');

    $this->actingAs($hr)->put("/employees/{$employee->id}", ewEditPayload($employee, ['address' => 'Newer Street', 'bank_name' => 'HBL', 'account_number' => '999']))->assertSessionHasNoErrors();
    expect(EmployeeContact::where('employee_id', $employee->id)->count())->toBe(1)->and($employee->fresh()->contact->address)->toBe('Newer Street');
});

it('writes one audit entry listing the changed fields, with identity and account fields masked', function () {
    [$employee] = ewExisting('employee', ['cnic' => '4220100000001']);
    $hr = userWithRole('hr');
    $entries = fn () => Activity::where('log_name', 'employees')->where('subject_id', $employee->id)->where('subject_type', $employee->getMorphClass())->count();
    $before = $entries();

    $this->actingAs($hr)->put("/employees/{$employee->id}", ewEditPayload($employee, [
        'first_name' => 'Zara', 'phone' => '111', 'cnic' => '4220199999999', 'dob' => '1991-01-01', 'bank_name' => 'HBL', 'account_number' => '555000111', 'address' => 'Somewhere',
    ]))->assertSessionHasNoErrors();

    expect($entries())->toBe($before + 1);
    $entry = Activity::where('log_name', 'employees')->where('subject_id', $employee->id)->where('subject_type', $employee->getMorphClass())->latest('id')->first();
    expect($entry->description)->toBe('Employee updated')
        ->and($entry->causer_id)->toBe($hr->id)
        ->and($entry->properties['attributes']['first_name'])->toBe('Zara')
        ->and($entry->properties['old']['first_name'])->toBe($employee->first_name)
        ->and($entry->properties['attributes']['phone'])->toBe('111')
        ->and($entry->properties['attributes']['bank_name'])->toBe('HBL')
        ->and($entry->properties['attributes']['address'])->toBe('Somewhere')
        ->and($entry->properties['attributes']['cnic'])->toBe('[changed]')
        ->and($entry->properties['attributes']['dob'])->toBe('[changed]')
        ->and($entry->properties['attributes']['account_number'])->toBe('[changed]')
        ->and($entry->properties['attributes'])->not->toHaveKey('last_name');
    $json = json_encode($entry->properties);
    foreach (['4220199999999', '4220100000001', '1991-01-01', '555000111'] as $secret) {
        expect($json)->not->toContain($secret);
    }
});

it('writes no audit entry when nothing changed', function () {
    [$employee] = ewExisting('employee');
    $before = Activity::where('log_name', 'employees')->count();

    $this->actingAs(userWithRole('hr'))->put("/employees/{$employee->id}", ewEditPayload($employee))->assertSessionHasNoErrors();

    expect(Activity::where('log_name', 'employees')->count())->toBe($before);
});

it('ignores a status posted to the update route', function () {
    [$employee, $user] = ewExisting('employee');

    $this->actingAs(userWithRole('hr'))->put("/employees/{$employee->id}", ewEditPayload($employee, ['status' => 'terminated']))->assertSessionHasNoErrors();

    expect($employee->fresh()->status)->toBe('active')->and($user->fresh()->is_active)->toBeTrue();
});

it('gives an employee with no login account one when saved', function () {
    $employee = Employee::factory()->create(['email' => 'nologin@corp.test']);

    $this->actingAs(userWithRole('hr'))->put("/employees/{$employee->id}", ewEditPayload($employee))->assertSessionHasNoErrors();

    expect($employee->fresh()->user)->not->toBeNull()->and($employee->fresh()->user->hasRole('employee'))->toBeTrue();
    Notification::assertSentTo($employee->fresh()->user, SetPasswordNotification::class);
});

// ---------------------------------------------------------------- reporting line

it('refuses a manager that would create a loop (self, two steps, three steps)', function () {
    $a = Employee::factory()->create();
    $b = Employee::factory()->create(['manager_id' => $a->id]);
    $c = Employee::factory()->create(['manager_id' => $b->id]);
    $hr = userWithRole('hr');

    foreach ([[$a, $a], [$a, $b], [$a, $c]] as [$employee, $manager]) {
        $this->actingAs($hr)->put("/employees/{$employee->id}", ewEditPayload($employee->fresh(), ['manager_id' => $manager->id]))->assertSessionHasErrors('manager_id');
    }

    expect($a->fresh()->manager_id)->toBeNull();
});

it('refuses a resigned, terminated or unknown manager but allows inactive-free chains', function () {
    $hr = userWithRole('hr');
    $resigned = Employee::factory()->create(['status' => 'resigned']);
    $terminated = Employee::factory()->create(['status' => 'terminated']);

    foreach ([$resigned, $terminated] as $manager) {
        $this->actingAs($hr)->post('/employees', ewPayload(['manager_id' => $manager->id]))->assertSessionHasErrors('manager_id');
    }

    $ok = Employee::factory()->create(['status' => 'probation']);
    $this->actingAs($hr)->post('/employees', ewPayload(['manager_id' => $ok->id]))->assertSessionHasNoErrors();
    expect(Employee::where('email', 'ayesha.khan@corp.test')->value('manager_id'))->toBe($ok->id);
});

it('does not re-check the manager status when the manager is unchanged', function () {
    $manager = Employee::factory()->create();
    [$employee] = ewExisting('employee', ['manager_id' => $manager->id]);
    $manager->update(['status' => 'resigned']);

    $this->actingAs(userWithRole('hr'))->put("/employees/{$employee->id}", ewEditPayload($employee->fresh(), ['first_name' => 'Zed']))->assertSessionHasNoErrors();
    expect($employee->fresh()->first_name)->toBe('Zed');
});

it('serves the manager picker: at most 20, active or probation only, minus self and everyone below', function () {
    $hr = userWithRole('hr');
    $boss = Employee::factory()->create(['first_name' => 'Boss', 'last_name' => 'Top']);
    $mid = Employee::factory()->create(['first_name' => 'Mid', 'last_name' => 'Level', 'manager_id' => $boss->id]);
    $low = Employee::factory()->create(['first_name' => 'Low', 'last_name' => 'Level', 'manager_id' => $mid->id]);
    Employee::factory()->create(['status' => 'resigned', 'first_name' => 'Gone', 'last_name' => 'Away']);
    Employee::factory()->create(['status' => 'inactive', 'first_name' => 'Idle', 'last_name' => 'Away']);
    Employee::factory()->create(['status' => 'terminated', 'first_name' => 'Fired', 'last_name' => 'Away']);
    Employee::factory()->count(25)->create();

    $all = $this->actingAs($hr)->getJson('/employees/manager-options')->assertOk()->json('data');
    expect(count($all))->toBe(20)
        ->and(array_keys($all[0]))->toBe(['id', 'name', 'code', 'designation']);

    $names = fn (array $rows) => array_column($rows, 'name');
    $away = $this->actingAs($hr)->getJson('/employees/manager-options?search=away')->json('data');
    expect($away)->toBe([]);

    $level = $this->actingAs($hr)->getJson('/employees/manager-options?search=level')->json('data');
    expect($names($level))->toContain('Mid Level')->toContain('Low Level');

    $below = $this->actingAs($hr)->getJson("/employees/manager-options?search=level&exclude={$boss->id}")->json('data');
    expect($names($below))->not->toContain('Mid Level')->not->toContain('Low Level');

    $self = $this->actingAs($hr)->getJson("/employees/manager-options?search=boss&exclude={$boss->id}")->json('data');
    expect($self)->toBe([]);

    $byCode = $this->actingAs($hr)->getJson('/employees/manager-options?search='.$low->employee_code)->json('data');
    expect(array_column($byCode, 'id'))->toContain($low->id);
});

it('keeps the picker to its fields only and survives odd parameters', function () {
    $hr = userWithRole('hr');
    Employee::factory()->create(['cnic' => '4220100000009']);

    $json = $this->actingAs($hr)->getJson('/employees/manager-options')->assertOk()->getContent();
    expect($json)->not->toContain('4220100000009')->not->toContain('email');

    $this->actingAs($hr)->getJson('/employees/manager-options?search[]=x&exclude[]=1')->assertOk();
    $this->actingAs($hr)->getJson('/employees/manager-options?exclude=abc')->assertOk();
    $this->actingAs($hr)->getJson('/employees/manager-options?search=%25')->assertOk()->assertJson(['data' => []]);
});

// ---------------------------------------------------------------- status

it('disables the login and records the last working day when resigning, and re-enables on return', function () {
    [$employee, $user] = ewExisting('employee');
    $hr = userWithRole('hr');

    $this->actingAs($hr)->patch("/employees/{$employee->id}/status", ['status' => 'resigned', 'last_working_day' => '2024-09-30'])
        ->assertSessionHasNoErrors()->assertSessionHas('success');

    expect($employee->fresh()->status)->toBe('resigned')->and($employee->fresh()->last_working_day->format('Y-m-d'))->toBe('2024-09-30')
        ->and($user->fresh()->is_active)->toBeFalse();

    $this->actingAs($hr)->patch("/employees/{$employee->id}/status", ['status' => 'active', 'last_working_day' => '2024-09-30'])->assertSessionHasNoErrors();

    expect($employee->fresh()->status)->toBe('active')->and($employee->fresh()->last_working_day)->toBeNull()
        ->and($user->fresh()->is_active)->toBeTrue();
});

it('disables the login for inactive and terminated, enables for probation', function () {
    [$employee, $user] = ewExisting('employee');
    $hr = userWithRole('hr');

    $this->actingAs($hr)->patch("/employees/{$employee->id}/status", ['status' => 'inactive'])->assertSessionHasNoErrors();
    expect($user->fresh()->is_active)->toBeFalse()->and($employee->fresh()->last_working_day)->toBeNull();

    $this->actingAs($hr)->patch("/employees/{$employee->id}/status", ['status' => 'probation'])->assertSessionHasNoErrors();
    expect($user->fresh()->is_active)->toBeTrue();

    $this->actingAs($hr)->patch("/employees/{$employee->id}/status", ['status' => 'terminated', 'last_working_day' => '2025-01-15'])->assertSessionHasNoErrors();
    expect($user->fresh()->is_active)->toBeFalse();
});

it('validates the status change', function (array $body, string $field) {
    [$employee] = ewExisting('employee', ['joining_date' => '2024-03-01']);

    $this->actingAs(userWithRole('hr'))->patch("/employees/{$employee->id}/status", $body)->assertSessionHasErrors($field);

    expect($employee->fresh()->status)->toBe('active');
})->with([
    'unknown status' => [['status' => 'on_leave'], 'status'],
    'no status' => [[], 'status'],
    'resigned needs a day' => [['status' => 'resigned'], 'last_working_day'],
    'terminated needs a day' => [['status' => 'terminated', 'last_working_day' => ''], 'last_working_day'],
    'day before joining' => [['status' => 'resigned', 'last_working_day' => '2024-02-28'], 'last_working_day'],
    'day too far ahead' => [['status' => 'resigned', 'last_working_day' => '2999-01-01'], 'last_working_day'],
    'day not a date' => [['status' => 'resigned', 'last_working_day' => 'someday'], 'last_working_day'],
]);

it('allows a last working day up to a year ahead', function () {
    [$employee] = ewExisting('employee', ['joining_date' => '2024-03-01']);
    $day = Carbon::today()->addDays(365)->format('Y-m-d');
    $tooFar = Carbon::today()->addDays(366)->format('Y-m-d');
    $hr = userWithRole('hr');

    $this->actingAs($hr)->patch("/employees/{$employee->id}/status", ['status' => 'resigned', 'last_working_day' => $tooFar])->assertSessionHasErrors('last_working_day');
    $this->actingAs($hr)->patch("/employees/{$employee->id}/status", ['status' => 'resigned', 'last_working_day' => $day])->assertSessionHasNoErrors();
});

it('refuses HR a status change on an admin or HR person and leaves the login on', function (string $current) {
    [$employee, $user] = ewExisting($current);

    $this->actingAs(userWithRole('hr'))->patch("/employees/{$employee->id}/status", ['status' => 'inactive'])
        ->assertSessionHas('error', EmployeeRoles::PRIVILEGED_MESSAGE);

    expect($employee->fresh()->status)->toBe('active')->and($user->fresh()->is_active)->toBeTrue();
})->with(['admin', 'hr']);

it('keeps the last active admin: an Admin cannot disable the only remaining one, and nothing changes', function () {
    [$otherEmployee, $other] = ewExisting('admin');
    $admin = userWithRole('admin');
    $own = employeeFor($admin, ['joining_date' => '2024-03-01']);

    $this->actingAs($admin)->patch("/employees/{$otherEmployee->id}/status", ['status' => 'inactive'])->assertSessionHasNoErrors();
    expect($other->fresh()->is_active)->toBeFalse();

    $this->actingAs($admin)->patch("/employees/{$own->id}/status", ['status' => 'inactive'])
        ->assertSessionHas('error', 'There must be at least one active Admin.');

    expect($own->fresh()->status)->toBe('active')->and($admin->fresh()->is_active)->toBeTrue();
});

it('will not let an Admin disable their own account through a status change', function () {
    $admin = userWithRole('admin');
    userWithRole('admin');
    $own = employeeFor($admin, ['joining_date' => '2024-03-01']);

    $this->actingAs($admin)->patch("/employees/{$own->id}/status", ['status' => 'inactive'])
        ->assertSessionHas('error', 'You cannot change your own role or disable your own account.');

    expect($own->fresh()->status)->toBe('active')->and($admin->fresh()->is_active)->toBeTrue();
});

it('changes the status of an employee that has no login account', function () {
    $employee = Employee::factory()->create(['joining_date' => '2024-03-01']);

    $this->actingAs(userWithRole('hr'))->patch("/employees/{$employee->id}/status", ['status' => 'inactive'])->assertSessionHasNoErrors();

    expect($employee->fresh()->status)->toBe('inactive');
});

it('audits a status change with old and new values in one entry', function () {
    [$employee] = ewExisting('employee');
    $hr = userWithRole('hr');

    $this->actingAs($hr)->patch("/employees/{$employee->id}/status", ['status' => 'resigned', 'last_working_day' => '2024-09-30']);

    $entries = Activity::where('log_name', 'employees')->where('subject_id', $employee->id)->where('subject_type', $employee->getMorphClass())->where('description', 'Employee status changed')->get();
    expect($entries)->toHaveCount(1);
    expect($entries->first()->properties['old']['status'])->toBe('active')
        ->and($entries->first()->properties['attributes']['status'])->toBe('resigned')
        ->and($entries->first()->properties['attributes']['last_working_day'])->toBe('2024-09-30')
        ->and($entries->first()->causer_id)->toBe($hr->id);
});

// ---------------------------------------------------------------- delete

it('deletes a fresh mistake with its satellites and its never-used login, keeping the name in the audit entry', function () {
    $this->actingAs(userWithRole('hr'))->post('/employees', ewPayload());
    $e = Employee::firstOrFail();
    $userId = $e->user->id;
    $admin = userWithRole('admin');

    $this->actingAs($admin)->delete("/employees/{$e->id}")->assertRedirect('/employees')->assertSessionHas('success');

    expect(Employee::find($e->id))->toBeNull()->and(User::find($userId))->toBeNull()
        ->and(EmployeeContact::where('employee_id', $e->id)->exists())->toBeFalse()
        ->and(EmployeeBankAccount::where('employee_id', $e->id)->exists())->toBeFalse();

    $entry = Activity::where('log_name', 'employees')->where('subject_id', $e->id)->where('description', 'Employee deleted')->first();
    expect($entry)->not->toBeNull()->and($entry->properties['subject_label'])->toBe('Ayesha Khan')
        ->and($entry->properties['attributes']['employee_code'])->toBe($e->employee_code)
        ->and($entry->causer_id)->toBe($admin->id);
});

it('refuses to delete someone who has signed in, and says what to do instead', function () {
    [$employee, $user] = ewExisting('employee');
    $user->forceFill(['last_login_at' => now()])->save();

    $this->actingAs(userWithRole('admin'))->delete("/employees/{$employee->id}")
        ->assertSessionHas('error', 'This employee has signed in before. Set the status to Inactive, Resigned or Terminated instead.');

    expect(Employee::find($employee->id))->not->toBeNull()->and(User::find($user->id))->not->toBeNull();
});

it('refuses to delete someone with direct reports, with the guidance appended', function () {
    [$employee] = ewExisting('employee');
    Employee::factory()->create(['manager_id' => $employee->id]);

    $this->actingAs(userWithRole('admin'))->delete("/employees/{$employee->id}")
        ->assertSessionHas('error', 'This employee has direct reports. Reassign them first. Set the status to Inactive, Resigned or Terminated instead.');

    expect(Employee::find($employee->id))->not->toBeNull();
});

it('will not let an admin delete their own employee record', function () {
    $admin = userWithRole('admin');
    $own = employeeFor($admin);

    $this->actingAs($admin)->delete("/employees/{$own->id}")->assertSessionHas('error');

    expect(Employee::find($own->id))->not->toBeNull()->and(User::find($admin->id))->not->toBeNull();
});

it('deletes an employee that has no login account', function () {
    $e = Employee::factory()->create();

    $this->actingAs(userWithRole('admin'))->delete("/employees/{$e->id}")->assertRedirect('/employees');

    expect(Employee::find($e->id))->toBeNull();
});

// ---------------------------------------------------------------- privileged accounts (fix round 1)

/** The state of an account that a refused request must leave alone. */
function ewAccountState(User $user): array
{
    $user = $user->fresh();

    return [$user->name, $user->email, $user->is_active, $user->employee_id, $user->roles()->pluck('name')->all()];
}

it('refuses HR every kind of edit on an admin, an HR person and their own record, and stores nothing', function (string $target, array $change) {
    $hr = userWithRole('hr');
    if ($target === 'self') {
        $employee = employeeFor($hr, ['joining_date' => '2024-03-01']);
        $account = $hr;
    } else {
        [$employee, $account] = ewExisting($target);
    }
    $columns = ['first_name', 'last_name', 'email', 'phone', 'manager_id', 'department_id'];
    $stateBefore = ewAccountState($account);
    $employeeBefore = $employee->fresh()->only($columns);

    $response = $this->actingAs($hr)->put("/employees/{$employee->id}", ewEditPayload($employee, $change));

    expect($response->status())->toBeIn([302, 403]);
    if ($response->status() === 302) {
        $response->assertSessionHasErrors(['email' => EmployeeRoles::PRIVILEGED_MESSAGE]);
    }
    expect(ewAccountState($account))->toBe($stateBefore)
        ->and($employee->fresh()->only($columns))->toBe($employeeBefore);
})->with(['admin', 'hr', 'self'])->with([
    'email' => [['email' => 'hr.controls@evil.test']],
    'name' => [['first_name' => 'Renamed']],
    'role' => [['role' => 'employee']],
    'unrelated field' => [['phone' => '0999']],
]);

it('lets an Admin change the email, name and role of an HR person', function () {
    [$employee, $account] = ewExisting('hr');

    $this->actingAs(userWithRole('admin'))->put("/employees/{$employee->id}", ewEditPayload($employee, ['email' => 'new.hr@corp.test', 'first_name' => 'Renamed', 'role' => 'manager']))
        ->assertSessionHasNoErrors();

    $account->refresh();
    expect($account->email)->toBe('new.hr@corp.test')->and($account->name)->toContain('Renamed')->and($account->hasRole('manager'))->toBeTrue();
});

it('refuses HR linking an existing admin or HR account on create, and changes nothing', function (string $role) {
    $account = User::factory()->create(['email' => 'ayesha.khan@corp.test', 'name' => 'Existing Person']);
    $account->assignRole($role);
    $before = ewAccountState($account);

    $this->actingAs(userWithRole('hr'))->post('/employees', ewPayload(['role' => 'employee']))
        ->assertSessionHasErrors(['email' => EmployeeRoles::PRIVILEGED_MESSAGE]);

    expect(Employee::count())->toBe(0)->and(ewAccountState($account))->toBe($before);
})->with(['admin', 'hr']);

it('refuses HR linking an existing admin or HR account while editing an employee without a login', function (string $role) {
    $account = User::factory()->create(['email' => 'boss@corp.test', 'name' => 'The Boss']);
    $account->assignRole($role);
    $employee = Employee::factory()->create(['email' => 'nologin@corp.test']);
    $before = ewAccountState($account);

    $this->actingAs(userWithRole('hr'))->put("/employees/{$employee->id}", ewEditPayload($employee, ['email' => 'boss@corp.test']))
        ->assertSessionHasErrors(['email' => EmployeeRoles::PRIVILEGED_MESSAGE]);

    expect(ewAccountState($account))->toBe($before)->and($employee->fresh()->email)->toBe('nologin@corp.test');
})->with(['admin', 'hr']);

it('lets an Admin link an existing account while editing an employee without a login', function () {
    $account = User::factory()->create(['email' => 'boss@corp.test']);
    $account->assignRole('admin');
    $employee = Employee::factory()->create(['email' => 'nologin@corp.test']);

    $this->actingAs(userWithRole('admin'))->put("/employees/{$employee->id}", ewEditPayload($employee, ['email' => 'boss@corp.test', 'role' => 'employee']))
        ->assertSessionHasNoErrors();

    expect($account->fresh()->employee_id)->toBe($employee->id)->and($account->fresh()->hasRole('admin'))->toBeTrue();
});

it('lets HR link an existing account that is not admin or HR while editing', function () {
    $account = User::factory()->create(['email' => 'plain@corp.test']);
    $account->assignRole('manager');
    $employee = Employee::factory()->create(['email' => 'nologin@corp.test']);

    $this->actingAs(userWithRole('hr'))->put("/employees/{$employee->id}", ewEditPayload($employee, ['email' => 'plain@corp.test']))->assertSessionHasNoErrors();

    expect($account->fresh()->employee_id)->toBe($employee->id)->and($account->fresh()->hasRole('manager'))->toBeTrue();
});

it('tells the edit page when the viewer may not edit', function () {
    [$adminEmployee] = ewExisting('admin');
    [$plainEmployee] = ewExisting('employee');
    $hr = userWithRole('hr');
    $admin = userWithRole('admin');

    expect($this->actingAs($hr)->get("/employees/{$adminEmployee->id}/edit")->viewData('page')['props']['can']['edit'])->toBeFalse()
        ->and($this->actingAs($hr)->get("/employees/{$plainEmployee->id}/edit")->viewData('page')['props']['can']['edit'])->toBeTrue()
        ->and($this->actingAs($admin)->get("/employees/{$adminEmployee->id}/edit")->viewData('page')['props']['can']['edit'])->toBeTrue();
});

it('leaves a login that an Admin disabled separately alone when active is posted again', function () {
    [$employee, $user] = ewExisting('employee');
    $user->forceFill(['is_active' => false])->save();
    $before = Activity::where('log_name', 'employees')->count();

    $this->actingAs(userWithRole('hr'))->patch("/employees/{$employee->id}/status", ['status' => 'active'])->assertSessionHasNoErrors();

    expect($user->fresh()->is_active)->toBeFalse()->and(Activity::where('log_name', 'employees')->count())->toBe($before);
});

it('leaves the login alone for a change inside the active group', function () {
    [$employee, $user] = ewExisting('employee');
    $user->forceFill(['is_active' => false])->save();

    $this->actingAs(userWithRole('hr'))->patch("/employees/{$employee->id}/status", ['status' => 'probation'])->assertSessionHasNoErrors();

    expect($employee->fresh()->status)->toBe('probation')->and($user->fresh()->is_active)->toBeFalse();
});

it('re-enables the login on a real move from inactive back to active, with an audit entry', function () {
    [$employee, $user] = ewExisting('employee', ['status' => 'inactive']);
    $user->forceFill(['is_active' => false])->save();

    $this->actingAs(userWithRole('hr'))->patch("/employees/{$employee->id}/status", ['status' => 'active'])->assertSessionHasNoErrors();

    expect($user->fresh()->is_active)->toBeTrue()->and($employee->fresh()->status)->toBe('active')
        ->and(Activity::where('log_name', 'employees')->where('description', 'Employee status changed')->count())->toBe(1);
});

it('answers a blank code on edit with a validation error, not a 500', function () {
    [$employee] = ewExisting('employee', ['employee_code' => 'LD-0010']);

    $this->actingAs(userWithRole('admin'))->put("/employees/{$employee->id}", ewEditPayload($employee, ['employee_code' => '']))->assertSessionHasErrors('employee_code');

    expect($employee->fresh()->employee_code)->toBe('LD-0010');
});

it('unlinks, not deletes, an account that existed before the employee record', function () {
    [$employee, $user] = ewExisting('employee');

    $this->actingAs(userWithRole('admin'))->delete("/employees/{$employee->id}")->assertRedirect('/employees');

    expect(Employee::find($employee->id))->toBeNull()->and(User::find($user->id))->not->toBeNull()->and($user->fresh()->employee_id)->toBeNull();
});

it('deletes the account made with the employee together with its password links', function () {
    $this->actingAs(userWithRole('hr'))->post('/employees', ewPayload());
    $e = Employee::firstOrFail();
    $email = $e->user->email;
    DB::table('password_reset_tokens')->updateOrInsert(['email' => $email], ['token' => 'x', 'created_at' => now()]);

    $this->actingAs(userWithRole('admin'))->delete("/employees/{$e->id}")->assertRedirect('/employees');

    expect(User::where('email', $email)->exists())->toBeFalse()->and(DB::table('password_reset_tokens')->where('email', $email)->exists())->toBeFalse();
});

it('records the acting user as the causer of the account creation entry', function () {
    $hr = userWithRole('hr');
    $this->actingAs($hr)->post('/employees', ewPayload());

    $entry = Activity::where('log_name', 'users')->where('description', 'like', 'Account created with role%')->first();
    expect($entry->causer_id)->toBe($hr->id);
});
