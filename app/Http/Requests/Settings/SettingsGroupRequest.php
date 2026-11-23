<?php

namespace App\Http\Requests\Settings;

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
