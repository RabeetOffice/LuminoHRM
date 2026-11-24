<?php

use App\Actions\CreateUserAccount;
use App\Actions\SetUserActive;
use App\Actions\UpdateUserAccount;
use App\Exceptions\AccountRuleViolation;
use App\Models\User;
use App\Notifications\SetPasswordNotification;
use App\Support\LastAdminGuard;
use App\Support\Settings;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Route;

// ---------------------------------------------------------------- brief tests

it('lets only admin open the users screen', function (string $role) {
    $this->actingAs(userWithRole($role))->get('/users')->assertForbidden();
})->with(['hr', 'manager', 'employee']);

it('creates a user with a role and emails a set-password link, never a password', function () {
    Notification::fake();
    $this->actingAs(userWithRole('admin'))->post('/users', ['name' => 'Sara Ahmed', 'email' => 'sara@example.com', 'role' => 'hr'])->assertSessionHasNoErrors();
    $u = User::whereEmail('sara@example.com')->first();
    expect($u->hasRole('hr'))->toBeTrue();
    Notification::assertSentTo($u, SetPasswordNotification::class);
});

it('rejects an unknown role and a duplicate email', function () {
    $admin = userWithRole('admin');
    $this->actingAs($admin)->post('/users', ['name' => 'X', 'email' => 'x@example.com', 'role' => 'superuser'])->assertSessionHasErrors('role');
    $this->actingAs($admin)->post('/users', ['name' => 'X', 'email' => $admin->email, 'role' => 'hr'])->assertSessionHasErrors('email');
});

it('will not demote or disable the last active admin', function () {
    $admin = userWithRole('admin');
    $this->actingAs($admin)->put("/users/{$admin->id}", ['name' => $admin->name, 'email' => $admin->email, 'role' => 'hr'])
        ->assertSessionHas('error', 'There must be at least one active Admin.');
    $this->actingAs($admin)->patch("/users/{$admin->id}/active", ['active' => false])->assertSessionHas('error');
    expect($admin->fresh()->hasRole('admin'))->toBeTrue()->and($admin->fresh()->is_active)->toBeTrue();
});

it('allows demoting an admin when another active admin exists', function () {
    $a = userWithRole('admin');
    $b = userWithRole('admin');
    $this->actingAs($a)->put("/users/{$b->id}", ['name' => $b->name, 'email' => $b->email, 'role' => 'manager'])->assertSessionHasNoErrors();
    expect($b->fresh()->hasRole('manager'))->toBeTrue()->and($b->fresh()->roles)->toHaveCount(1);
});

it('disables and re-enables a user', function () {
    $u = userWithRole('employee');
    $this->actingAs(userWithRole('admin'))->patch("/users/{$u->id}/active", ['active' => false]);
    expect($u->fresh()->is_active)->toBeFalse();
    $this->actingAs(userWithRole('admin'))->patch("/users/{$u->id}/active", ['active' => true]);
    expect($u->fresh()->is_active)->toBeTrue();
});

// -------------------------------------------------------------- authorisation

it('forbids hr, manager and employee on every users route and changes nothing', function (string $role) {
    Notification::fake();
    $actor = userWithRole($role);
    $target = userWithRole('employee');

    $this->actingAs($actor)->post('/users', ['name' => 'New', 'email' => 'new@example.com', 'role' => 'hr'])->assertForbidden();
    $this->actingAs($actor)->put("/users/{$target->id}", ['name' => 'Changed', 'email' => 'c@example.com', 'role' => 'admin'])->assertForbidden();
    $this->actingAs($actor)->patch("/users/{$target->id}/active", ['active' => false])->assertForbidden();
    $this->actingAs($actor)->post("/users/{$target->id}/send-reset")->assertForbidden();

    expect(User::whereEmail('new@example.com')->exists())->toBeFalse()
        ->and($target->fresh()->name)->toBe($target->name)
        ->and($target->fresh()->is_active)->toBeTrue()
        ->and($target->fresh()->hasRole('employee'))->toBeTrue();
    Notification::assertNothingSent();
})->with(['hr', 'manager', 'employee']);

it('redirects guests to the sign-in page', function () {
    $this->get('/users')->assertRedirect('/login');
    $this->post('/users', [])->assertRedirect('/login');
    $this->patch('/users/1/active')->assertRedirect('/login');
});

