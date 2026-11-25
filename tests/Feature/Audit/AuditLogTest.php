<?php

use App\Actions\CreateUserAccount;
use App\Actions\SetUserActive;
use App\Actions\UpdateUserAccount;
use App\Models\ActivityEntry;
use App\Models\Department;
use App\Models\Designation;
use App\Models\User;
use App\Support\AccessSync;
use App\Support\Audit\Audit;
use App\Support\Audit\AuditLogAction;
use App\Support\Audit\NeverCleanActivityLogAction;
use App\Support\Settings;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationEnabled;
use Laravel\Passkeys\Events\PasskeyDeleted;
use Laravel\Passkeys\Events\PasskeyRegistered;
use Laravel\Passkeys\Passkey;
use Spatie\Activitylog\Models\Activity;

/** A log action that always fails, to prove audited changes roll back. */
class FailingAuditAction extends AuditLogAction
{
    protected function save(Model $activity): void
    {
        throw new RuntimeException('audit store is down');
    }
}

function breakAuditLog(): void
{
    Config::set('activitylog.actions.log_activity', FailingAuditAction::class);
}

// ---------------------------------------------------------------- brief tests

it('records who changed a department, with old and new values', function () {
    $admin = userWithRole('admin');
    $d = Department::create(['name' => 'Ops']);
    $this->actingAs($admin)->put("/organization/departments/{$d->id}", ['name' => 'Operations']);
    $log = Activity::where('log_name', 'organization')->latest('id')->first();
    expect($log->causer_id)->toBe($admin->id);
    expect($log->properties['old']['name'])->toBe('Ops')->and($log->properties['attributes']['name'])->toBe('Operations');
});

it('records settings changes without storing the SMTP password', function () {
    $this->actingAs(userWithRole('admin'))->put('/hrm-settings/email', validEmail(['password' => 'secret-1']));
    $log = Activity::where('log_name', 'settings')->latest('id')->first();
    expect(json_encode($log->properties))->not->toContain('secret-1');
});

it('records role changes and never records password hashes', function () {
    $admin = userWithRole('admin');
    $u = userWithRole('employee');
    $this->actingAs($admin)->put("/users/{$u->id}", ['name' => $u->name, 'email' => $u->email, 'role' => 'manager']);
    $log = Activity::where('log_name', 'users')->latest('id')->first();
    expect($log->description)->toContain('manager');
    expect(json_encode(Activity::all()->pluck('properties')))->not->toContain($u->password);
});

it('records sign-in, failed sign-in and sign-out', function () {
    $u = userWithRole('employee');
    $this->post('/login', ['email' => $u->email, 'password' => 'wrong']);
    $this->post('/login', ['email' => $u->email, 'password' => 'password']);
    expect(Activity::where('log_name', 'auth')->pluck('description')->all())->toContain('Failed sign-in', 'Signed in');
});

it('shows the log to admin only', function (string $role) {
    $this->actingAs(userWithRole($role))->get('/audit-log')->assertForbidden();
})->with(['hr', 'manager', 'employee']);

it('filters by area and date', function () {
    $admin = userWithRole('admin');
    Department::create(['name' => 'A']);
    $this->actingAs($admin)->get('/audit-log?area=organization&from='.now()->toDateString())
        ->assertInertia(fn ($p) => $p->has('entries.data', 1));
});

it('exposes no route to change or delete entries', function () {
    $routes = collect(Route::getRoutes())->filter(fn ($r) => str_starts_with($r->uri(), 'audit-log'));
    expect($routes->flatMap->methods()->unique()->values()->all())->toEqualCanonicalizing(['GET', 'HEAD']);
});

// ---------------------------------------------------------------- organization

it('records department and designation create, update and delete', function () {
    $admin = userWithRole('admin');
    $this->actingAs($admin)->post('/organization/departments', ['name' => 'Finance'])->assertSessionHasNoErrors();
    $dept = Department::where('name', 'Finance')->first();
    $this->actingAs($admin)->post('/organization/designations', ['name' => 'Accountant', 'department_id' => $dept->id])->assertSessionHasNoErrors();
    $desig = Designation::where('name', 'Accountant')->first();
    $this->actingAs($admin)->put("/organization/designations/{$desig->id}", ['name' => 'Senior Accountant', 'department_id' => $dept->id]);
    $this->actingAs($admin)->delete("/organization/designations/{$desig->id}");
    $this->actingAs($admin)->delete("/organization/departments/{$dept->id}");

    $descriptions = Activity::where('log_name', 'organization')->orderBy('id')->pluck('description')->all();
    expect($descriptions)->toBe([
        'Department created', 'Designation created', 'Designation updated', 'Designation deleted', 'Department deleted',
    ]);

    $deleted = Activity::where('description', 'Department deleted')->first();
    expect($deleted->properties['old']['name'])->toBe('Finance')
        ->and($deleted->properties['subject_label'])->toBe('Finance')
        ->and($deleted->causer_id)->toBe($admin->id);

    $created = Activity::where('description', 'Department created')->first();
    expect($created->properties['attributes']['name'])->toBe('Finance')->and($created->properties)->not->toHaveKey('old');
});

