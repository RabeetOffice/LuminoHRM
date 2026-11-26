<?php

namespace App\Http\Requests\Performance;

use App\Actions\Performance\AddReviewPeople;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Add people to an open cycle: a list of employee ids. Who can be added (and the per-person reasons) is AddReviewPeople's
 * rule, judged under the cycle lock.
 */
class AddPeopleRequest extends FormRequest
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
            'employee_ids' => ['required', 'array', 'min:1', 'max:500'],
            'employee_ids.*' => ['integer', 'min:1'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'employee_ids.required' => AddReviewPeople::NONE,
            'employee_ids.array' => AddReviewPeople::NONE,
            'employee_ids.min' => AddReviewPeople::NONE,
            'employee_ids.max' => 'Choose at most 500 people at a time.',
            'employee_ids.*.integer' => AddReviewPeople::NONE,
            'employee_ids.*.min' => AddReviewPeople::NONE,
        ];
    }

    /** @return list<int> */
    public function employeeIds(): array
    {
        return array_map('intval', array_values($this->validated('employee_ids')));
    }
}
