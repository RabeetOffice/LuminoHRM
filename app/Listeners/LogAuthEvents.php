<?php

namespace App\Listeners;

use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Laravel\Fortify\Fortify;

/**
 * Sign-in, failed sign-in and sign-out entries (area "auth"), from the framework's auth events so password,
 * two-factor and passkey sign-ins are all covered. Registered in AppServiceProvider (the method names do not
 * start with "handle", so the framework's listener auto-discovery does not register them a second time).
 *
 * A logging failure must never block authentication: every entry goes through Audit::tryRecord, which
 * reports the problem to the error log and carries on.
 *
 * Never recorded: the attempted password (the Failed event carries it; this class reads only the email).
 */
class LogAuthEvents
{
    /** Routes on which a Login event is a real sign-in (a remember-cookie restore also fires Login on any request). */
    private const SIGN_IN_ROUTES = ['login.store', 'two-factor.login.store', 'passkey.login'];

    public function onLogin(Login $event): void
    {
        $user = $event->user;

        // A disabled account is refused elsewhere ("Sign-in refused: account disabled"), never "signed in".
        if (! $user instanceof User || ! $user->is_active || ! request()->routeIs(...self::SIGN_IN_ROUTES)) {
            return;
        }

        Audit::tryRecord('auth', 'Signed in', $user, causer: $user);
    }

    public function onFailed(Failed $event): void
    {
        $email = $event->credentials[Fortify::username()] ?? null;

        if (! is_string($email)) {
            return;
        }

        Audit::tryRecord('auth', 'Failed sign-in', null, [
            'attempted_email' => mb_substr(trim($email), 0, 255),
            'ip' => request()->ip(),
        ]);
    }

    public function onLogout(Logout $event): void
    {
        // Only a person signing out (not the disabled-account backstop, which ends the session itself).
        if (! $event->user instanceof User || ! request()->routeIs('logout')) {
            return;
        }

        Audit::tryRecord('auth', 'Signed out', $event->user, causer: $event->user);
    }
}
