<?php

namespace App\Http\Requests\Settings;

use App\Support\Settings;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Base of the per-group Settings requests. Authorisation is per group, from
 * config('hrm.settings_groups'): the user needs any one of the group's permissions.
 * The controller has already turned an unknown group into a 404.
 */
abstract class SettingsGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        $permissions = config('hrm.settings_groups.'.$this->route('group'), []);

        return $permissions !== [] && $this->user()?->canAny($permissions) === true;
    }

    /** Fields never filled in from the stored value: secrets and uploads. */
    private const NOT_FILLED = ['password', 'clear_password', 'logo', 'logo_dark', 'remove_logo', 'remove_logo_dark'];

    /**
     * A card saves only its own fields. Fields not sent take their stored value before
     * validation, so the group's rules run on the whole group (for example shift_end after the
     * stored shift_start) and a partial payload cannot slip past them.
     */
    protected function prepareForValidation(): void
    {
        $stored = Settings::group((string) $this->route('group'));
        $missing = [];

        foreach (array_keys($this->rules()) as $field) {
            if (array_key_exists($field, $stored) && ! in_array($field, self::NOT_FILLED, true) && ! $this->has($field)) {
                $missing[$field] = $stored[$field];
            }
        }

        $this->merge($missing);
    }

    /**
     * The values to hand to Settings::set(). Defaults to the validated input.
     *
     * @return array<string, mixed>
     */
    public function settingsValues(): array
    {
        return $this->validated();
    }
}