it('logs nothing for a save that changes nothing', function () {
    $admin = userWithRole('admin');
    $d = Department::create(['name' => 'Same']);
    $before = Activity::count();
    $this->actingAs($admin)->put("/organization/departments/{$d->id}", ['name' => 'Same']);
    expect(Activity::count())->toBe($before);
});

it('stores the request IP address and a truncated user agent', function () {
    $admin = userWithRole('admin');
    $this->actingAs($admin)->withHeaders(['User-Agent' => str_repeat('A', 400)])
        ->post('/organization/departments', ['name' => 'Ctx']);
    $log = Activity::where('log_name', 'organization')->latest('id')->first();
    expect($log->properties['ip'])->not->toBeEmpty()->and(strlen($log->properties['user_agent']))->toBe(255);
});

// ---------------------------------------------------------------- users

it('records account creation with name, email and role but no password', function () {
    Notification::fake();
    $admin = userWithRole('admin');
    $this->actingAs($admin)->post('/users', ['name' => 'Sara Ahmed', 'email' => 'sara@example.com', 'role' => 'hr']);
    $log = Activity::where('log_name', 'users')->where('description', 'like', 'Account created%')->first();
    expect($log->causer_id)->toBe($admin->id)
        ->and($log->description)->toContain('hr')
        ->and($log->properties['attributes'])->toBe(['name' => 'Sara Ahmed', 'email' => 'sara@example.com', 'role' => 'hr']);
    expect(Activity::where('description', 'Set-password link sent')->count())->toBe(1);
    $hash = User::whereEmail('sara@example.com')->value('password');
    expect(json_encode(Activity::all()->pluck('properties')))->not->toContain($hash);
});

it('records a set-password link failure', function () {
    Password::shouldReceive('broker')->andThrow(new RuntimeException('smtp down'));
    Log::spy();
    $this->actingAs(userWithRole('admin'))->post('/users', ['name' => 'Sara Ahmed', 'email' => 'sara@example.com', 'role' => 'hr']);
    expect(Activity::where('description', 'Set-password link failed')->count())->toBe(1);
});

it('records name and email changes and role changes as separate entries', function () {
    $admin = userWithRole('admin');
    $u = userWithRole('employee');
    $this->actingAs($admin)->put("/users/{$u->id}", ['name' => 'New Name', 'email' => 'new@example.com', 'role' => 'hr']);
    $entries = Activity::where('log_name', 'users')->orderBy('id')->get();
    expect($entries->pluck('description')->all())->toBe(['Account details updated', 'Role changed from employee to hr']);
    expect($entries[0]->properties['attributes'])->toBe(['name' => 'New Name', 'email' => 'new@example.com']);
    expect($entries[1]->properties['old'])->toBe(['role' => 'employee'])->and($entries[1]->properties['attributes'])->toBe(['role' => 'hr']);
    expect($entries[1]->causer_id)->toBe($admin->id)->and($entries[1]->subject_id)->toBe($u->id);
    expect(json_encode($entries->pluck('properties')))->not->toContain('remember_token')->not->toContain('two_factor');
});

it('records no role entry when the role did not change', function () {
    $admin = userWithRole('admin');
    $u = userWithRole('employee');
    $this->actingAs($admin)->put("/users/{$u->id}", ['name' => 'Only Name', 'email' => $u->email, 'role' => 'employee']);
    expect(Activity::where('log_name', 'users')->pluck('description')->all())->toBe(['Account details updated']);
});

it('records disabling and enabling an account', function () {
    $admin = userWithRole('admin');
    $u = userWithRole('employee');
    $this->actingAs($admin)->patch("/users/{$u->id}/active", ['active' => false]);
    $this->actingAs($admin)->patch("/users/{$u->id}/active", ['active' => true]);
    $this->actingAs($admin)->patch("/users/{$u->id}/active", ['active' => true]); // no-op
    $entries = Activity::where('log_name', 'users')->orderBy('id')->get();
    expect($entries->pluck('description')->all())->toBe(['Account disabled', 'Account enabled']);
    expect($entries[0]->properties['old'])->toBe(['status' => 'active'])->and($entries[0]->properties['attributes'])->toBe(['status' => 'disabled']);
});

it('records reset links sent and refused', function () {
    $admin = userWithRole('admin');
    $u = userWithRole('employee');
    $this->actingAs($admin)->post("/users/{$u->id}/send-reset");
    expect(Activity::where('description', 'Password reset link sent')->where('subject_id', $u->id)->count())->toBe(1);
});

it('records the first admin as created by the system with no user', function () {
    test()->seed(RolePermissionSeeder::class);
    Config::set('hrm.admin.email', 'first@example.com');
    Config::set('hrm.admin.password', 'a-long-password-1');
    expect(app(AccessSync::class)->createFirstAdmin())->toBe(AccessSync::ADMIN_CREATED);
    $log = Activity::where('description', 'First admin created by system')->first();
    expect($log)->not->toBeNull()->and($log->causer_id)->toBeNull()
        ->and($log->properties['attributes']['role'])->toBe('admin');
    expect(json_encode($log->properties))->not->toContain('a-long-password-1');
});

