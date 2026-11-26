<?php

namespace App\Http\Requests\Performance;

use App\Actions\Performance\SaveKpi;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Add or change a KPI. Both targets are validated as plain numbers here; the rules about who may be chosen (and the exact
 * refusals) are SaveKpi's, under the lock.
 */
class KpiRequest extends FormRequest
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
            'name' => ['required', 'string', 'min:1', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
            'weight' => ['required', 'integer', 'between:1,100'],
            'is_active' => ['required', 'boolean'],
            'target' => ['required', Rule::in(['designation', 'employee'])],
            'designation_id' => ['nullable', 'integer'],
            'employee_id' => ['nullable', 'integer'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'weight.required' => SaveKpi::WEIGHT_MESSAGE,
            'weight.integer' => SaveKpi::WEIGHT_MESSAGE,
            'weight.between' => SaveKpi::WEIGHT_MESSAGE,
            'target.required' => SaveKpi::TARGET_NONE,
            'target.in' => SaveKpi::TARGET_NONE,
        ];
    }

    /** @return array<string, mixed> */
    public function kpiData(): array
    {
        return $this->validated();
    }
}
