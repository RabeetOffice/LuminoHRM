<?php

namespace App\Http\Requests\Settings;

/**
 * Settings > Leave. The carry-forward cap lives on each leave type (Leave > Leave Types), not here: a
 * carry_forward_cap sent by an old form is not validated and not stored.
 */
class LeaveRequest extends SettingsGroupRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'hr_step_enabled' => ['required', 'boolean'],
            'prorate_new_joiners' => ['required', 'boolean'],
        ];
    }
}
