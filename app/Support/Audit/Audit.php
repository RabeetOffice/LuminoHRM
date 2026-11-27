<?php

namespace App\Support\Audit;

use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * The one way to write an audit entry that is not a plain model change. See docs/audit-log.md.
 *
 *   Audit::record('users', 'Account disabled', $user, ['old' => ['status' => 'active'], 'attributes' => ['status' => 'disabled']]);
 *
 * record() throws when the entry cannot be written. Call it inside the same DB::transaction as the change it
 * describes, so a failed entry rolls the change back: an audited action that cannot be logged must not happen.
 * tryRecord() is for sign-in and sign-out only, where a logging failure must never lock anyone out.
 */
class Audit
{
    /**
     * Areas (the entry's log name => the label shown in the viewer's filter). This is the only list:
     * a new phase adds its area here and nowhere else.
     */
    public const AREAS = [
        'organization' => 'Organization',
        'users' => 'Users',
        'settings' => 'Settings',
        'auth' => 'Sign-in',
        'employees' => 'Employees',
        'attendance' => 'Attendance',
        'leave' => 'Leave',
        'payroll' => 'Payroll',
        'assets' => 'Assets',
        'performance' => 'Performance',
        'exit' => 'Exit',
        'system' => 'System',
    ];

    /** Text from outside (typed email, user agent) made safe to store: valid UTF-8, no control characters, at most $max characters. */
    public static function clean(string $text, int $max = 255): string
    {
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        $text = (string) preg_replace('/[\x00-\x1F\x7F]+/', '', $text);

        return mb_substr($text, 0, $max);
    }

    /**
     * @param  array<string, mixed>  $properties  Use {"old": {...}, "attributes": {...}} for values the viewer shows side by side. Never put secrets here.
     * @param  Model|null  $causer  Who did it. Default: the signed-in user, or nobody (the viewer shows "System") in a console command.
     */
    public static function record(string $area, string $description, ?Model $subject = null, array $properties = [], ?Model $causer = null): void
    {
        $logger = activity($area)->withProperties($properties);

        if ($subject !== null) {
            $logger->performedOn($subject);
        }

        if ($causer !== null) {
            $logger->causedBy($causer);
        }

        $logger->log($description);
    }

    /**
     * Like record() but never throws: a failure is reported to the error log and the caller carries on.
     *
     * @param  array<string, mixed>  $properties
     */
    public static function tryRecord(string $area, string $description, ?Model $subject = null, array $properties = [], ?Model $causer = null): void
    {
        try {
            static::record($area, $description, $subject, $properties, $causer);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
