<?php

namespace App\Http\Requests\Exits;

use App\Actions\Exits\ExitGuards;
use App\Actions\Exits\RecordExit;
use App\Actions\Exits\SubmitResignation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * HR records an exit. Access (exit.clearance, the person in scope, not oneself, Admin / HR people by an Admin) is checked
 * by the controller and RecordExit; the day, reason and type rules are RecordExit's, under its locks.
 */
class RecordExitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->input('submitted_on') === '') {
            $this->merge(['submitted_on' => null]);
        }
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'integer', Rule::exists('employees', 'id')],
            'type' => ['required', 'string', Rule::in((array) config('hrm.exit.types'))],
            'last_working_day' => ['required', 'date_format:Y-m-d'],
            'reason' => ['required', 'string'],
            'submitted_on' => ['nullable', 'date_format:Y-m-d'],
            'approve_now' => ['nullable', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'employee_id.required' => 'Choose an employee.',
            'employee_id.integer' => 'Choose an employee.',
            'employee_id.exists' => 'Choose an employee.',
            'type.required' => RecordExit::BAD_TYPE,
            'type.in' => RecordExit::BAD_TYPE,
            'last_working_day.required' => ExitGuards::BAD_DATE,
            'last_working_day.date_format' => ExitGuards::BAD_DATE,
            'submitted_on.date_format' => ExitGuards::BAD_DATE,
            'reason.required' => SubmitResignation::NO_REASON,
        ];
    }
}