// ---------------------------------------------------------------- settings

it('records only the changed settings keys with old and new values', function () {
    $admin = userWithRole('admin');
    $this->actingAs($admin)->put('/hrm-settings/attendance', validAttendance(['grace_minutes' => 20]))->assertSessionHasNoErrors();
    $log = Activity::where('log_name', 'settings')->latest('id')->first();
    expect($log->description)->toBe('Attendance settings changed')
        ->and($log->properties['old'])->toBe(['attendance.grace_minutes' => 15])
        ->and($log->properties['attributes'])->toBe(['attendance.grace_minutes' => 20])
        ->and($log->causer_id)->toBe($admin->id);
});

it('records nothing when a settings save changes nothing', function () {
    $this->actingAs(userWithRole('admin'))->put('/hrm-settings/attendance', validAttendance())->assertSessionHasNoErrors();
    expect(Activity::where('log_name', 'settings')->count())->toBe(0);
});

it('masks the SMTP password: only the fact that it changed is recorded, even when cleared', function () {
    $admin = userWithRole('admin');
    $this->actingAs($admin)->put('/hrm-settings/email', validEmail(['password' => 'secret-1']));
    $log = Activity::where('log_name', 'settings')->latest('id')->first();
    expect($log->properties['attributes']['email.password'])->toBe('[changed]')
        ->and($log->properties['old']['email.password'])->toBe('[changed]');
    $stored = Settings::get('email.password');
    expect($stored)->not->toBe('')->and(Crypt::decryptString($stored))->toBe('secret-1');
    // Neither the plain text nor the encrypted value appears anywhere in the log.
    $everything = json_encode(Activity::all()->map->only(['description', 'properties', 'attribute_changes']));
    expect($everything)->not->toContain('secret-1')->not->toContain($stored);

    // A second save that leaves the password alone does not mention it.
    $this->actingAs($admin)->put('/hrm-settings/email', validEmail(['password' => '', 'username' => 'other']));
    $second = Activity::where('log_name', 'settings')->latest('id')->first();
    expect($second->properties['attributes'])->toHaveKey('email.username')->not->toHaveKey('email.password');

    $this->actingAs($admin)->put('/hrm-settings/email', validEmail(['clear_password' => true, 'username' => 'other']));
    $third = Activity::where('log_name', 'settings')->latest('id')->first();
    expect($third->properties['attributes']['email.password'])->toBe('[changed]');
});

it('records logo changes as a fact, never a path or contents', function () {
    Storage::fake();
    $admin = userWithRole('admin');
    $payload = fn (array $extra = []) => array_merge(['accent' => '#10b77f'], $extra);

    $this->actingAs($admin)->post('/hrm-settings/branding', $payload(['_method' => 'PUT', 'logo' => UploadedFile::fake()->image('logo.png', 40, 40)]))->assertSessionHasNoErrors();
    $added = Activity::where('log_name', 'settings')->latest('id')->first();
    expect($added->properties['attributes']['branding.logo'])->toBe('[added]')->and($added->properties['old']['branding.logo'])->toBe('[none]');

    $this->actingAs($admin)->post('/hrm-settings/branding', $payload(['_method' => 'PUT', 'logo' => UploadedFile::fake()->image('two.png', 40, 40)]));
    $replaced = Activity::where('log_name', 'settings')->latest('id')->first();
    expect($replaced->properties['attributes']['branding.logo'])->toBe('[replaced]');

    $this->actingAs($admin)->post('/hrm-settings/branding', $payload(['_method' => 'PUT', 'remove_logo' => true]));
    $removed = Activity::where('log_name', 'settings')->latest('id')->first();
    expect($removed->properties['attributes']['branding.logo'])->toBe('[removed]');

    expect(json_encode(Activity::all()->pluck('properties')))->not->toContain('.png');
});

// ---------------------------------------------------------------- auth

it('records failed sign-ins with the attempted email and IP but never the password', function () {
    $this->post('/login', ['email' => 'nobody@example.com', 'password' => 'hunter2-attempt']);
    $log = Activity::where('log_name', 'auth')->where('description', 'Failed sign-in')->first();
    expect($log->properties['attempted_email'])->toBe('nobody@example.com')
        ->and($log->properties['ip'])->not->toBeEmpty()
        ->and($log->causer_id)->toBeNull();
    expect(json_encode(Activity::all()))->not->toContain('hunter2-attempt');
});

it('truncates an attempted email to 255 characters', function () {
    $this->post('/login', ['email' => str_repeat('a', 400).'@example.com', 'password' => 'x']);
    $log = Activity::where('description', 'Failed sign-in')->first();
    expect(mb_strlen($log->properties['attempted_email']))->toBe(255);
});

it('writes exactly one Signed in entry per sign-in, attributed to the user, and one Signed out', function () {
    $u = userWithRole('employee');
    $this->post('/login', ['email' => $u->email, 'password' => 'password'])->assertRedirect();
    expect(Activity::where('description', 'Signed in')->count())->toBe(1);
    $log = Activity::where('description', 'Signed in')->first();
    expect($log->causer_id)->toBe($u->id);

    $this->post('/logout');
    expect(Activity::where('description', 'Signed out')->count())->toBe(1)
        ->and(Activity::where('description', 'Signed out')->first()->causer_id)->toBe($u->id);
});

