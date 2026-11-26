<?php

namespace App\Http\Requests\Assets;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Change an asset's status by hand. `assigned` is a valid value to send (so it gets the friendly "Assign the asset to an
 * employee instead." from AssetRules) but never a successful one. Access: can:assets.assign on the route.
 */
class AssetStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::in(config('hrm.assets.statuses'))],
        ];
    }
}
