<?php

use App\Models\User;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Http\Request;
use Spatie\Activitylog\Models\Activity;

const LOCKOUT_TEXT = 'Sign-in locked out: too many attempts';

function lockoutFrom(string $ip, string $email = 'someone@example.com'): void
{
    $request = Request::create('/login', 'POST', ['email' => $email], server: ['REMOTE_ADDR' => $ip]);
    event(new Lockout($request));
}

it('records a sign-in lockout with the address and the cleaned attempted email', function () {
    lockoutFrom('203.0.113.5', "bad\x00".str_repeat('a', 400).'@example.com');

    $entry = Activity::where('log_name', 'auth')->where('description', LOCKOUT_TEXT)->sole();
    expect($entry->properties['ip'])->toBe('203.0.113.5');
    expect($entry->properties['attempted_email'])->not->toContain("\x00")->and(mb_strlen($entry->properties['attempted_email']))->toBeLessThanOrEqual(255);
    expect($entry->causer_id)->toBeNull();
});

it('records at most one lockout entry per address per 10 minutes', function () {
    lockoutFrom('203.0.113.5');
    lockoutFrom('203.0.113.5', 'other@example.com');
    lockoutFrom('203.0.113.6');

    expect(Activity::where('description', LOCKOUT_TEXT)->count())->toBe(2);

    $this->travel(11)->minutes();
    lockoutFrom('203.0.113.5');
    expect(Activity::where('description', LOCKOUT_TEXT)->count())->toBe(3);
});

it('is raised by the real login throttle', function () {
    User::factory()->create(['email' => 'real@example.com']);
    for ($i = 0; $i < 7; $i++) {
        $this->post('/login', ['email' => 'real@example.com', 'password' => 'wrong-password']);
    }
    expect(Activity::where('description', LOCKOUT_TEXT)->count())->toBe(1);
});