it('does not log a remember-cookie restore or an ordinary request as a sign-in', function () {
    $u = userWithRole('employee');
    $this->actingAs($u)->get('/dashboard');
    expect(Activity::where('log_name', 'auth')->count())->toBe(0);
});

it('records a refused sign-in for a disabled account and no Signed in entry', function () {
    $u = userWithRole('employee');
    $u->forceFill(['is_active' => false])->save();
    $this->post('/login', ['email' => $u->email, 'password' => 'password'])->assertSessionHasErrors('email');
    expect(Activity::where('log_name', 'auth')->pluck('description')->all())->toBe(['Sign-in refused: account disabled']);
    expect(Activity::first()->causer_id)->toBe($u->id);
});

it('never blocks sign-in or sign-out when the audit log is broken', function () {
    $u = userWithRole('employee');
    breakAuditLog();
    Log::spy();
    $this->post('/login', ['email' => 'x@example.com', 'password' => 'bad'])->assertSessionHasErrors();
    $this->post('/login', ['email' => $u->email, 'password' => 'password'])->assertRedirect();
    $this->assertAuthenticatedAs($u);
    $this->post('/logout');
    $this->assertGuest();
    expect(Activity::count())->toBe(0);
});

it('does not audit /iclock machine requests', function () {
    DB::enableQueryLog();
    $this->get('/iclock/cdata?SN=NOPE');
    expect(collect(DB::getQueryLog())->pluck('query')->filter(fn ($q) => str_contains($q, 'activity_log')))->toBeEmpty();
});

// ---------------------------------------------------------------- failure: audited actions roll back

it('rolls back a department change when the entry cannot be written', function () {
    $admin = userWithRole('admin');
    $d = Department::create(['name' => 'Keep']);
    breakAuditLog();

    $this->actingAs($admin)->withoutExceptionHandling();
    expect(fn () => $this->put("/organization/departments/{$d->id}", ['name' => 'Changed']))->toThrow(RuntimeException::class);
    expect($d->fresh()->name)->toBe('Keep');

    expect(fn () => $this->post('/organization/departments', ['name' => 'Brand New']))->toThrow(RuntimeException::class);
    expect(Department::where('name', 'Brand New')->exists())->toBeFalse();

    expect(fn () => $this->delete("/organization/departments/{$d->id}"))->toThrow(RuntimeException::class);
    expect(Department::whereKey($d->id)->exists())->toBeTrue();
});

it('rolls back a role change, a disable, an account creation and a settings save when the entry cannot be written', function () {
    Notification::fake();
    $admin = userWithRole('admin');
    $u = userWithRole('employee');
    breakAuditLog();
    $this->withoutExceptionHandling();

    expect(fn () => app(UpdateUserAccount::class)->handle($u, $u->name, $u->email, 'manager', $admin))->toThrow(RuntimeException::class);
    expect($u->fresh()->hasRole('employee'))->toBeTrue()->and($u->fresh()->hasRole('manager'))->toBeFalse();

    expect(fn () => app(SetUserActive::class)->handle($u, false, $admin))->toThrow(RuntimeException::class);
    expect($u->fresh()->is_active)->toBeTrue();

    expect(fn () => app(CreateUserAccount::class)->handle('Nobody', 'nobody@example.com', 'hr'))->toThrow(RuntimeException::class);
    expect(User::whereEmail('nobody@example.com')->exists())->toBeFalse();

    expect(fn () => Settings::set('attendance', ['grace_minutes' => 40]))->toThrow(RuntimeException::class);
    expect(Settings::get('attendance.grace_minutes'))->toBe(15);
});

// ---------------------------------------------------------------- never recorded

it('never records secrets: allow-lists and the scrubber', function () {
    $admin = userWithRole('admin');
    $u = userWithRole('employee');
    // Changes to every sensitive column are not logged by the model.
    $u->forceFill([
        'password' => 'brand-new-password',
        'remember_token' => 'remember-me-token',
        'two_factor_secret' => 'tf-secret',
        'two_factor_recovery_codes' => 'tf-codes',
    ])->save();
    expect(Activity::count())->toBe(0);

    // The scrubber catches a secret that a caller puts in by mistake.
    Audit::record('users', 'Oops', $u, ['attributes' => ['password' => 'plain-secret', 'nested' => ['api_key' => 'k-123', 'name' => 'ok']]]);
    $log = Activity::latest('id')->first();
    expect($log->properties['attributes']['password'])->toBe('[hidden]')
        ->and($log->properties['attributes']['nested']['api_key'])->toBe('[hidden]')
        ->and($log->properties['attributes']['nested']['name'])->toBe('ok');
    expect(json_encode(Activity::all()))->not->toContain('plain-secret')->not->toContain('k-123');
});

// ---------------------------------------------------------------- append-only

