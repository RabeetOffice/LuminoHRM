<?php

use App\Models\Attendance;
use App\Models\Device;
use App\Models\DevicePunch;
use App\Models\Employee;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->withoutVite(); // the page is built once by the coordinator, not by this task
    $this->travelTo(CarbonImmutable::parse('2026-11-24 12:00:00', 'Asia/Karachi'));
    $this->device = Device::factory()->create(['name' => 'Front door', 'serial_no' => 'SNFRONT']);
});

/** An employee who joined well before the test days. */
function pnEmployee(array $attrs = []): Employee
{
    return Employee::factory()->create(array_merge(['joining_date' => '2026-01-01'], $attrs));
}

// ---------------------------------------------------------------- access

it('lets admin and hr open raw punches', function (string $role) {
    punch('777', '2026-11-23 09:00:00');

    $this->actingAs(userWithRole($role))->get('/attendance/punches')->assertOk()
        ->assertInertia(fn ($p) => $p->component('attendance/punches')->has('punches.data', 1)->where('can.assign', true));
})->with(['admin', 'hr']);

it('forbids managers and employees on every punch route', function (string $role) {
    $user = userWithRole($role);
    $employee = pnEmployee();
    punch('777', '2026-11-23 09:00:00');

    $this->actingAs($user)->get('/attendance/punches')->assertForbidden();
    $this->actingAs($user)->post('/attendance/punches/assign', ['device_user_id' => '777', 'employee_id' => $employee->id])
        ->assertForbidden();

    expect($employee->fresh()->device_user_id)->toBeNull();
})->with(['manager', 'employee']);

it('redirects guests to the sign-in page', function () {
    $this->get('/attendance/punches')->assertRedirect('/login');
    $this->post('/attendance/punches/assign', [])->assertRedirect('/login');
});

// ---------------------------------------------------------------- list

it('lists punches newest first with the employee or an unknown marker', function () {
    $ali = pnEmployee(['first_name' => 'Ali', 'last_name' => 'Khan', 'device_user_id' => '101']);
    punch($ali, '2026-11-23 09:00:05');
    punch('999', '2026-11-23 18:01:00');
    punch($ali, '2026-11-22 09:10:00');

    $this->actingAs(userWithRole('admin'))->get('/attendance/punches')
        ->assertInertia(fn ($p) => $p
            ->has('punches.data', 3)
            ->where('punches.total', 3)
            ->where('punches.data.0.device_user_id', '999')
            ->where('punches.data.0.employee', null)
            ->where('punches.data.0.date', '2026-11-23')
            ->where('punches.data.0.time', '18:01:00')
            ->where('punches.data.0.device', 'Front door')
            ->where('punches.data.0.verify', 'Fingerprint')
            ->where('punches.data.0.source', 'device')
            ->where('punches.data.1.employee.name', 'Ali Khan')
            ->where('punches.data.1.time', '09:00:05')
            ->where('punches.data.2.date', '2026-11-22'));
});

it('shows the attendance day a punch before the day cutoff counts for', function () {
    punch('101', '2026-11-24 01:30:00');

    $this->actingAs(userWithRole('admin'))->get('/attendance/punches')
        ->assertInertia(fn ($p) => $p
            ->where('punches.data.0.date', '2026-11-24')
            ->where('punches.data.0.punch_date', '2026-11-23'));
});

it('paginates', function () {
    foreach (range(1, 12) as $i) {
        punch('500', sprintf('2026-11-20 08:%02d:00', $i));
    }

    $this->actingAs(userWithRole('admin'))->get('/attendance/punches')
        ->assertInertia(fn ($p) => $p->has('punches.data', 10)->where('punches.total', 12)->where('punches.last_page', 2)
            ->where('punches.data.0.time', '08:12:00'));

    $this->actingAs(userWithRole('admin'))->get('/attendance/punches?page=2')
        ->assertInertia(fn ($p) => $p->has('punches.data', 2));
});

// ---------------------------------------------------------------- filters

