<?php

namespace App\Http\Requests;

use App\Actions\Leave\ApplyForLeave;
use Illuminate\Foundation\Http\FormRequest;

/**
 * An application for leave (POST /leave/requests). employee_id is optional: absent, the signed-in person's own record;
 * present, someone whose leave they may administer (checked by the controller and the action). A half day is one date
 * (to_date follows from_date). Which days count, the window, overlaps, the balance and the attachment's content are
 * checked by ApplyForLeave.
 */
class LeaveApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $clean = fn (mixed $v) => is_string($v) ? (trim($v) === '' ? null : trim($v)) : $v;
        $half = $clean($this->input('half'));
        $from = $clean($this->input('from_date'));

        $this->merge([
            'employee_id' => $clean($this->input('employee_id')),
            'from_date' => $from,
            'to_date' => $half !== null ? $from : $clean($this->input('to_date')),
            'half' => $half,
            'reason' => is_string($this->input('reason')) ? trim($this->input('reason')) : $this->input('reason'),
        ]);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'employee_id' => ['bail', 'nullable', 'integer'],
            'leave_type_id' => ['bail', 'required', 'integer', 'exists:leave_types,id'],
            'from_date' => ['bail', 'required', 'string', 'date_format:Y-m-d'],
            'to_date' => ['bail', 'required', 'string', 'date_format:Y-m-d', 'after_or_equal:from_date'],
            'half' => ['bail', 'nullable', 'string', 'in:am,pm'],
            'reason' => ['bail', 'required', 'string', 'min:5', 'max:500'],
            'attachment' => ['bail', 'nullable', 'file', 'max:'.ApplyForLeave::ATTACHMENT_MAX_KB],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'leave_type_id.required' => 'Choose a leave type.',
            'leave_type_id.exists' => 'Choose a leave type.',
            'from_date.required' => 'Choose the first day.',
            'from_date.date_format' => 'That is not a valid date.',
            'to_date.required' => 'Choose the last day.',
            'to_date.date_format' => 'That is not a valid date.',
            'to_date.after_or_equal' => 'The end date is before the start date.',
            'half.in' => 'Choose the first or the second half.',
            'reason.required' => 'Say why you need the leave.',
            'reason.min' => 'The reason must be at least 5 characters.',
            'reason.max' => 'The reason may be at most 500 characters.',
            'attachment.file' => 'The attachment could not be uploaded.',
            'attachment.max' => 'The attachment may be at most 5 MB.',
        ];
    }
}
