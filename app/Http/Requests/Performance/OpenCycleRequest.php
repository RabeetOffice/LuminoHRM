<?php

namespace App\Http\Requests\Performance;

use App\Actions\Performance\OpenReviewCycle;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Open a review cycle: a period key and an optional due date. Which period and which date are allowed (and the exact
 * refusals) are OpenReviewCycle's rules, judged under the lock.
 */
class OpenCycleRequest extends FormRequest
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
            'period' => ['required', 'string', 'max:10'],
            'due_on' => ['nullable', 'string', 'max:10'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'period.required' => OpenReviewCycle::UNKNOWN,
            'period.string' => OpenReviewCycle::UNKNOWN,
            'period.max' => OpenReviewCycle::UNKNOWN,
            'due_on.string' => OpenReviewCycle::BAD_DATE,
            'due_on.max' => OpenReviewCycle::BAD_DATE,
        ];
    }
}
