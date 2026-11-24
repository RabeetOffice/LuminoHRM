<?php

namespace App\Support\Audit;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Actions\LogActivityAction;

/**
 * Every audit entry passes through here (model events and Audit::record alike).
 *
 * - Keeps the old/new values in `properties` as {"old": {...}, "attributes": {...}}.
 * - Adds the request's IP address and a short user agent when there is a web request.
 * - Adds the subject's last known name (`subject_label`), so the entry still reads well after the record is gone.
 * - Last line of defence: any property whose name looks like a secret is replaced before saving.
 */
class AuditLogAction extends LogActivityAction
{
    public const HIDDEN = '[hidden]';

    /** Marks a secret that changed without recording it (also used by Settings). */
    public const CHANGED = '[changed]';

    /** Substrings of a property name that mark it as secret. */
    private const SECRET_NAMES = ['password', 'token', 'secret', 'recovery', 'passkey', 'credential', 'api_key', 'remember'];

    protected function transformChanges(Model $activity): void
    {
        $properties = $activity->properties?->toArray() ?? [];
        $changes = $activity->attribute_changes?->toArray() ?? [];

        // The package keeps model changes in `attribute_changes`; the audit log keeps one shape in `properties`.
        $properties = array_merge($properties, $changes);
        $activity->attribute_changes = null;

        $request = request();
        $ip = $request->ip();

        if ($ip !== null && ! isset($properties['ip'])) {
            $properties['ip'] = $ip;
            $properties['user_agent'] = mb_substr((string) $request->userAgent(), 0, 255);
        }

        $activity->properties = collect($properties);
    }

    protected function beforeActivityLogged(Model $activity): void
    {
        $subject = $activity->subject ?? null;

        if ($subject instanceof Model && method_exists($subject, 'auditLabel')) {
            $activity->properties = ($activity->properties ?? collect())->put('subject_label', mb_substr((string) $subject->auditLabel(), 0, 255));
        }

        parent::beforeActivityLogged($activity);
    }

    protected function save(Model $activity): void
    {
        $activity->properties = collect($this->scrub($activity->properties?->toArray() ?? []));

        parent::save($activity);
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    private function scrub(array $values): array
    {
        foreach ($values as $key => $value) {
            if (is_string($key) && $this->looksSecret($key)) {
                $values[$key] = $value === self::CHANGED ? $value : self::HIDDEN;

                continue;
            }

            if (is_array($value)) {
                $values[$key] = $this->scrub($value);
            }
        }

        return $values;
    }

    private function looksSecret(string $key): bool
    {
        $key = strtolower($key);

        foreach (self::SECRET_NAMES as $needle) {
            if (str_contains($key, $needle)) {
                return true;
            }
        }

        return false;
    }
}