it('has no delete route under /users: accounts are disabled, never removed', function () {
    $deletes = collect(Route::getRoutes()->getRoutes())->filter(
        fn ($r) => in_array('DELETE', $r->methods(), true) && str_starts_with(ltrim($r->uri(), '/'), 'users'),
    );
    expect($deletes)->toHaveCount(0);

    $u = userWithRole('employee');
    $this->actingAs(userWithRole('admin'))->delete("/users/{$u->id}")->assertStatus(405);
    expect(User::find($u->id))->not->toBeNull();
});

it('names its routes as the brief requires', function () {
    expect(route('users.index', [], false))->toBe('/users')
        ->and(route('users.store', [], false))->toBe('/users')
        ->and(route('users.update', 5, false))->toBe('/users/5')
        ->and(route('users.toggle', 5, false))->toBe('/users/5/active')
        ->and(route('users.send-reset', 5, false))->toBe('/users/5/send-reset');
});

// --------------------------------------------------------------------- create

it('creates an active, verified account with one role, an unusable password and a queued link', function () {
    Notification::fake();
    $this->actingAs(userWithRole('admin'))->post('/users', ['name' => 'Sara Ahmed', 'email' => 'sara@example.com', 'role' => 'manager'])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success');

    $u = User::whereEmail('sara@example.com')->first();
    expect($u->is_active)->toBeTrue()
        ->and($u->email_verified_at)->not->toBeNull()
        ->and($u->roles)->toHaveCount(1)
        ->and($u->hasRole('manager'))->toBeTrue()
        ->and($u->password)->not->toBe('')
        ->and(strlen($u->password))->toBeGreaterThan(40);

    Notification::assertSentTo($u, SetPasswordNotification::class, fn ($n) => $n instanceof ShouldQueue);
    Notification::assertSentToTimes($u, SetPasswordNotification::class, 1);
});

it('gives each new account a different random password that the admin never sees', function () {
    Notification::fake();
    $a = app(CreateUserAccount::class)->handle('A One', 'a1@example.com', 'employee');
    $b = app(CreateUserAccount::class)->handle('B Two', 'b2@example.com', 'employee');

    expect($a->password)->not->toBe($b->password)
        ->and(Hash::check('password', $a->password))->toBeFalse();

    $response = $this->actingAs(userWithRole('admin'))->post('/users', ['name' => 'C Three', 'email' => 'c3@example.com', 'role' => 'hr']);
    $response->assertSessionMissing('password');
    expect(json_encode(session()->all()))->not->toContain(User::whereEmail('c3@example.com')->first()->password);
});

it('lets the new user set a password through the emailed link and then sign in', function () {
    Notification::fake();
    app(CreateUserAccount::class)->handle('Sara Ahmed', 'sara@example.com', 'employee');
    $u = User::whereEmail('sara@example.com')->first();

    $token = null;
    Notification::assertSentTo($u, SetPasswordNotification::class, function ($n) use (&$token) {
        $token = $n->token;

        return true;
    });

    $this->post('/reset-password', [
        'token' => $token, 'email' => 'sara@example.com',
        'password' => 'A-Strong-Passw0rd!x', 'password_confirmation' => 'A-Strong-Passw0rd!x',
    ])->assertSessionHasNoErrors();

    $this->post('/login', ['email' => 'sara@example.com', 'password' => 'A-Strong-Passw0rd!x'])->assertSessionHasNoErrors();
    $this->assertAuthenticatedAs($u->fresh());
    $this->get('/dashboard')->assertOk();
});

it('words the mail with the company name, the link and the broker expiry', function () {
    Settings::set('company', ['name' => 'Acme Ltd']);
    config(['auth.passwords.users.expire' => 45]);
    $u = User::factory()->create(['email' => 'sara@example.com']);

    $mail = (new SetPasswordNotification('tok123'))->toMail($u);
    $text = implode("\n", [...$mail->introLines, $mail->actionText, ...$mail->outroLines]);

    expect($mail->subject)->toBe('Set your Lumino HRM password')
        ->and($text)->toContain('Acme Ltd')
        ->and($text)->toContain('45 minutes')
        ->and($mail->actionUrl)->toContain('/reset-password/tok123')
        ->and($mail->actionUrl)->toContain('email=sara%40example.com');
});

