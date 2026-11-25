<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * HR cancelling approved leave: the first day to cancel ("Y-m-d"; empty = today, by AttendanceClock) and a reason of
 * 5 to 500 characters (the employee sees it). The allowed range of days is checked by LeaveDecisions::cancelApproved.
 */
class LeaveCancelRequest extends FormRequest
{
    /** Access is checked by the route middleware and LeaveRouting. */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        foreach (['note', 'from_date'] as $key) {
            $value = $this->input($key);
            $this->merge([$key => is_string($value) ? (trim($value) === '' ? null : trim($value)) : $value]);
        }
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'from_date' => ['bail', 'nullable', 'string', 'date_format:Y-m-d'],
            'note' => ['bail', 'required', 'string', 'min:5', 'max:500'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'from_date.date_format' => 'Choose the first day to cancel.',
            'note.required' => 'Say why the leave is cancelled. The employee will see this.',
            'note.min' => 'Say why the leave is cancelled (at least 5 characters).',
        ];
    }

    public function fromDate(): ?string
    {
        $from = $this->validated('from_date');

        return is_string($from) ? $from : null;
    }

    public function reason(): string
    {
        return (string) $this->validated('note');
    }
}