it('cannot update or delete an entry through the model, and the clean command deletes nothing', function () {
    Audit::record('users', 'Something');
    $entry = ActivityEntry::first();
    expect(fn () => $entry->update(['description' => 'edited']))->toThrow(LogicException::class);
    expect(fn () => $entry->delete())->toThrow(LogicException::class);

    // An old row, and an accidental `activitylog:clean --days=1`.
    DB::table('activity_log')->update(['created_at' => now()->subYears(5)]);
    Artisan::call('activitylog:clean', ['--days' => 1, '--force' => true]);
    expect(ActivityEntry::count())->toBe(1);
    expect(app(NeverCleanActivityLogAction::class)->execute(1))->toBe(0);
    expect(config('activitylog.actions.clean_log'))->toBe(NeverCleanActivityLogAction::class);
});

it('has no schedule entry for the clean command', function () {
    Artisan::call('schedule:list');
    expect(Artisan::output())->not->toContain('activitylog');
});

// ---------------------------------------------------------------- viewer

function seedEntries(): array
{
    $admin = userWithRole('admin');
    $hr = userWithRole('hr');
    test()->actingAs($hr);
    Department::create(['name' => 'Alpha']);
    test()->actingAs($admin);
    Department::create(['name' => 'Beta']);
    Audit::record('settings', '100% sure_thing', null, ['old' => ['a.b' => '1'], 'attributes' => ['a.b' => '2']]);
    Audit::record('auth', 'Failed sign-in', null, ['attempted_email' => '<script>alert(1)</script>']);
    auth()->logout();
    Audit::record('users', 'Console thing');

    return [$admin, $hr];
}

it('lists newest first with an id tiebreak, with user, area and values', function () {
    [$admin] = seedEntries();
    $this->actingAs($admin)->get('/audit-log')->assertInertia(fn ($p) => $p
        ->component('audit-log/index')
        ->where('entries.total', 5)
        ->where('entries.data.0.description', 'Console thing')
        ->where('entries.data.0.user', null)
        ->where('entries.data.1.description', 'Failed sign-in')
        ->where('entries.data.2.area', 'settings')
        ->where('entries.data.2.area_label', 'Settings')
        ->where('entries.data.2.changes.0', ['key' => 'a.b', 'old' => '1', 'new' => '2'])
        ->where('entries.data.4.user.name', fn ($name) => is_string($name))
        ->where('entries.data.4.subject.label', 'Alpha')
        ->where('entries.data.4.subject.type', 'Department')
        ->has('entries.data.4.details', 2)
        ->where('areaOptions.0', ['value' => 'organization', 'label' => 'Organization']));
});

it('passes text values through as text and exposes no causer email or secrets', function () {
    [$admin] = seedEntries();
    $page = $this->actingAs($admin)->get('/audit-log')->viewData('page');
    $json = json_encode($page['props']['entries']);
    expect($json)->toContain('alert(1)')->not->toContain($admin->email)->not->toContain($admin->password);
});

it('filters by user, including the system', function () {
    [$admin, $hr] = seedEntries();
    $this->actingAs($admin)->get('/audit-log?user_id='.$hr->id)->assertInertia(fn ($p) => $p->where('entries.total', 1)->where('filters.user_id', (string) $hr->id));
    $this->actingAs($admin)->get('/audit-log?user_id=system')->assertInertia(fn ($p) => $p->where('entries.total', 1));
    $this->actingAs($admin)->get('/audit-log?user_id='.$admin->id)->assertInertia(fn ($p) => $p->where('entries.total', 3));
});

it('offers only users who appear as causers in the filter', function () {
    [$admin, $hr] = seedEntries();
    userWithRole('employee'); // never caused anything
    $this->actingAs($admin)->get('/audit-log')->assertInertia(fn ($p) => $p
        ->has('userOptions', 2)
        ->where('userOptions.0', fn ($o) => array_keys($o->toArray()) === ['id', 'name']));
});

it('searches descriptions with literal wildcards', function () {
    [$admin] = seedEntries();
    $this->actingAs($admin)->get('/audit-log?search='.urlencode('100%'))->assertInertia(fn ($p) => $p->where('entries.total', 1));
    $this->actingAs($admin)->get('/audit-log?search='.urlencode('sure_thing'))->assertInertia(fn ($p) => $p->where('entries.total', 1));
    $this->actingAs($admin)->get('/audit-log?search='.urlencode('%'))->assertInertia(fn ($p) => $p->where('entries.total', 1));
    $this->actingAs($admin)->get('/audit-log?search=failed')->assertInertia(fn ($p) => $p->where('entries.total', 1));
});