it('normalises the email to lowercase and trims it, and rejects a duplicate in any case', function () {
    Notification::fake();
    $admin = userWithRole('admin');
    $this->actingAs($admin)->post('/users', ['name' => '  Sara   Ahmed ', 'email' => '  Sara@Example.COM ', 'role' => 'hr'])->assertSessionHasNoErrors();

    $u = User::whereEmail('sara@example.com')->first();
    expect($u)->not->toBeNull()->and($u->email)->toBe('sara@example.com')->and($u->name)->toBe('Sara Ahmed');

    $this->actingAs($admin)->post('/users', ['name' => 'Other', 'email' => 'SARA@example.com', 'role' => 'hr'])->assertSessionHasErrors('email');
    expect(User::count())->toBe(2);
});

it('returns validation errors, not server errors, for missing and array-valued fields', function () {
    $admin = userWithRole('admin');
    $this->actingAs($admin)->post('/users', [])->assertSessionHasErrors(['name', 'email', 'role']);
    $this->actingAs($admin)->post('/users', ['name' => ['x'], 'email' => ['y@example.com'], 'role' => ['hr']])
        ->assertSessionHasErrors(['name', 'email', 'role']);
    $this->actingAs($admin)->post('/users', ['name' => str_repeat('a', 256), 'email' => 'not-an-email', 'role' => 'hr'])
        ->assertSessionHasErrors(['name', 'email']);
    expect(User::count())->toBe(1);
});

it('never takes is_active, password, email_verified_at or roles from the request', function () {
    Notification::fake();
    $admin = userWithRole('admin');

    $this->actingAs($admin)->post('/users', [
        'name' => 'Sara', 'email' => 'sara@example.com', 'role' => 'employee',
        'is_active' => false, 'password' => 'Chosen-Passw0rd!', 'email_verified_at' => null, 'roles' => ['admin'],
    ])->assertSessionHasNoErrors();
    $u = User::whereEmail('sara@example.com')->first();
    expect($u->is_active)->toBeTrue()
        ->and(Hash::check('Chosen-Passw0rd!', $u->password))->toBeFalse()
        ->and($u->email_verified_at)->not->toBeNull()
        ->and($u->hasRole('employee'))->toBeTrue()->and($u->roles)->toHaveCount(1);

    $this->actingAs($admin)->put("/users/{$u->id}", [
        'name' => 'Sara', 'email' => 'sara@example.com', 'role' => 'employee',
        'is_active' => false, 'password' => 'Chosen-Passw0rd!', 'email_verified_at' => null,
    ])->assertSessionHasNoErrors();
    $u->refresh();
    expect($u->is_active)->toBeTrue()
        ->and(Hash::check('Chosen-Passw0rd!', $u->password))->toBeFalse()
        ->and($u->email_verified_at)->not->toBeNull();
});

it('creates accounts without an HTTP request or a signed-in user', function () {
    Notification::fake();
    expect(auth()->check())->toBeFalse();
    $this->seed(RolePermissionSeeder::class);

    $u = app(CreateUserAccount::class)->handle('  Omar  Khan ', ' Omar@Example.com ', 'hr');

    expect($u)->toBeInstanceOf(User::class)
        ->and($u->exists)->toBeTrue()
        ->and($u->email)->toBe('omar@example.com')
        ->and($u->name)->toBe('Omar Khan')
        ->and($u->hasRole('hr'))->toBeTrue();
    Notification::assertSentTo($u, SetPasswordNotification::class);
});

it('refuses an unknown role at the action level and creates nothing', function () {
    Notification::fake();
    $this->seed(RolePermissionSeeder::class);

    expect(fn () => app(CreateUserAccount::class)->handle('X', 'x@example.com', 'superuser'))->toThrow(InvalidArgumentException::class);
    expect(User::count())->toBe(0);
    Notification::assertNothingSent();
});

// --------------------------------------------------------------------- update

it('updates name, email and role, keeping exactly one role', function () {
    $admin = userWithRole('admin');
    $u = userWithRole('employee');

    $this->actingAs($admin)->put("/users/{$u->id}", ['name' => '  New   Name ', 'email' => ' New@Example.com ', 'role' => 'hr'])
        ->assertSessionHasNoErrors()->assertSessionHas('success');

    $u->refresh();
    expect($u->name)->toBe('New Name')->and($u->email)->toBe('new@example.com')
        ->and($u->roles)->toHaveCount(1)->and($u->hasRole('hr'))->toBeTrue();
});

it('does not require re-verification after an email change', function () {
    $admin = userWithRole('admin');
    $u = userWithRole('employee');
    $this->actingAs($admin)->put("/users/{$u->id}", ['name' => $u->name, 'email' => 'moved@example.com', 'role' => 'employee']);
    expect($u->fresh()->email_verified_at)->not->toBeNull();
});