it('filters by attendance day range, device, employee, machine user id and unknown only', function () {
    $other = Device::factory()->create(['name' => 'Back door']);
    $ali = pnEmployee(['device_user_id' => '101']);
    punch($ali, '2026-11-20 09:00:00');
    punch($ali, '2026-11-22 09:00:00', $other);
    punch('999', '2026-11-22 10:00:00');
    punch('999', '2026-11-23 01:00:00'); // counts for the 22nd
    $admin = userWithRole('admin');

    $count = fn (string $query) => $this->actingAs($admin)->get('/attendance/punches?'.$query)->viewData('page')['props']['punches']['total'];

    expect($count('from=2026-11-22&to=2026-11-22'))->toBe(3)
        ->and($count('from=2026-11-21'))->toBe(3)
        ->and($count('to=2026-11-20'))->toBe(1)
        ->and($count('device='.$other->id))->toBe(1)
        ->and($count('employee='.$ali->id))->toBe(2)
        ->and($count('unknown=1'))->toBe(2)
        ->and($count('user=999'))->toBe(2)
        ->and($count('unknown=1&device='.$other->id))->toBe(0)
        // Swapped dates are put in order.
        ->and($count('from=2026-11-22&to=2026-11-20'))->toBe(4);
});

it('ignores filter values that cannot be applied', function () {
    punch('999', '2026-11-22 10:00:00');

    $this->actingAs(userWithRole('admin'))
        ->get('/attendance/punches?from=2026-02-30&to[]=x&device=abc&employee=-1&unknown=yes&page[]=2&user[]=1')
        ->assertOk()
        ->assertInertia(fn ($p) => $p->where('punches.total', 1)
            ->where('filters.from', '')
            ->where('filters.device', null)
            ->where('filters.unknown', false));
});

// ---------------------------------------------------------------- assign

it('assigns an unknown machine user id through the employee update path: relink, rebuild and audit', function () {
    $employee = pnEmployee(['first_name' => 'Sara', 'last_name' => 'Ali']);
    punch('555', '2026-11-23 09:00:00');
    punch('555', '2026-11-23 18:05:00');

    $this->actingAs(userWithRole('hr'))
        ->post('/attendance/punches/assign', ['device_user_id' => ' 555 ', 'employee_id' => $employee->id])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success');

    expect($employee->fresh()->device_user_id)->toBe('555')
        ->and(DevicePunch::where('device_user_id', '555')->pluck('employee_id')->unique()->all())->toBe([$employee->id]);

    $day = Attendance::query()->where('employee_id', $employee->id)->onDate('2026-11-23')->first();
    expect($day)->not->toBeNull()->and($day->needs_rebuild)->toBeTrue();

    $entry = Activity::query()->where('log_name', 'employees')->where('description', 'Employee updated')->latest('id')->first();
    expect($entry)->not->toBeNull()
        ->and($entry->subject_id)->toBe($employee->id)
        ->and($entry->properties['attributes']['device_user_id'])->toBe('555');

    $this->artisan('hrm:attendance-rebuild')->assertSuccessful();
    expect(Attendance::query()->where('employee_id', $employee->id)->onDate('2026-11-23')->first()->status)->toBe('present');
});

it('refuses an id that has no unknown punches', function () {
    $employee = pnEmployee();

    $this->actingAs(userWithRole('admin'))
        ->post('/attendance/punches/assign', ['device_user_id' => '404', 'employee_id' => $employee->id])
        ->assertSessionHasErrors('device_user_id');

    expect($employee->fresh()->device_user_id)->toBeNull();
});

it('refuses an employee who already has a machine user id', function () {
    $employee = pnEmployee(['device_user_id' => '101']);
    punch('555', '2026-11-23 09:00:00');

    $this->actingAs(userWithRole('admin'))
        ->post('/attendance/punches/assign', ['device_user_id' => '555', 'employee_id' => $employee->id])
        ->assertSessionHasErrors('employee_id');

    expect($employee->fresh()->device_user_id)->toBe('101');
});

