<?php

namespace App\Http\Requests;

use App\Actions\Payroll\PayrollLineInputs;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A one-off item on a draft payroll line: label, type (addition | deduction), amount in whole rupees (commas allowed)
 * and, for an addition, whether it is taxable (default yes). PayrollLineInputs checks the same limits and who may edit.
 */
class PayrollAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('amount'))) {
            $this->merge(['amount' => str_replace(',', '', trim($this->input('amount')))]);
        }

        if (is_string($this->input('label'))) {
            $this->merge(['label' => trim($this->input('label'))]);
        }
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'label' => ['bail', 'required', 'string', 'min:2', 'max:100'],
            'type' => ['bail', 'required', Rule::in((array) config('hrm.payroll.adjustment_types'))],
            'amount' => ['bail', 'required', 'integer', 'min:1', 'max:'.PayrollLineInputs::MAX_RUPEES],
            'taxable' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'label.required' => 'Enter a label.',
            'label.min' => 'Enter a label of 2 to 100 characters.',
            'type.required' => 'Choose an addition or a deduction.',
            'type.in' => 'Choose an addition or a deduction.',
            'amount.required' => 'Enter the amount in whole rupees.',
            'amount.integer' => 'Enter whole rupees.',
            'amount.min' => 'Enter whole rupees from 1 to 99,999,999.',
            'amount.max' => 'Enter whole rupees from 1 to 99,999,999.',
        ];
    }

    public function amount(): int
    {
        return (int) $this->validated('amount');
    }

    public function taxable(): bool
    {
        return $this->validated('type') === 'addition' && $this->boolean('taxable', true);
    }
}
