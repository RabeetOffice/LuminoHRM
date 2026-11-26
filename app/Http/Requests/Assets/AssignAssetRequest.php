<?php

namespace App\Http\Requests\Assets;

use App\Actions\Assets\AssignAsset;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Hand an asset to an employee: who, on which day ("Y-m-d"), and an optional note. Availability, the employee's status
 * and the date bounds (not in the future, not before joining) are decided by AssignAsset under the locks.
 */
class AssignAssetRequest extends FormRequest
{
    /** Access is enforced by the can:assets.assign route middleware; the employee's scope by the controller. */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'assigned_on' => ['required', 'date_format:Y-m-d'],
            'note' => ['nullable', 'string', 'max:'.AssignAsset::NOTE_MAX],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'employee_id.required' => 'Choose who gets the asset.',
            'employee_id.exists' => 'Choose who gets the asset.',
            'assigned_on.required' => AssignAsset::BAD_DATE,
            'assigned_on.date_format' => AssignAsset::BAD_DATE,
            'note.max' => AssignAsset::NOTE_TOO_LONG,
        ];
    }
}