it('refuses an id another employee already holds', function () {
    // Punches from before the holder joined stay unlinked.
    $holder = pnEmployee(['device_user_id' => '555', 'joining_date' => '2026-11-23']);
    punch('555', '2026-11-20 09:00:00');
    $employee = pnEmployee();

    $this->actingAs(userWithRole('admin'))
        ->post('/attendance/punches/assign', ['device_user_id' => '555', 'employee_id' => $employee->id])
        ->assertSessionHasErrors('device_user_id');

    expect($employee->fresh()->device_user_id)->toBeNull();
});

it('refuses an unknown employee', function () {
    punch('555', '2026-11-23 09:00:00');

    $this->actingAs(userWithRole('admin'))
        ->post('/attendance/punches/assign', ['device_user_id' => '555', 'employee_id' => 999999])
        ->assertSessionHasErrors('employee_id');
});

it('lets only an Admin give an id to an employee whose login is Admin or HR', function () {
    $account = userWithRole('hr');
    $employee = employeeFor($account, ['joining_date' => '2026-01-01']);
    punch('555', '2026-11-23 09:00:00');

    $this->actingAs(userWithRole('hr'))
        ->post('/attendance/punches/assign', ['device_user_id' => '555', 'employee_id' => $employee->id])
        ->assertSessionHasErrors('employee_id');
    expect($employee->fresh()->device_user_id)->toBeNull();

    $this->actingAs(userWithRole('admin'))
        ->post('/attendance/punches/assign', ['device_user_id' => '555', 'employee_id' => $employee->id])
        ->assertSessionHasNoErrors();
    expect($employee->fresh()->device_user_id)->toBe('555');
});

it('assigns an id to an employee with no login without creating an account, mail or account audit entry', function () {
    Mail::fake();
    Notification::fake();
    $employee = pnEmployee(['first_name' => 'No', 'last_name' => 'Login', 'email' => 'nologin@example.test']);
    punch('555', '2026-11-23 09:00:00');
    $admin = userWithRole('admin');
    $users = User::count();
    $before = Activity::query()->max('id') ?? 0;

    $this->actingAs($admin)
        ->post('/attendance/punches/assign', ['device_user_id' => '555', 'employee_id' => $employee->id])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success', fn (string $m) => ! str_contains($m, 'login') && ! str_contains($m, 'email'));

    expect($employee->fresh()->device_user_id)->toBe('555')
        ->and($employee->fresh()->user)->toBeNull()
        ->and(User::count())->toBe($users)
        ->and(User::query()->where('email', 'nologin@example.test')->exists())->toBeFalse();

    Mail::assertNothingOutgoing();
    Notification::assertNothingSent();

    $entries = Activity::query()->where('id', '>', $before)->get();
    expect($entries->where('subject_type', (new User)->getMorphClass())->count())->toBe(0)
        ->and($entries->pluck('description')->all())->toBe(['Employee updated']);
});

it('assigns an id to an employee with a login without changing the account name or email', function () {
    Mail::fake();
    Notification::fake();
    $account = userWithRole('employee');
    $account->forceFill(['name' => 'Account Name', 'email' => 'account@example.test'])->save();
    $employee = employeeFor($account, ['joining_date' => '2026-01-01']);
    // The employee row differs from the login (as if edited without a sync): assigning must not copy it across.
    Employee::query()->whereKey($employee->id)->update(['first_name' => 'Other', 'last_name' => 'Person', 'email' => 'other@example.test']);
    punch('555', '2026-11-23 09:00:00');

    $this->actingAs(userWithRole('admin'))
        ->post('/attendance/punches/assign', ['device_user_id' => '555', 'employee_id' => $employee->id])
        ->assertSessionHasNoErrors();

    $account->refresh();
    expect($employee->fresh()->device_user_id)->toBe('555')
        ->and($account->name)->toBe('Account Name')
        ->and($account->email)->toBe('account@example.test');
    Notification::assertNothingSent();
});

