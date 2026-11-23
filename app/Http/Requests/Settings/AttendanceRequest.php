<?php

namespace App\Http\Requests\Settings;

use Illuminate\Validation\Rule;

class AttendanceRequest extends SettingsGroupRequest
{
    public const DAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'shift_start' => ['bail', 'required', 'string', 'date_format:H:i'],
            'shift_end' => ['bail', 'required', 'string', 'date_format:H:i', 'after:shift_start'],
            'grace_minutes' => ['bail', 'required', 'integer', 'between:0,120'],
            'weekly_offs' => ['present', 'array', 'max:7'],
            'weekly_offs.*' => ['bail', 'string', 'distinct', Rule::in(self::DAYS)],
            'late_rule_count' => ['bail', 'required', 'integer', 'between:1,31'],
            'half_day_hours' => ['bail', 'required', 'integer', 'between:1,12'],
            'overtime_enabled' => ['required', 'boolean'],
            'overtime_min_minutes' => ['bail', 'required', 'integer', 'between:0,480'],
        ];
    }
}
