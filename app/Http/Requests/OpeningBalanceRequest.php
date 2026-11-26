<?php

namespace App\Http\Requests;

use App\Support\Payroll\Money;
use Illuminate\Foundation\Http\FormRequest;

/**
 * One person's opening tax figures for a tax year (PUT /payroll/opening/{employee}/{taxYear}): taxable income and tax
 * deducted to date, whole rupees 0 or more; an optional note. The tax year and who may edit are checked by
 * OpeningFiguresController / SaveOpeningBalance (404 / 403).
 */
class OpeningBalanceRequest extends FormRequest
{
    public const MAX_RUPEES = 99_999_999;

    /** Access: can:payroll.run on the route, PayrollAccess::mayEditSalary in SaveOpeningBalance. */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        foreach (['taxable_income', 'tax_deducted'] as $field) {
            $value = $this->input($field);

            if (is_string($value)) {
                $this->merge([$field => str_replace(',', '', trim($value))]);
            }
        }

        if (is_string($this->input('note'))) {
            $this->merge(['note' => trim($this->input('note'))]);
        }
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'taxable_income' => ['bail', 'required', 'integer', 'min:0', 'max:'.self::MAX_RUPEES],
            'tax_deducted' => ['bail', 'required', 'integer', 'min:0', 'max:'.self::MAX_RUPEES],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function taxableIncome(): int
    {
        return Money::rupees((int) $this->input('taxable_income'));
    }

    public function taxDeducted(): int
    {
        return Money::rupees((int) $this->input('tax_deducted'));
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'taxable_income.integer' => 'Enter whole rupees.',
            'tax_deducted.integer' => 'Enter whole rupees.',
        ];
    }
}