it('says how many punches were linked, including none', function () {
    $employee = pnEmployee(['joining_date' => '2026-11-23']);
    punch('555', '2026-11-20 09:00:00'); // before joining: stays unknown
    punch('555', '2026-11-23 09:00:00');
    punch('555', '2026-11-23 18:00:00');
    $late = pnEmployee(['joining_date' => '2026-11-24']);
    punch('556', '2026-11-22 09:00:00');
    $admin = userWithRole('admin');

    $this->actingAs($admin)
        ->post('/attendance/punches/assign', ['device_user_id' => '555', 'employee_id' => $employee->id])
        ->assertSessionHas('success', fn (string $m) => str_contains($m, '2 punches linked') && str_contains($m, '1 earlier punch stays unknown'));

    $this->actingAs($admin)
        ->post('/attendance/punches/assign', ['device_user_id' => '556', 'employee_id' => $late->id])
        ->assertSessionHas('success', fn (string $m) => str_contains($m, 'No punches linked'));
    expect($late->fresh()->device_user_id)->toBe('556');
});

it('lists only working employees for assigning and refuses others', function (string $status) {
    $gone = pnEmployee(['status' => $status]);
    $working = pnEmployee(['status' => 'probation']);
    punch('555', '2026-11-23 09:00:00');
    $admin = userWithRole('admin');

    $this->actingAs($admin)->get('/attendance/punches')
        ->assertInertia(fn ($p) => $p
            ->where('employees', fn ($list) => collect($list)->firstWhere('id', $gone->id)['assignable'] === false
                && collect($list)->firstWhere('id', $working->id)['assignable'] === true));

    $this->actingAs($admin)
        ->post('/attendance/punches/assign', ['device_user_id' => '555', 'employee_id' => $gone->id])
        ->assertSessionHasErrors(['employee_id' => "{$gone->full_name} is not currently working here, so a machine user ID cannot be given to them."]);

    expect($gone->fresh()->device_user_id)->toBeNull();
})->with(['inactive', 'resigned', 'terminated']);

it('previews how many punches an assignment would link, on a partial reload only', function () {
    $employee = pnEmployee(['joining_date' => '2026-11-22']);
    punch('555', '2026-11-20 09:00:00');
    punch('555', '2026-11-22 09:00:00');
    punch('555', '2026-11-23 09:00:00');
    punch('555', '2026-11-23 18:00:00');

    $this->actingAs(userWithRole('admin'))
        ->get('/attendance/punches?preview_user=555&preview_employee='.$employee->id)
        ->assertInertia(fn ($p) => $p
            ->missing('assignPreview')
            ->reloadOnly('assignPreview', fn ($r) => $r
                ->where('assignPreview.device_user_id', '555')
                ->where('assignPreview.employee_id', $employee->id)
                ->where('assignPreview.joining_date', '2026-11-22')
                ->where('assignPreview.linked', 3)
                ->where('assignPreview.earlier', 1)));
});

it('previews nothing for an employee outside the assignable list', function () {
    $gone = pnEmployee(['status' => 'resigned']);
    punch('555', '2026-11-23 09:00:00');

    $this->actingAs(userWithRole('admin'))
        ->get('/attendance/punches?preview_user=555&preview_employee='.$gone->id)
        ->assertInertia(fn ($p) => $p->reloadOnly('assignPreview', fn ($r) => $r->where('assignPreview', null)));
});

it('needs the employee management permission as well as attendance.edit', function () {
    $user = User::factory()->create();
    userWithRole('employee'); // seeds the permissions
    $user->givePermissionTo('attendance.view-all', 'attendance.edit');
    $employee = pnEmployee();
    punch('555', '2026-11-23 09:00:00');

    $this->actingAs($user)->get('/attendance/punches')->assertOk()->assertInertia(fn ($p) => $p->where('can.assign', false));
    $this->actingAs($user)
        ->post('/attendance/punches/assign', ['device_user_id' => '555', 'employee_id' => $employee->id])
        ->assertForbidden();
});
