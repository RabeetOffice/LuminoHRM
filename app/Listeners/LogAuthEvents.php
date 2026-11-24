<?php

namespace App\Listeners;

use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\Cache;
use Laravel\Fortify\Events\RecoveryCodesGenerated;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationEnabled;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;
use Laravel\Passkeys\Events\PasskeyDeleted;
use Laravel\Passkeys\Events\PasskeyRegistered;
use Throwable;

/**
 * Sign-in, failed sign-in, sign-out and self-service security entries (area "auth"), from the framework's and
 * Fortify's events so password, two-factor and passkey flows are all covered. Registered in AppServiceProvider
 * (the method names do not start with "handle", so listener auto-discovery cannot register them a second time).
 *
 * A logging failure must never block authentication or a security change: every entry goes through
 * Audit::tryRecord, which reports the problem to the error log and carries on.
 *
 * Never recorded: the attempted password (the Failed event carries it; only the email is read), password
 * hashes, two-factor secrets and recovery codes, passkey credentials or tokens.
 */
class LogAuthEvents
{
    /** Routes on which a Login event is a real sign-in (a remember-cookie restore also fires Login on any request). */
    private const SIGN_IN_ROUTES = ['login.store', 'two-factor.login.store', 'passkey.login'];

    /** At most this many "Failed sign-in" entries per IP address per window; see failedSignInDecision(). */
    public const FAILED_LIMIT = 20;

    public const FAILED_WINDOW_SECONDS = 3600;

    public const FAILED_CAPPED_MARKER = 'Further failed sign-ins from this address are not being recorded';

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

        $ip = request()->ip();

        $decision = $this->failedSignInDecision($ip);

        if ($decision === 'skip') {
            return;
        }

        if ($decision === 'marker') {
            Audit::tryRecord('auth', self::FAILED_CAPPED_MARKER, null, ['ip' => $ip]);

            return;
        }

        Audit::tryRecord('auth', 'Failed sign-in', null, [
            'attempted_email' => Audit::clean(trim($email)),
            'ip' => $ip,
        ]);
    }

    /**
     * Caps the failed sign-in entries an attacker can create: 20 per IP address per window (a counter in the app's
     * cache store that starts with the first failure and lasts an hour). The 21st attempt writes one marker entry,
     * then nothing more is recorded for that address until the window ends. If the cache is unavailable the
     * attempt is recorded rather than lost.
     *
     * @return 'record'|'marker'|'skip'
     */
    private function failedSignInDecision(?string $ip): string
    {
        try {
            $key = 'audit:failed-signins:'.sha1((string) $ip);

            Cache::add($key, 0, self::FAILED_WINDOW_SECONDS);
            $count = (int) Cache::increment($key);
        } catch (Throwable $e) {
            report($e);

            return 'record';
        }

        return match (true) {
            $count <= self::FAILED_LIMIT => 'record',
            $count === self::FAILED_LIMIT + 1 => 'marker',
            default => 'skip',
        };
    }

    /** At most one lockout entry per IP address per this many seconds (separate from the failed sign-in cap). */
    public const LOCKOUT_WINDOW_SECONDS = 600;

    public function onLockout(Lockout $event): void
    {
        $ip = $event->request->ip();

        try {
            // First lockout for this address in the window wins; the cache is the limiter. If it is down, record anyway.
            if (! Cache::add('audit:lockout:'.sha1((string) $ip), 1, self::LOCKOUT_WINDOW_SECONDS)) {
                return;
            }
        } catch (Throwable $e) {
            report($e);
        }

        $email = $event->request->input(Fortify::username());

        Audit::tryRecord('auth', 'Sign-in locked out: too many attempts', null, array_filter([
            'attempted_email' => is_string($email) ? Audit::clean(trim($email)) : null,
            'ip' => $ip,
        ], fn ($v) => $v !== null));
    }

    public function onLogout(Logout $event): void
    {
        // Only a person signing out (not the disabled-account backstop, which ends the session itself).
        if (! $event->user instanceof User || ! request()->routeIs('logout')) {
            return;
        }

        Audit::tryRecord('auth', 'Signed out', $event->user, causer: $event->user);
    }

    /** The forgot-password flow completed, or a new account's set-password link was used (same route). */
    public function onPasswordReset(PasswordReset $event): void
    {
        $this->security('Password reset', $event->user);
    }

    public function onTwoFactorEnabled(TwoFactorAuthenticationEnabled $event): void
    {
        // With confirmation switched on the setup is not finished until it is confirmed: one entry, at that point.
        if (Features::optionEnabled(Features::twoFactorAuthentication(), 'confirm')) {
            return;
        }

        $this->security('Two-factor authentication enabled', $event->user);
    }

    public function onTwoFactorConfirmed(TwoFactorAuthenticationConfirmed $event): void
    {
        $this->security('Two-factor authentication enabled', $event->user);
    }

    public function onTwoFactorDisabled(TwoFactorAuthenticationDisabled $event): void
    {
        $this->security('Two-factor authentication disabled', $event->user);
    }

    public function onRecoveryCodesGenerated(RecoveryCodesGenerated $event): void
    {
        $this->security('Recovery codes regenerated', $event->user);
    }

    public function onPasskeyRegistered(PasskeyRegistered $event): void
    {
        $this->security('Passkey added', $event->user);
    }

    public function onPasskeyDeleted(PasskeyDeleted $event): void
    {
        $this->security('Passkey removed', $event->user);
    }

    /** One entry about the user's own account, made by that user. No secret material, not even the passkey's name. */
    private function security(string $description, mixed $user): void
    {
        if (! $user instanceof User) {
            return;
        }

        Audit::tryRecord('auth', $description, $user, causer: $user);
    }
}
