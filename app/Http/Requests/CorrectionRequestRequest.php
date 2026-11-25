<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * An employee's request to correct one of their own days (POST /attendance/corrections). Times are "H:i" wall-clock
 * times on that date; an empty time keeps the recorded one. The day itself (window, employment, start date, manual,
 * one open request) and the clock-out-after-midnight rule are checked by CorrectionEligibility.
 */
class CorrectionRequestRequest extends FormRequest
{
    /** Anyone signed in with an employee record; the controller enforces "own record only". */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $clean = fn (mixed $v) => is_string($v) ? (trim($v) === '' ? null : trim($v)) : $v;

        $this->merge([
            'date' => $clean($this->input('date')),
            'clock_in' => $clean($this->input('clock_in')),
            'clock_out' => $clean($this->input('clock_out')),
            'reason' => is_string($this->input('reason')) ? trim($this->input('reason')) : $this->input('reason'),
        ]);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'date' => ['bail', 'required', 'string', 'date_format:Y-m-d'],
            'clock_in' => ['bail', 'nullable', 'string', 'date_format:H:i'],
            'clock_out' => ['bail', 'nullable', 'string', 'date_format:H:i'],
            'reason' => ['bail', 'required', 'string', 'min:5', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'date.required' => 'Choose the day to correct.',
            'date.date_format' => 'That is not a valid date.',
            'clock_in.date_format' => 'Enter the clock-in as a time, e.g. 09:00.',
            'clock_out.date_format' => 'Enter the clock-out as a time, e.g. 18:00.',
            'reason.required' => 'Say why the recorded time is wrong.',
            'reason.min' => 'The reason must be at least 5 characters.',
        ];
    }
}
