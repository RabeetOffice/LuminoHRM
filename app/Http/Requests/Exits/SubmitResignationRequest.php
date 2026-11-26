<?php

namespace App\Http\Requests\Exits;

use App\Actions\Exits\ExitGuards;
use App\Actions\Exits\SubmitResignation;
use Illuminate\Foundation\Http\FormRequest;

/**
 * An employee's own resignation: the last working day and a reason. The day rules (today or later, joining date, 365
 * days ahead) and the length of the reason are SubmitResignation's, under its locks; this only checks the shape.
 */
class SubmitResignationRequest extends FormRequest
{
    /** Anyone signed in may resign; the action needs an employee record. */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'last_working_day' => ['required', 'date_format:Y-m-d'],
            'reason' => ['required', 'string'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'last_working_day.required' => ExitGuards::BAD_DATE,
            'last_working_day.date_format' => ExitGuards::BAD_DATE,
            'reason.required' => SubmitResignation::NO_REASON,
        ];
    }
}