it('validates the email as unique ignoring case, except for the user being edited', function () {
    $admin = userWithRole('admin');
    $u = userWithRole('employee');
    $other = userWithRole('hr');

    $this->actingAs($admin)->put("/users/{$u->id}", ['name' => $u->name, 'email' => strtoupper($other->email), 'role' => 'employee'])
        ->assertSessionHasErrors('email');
    $this->actingAs($admin)->put("/users/{$u->id}", ['name' => $u->name, 'email' => strtoupper($u->email), 'role' => 'employee'])
        ->assertSessionHasNoErrors();
    expect($u->fresh()->email)->toBe(strtolower($u->email));
});

it('rejects an unknown role and array-valued fields on update', function () {
    $admin = userWithRole('admin');
    $u = userWithRole('employee');

    $this->actingAs($admin)->put("/users/{$u->id}", ['name' => 'X', 'email' => 'x@example.com', 'role' => 'superuser'])->assertSessionHasErrors('role');
    $this->actingAs($admin)->put("/users/{$u->id}", ['name' => ['x'], 'email' => ['x@example.com'], 'role' => ['admin']])
        ->assertSessionHasErrors(['name', 'email', 'role']);
    expect($u->fresh()->hasRole('employee'))->toBeTrue();
});

// ----------------------------------------------------------- last active admin

it('keeps every change together: a refused demotion leaves the name and email untouched', function () {
    $admin = userWithRole('admin');
    $before = $admin->only(['name', 'email']);

    $this->actingAs($admin)->put("/users/{$admin->id}", ['name' => 'Renamed', 'email' => 'renamed@example.com', 'role' => 'employee'])
        ->assertSessionHas('error', 'There must be at least one active Admin.');

    expect($admin->fresh()->only(['name', 'email']))->toBe($before);
});

it('does not count a disabled admin as an active one', function () {
    $a = userWithRole('admin');
    $b = userWithRole('admin');
    $b->forceFill(['is_active' => false])->save();

    expect(fn () => app(UpdateUserAccount::class)->handle($a, 'N', 'n@example.com', 'hr', actor: $b))
        ->toThrow(AccountRuleViolation::class, 'There must be at least one active Admin.');
    expect($a->fresh()->hasRole('admin'))->toBeTrue();
});

it('enforces the last-admin rule in the actions, not only in the controller', function () {
    $admin = userWithRole('admin');

    expect(fn () => app(UpdateUserAccount::class)->handle($admin, 'N', 'n@example.com', 'employee'))
        ->toThrow(AccountRuleViolation::class, LastAdminGuard::MESSAGE);
    expect(fn () => app(SetUserActive::class)->handle($admin, false))
        ->toThrow(AccountRuleViolation::class, LastAdminGuard::MESSAGE);

    expect($admin->fresh()->hasRole('admin'))->toBeTrue()->and($admin->fresh()->is_active)->toBeTrue();
});

it('lets another caller protect its own change with the guard, which rolls everything back', function () {
    $admin = userWithRole('admin');
    $other = userWithRole('admin');

    // Another admin exists: the change runs and its result is returned.
    expect(LastAdminGuard::protect($admin, fn () => 'done'))->toBe('done');

    // Sole admin: the change never runs.
    $other->forceFill(['is_active' => false])->save();
    $ran = false;
    expect(function () use ($admin, &$ran) {
        LastAdminGuard::protect($admin, function () use (&$ran) {
            $ran = true;
        });
    })->toThrow(AccountRuleViolation::class);
    expect($ran)->toBeFalse();

    // A failure inside the change rolls the whole transaction back.
    $third = userWithRole('admin');
    expect(function () use ($third) {
        LastAdminGuard::protect($third, function () use ($third) {
            $third->forceFill(['name' => 'Half done'])->save();
            throw new RuntimeException('boom');
        });
    })->toThrow(RuntimeException::class);
    expect($third->fresh()->name)->not->toBe('Half done');
});

it('does not block demoting or disabling someone who is not an active admin', function () {
    $admin = userWithRole('admin');
    $employee = userWithRole('employee');

    app(UpdateUserAccount::class)->handle($employee, 'E', 'e@example.com', 'manager', actor: $admin);
    app(SetUserActive::class)->handle($employee, false, actor: $admin);

    expect($employee->fresh()->hasRole('manager'))->toBeTrue()->and($employee->fresh()->is_active)->toBeFalse();
});