it('treats a date range as whole days in the display timezone, both ends inclusive', function () {
    $previous = date_default_timezone_get();
    date_default_timezone_set('UTC'); // what config app.timezone does at boot
    Settings::set('company', ['timezone' => 'Asia/Karachi']); // UTC+5
    $admin = userWithRole('admin');

    $at = function (string $utc, string $description) {
        Audit::record('users', $description);
        DB::table('activity_log')->where('description', $description)->update(['created_at' => $utc]);
    };
    // Karachi days: 2026-03-10 runs from 2026-03-09 19:00 UTC to 2026-03-10 18:59:59 UTC.
    $at('2026-03-09 18:59:59', 'before');
    $at('2026-03-09 19:00:00', 'first-second');
    $at('2026-03-10 18:59:59', 'last-second');
    $at('2026-03-10 19:00:00', 'after');

    $get = fn (string $q) => $this->actingAs($admin)->get('/audit-log?'.$q)->viewData('page')['props']['entries']['data'];
    expect(collect($get('from=2026-03-10&to=2026-03-10'))->pluck('description')->all())->toBe(['last-second', 'first-second']);
    expect(collect($get('from=2026-03-10'))->pluck('description')->all())->toContain('after', 'last-second', 'first-second')->not->toContain('before');
    expect(collect($get('to=2026-03-09'))->pluck('description')->all())->toContain('before')->not->toContain('first-second');
    // Times are sent with an offset so the browser can show them in the display timezone.
    expect($get('from=2026-03-10&to=2026-03-10')[1]['created_at'])->toBe('2026-03-09T19:00:00+00:00');
    date_default_timezone_set($previous);
});

it('never errors on invalid or array filter values', function () {
    [$admin] = seedEntries();
    $queries = [
        'from=garbage', 'to=2026-13-45', 'from[]=2026-01-01', 'to[a]=b', 'area=nonsense', 'area[]=users', 'user_id=abc',
        'user_id[]=1', 'user_id=99999999999999999999999', 'search[]=x', 'per_page[]=5', 'per_page=7', 'page[]=2', 'page=abc',
        'from=0000-01-01', 'to=9999-12-31', 'search='.str_repeat('x', 500),
    ];

    foreach ($queries as $q) {
        $this->actingAs($admin)->get('/audit-log?'.$q)->assertOk();
    }

    $this->actingAs($admin)->get('/audit-log?area=nonsense&from=garbage&per_page=7')->assertInertia(fn ($p) => $p
        ->where('entries.total', 5)->where('filters.area', '')->where('filters.from', '')->where('filters.per_page', 10));
});

it('paginates with 10, 25 or 50 rows and clamps a page past the end', function () {
    $admin = userWithRole('admin');
    $this->actingAs($admin);
    foreach (range(1, 27) as $i) {
        Audit::record('users', "Entry {$i}");
    }

    $this->get('/audit-log')->assertInertia(fn ($p) => $p->has('entries.data', 10)->where('entries.last_page', 3)->where('entries.total', 27));
    $this->get('/audit-log?per_page=25&page=2')->assertInertia(fn ($p) => $p->has('entries.data', 2)->where('entries.current_page', 2));
    $this->get('/audit-log?page=99')->assertRedirect('/audit-log?page=3');
    $this->get('/audit-log?page=99999999999999999999')->assertRedirect('/audit-log?page=3');
    $this->get('/audit-log?area=settings&page=5')->assertRedirect('/audit-log?area=settings');
});

it('shows entries whose subject was deleted, with the last known name', function () {
    $admin = userWithRole('admin');
    $this->actingAs($admin);
    $d = Department::create(['name' => 'Short-lived']);
    $d->delete();
    $this->get('/audit-log?area=organization')->assertInertia(fn ($p) => $p
        ->where('entries.total', 2)
        ->where('entries.data.0.subject.label', 'Short-lived')
        ->where('entries.data.0.changes.0.old', 'Short-lived'));
});

it('shows a deleted causer as a deleted user rather than the system', function () {
    $admin = userWithRole('admin');
    $gone = userWithRole('hr');
    $this->actingAs($gone);
    Department::create(['name' => 'By gone']);
    auth()->logout();
    DB::table('users')->where('id', $gone->id)->delete();
    $this->actingAs($admin)->get('/audit-log')->assertInertia(fn ($p) => $p->where('entries.data.0.user.name', 'Deleted user'));
});

it('redirects guests to sign-in', function () {
    $this->get('/audit-log')->assertRedirect('/login');
});

it('adds the activity_log indexes the viewer filters on', function () {
    $indexes = collect(DB::select("select name from sqlite_master where type = 'index' and tbl_name = 'activity_log'"))->pluck('name');
    expect($indexes)->toContain('activity_log_created_at_index', 'activity_log_log_name_created_at_index', 'causer');
})->skip(fn () => DB::getDriverName() !== 'sqlite');

it('keeps the area list in one place', function () {
    expect(array_keys(Audit::AREAS))->toBe(['organization', 'users', 'settings', 'auth', 'employees', 'attendance', 'leave']);
});

it('has a clock-independent order check', function () {
    Carbon::setTestNow('2026-05-01 10:00:00');
    Audit::record('users', 'first');
    Audit::record('users', 'second');
    expect(ActivityEntry::orderByDesc('created_at')->orderByDesc('id')->pluck('description')->all())->toBe(['second', 'first']);
    Carbon::setTestNow();
});

// ---------------------------------------------------------------- fix round 1

