<?php

namespace App\Http\Requests\Performance;

use App\Actions\Performance\OpenReviewCycle;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Change an open cycle's due date. The date rules (a real date, on or after the period end) are ChangeCycleDueDate's.
 */
class DueDateRequest extends FormRequest
{
    /** Access is enforced by the can:performance.manage route middleware. */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'due_on' => ['required', 'string', 'max:10'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'due_on.required' => OpenReviewCycle::BAD_DATE,
            'due_on.string' => OpenReviewCycle::BAD_DATE,
            'due_on.max' => OpenReviewCycle::BAD_DATE,
        ];
    }
}
