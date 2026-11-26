<?php

namespace App\Http\Requests\Assets;

use App\Support\Names;
use App\Support\Payroll\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Add or edit an asset. The cost is typed in whole rupees (`cost_rupees`, a form field only); assetData() turns it into
 * the paisa the actions take. Access is enforced by the can:assets.assign route middleware; serial and date rules that
 * need the database or the clock are enforced by SaveAsset under its locks.
 */
class AssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['name' => Names::normalise($this->input('name'))]);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:1', 'max:120'],
            'asset_category_id' => ['required', 'integer', Rule::exists('asset_categories', 'id')],
            'serial_number' => ['nullable', 'string', 'max:100'],
            'purchase_date' => ['nullable', 'date_format:Y-m-d'],
            'cost_rupees' => ['nullable', 'integer', 'min:0', 'max:99999999'],
            'condition' => ['required', Rule::in(config('hrm.assets.conditions'))],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'asset_category_id.required' => 'Choose a category.',
            'asset_category_id.exists' => 'Choose a category.',
            'asset_category_id.integer' => 'Choose a category.',
        ];
    }

    /**
     * What SaveAsset takes: the validated fields with the cost in paisa.
     *
     * @return array<string, mixed>
     */
    public function assetData(): array
    {
        $v = $this->validated();
        // A request without `cost_rupees` leaves the stored cost alone; one that sends it (even empty) sets or clears it.
        if ($this->has('cost_rupees')) {
            $rupees = $v['cost_rupees'] ?? null;
            $v['cost'] = $rupees === null ? null : Money::rupees((int) $rupees);
        }

        unset($v['cost_rupees']);

        return $v;
    }
}
