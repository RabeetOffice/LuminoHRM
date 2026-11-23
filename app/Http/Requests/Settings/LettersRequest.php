<?php

namespace App\Http\Requests\Settings;

class LettersRequest extends SettingsGroupRequest
{
    /** Placeholders the letter generator will replace (PDF generation arrives in a later phase). */
    public const PLACEHOLDERS = ['{employee_name}', '{designation}', '{department}', '{joining_date}', '{last_working_day}', '{company_name}'];

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'experience_template' => ['bail', 'required', 'string', 'max:5000'],
        ];
    }
}
