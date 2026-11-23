<?php

namespace App\Http\Requests\Settings;

use DateTimeZone;
use Illuminate\Validation\Rule;

class CompanyRequest extends SettingsGroupRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['bail', 'required', 'string', 'max:100'],
            'address' => ['bail', 'nullable', 'string', 'max:500'],
            'currency' => ['bail', 'required', 'string', 'regex:/^[A-Z]{3}\z/'],
            // Used for display and, later, attendance day boundaries. It never changes PHP's or the app's timezone.
            'timezone' => ['bail', 'required', 'string', Rule::in(DateTimeZone::listIdentifiers())],
            'date_format' => ['bail', 'required', 'string', Rule::in(config('hrm.date_formats'))],
        ];
    }
}
