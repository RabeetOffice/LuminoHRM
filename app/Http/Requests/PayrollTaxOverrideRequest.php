<?php

namespace App\Http\Requests;

use App\Actions\Payroll\PayrollLineInputs;
use Illuminate\Foundation\Http\FormRequest;

/**
 * PUT .../tax: the tax for this month in whole rupees (0 or more) with a reason, or an empty amount to clear the
 * override. PayrollLineInputs::overrideTax checks the reason length and who may edit.
 */
class PayrollTaxOverrideRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('amount'))) {
            $amount = str_replace(',', '', trim($this->input('amount')));
            $this->merge(['amount' => $amount === '' ? null : $amount]);
        }

        if (is_string($this->input('reason'))) {
            $this->merge(['reason' => trim($this->input('reason'))]);
        }
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'amount' => ['bail', 'nullable', 'integer', 'min:0', 'max:'.PayrollLineInputs::MAX_RUPEES],
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'amount.integer' => 'Enter whole rupees.',
            'amount.min' => 'Enter whole rupees from 0 to 99,999,999.',
            'amount.max' => 'Enter whole rupees from 0 to 99,999,999.',
        ];
    }

    /** Whole rupees, or null to clear. */
    public function amount(): ?int
    {
        $amount = $this->validated('amount');

        return $amount === null ? null : (int) $amount;
    }
}