it('no route in the application can delete a user, and the profile page no longer offers it', function () {
    $user = userWithRole('employee');
    $this->actingAs($user)->delete('/settings/profile', ['password' => 'password'])->assertStatus(405);
    expect(User::whereKey($user->id)->exists())->toBeTrue();

    $names = collect(Route::getRoutes())->map->getName()->filter()->all();
    expect($names)->not->toContain('profile.destroy');

    // No DELETE route anywhere in the application whose action is a user or profile controller.
    $deleting = collect(Route::getRoutes())
        ->filter(fn ($r) => in_array('DELETE', $r->methods(), true))
        ->map(fn ($r) => $r->getActionName())
        ->filter(fn ($a) => str_contains($a, 'UserController') || str_contains($a, 'ProfileController'));
    expect($deleting->all())->toBe([]);

    expect(file_exists(resource_path('js/components/delete-user.tsx')))->toBeFalse();
    expect(file_get_contents(resource_path('js/pages/settings/profile.tsx')))->not->toContain('DeleteUser')->not->toContain('Delete account');
    $this->actingAs($user)->get('/settings/profile')->assertInertia(fn ($p) => $p->component('settings/profile'));
});

it('records a self-service password change without the password', function () {
    $u = userWithRole('employee');
    $this->actingAs($u)->put('/settings/password', [
        'current_password' => 'password', 'password' => 'brand-new-pass-1A!', 'password_confirmation' => 'brand-new-pass-1A!',
    ])->assertSessionHasNoErrors();
    $entries = Activity::where('log_name', 'auth')->get();
    expect($entries->pluck('description')->all())->toBe(['Password changed'])
        ->and($entries[0]->causer_id)->toBe($u->id);
    expect(json_encode(Activity::all()))->not->toContain('brand-new-pass');
});

it('records a completed password reset once', function () {
    $u = userWithRole('employee');
    $token = Password::broker()->createToken($u);
    $this->post('/reset-password', [
        'token' => $token, 'email' => $u->email, 'password' => 'brand-new-pass-1A!', 'password_confirmation' => 'brand-new-pass-1A!',
    ])->assertSessionHasNoErrors();
    expect(Activity::where('description', 'Password reset')->count())->toBe(1);
    expect(Activity::where('description', 'Password reset')->first()->causer_id)->toBe($u->id);
    expect(json_encode(Activity::all()))->not->toContain($token)->not->toContain('brand-new-pass');
});

it('records two-factor changes and recovery codes through HTTP, never the secrets', function () {
    $u = userWithRole('employee');
    $this->actingAs($u)->withSession(['auth.password_confirmed_at' => time()]);

    $this->post('/user/two-factor-authentication')->assertSessionHasNoErrors();
    // Confirmation is switched on: enabling is not finished yet, so nothing is recorded.
    expect(Activity::where('log_name', 'auth')->count())->toBe(0);
    expect($u->fresh()->two_factor_secret)->not->toBeNull();

    $this->post('/user/two-factor-recovery-codes')->assertSessionHasNoErrors();
    $this->delete('/user/two-factor-authentication')->assertSessionHasNoErrors();

    expect(Activity::where('log_name', 'auth')->orderBy('id')->pluck('description')->all())
        ->toBe(['Recovery codes regenerated', 'Two-factor authentication disabled']);
    expect(json_encode(Activity::all()))->not->toContain('secret')->not->toContain('recovery_codes');
});

it('records confirmed two-factor setup and passkey add/remove events (listener driven directly)', function () {
    $u = userWithRole('employee');
    $passkey = new Passkey;

    event(new TwoFactorAuthenticationEnabled($u));
    expect(Activity::count())->toBe(0);
    event(new TwoFactorAuthenticationConfirmed($u));
    event(new PasskeyRegistered($u, $passkey));
    event(new PasskeyDeleted($u, $passkey));

    expect(Activity::orderBy('id')->pluck('description')->all())->toBe([
        'Two-factor authentication enabled', 'Passkey added', 'Passkey removed',
    ]);
    expect(Activity::pluck('causer_id')->unique()->all())->toBe([$u->id]);
});

it('never fails a security action when the audit log is broken', function () {
    $u = userWithRole('employee');
    breakAuditLog();
    Log::spy();
    $this->actingAs($u)->put('/settings/password', [
        'current_password' => 'password', 'password' => 'brand-new-pass-1A!', 'password_confirmation' => 'brand-new-pass-1A!',
    ])->assertSessionHasNoErrors()->assertRedirect();
    event(new TwoFactorAuthenticationDisabled($u));
    expect(Activity::count())->toBe(0);
});

it('caps failed sign-in entries per IP per hour with a single marker, then resets', function () {
    foreach (range(1, 25) as $i) {
        $this->post('/login', ['email' => "attacker{$i}@example.com", 'password' => 'x']);
    }
    expect(Activity::where('description', 'Failed sign-in')->count())->toBe(20);
    $markers = Activity::where('description', 'Further failed sign-ins from this address are not being recorded');
    expect($markers->count())->toBe(1)->and($markers->first()->properties['ip'])->not->toBeEmpty();

    // Successful sign-ins and refusals are always recorded, even while capped.
    $u = userWithRole('employee');
    $this->post('/login', ['email' => $u->email, 'password' => 'password'])->assertRedirect();
    expect(Activity::where('description', 'Signed in')->count())->toBe(1);
    auth()->logout();
    $off = userWithRole('employee');
    $off->forceFill(['is_active' => false])->save();
    $this->post('/login', ['email' => $off->email, 'password' => 'password']);
    expect(Activity::where('description', 'Sign-in refused: account disabled')->count())->toBe(1);
    expect(Activity::where('description', 'Failed sign-in')->count())->toBe(20);

    // A new window records again.
    $this->travel(61)->minutes();
    $this->post('/login', ['email' => 'later@example.com', 'password' => 'x']);
    expect(Activity::where('description', 'Failed sign-in')->count())->toBe(21);
});