it('uses a row lock on the admin rows on databases that support it', function () {
    $sql = User::role('admin')->where('users.is_active', true)->lockForUpdate()->toSql();
    // SQLite has no row locks (its grammar drops the clause); MariaDB and MySQL keep it.
    if (DB::getDriverName() !== 'sqlite') {
        expect($sql)->toContain('for update');
    }
    $admin = userWithRole('admin');
    $statements = [];
    DB::listen(function ($q) use (&$statements) {
        $statements[] = strtolower($q->sql);
    });

    expect(fn () => LastAdminGuard::protect($admin, fn () => null))->toThrow(AccountRuleViolation::class);
    expect(collect($statements)->contains(fn ($s) => str_contains($s, 'model_has_roles')))->toBeTrue();
});

// ------------------------------------------------------------------ own account

it('will not let an admin demote or disable themselves even when other admins exist', function () {
    $a = userWithRole('admin');
    userWithRole('admin');
    $message = 'You cannot change your own role or disable your own account.';

    $this->actingAs($a)->put("/users/{$a->id}", ['name' => $a->name, 'email' => $a->email, 'role' => 'hr'])
        ->assertSessionHas('error', $message);
    $this->actingAs($a)->patch("/users/{$a->id}/active", ['active' => false])->assertSessionHas('error', $message);

    expect($a->fresh()->hasRole('admin'))->toBeTrue()->and($a->fresh()->is_active)->toBeTrue();
});

it('lets an admin edit their own name and email while keeping the role', function () {
    $a = userWithRole('admin');
    userWithRole('admin');

    $this->actingAs($a)->put("/users/{$a->id}", ['name' => 'My New Name', 'email' => 'me@example.com', 'role' => 'admin'])
        ->assertSessionHasNoErrors()->assertSessionMissing('error');

    expect($a->fresh()->name)->toBe('My New Name')->and($a->fresh()->email)->toBe('me@example.com')
        ->and($a->fresh()->hasRole('admin'))->toBeTrue();
});

it('refuses self changes in the actions as well', function () {
    $a = userWithRole('admin');
    userWithRole('admin');

    expect(fn () => app(UpdateUserAccount::class)->handle($a, 'N', 'n@example.com', 'hr', actor: $a))
        ->toThrow(AccountRuleViolation::class, 'You cannot change your own role or disable your own account.');
    expect(fn () => app(SetUserActive::class)->handle($a, false, actor: $a))
        ->toThrow(AccountRuleViolation::class, 'You cannot change your own role or disable your own account.');
});

// -------------------------------------------------------------- disable/enable

it('ends the sessions and the remember token of a disabled user, and only theirs', function () {
    $admin = userWithRole('admin');
    $u = userWithRole('employee');
    $keep = userWithRole('employee');
    $u->forceFill(['remember_token' => 'remember-me-token'])->save();

    foreach ([['s1', $u->id], ['s2', $u->id], ['s3', $keep->id], ['s4', null]] as [$id, $uid]) {
        DB::table('sessions')->insert(['id' => $id, 'user_id' => $uid, 'ip_address' => '127.0.0.1', 'user_agent' => 'x', 'payload' => '', 'last_activity' => time()]);
    }

    $this->actingAs($admin)->patch("/users/{$u->id}/active", ['active' => false])->assertSessionHas('success');

    expect($u->fresh()->is_active)->toBeFalse()
        ->and($u->fresh()->remember_token)->toBeNull()
        ->and(DB::table('sessions')->where('user_id', $u->id)->count())->toBe(0)
        ->and(DB::table('sessions')->where('user_id', $keep->id)->count())->toBe(1)
        ->and(DB::table('sessions')->whereNull('user_id')->count())->toBe(1);
});

it('signs a disabled user out on their next request', function () {
    $u = userWithRole('employee');
    $this->actingAs(userWithRole('admin'))->patch("/users/{$u->id}/active", ['active' => false]);

    $this->actingAs($u->fresh())->get('/dashboard')->assertRedirect('/login');
});

it('does not touch sessions when a user is enabled again', function () {
    $u = userWithRole('employee');
    $u->forceFill(['is_active' => false])->save();
    DB::table('sessions')->insert(['id' => 's1', 'user_id' => $u->id, 'ip_address' => '127.0.0.1', 'user_agent' => 'x', 'payload' => '', 'last_activity' => time()]);

    $this->actingAs(userWithRole('admin'))->patch("/users/{$u->id}/active", ['active' => true]);

    expect($u->fresh()->is_active)->toBeTrue()->and(DB::table('sessions')->where('user_id', $u->id)->count())->toBe(1);
});

