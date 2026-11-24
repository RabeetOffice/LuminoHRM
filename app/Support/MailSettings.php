<?php

namespace App\Support;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/**
 * Puts the saved email settings into the running mail config.
 *
 * Called by AppServiceProvider when the mail manager is first resolved, so a request that never
 * sends mail runs no settings query, and so it works after `config:cache` (the cached file is
 * loaded first, this overrides it in memory). With no saved host the .env mailer stays in force.
 */
class MailSettings
{
    public static function apply(): void
    {
        try {
            $email = Settings::group('email');
        } catch (Throwable $e) {
            // Mail must still go out through the .env mailer if the settings cannot be read.
            report($e);

            return;
        }

        $host = trim((string) $email['host']);

        if ($host === '') {
            return;
        }

        $encryption = (string) $email['encryption'];

        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.transport' => 'smtp',
            'mail.mailers.smtp.host' => $host,
            // A dead host must not hang the Settings screen for the default minute.
            'mail.mailers.smtp.timeout' => 15,
            'mail.mailers.smtp.port' => (int) $email['port'],
            'mail.mailers.smtp.username' => self::nullIfEmpty($email['username']),
            'mail.mailers.smtp.password' => self::password((string) $email['password']),
            // ssl = implicit TLS (usually port 465); tls = STARTTLS; none = plain, no STARTTLS upgrade.
            'mail.mailers.smtp.scheme' => $encryption === 'ssl' ? 'smtps' : 'smtp',
            'mail.mailers.smtp.auto_tls' => $encryption !== 'none',
        ]);

        if (trim((string) $email['from_address']) !== '') {
            config(['mail.from.address' => trim((string) $email['from_address'])]);
        }

        if (trim((string) $email['from_name']) !== '') {
            config(['mail.from.name' => trim((string) $email['from_name'])]);
        }
    }

    private static function password(string $stored): ?string
    {
        if ($stored === '') {
            return null;
        }

        try {
            return Crypt::decryptString($stored);
        } catch (DecryptException) {
            // The key changed or the value is damaged: send without a password rather than crash.
            return null;
        }
    }

    private static function nullIfEmpty(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
