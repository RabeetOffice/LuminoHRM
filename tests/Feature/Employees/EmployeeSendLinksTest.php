<?php

use App\Models\Employee;
use App\Models\User;
use App\Notifications\SetPasswordNotification;
use Illuminate\Support\Facades\Notification;
use Spatie\Activitylog\Models\Activity;

/** An employee with a login holding $role; $userAttrs go on the account. */
function slPerson(string $role = 'employee', array $userAttrs = []): array
{
    $user = userWithRole($role);
    $user->forceFill($userAttrs)->save();
    $employee = employeeFor($user);

    return [$employee, $user];
}

it('queues links only for active logins that have never signed in, and reports the skips', function () {
    Notification::fake();
    $admin = userWithRole('admin');
    [$fresh, $freshUser] = slPerson();
    [$signedIn, $signedInUser] = slPerson('employee', ['last_login_at' => now()->subDay()]);
    [$disabled, $disabledUser] = slPerson('employee', ['is_active' => false]);
    $noLogin = Employee::factory()->create();

    $this->actingAs($admin)
        ->from('/employees')
        ->post('/employees/send-links', ['ids' => [$fresh->id, $signedIn->id, $disabled->id, $noLogin->id, 999999]])
        ->assertRedirect('/employees')
        ->assertSessionHas('success', 'Queued set-password links for 1 employee. Skipped 4: they have signed in before, their login is disabled or missing, or only an Admin may send them a link.');

    Notification::assertSentTo($freshUser, SetPasswordNotification::class);
    Notification::assertNotSentTo([$signedInUser, $disabledUser], SetPasswordNotification::class);
    Notification::assertCount(1);

    $entry = Activity::where('log_name', 'employees')->where('description', 'Set-password links sent')->sole();
    expect($entry->causer_id)->toBe($admin->id);
    expect($entry->properties->toArray())->toMatchArray(['requested' => 5, 'queued' => 1, 'skipped' => 4]);
});

it('says so when no link could be queued', function () {
    Notification::fake();
    $admin = userWithRole('admin');
    [$signedIn] = slPerson('employee', ['last_login_at' => now()]);

    $this->actingAs($admin)->from('/employees')
        ->post('/employees/send-links', ['ids' => [$signedIn->id]])
        ->assertSessionHas('error', 'No links were sent. Links go only to logins that are enabled and have never been used.');

    Notification::assertNothingSent();
});

it('skips Admin and HR logins for HR but not for an Admin', function () {
    Notification::fake();
    $hr = userWithRole('hr');
    $admin = userWithRole('admin');
    [$chief, $chiefUser] = slPerson('admin');
    [$colleague, $colleagueUser] = slPerson('hr');
    [$plain, $plainUser] = slPerson('employee');

    $this->actingAs($hr)->from('/employees')
        ->post('/employees/send-links', ['ids' => [$chief->id, $colleague->id, $plain->id]])
        ->assertSessionHas('success');
    Notification::assertSentTo($plainUser, SetPasswordNotification::class);
    Notification::assertNotSentTo([$chiefUser, $colleagueUser], SetPasswordNotification::class);

    $this->travel(2)->minutes(); // past the password broker's one-minute throttle
    $this->actingAs($admin)->from('/employees')
        ->post('/employees/send-links', ['ids' => [$chief->id, $colleague->id]])
        ->assertSessionHas('success', 'Queued set-password links for 2 employees.');
    Notification::assertSentTo([$chiefUser, $colleagueUser], SetPasswordNotification::class);
});

it('accepts at most 50 employees per click', function () {
    Notification::fake();
    $admin = userWithRole('admin');

    $this->actingAs($admin)->from('/employees')
        ->post('/employees/send-links', ['ids' => range(1, 51)])
        ->assertSessionHasErrors('ids');

    $this->actingAs($admin)->from('/employees')
        ->post('/employees/send-links', ['ids' => []])
        ->assertSessionHasErrors('ids');

    $this->actingAs($admin)->from('/employees')
        ->post('/employees/send-links', ['ids' => ['x']])
        ->assertSessionHasErrors('ids.0');

    Notification::assertNothingSent();
});

it('lets only Admin and HR send links', function (string $role) {
    Notification::fake();
    $user = userWithRole($role);
    [$target] = slPerson();

    $this->actingAs($user)->post('/employees/send-links', ['ids' => [$target->id]])->assertForbidden();
    Notification::assertNothingSent();
})->with(['manager', 'employee']);

it('sends guests to the sign-in page', function () {
    $this->post('/employees/send-links', ['ids' => [1]])->assertRedirect('/login');
});

it('ignores the list as an attack surface: a link never goes to an account the employee is not linked to', function () {
    Notification::fake();
    $admin = userWithRole('admin');
    $stranger = User::factory()->create(['email' => 'stranger@corp.test']);
    $employee = Employee::factory()->create(['email' => 'stranger@corp.test']); // same email, but not linked

    $this->actingAs($admin)->from('/employees')->post('/employees/send-links', ['ids' => [$employee->id]]);

    Notification::assertNotSentTo($stranger, SetPasswordNotification::class);
});