// ------------------------------------------------------------------ send reset

it('sends a password reset link to an active user', function () {
    Notification::fake();
    $u = userWithRole('employee');

    $this->actingAs(userWithRole('admin'))->post("/users/{$u->id}/send-reset")->assertSessionHas('success');

    Notification::assertSentTo($u, ResetPassword::class);
});

it('answers a throttled reset request with a friendly message, not a server error', function () {
    Notification::fake();
    $u = userWithRole('employee');
    $admin = userWithRole('admin');

    $this->actingAs($admin)->post("/users/{$u->id}/send-reset")->assertSessionHas('success');
    $this->actingAs($admin)->post("/users/{$u->id}/send-reset")->assertRedirect()
        ->assertSessionHas('error', fn ($m) => str_contains($m, 'wait'));
    Notification::assertSentToTimes($u, ResetPassword::class, 1);
});

it('refuses to send a reset link to a disabled user', function () {
    Notification::fake();
    $u = userWithRole('employee');
    $u->forceFill(['is_active' => false])->save();

    $this->actingAs(userWithRole('admin'))->post("/users/{$u->id}/send-reset")
        ->assertSessionHas('error', fn ($m) => str_contains($m, 'disabled'));
    Notification::assertNothingSent();
});

// ------------------------------------------------------------------------ list

function listProps(array $query = [], ?User $as = null): array
{
    $as ??= userWithRole('admin');
    $response = test()->actingAs($as)->get('/users?'.http_build_query($query))->assertOk();

    return $response->viewData('page')['props'];
}

it('lists users with totals, sorted by name then id, with explicit row keys only', function () {
    $admin = userWithRole('admin');
    $admin->forceFill(['name' => 'Mia Admin'])->save();
    User::factory()->create(['name' => 'Zed'])->assignRole('employee');
    User::factory()->create(['name' => 'Abe'])->assignRole('hr');
    User::factory()->create(['name' => 'Abe'])->assignRole('manager');
    User::factory()->create(['name' => 'Off', 'is_active' => false])->assignRole('employee');

    $props = listProps(as: $admin);

    expect(collect($props['users']['data'])->pluck('name')->all())->toBe(['Abe', 'Abe', 'Mia Admin', 'Off', 'Zed'])
        ->and(collect($props['users']['data'])->take(2)->pluck('id')->all())->toBe(collect($props['users']['data'])->take(2)->pluck('id')->sort()->values()->all())
        ->and($props['counts'])->toBe(['all' => 5, 'active' => 4, 'disabled' => 1])
        ->and(array_keys($props['users']['data'][0]))->toBe(['id', 'name', 'email', 'role', 'is_active', 'last_login_at', 'is_self']);
});

