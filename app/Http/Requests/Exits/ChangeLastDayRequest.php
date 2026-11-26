<?php

namespace App\Http\Requests\Exits;

use App\Actions\Exits\ExitGuards;
use Illuminate\Foundation\Http\FormRequest;

/** A new last working day for an approved exit. The rules (joining date, 365 days, lock) are ChangeExitLastDay's. */
class ChangeLastDayRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return ['last_working_day' => ['required', 'date_format:Y-m-d']];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'last_working_day.required' => ExitGuards::BAD_DATE,
            'last_working_day.date_format' => ExitGuards::BAD_DATE,
        ];
    }
}
