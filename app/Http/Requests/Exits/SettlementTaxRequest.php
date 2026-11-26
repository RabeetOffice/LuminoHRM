<?php

namespace App\Http\Requests\Exits;

use Illuminate\Foundation\Http\FormRequest;

/** Override the draft's tax with a whole-rupee amount and a reason, or clear the override (amount left empty). */
class SettlementTaxRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        foreach (['amount', 'reason'] as $key) {
            if ($this->input($key) === '') {
                $this->merge([$key => null]);
            }
        }
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'amount' => ['nullable', 'integer', 'min:0', 'max:99999999'],
            'reason' => ['nullable', 'string', 'max:300'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'amount.integer' => 'Enter the tax in whole rupees.',
            'amount.min' => 'Enter the tax in whole rupees.',
            'amount.max' => 'Enter the tax in whole rupees.',
            'reason.max' => 'The reason can be at most 300 characters.',
        ];
    }
}