it('never puts password hashes, tokens or two-factor secrets in the page props', function () {
    $admin = userWithRole('admin');
    $u = User::factory()->withTwoFactor()->create(['remember_token' => 'REMEMBER-SECRET-TOKEN']);
    $u->assignRole('employee');

    $raw = json_encode($this->actingAs($admin)->get('/users')->assertOk()->viewData('page'));

    foreach ([$u->password, $admin->password, 'REMEMBER-SECRET-TOKEN', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token', '"password"'] as $needle) {
        expect($raw)->not->toContain($needle);
    }
});

it('marks the signed-in admin own row', function () {
    $admin = userWithRole('admin');
    User::factory()->create()->assignRole('employee');

    $rows = collect(listProps(as: $admin)['users']['data']);

    expect($rows->where('is_self', true)->pluck('id')->all())->toBe([$admin->id])
        ->and($rows->firstWhere('id', $admin->id)['role'])->toBe('admin');
});

it('filters by status tab, role and search without changing the tab totals', function () {
    $admin = userWithRole('admin');
    User::factory()->create(['name' => 'Sara Hr', 'email' => 'sara@example.com'])->assignRole('hr');
    User::factory()->create(['name' => 'Omar Hr', 'email' => 'omar@corp.com', 'is_active' => false])->assignRole('hr');
    User::factory()->create(['name' => 'Lina Emp', 'email' => 'lina@example.com'])->assignRole('employee');

    $names = fn (array $q) => collect(listProps($q, $admin)['users']['data'])->pluck('name')->all();

    expect($names(['status' => 'disabled']))->toBe(['Omar Hr'])
        ->and($names(['status' => 'active', 'role' => 'hr']))->toBe(['Sara Hr'])
        ->and($names(['role' => 'hr']))->toBe(['Omar Hr', 'Sara Hr'])
        ->and($names(['search' => 'corp.com']))->toBe(['Omar Hr'])
        ->and($names(['search' => 'LINA']))->toBe(['Lina Emp'])
        ->and($names(['status' => 'bogus']))->toHaveCount(4)
        ->and($names(['role' => 'superuser']))->toHaveCount(4);

    expect(listProps(['status' => 'disabled', 'search' => 'zzz'], $admin)['counts'])->toBe(['all' => 4, 'active' => 3, 'disabled' => 1]);
});

it('treats percent and underscore in the search literally', function () {
    $admin = userWithRole('admin');
    User::factory()->create(['name' => '100% Real', 'email' => 'p1@example.com']);
    User::factory()->create(['name' => 'Plan_B', 'email' => 'p2@example.com']);
    User::factory()->create(['name' => 'Planxb', 'email' => 'p3@example.com']);

    $names = fn (string $s) => collect(listProps(['search' => $s], $admin)['users']['data'])->pluck('name')->all();

    expect($names('%'))->toBe(['100% Real'])->and($names('n_b'))->toBe(['Plan_B']);
});

it('honours per_page 10, 25 and 50 only and clamps a page past the end', function () {
    $admin = userWithRole('admin');
    User::factory()->count(24)->create();

    expect(listProps(['per_page' => 25], $admin)['users']['per_page'])->toBe(25)
        ->and(listProps(['per_page' => 7], $admin)['users']['per_page'])->toBe(10)
        ->and(listProps(['per_page' => '25abc'], $admin)['users']['per_page'])->toBe(10)
        ->and(listProps([], $admin)['users']['total'])->toBe(25);

    $response = $this->actingAs($admin)->get('/users?page=99')->assertRedirect();
    parse_str((string) parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
    expect($query)->toBe(['page' => '3']);
    $this->actingAs($admin)->get('/users?page=2&per_page=50')->assertRedirect('/users?per_page=50');
});

it('does not fail on array-valued query parameters', function () {
    $admin = userWithRole('admin');
    $this->actingAs($admin)->get('/users?status[]=x&role[]=hr&search[]=y&per_page[]=25&page[]=2')
        ->assertOk()
        ->assertInertia(fn ($p) => $p->where('filters.status', 'all')->where('filters.role', '')->where('filters.search', '')->where('filters.per_page', 10));
});

it('offers the four roles as options and reports the filters it applied', function () {
    $props = listProps(['status' => 'active', 'role' => 'hr', 'search' => ' sa ', 'per_page' => 25]);

    expect(collect($props['roleOptions'])->pluck('value')->all())->toBe(['admin', 'hr', 'manager', 'employee'])
        ->and($props['filters'])->toBe(['status' => 'active', 'role' => 'hr', 'search' => 'sa', 'per_page' => 25]);
});

// ------------------------------------------------------------- fix round 1

it('takes the desired state explicitly: a repeat or stale request is a no-op, not a flip', function () {
    $admin = userWithRole('admin');
    $u = userWithRole('employee');

    $this->actingAs($admin)->patch("/users/{$u->id}/active", ['active' => false])->assertSessionHas('success');
    // A second admin (or a stale tab) sends the same instruction: still disabled.
    $this->actingAs($admin)->patch("/users/{$u->id}/active", ['active' => false])->assertSessionHasNoErrors()->assertSessionHas('success');
    expect($u->fresh()->is_active)->toBeFalse();

    $this->actingAs($admin)->patch("/users/{$u->id}/active", ['active' => true]);
    $this->actingAs($admin)->patch("/users/{$u->id}/active", ['active' => true]);
    expect($u->fresh()->is_active)->toBeTrue();
});

it('requires a boolean active value', function () {
    $admin = userWithRole('admin');
    $u = userWithRole('employee');

    $this->actingAs($admin)->patch("/users/{$u->id}/active")->assertSessionHasErrors('active');
    $this->actingAs($admin)->patch("/users/{$u->id}/active", ['active' => 'maybe'])->assertSessionHasErrors('active');
    $this->actingAs($admin)->patch("/users/{$u->id}/active", ['active' => [true]])->assertSessionHasErrors('active');
    expect($u->fresh()->is_active)->toBeTrue();
});

it('keeps the account and shows a friendly error when the set-password link cannot be sent', function () {
    $admin = userWithRole('admin');
    Password::shouldReceive('broker')->andThrow(new RuntimeException('mail down: secret-detail'));
    Log::spy();

    $this->actingAs($admin)->post('/users', ['name' => 'Sara', 'email' => 'sara@example.com', 'role' => 'hr'])
        ->assertRedirect()
        ->assertSessionHas('error', 'Account created, but the link could not be sent. Use the reset icon.');

    expect(User::whereEmail('sara@example.com')->exists())->toBeTrue();
    Log::shouldHaveReceived('warning')->withArgs(fn ($m, $c = []) => ! str_contains(json_encode([$m, $c]), 'secret-detail'));
});

it('reports delivery through linkSent() without throwing', function () {
    Notification::fake();
    $action = app(CreateUserAccount::class);
    $action->handle('A One', 'a1@example.com', 'employee');
    expect($action->linkSent())->toBeTrue();
});

it('answers a mail failure on send-reset with a friendly error, never a 500', function () {
    $u = userWithRole('employee');
    $admin = userWithRole('admin');
    Password::shouldReceive('sendResetLink')->andThrow(new RuntimeException('smtp exploded secret-detail'));
    Log::spy();

    $this->actingAs($admin)->post("/users/{$u->id}/send-reset")->assertRedirect()
        ->assertSessionHas('error', fn ($m) => str_contains($m, 'could not be sent') && ! str_contains($m, 'secret-detail'));
    Log::shouldHaveReceived('warning')->withArgs(fn ($m, $c = []) => ! str_contains(json_encode([$m, $c]), 'secret-detail'));
});

it('deletes the pending broker token when a user is disabled, so old links stop working', function () {
    Notification::fake();
    $admin = userWithRole('admin');
    $u = app(CreateUserAccount::class)->handle('Sara', 'sara@example.com', 'employee');
    $token = null;
    Notification::assertSentTo($u, SetPasswordNotification::class, function ($n) use (&$token) {
        $token = $n->token;

        return true;
    });
    expect(DB::table('password_reset_tokens')->where('email', $u->email)->exists())->toBeTrue();

    $this->actingAs($admin)->patch("/users/{$u->id}/active", ['active' => false]);

    expect(DB::table('password_reset_tokens')->where('email', $u->email)->exists())->toBeFalse()
        ->and(Password::broker()->tokenExists($u->fresh(), $token))->toBeFalse();
});

it('still refuses a disabled user at sign-in even after a password reset', function () {
    $u = User::factory()->create(['is_active' => false, 'email' => 'off@example.com']);
    $u->forceFill(['password' => 'A-Strong-Passw0rd!x'])->save();

    $this->post('/login', ['email' => 'off@example.com', 'password' => 'A-Strong-Passw0rd!x'])->assertSessionHasErrors('email');
    $this->assertGuest();
});

it('does not email a reset link to a disabled account from the public forgot-password form', function () {
    Notification::fake();
    $off = User::factory()->create(['is_active' => false]);
    $on = User::factory()->create();

    $this->post('/forgot-password', ['email' => $off->email]);
    Notification::assertNothingSent();
    $this->post('/forgot-password', ['email' => $on->email]);
    Notification::assertSentTo($on, ResetPassword::class);
});

it('answers the forgot-password form for a disabled account exactly as for an active one', function () {
    $off = User::factory()->create(['is_active' => false]);
    $on = User::factory()->create();
    $state = fn ($r) => [$r->getStatusCode(), $r->headers->get('Location'), session('status'), json_encode(session('errors'))];

    $first = $state($this->post('/forgot-password', ['email' => $off->email]));
    $this->flushSession();
    $second = $state($this->post('/forgot-password', ['email' => $on->email]));

    // The kit itself answers an unknown address differently ("We can't find a user"), so the account state
    // is hidden only relative to active accounts; see the task 8 fix report.
    expect($first)->toBe($second);
});

it('ends in the clamp redirect for a huge page number', function () {
    $admin = userWithRole('admin');
    $this->actingAs($admin)->get('/users?page=99999999999999999999')->assertRedirect('/users');
});

it('encrypts the queued set-password payload and limits retries', function () {
    $n = new SetPasswordNotification('tok');
    expect($n)->toBeInstanceOf(ShouldBeEncrypted::class)
        ->and($n->tries)->toBe(3);
});
