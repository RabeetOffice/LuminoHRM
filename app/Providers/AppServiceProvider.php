<?php

namespace App\Providers;

use App\Listeners\LogAuthEvents;
use App\Support\MailSettings;
use App\Support\SettingsStore;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Fortify\Events\RecoveryCodesGenerated;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationEnabled;
use Laravel\Passkeys\Events\PasskeyDeleted;
use Laravel\Passkeys\Events\PasskeyRegistered;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // One read of the settings table per request or job; the container drops it between units of work.
        $this->app->scoped(SettingsStore::class);

        // Email settings are applied the first time a mailer is needed, not at boot:
        // pages that send no mail run no settings query, and artisan commands never touch the table.
        $this->app->resolving('mail.manager', fn () => MailSettings::apply());
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        Event::listen(Login::class, [LogAuthEvents::class, 'onLogin']);
        Event::listen(Failed::class, [LogAuthEvents::class, 'onFailed']);
        Event::listen(Logout::class, [LogAuthEvents::class, 'onLogout']);
        Event::listen(Lockout::class, [LogAuthEvents::class, 'onLockout']);
        Event::listen(PasswordReset::class, [LogAuthEvents::class, 'onPasswordReset']);
        Event::listen(TwoFactorAuthenticationEnabled::class, [LogAuthEvents::class, 'onTwoFactorEnabled']);
        Event::listen(TwoFactorAuthenticationConfirmed::class, [LogAuthEvents::class, 'onTwoFactorConfirmed']);
        Event::listen(TwoFactorAuthenticationDisabled::class, [LogAuthEvents::class, 'onTwoFactorDisabled']);
        Event::listen(RecoveryCodesGenerated::class, [LogAuthEvents::class, 'onRecoveryCodesGenerated']);
        Event::listen(PasskeyRegistered::class, [LogAuthEvents::class, 'onPasskeyRegistered']);
        Event::listen(PasskeyDeleted::class, [LogAuthEvents::class, 'onPasskeyDeleted']);
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
