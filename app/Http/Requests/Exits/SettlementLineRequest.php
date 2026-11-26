<?php

namespace App\Http\Requests\Exits;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * One HR line on a settlement draft: kind (bonus is an addition, notice_shortfall a deduction, other either), label and
 * a whole-rupee amount. The limits (20 lines, 1 to 10,000,000 rupees) are SaveSettlementLine's.
 */
class SettlementLineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['label' => is_string($this->input('label')) ? trim($this->input('label')) : $this->input('label')]);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'kind' => ['required', Rule::in((array) config('hrm.exit.line_kinds'))],
            'type' => ['nullable', Rule::in(['addition', 'deduction'])],
            'label' => ['required', 'string', 'max:100'],
            'amount' => ['required', 'integer', 'min:1', 'max:10000000'],
            'is_taxable' => ['nullable', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        $amount = 'Enter an amount between 1 and 10,000,000 rupees.';

        return [
            'kind.required' => 'Choose the kind of line.',
            'kind.in' => 'Choose the kind of line.',
            'label.required' => 'Enter a label.',
            'label.max' => 'The label can be at most 100 characters.',
            'amount.required' => $amount,
            'amount.integer' => $amount,
            'amount.min' => $amount,
            'amount.max' => $amount,
        ];
    }

    /**
     * The line as SaveSettlementLine takes it: type follows the kind (other chooses), amount in whole rupees.
     *
     * @return array{kind: string, type: string, label: string, amount: int, is_taxable: bool}
     */
    public function line(): array
    {
        $kind = (string) $this->validated('kind');
        $type = match ($kind) {
            'bonus' => 'addition',
            'notice_shortfall' => 'deduction',
            default => (string) ($this->validated('type') ?? 'addition'),
        };

        return [
            'kind' => $kind,
            'type' => $type,
            'label' => (string) $this->validated('label'),
            'amount' => (int) $this->validated('amount'),
            'is_taxable' => $type === 'addition' && $this->boolean('is_taxable', true),
        ];
    }
}
