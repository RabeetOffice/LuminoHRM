<?php

namespace App\Http\Requests\Settings;

use Closure;

class LettersRequest extends SettingsGroupRequest
{
    /** Placeholders the letter generator will replace (PDF generation arrives in a later phase). */
    public const PLACEHOLDERS = ['{employee_name}', '{designation}', '{department}', '{joining_date}', '{last_working_day}', '{company_name}'];

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'experience_template' => [
                'bail', 'required', 'string', 'max:5000',
                function (string $attribute, mixed $value, Closure $fail): void {
                    preg_match_all('/\{[A-Za-z_][A-Za-z0-9_]*\}/', (string) $value, $found);
                    $unknown = array_values(array_diff(array_unique($found[0]), self::PLACEHOLDERS));

                    if ($unknown !== []) {
                        $fail('Unknown placeholder '.implode(', ', $unknown).'. Supported: '.implode(', ', self::PLACEHOLDERS).'.');
                    }
                },
            ],
        ];
    }
}
