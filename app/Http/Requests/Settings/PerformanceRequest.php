<?php

namespace App\Http\Requests\Settings;

use Illuminate\Validation\Rule;

/**
 * Settings > Performance. Both values are read when a cycle is opened and copied onto it, so a change affects only the
 * cycles opened afterwards. Authorised by performance.manage (config('hrm.settings_groups.performance')): Admin and HR.
 */
class PerformanceRequest extends SettingsGroupRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'review_period' => ['bail', 'required', 'string', Rule::in(config('hrm.performance.period_types'))],
            'self_review_enabled' => ['required', 'boolean'],
        ];
    }
}
