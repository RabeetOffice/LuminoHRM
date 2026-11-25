<?php

namespace App\Http\Requests;

use App\Actions\Attendance\ApplyManualCorrection;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A manual correction of one employee-day (PUT /attendance/daily/{employee}/{date}). Times are "H:i" wall-clock times
 * on that date (optional); status is an optional override (every status except on_leave); the reason is required.
 * The date, the employment window and the clock-out-after-midnight rule are checked by ApplyManualCorrection.
 */
class ManualCorrectionRequest extends FormRequest
{
    /** Access is enforced by the can:attendance.edit route middleware and the controller's scope check. */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $blank = fn (mixed $v) => is_string($v) && trim($v) === '' ? null : (is_string($v) ? trim($v) : $v);

        $this->merge([
            'clock_in' => $blank($this->input('clock_in')),
            'clock_out' => $blank($this->input('clock_out')),
            'status' => $blank($this->input('status')),
            'reason' => is_string($this->input('reason')) ? trim($this->input('reason')) : $this->input('reason'),
        ]);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'clock_in' => ['bail', 'nullable', 'string', 'date_format:H:i'],
            'clock_out' => ['bail', 'nullable', 'string', 'date_format:H:i'],
            'status' => ['bail', 'nullable', 'string', Rule::in(ApplyManualCorrection::OVERRIDE_STATUSES)],
            'reason' => ['bail', 'required', 'string', 'min:5', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'clock_in.date_format' => 'Enter the clock-in as a time, e.g. 09:00.',
            'clock_out.date_format' => 'Enter the clock-out as a time, e.g. 18:00.',
            'status.in' => 'Choose a valid status.',
            'reason.required' => 'Enter the reason for this correction.',
            'reason.min' => 'The reason must be at least 5 characters.',
        ];
    }
}
