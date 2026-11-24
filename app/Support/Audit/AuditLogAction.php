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

    /**
     * Backstop only (the declared allow-lists and secret settings are the real control): a property is hidden when the
     * last word of its name is one of these ("reset_token", "two_factor_secret", "email.password"), so "token_count" is not.
     */
    private const SECRET_WORDS = ['password', 'passwd', 'token', 'secret', 'hash', 'codes', 'passkey', 'credential', 'credentials', 'remember'];

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
            $properties['user_agent'] = Audit::clean((string) $request->userAgent());
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
            if (is_string($key) && $value !== null && in_array($key, (array) config('hrm.audit.masked_attributes', []), true)) {
                $values[$key] = self::CHANGED;

                continue;
            }

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
        $key = strtolower((string) preg_replace('/([a-z])([A-Z])/', '$1_$2', $key));
        $words = preg_split('/[^a-z0-9]+/', $key, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($words === []) {
            return false;
        }

        return in_array(end($words), self::SECRET_WORDS, true) || str_ends_with(implode('_', $words), 'api_key');
    }
}
