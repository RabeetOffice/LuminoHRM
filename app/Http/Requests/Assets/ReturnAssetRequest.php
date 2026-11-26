<?php

namespace App\Http\Requests\Assets;

use App\Actions\Assets\AssignAsset;
use App\Actions\Assets\ReturnAsset;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Take an asset back: the day ("Y-m-d"), the condition it came back in, what it becomes next (available, in repair or
 * retired) and an optional note. The date bounds and "only an Admin can retire" are decided by ReturnAsset under the locks.
 */
class ReturnAssetRequest extends FormRequest
{
    /** Access is enforced by the can:assets.assign route middleware. */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'returned_on' => ['required', 'date_format:Y-m-d'],
            'return_condition' => ['required', 'string', Rule::in(config('hrm.assets.conditions'))],
            'next_status' => ['required', 'string', Rule::in(ReturnAsset::NEXT_STATUSES)],
            'return_note' => ['nullable', 'string', 'max:'.AssignAsset::NOTE_MAX],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'returned_on.required' => ReturnAsset::BAD_DATE,
            'returned_on.date_format' => ReturnAsset::BAD_DATE,
            'return_condition.required' => ReturnAsset::BAD_CONDITION,
            'return_condition.in' => ReturnAsset::BAD_CONDITION,
            'next_status.required' => ReturnAsset::BAD_NEXT_STATUS,
            'next_status.in' => ReturnAsset::BAD_NEXT_STATUS,
            'return_note.max' => AssignAsset::NOTE_TOO_LONG,
        ];
    }
}
