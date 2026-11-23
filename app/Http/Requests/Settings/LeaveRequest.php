<?php

namespace App\Http\Requests\Settings;

class LeaveRequest extends SettingsGroupRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'hr_step_enabled' => ['required', 'boolean'],
            'carry_forward_cap' => ['bail', 'required', 'integer', 'between:0,365'],
            'prorate_new_joiners' => ['required', 'boolean'],
        ];
    }
}
