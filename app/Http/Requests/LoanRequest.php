<?php

namespace App\Http\Requests;

use App\Support\Payroll\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A loan or advance from the loan dialog: POST /payroll/loans (create) and PUT /payroll/loans/{loan} (edit). Amounts
 * are whole rupees (integers, turned into paisa by figures()); months "YYYY-MM".
 *
 * Create: employee_id, type (loan | advance), amount, installment (optional on an advance: the whole amount),
 * start_month, opening_repaid (optional, 0: "repaid before go-live" for a loan already running), note.
 * Edit: installment and note; amount, start_month and opening_repaid as the dialog sends them (SaveLoan refuses a
 * change to them once the loan has repayments).
 *
 * Who may and the money rules between fields are SaveLoan's (403 / errors on the same fields).
 */
class LoanRequest extends FormRequest
{
    public const MAX_RUPEES = 99_999_999;

    /** Access: can:payroll.run on the route, PayrollAccess::mayEditSalary in SaveLoan. */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        foreach (['amount', 'installment', 'opening_repaid'] as $field) {
            $value = $this->input($field);

            if (is_string($value)) {
                $value = str_replace(',', '', trim($value));
                $this->merge([$field => $value === '' ? null : $value]);
            }
        }

        if (is_string($this->input('note'))) {
            $this->merge(['note' => trim($this->input('note'))]);
        }
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $creating = $this->isMethod('POST');
        $rupees = ['integer', 'min:1', 'max:'.self::MAX_RUPEES];

        return [
            'employee_id' => $creating ? ['bail', 'required', 'integer', 'exists:employees,id'] : ['prohibited'],
            'type' => $creating ? ['bail', 'required', Rule::in((array) config('hrm.payroll.loan_types'))] : ['prohibited'],
            'amount' => ['bail', 'required', ...$rupees],
            'installment' => [
                'bail',
                $creating ? Rule::requiredIf(fn () => $this->input('type') !== 'advance') : 'required',
                'nullable',
                ...$rupees,
            ],
            'start_month' => ['bail', 'required', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'opening_repaid' => ['bail', 'nullable', 'integer', 'min:0', 'max:'.self::MAX_RUPEES],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * The figures in paisa, for SaveLoan. On an edit only the keys the request sent are included: an omitted
     * opening_repaid or note is left as stored (SaveLoan keeps what is not in the array).
     *
     * @return array{type?: string, amount: int, installment: int|null, start_month: string, opening_repaid?: int, note?: string|null}
     */
    public function figures(): array
    {
        $installment = $this->input('installment');
        $creating = $this->isMethod('POST');

        $figures = [
            'amount' => Money::rupees((int) $this->input('amount')),
            'installment' => $installment === null ? null : Money::rupees((int) $installment),
            'start_month' => (string) $this->input('start_month'),
        ];

        if ($creating || $this->has('opening_repaid')) {
            $figures['opening_repaid'] = Money::rupees((int) ($this->input('opening_repaid') ?? 0));
        }

        if ($creating || $this->has('note')) {
            $figures['note'] = $this->filled('note') ? (string) $this->input('note') : null;
        }

        if ($creating) {
            $figures['type'] = (string) $this->input('type');
        }

        return $figures;
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'employee_id' => 'employee',
            'start_month' => 'first deduction month',
            'opening_repaid' => 'repaid before go-live',
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'employee_id.required' => 'Choose the employee.',
            'start_month.required' => 'Choose the first deduction month.',
            'start_month.regex' => 'Choose the first deduction month.',
            'amount.integer' => 'Enter whole rupees.',
            'installment.integer' => 'Enter whole rupees.',
            'opening_repaid.integer' => 'Enter whole rupees.',
        ];
    }
}