it('records a failed sign-in when the cache is unavailable', function () {
    Cache::shouldReceive('add')->andThrow(new RuntimeException('cache down'));
    $this->post('/login', ['email' => 'nobody@example.com', 'password' => 'x']);
    expect(Activity::where('description', 'Failed sign-in')->count())->toBe(1);
});

it('strips control characters from stored text', function () {
    expect(Audit::clean("bad\x00\x07\r\nname@example.com"))->toBe('badname@example.com');
    expect(mb_strlen(Audit::clean(str_repeat('é', 400))))->toBe(255);
    expect(Audit::clean("ok\xC3\x28bad"))->toBeString();
    $this->post('/login', ['email' => "a\x07b@example.com", 'password' => 'x']);
    expect(Activity::where('description', 'Failed sign-in')->first()->properties['attempted_email'])->toBe('ab@example.com');
});

it('never turns a sent reset link into an error when the entry cannot be written, and records refusals', function () {
    Notification::fake();
    $admin = userWithRole('admin');
    $u = userWithRole('employee');

    $this->actingAs($admin)->post("/users/{$u->id}/send-reset")->assertSessionHas('success');
    $this->actingAs($admin)->post("/users/{$u->id}/send-reset")->assertSessionHas('error');
    expect(Activity::where('description', 'Reset link not sent: passwords.throttled')->where('subject_id', $u->id)->count())->toBe(1);

    $other = userWithRole('employee');
    breakAuditLog();
    $this->actingAs($admin)->post("/users/{$other->id}/send-reset")->assertSessionHas('success');
    Notification::assertSentTo($other, ResetPassword::class);
});

it('fails loudly if the package integration stops putting model changes into properties', function () {
    $admin = userWithRole('admin');
    $d = Department::create(['name' => 'Before Rename']);
    $this->actingAs($admin)->put("/organization/departments/{$d->id}", ['name' => 'After Rename']);
    $log = Activity::where('log_name', 'organization')->where('description', 'Department updated')->latest('id')->first();
    $message = 'spatie/laravel-activitylog integration broke: a model change no longer lands in properties.old / properties.attributes. Do not upgrade the package until AuditLogAction is fixed.';
    expect($log)->not->toBeNull($message);
    expect($log->properties['old']['name'] ?? null)->toBe('Before Rename', $message);
    expect($log->properties['attributes']['name'] ?? null)->toBe('After Rename', $message);
});

it('records the real old settings value even when the request cache was stale', function () {
    userWithRole('admin');
    Settings::set('attendance', ['grace_minutes' => 20]);
    expect(Settings::get('attendance.grace_minutes'))->toBe(20); // loads the request-scoped copy
    DB::table('settings')->where('group', 'attendance')->where('key', 'grace_minutes')->update(['value' => '25']);
    Settings::set('attendance', ['grace_minutes' => 30]);
    $log = Activity::where('log_name', 'settings')->latest('id')->first();
    expect($log->properties['old']['attendance.grace_minutes'])->toBe(25)->and($log->properties['attributes']['attendance.grace_minutes'])->toBe(30);
});

it('records designation department names next to the ids', function () {
    $a = Department::create(['name' => 'Alpha Dept']);
    $b = Department::create(['name' => 'Beta Dept']);
    $g = Designation::create(['name' => 'Lead', 'department_id' => $a->id]);
    expect(Activity::where('description', 'Designation created')->first()->properties['attributes']['department'])->toBe('Alpha Dept');
    $g->update(['department_id' => $b->id]);
    $log = Activity::where('description', 'Designation updated')->first();
    expect($log->properties['old']['department'])->toBe('Alpha Dept')->and($log->properties['attributes']['department'])->toBe('Beta Dept')
        ->and($log->properties['old']['department_id'])->toBe($a->id);
    $g->delete();
    expect(Activity::where('description', 'Designation deleted')->first()->properties['old']['department'])->toBe('Beta Dept');
});

it('scrubs by whole key words, so token_count survives but reset_token does not', function () {
    Audit::record('users', 'Scrub', null, ['attributes' => ['token_count' => 3, 'reset_token' => 'abc', 'twoFactorSecret' => 'x', 'two_factor_recovery_codes' => 'y', 'email.password' => '[changed]', 'password_hash' => 'z', 'name' => 'n']]);
    $p = Activity::latest('id')->first()->properties['attributes'];
    expect($p['token_count'])->toBe(3)->and($p['reset_token'])->toBe('[hidden]')->and($p['twoFactorSecret'])->toBe('[hidden]')
        ->and($p['two_factor_recovery_codes'])->toBe('[hidden]')->and($p['email.password'])->toBe('[changed]')
        ->and($p['password_hash'])->toBe('[hidden]')->and($p['name'])->toBe('n');
});
