<?php

namespace App\Http\Requests;

use App\Support\Payroll\Money;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * A salary profile as the Salary Profiles form sends it (POST /payroll/salary-profiles/{employee},
 * PUT /payroll/salary-profiles/{employee}/{profile}). Money is typed in WHOLE rupees (integers, no grouping commas: the
 * page strips them) and turned into paisa here; a percentage tax is typed as a percentage with at most two decimals and
 * turned into basis points with Money::bp (string arithmetic, no float). profileData() is what SaveSalaryProfile takes.
 *
 *   effective_from      required Y-m-d (joining date, locked months and duplicates: SaveSalaryProfile)
 *   basic               integer rupees 0 to 99,999,999
 *   tax_mode            none | fixed | percentage
 *   tax_value_rupees    fixed: integer rupees 0 to 99,999,999
 *   tax_percent         percentage: 0 to 100, at most two decimals
 *   tax_base            percentage: gross_taxable (default) | basic
 *   components[]        at most 20: name 1-60 (unique in the profile, any case), type allowance | deduction,
 *                       amount integer rupees 1 to 99,999,999, is_taxable (allowances only; a deduction is never taxable)
 *   note                at most 255
 *
 * Who may write is checked by SaveSalaryProfile (PayrollAccess::mayEditSalary, 403); the route needs payroll.run.
 */
class SalaryProfileRequest extends FormRequest
{
    public const MAX_RUPEES = 99_999_999;

    /** Access: can:payroll.run on the route, PayrollAccess::mayEditSalary in SaveSalaryProfile. */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $merge = [];

        foreach (['note', 'effective_from'] as $field) {
            $value = $this->input($field);
            if (is_string($value)) {
                $merge[$field] = trim($value);
            }
        }

        // A percentage sent as a JSON number is read as the text people typed ("5.25").
        $percent = $this->input('tax_percent');
        if (is_int($percent) || is_float($percent)) {
            $merge['tax_percent'] = (string) $percent;
        } elseif (is_string($percent)) {
            $merge['tax_percent'] = trim($percent);
        }

        $components = $this->input('components');
        if (is_array($components)) {
            $merge['components'] = array_map(function (mixed $c): mixed {
                if (is_array($c) && is_string($c['name'] ?? null)) {
                    $c['name'] = trim($c['name']);
                }

                return $c;
            }, $components);
        }

        if ($merge !== []) {
            $this->merge($merge);
        }
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $rupees = fn (int $min): array => ['bail', 'required', 'integer', 'min:'.$min, 'max:'.self::MAX_RUPEES];
        $mode = $this->input('tax_mode');

        return [
            'effective_from' => ['bail', 'required', 'string', 'date_format:Y-m-d'],
            'basic' => $rupees(0),
            'tax_mode' => ['bail', 'required', 'string', Rule::in((array) config('hrm.payroll.tax_modes'))],
            'tax_value_rupees' => $mode === 'fixed' ? $rupees(0) : ['nullable'],
            'tax_percent' => $mode === 'percentage' ? ['bail', 'required', 'string', function (string $attribute, mixed $value, Closure $fail): void {
                try {
                    Money::bp((string) $value);
                } catch (InvalidArgumentException) {
                    $fail('The tax rate must be a percentage from 0 to 100 with at most two decimals.');
                }
            }] : ['nullable'],
            'tax_base' => $mode === 'percentage'
                ? ['bail', 'nullable', 'string', Rule::in((array) config('hrm.payroll.tax_bases'))]
                : ['nullable'],
            'note' => ['nullable', 'string', 'max:255'],
            'components' => ['nullable', 'array', 'max:'.(int) config('hrm.payroll.max_components', 20), $this->uniqueNames(...)],
            'components.*' => ['array'],
            'components.*.name' => ['bail', 'required', 'string', 'min:1', 'max:60'],
            'components.*.type' => ['bail', 'required', 'string', Rule::in((array) config('hrm.payroll.component_types'))],
            'components.*.amount' => $rupees(1),
            'components.*.is_taxable' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * What SaveSalaryProfile takes: money in paisa, the tax value in paisa (fixed), basis points (percentage) or 0.
     *
     * @return array{effective_from: string, basic: int, tax_mode: string, tax_value: int, tax_base: string|null, note: string|null, components: list<array{name: string, type: string, amount: int, is_taxable: bool}>}
     */
    public function profileData(): array
    {
        $mode = (string) $this->validated('tax_mode');
        $note = $this->validated('note');

        $components = [];
        foreach ((array) ($this->validated('components') ?? []) as $c) {
            $type = (string) $c['type'];
            $components[] = [
                'name' => (string) $c['name'],
                'type' => $type,
                'amount' => Money::rupees((int) $c['amount']),
                // A fixed deduction is never taxable (the model forces it too).
                'is_taxable' => $type === 'allowance' && filter_var($c['is_taxable'] ?? false, FILTER_VALIDATE_BOOLEAN),
            ];
        }

        return [
            'effective_from' => (string) $this->validated('effective_from'),
            'basic' => Money::rupees((int) $this->validated('basic')),
            'tax_mode' => $mode,
            'tax_value' => match ($mode) {
                'fixed' => Money::rupees((int) $this->validated('tax_value_rupees')),
                'percentage' => Money::bp((string) $this->validated('tax_percent')),
                default => 0,
            },
            'tax_base' => $mode === 'percentage' ? ((string) ($this->validated('tax_base') ?? '') ?: 'gross_taxable') : null,
            'note' => is_string($note) && $note !== '' ? $note : null,
            'components' => $components,
        ];
    }

    /** Component names are unique within the profile, whatever the case. */
    private function uniqueNames(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_array($value)) {
            return;
        }

        $seen = [];
        foreach ($value as $c) {
            $name = is_array($c) && is_string($c['name'] ?? null) ? mb_strtolower(trim($c['name'])) : null;

            if ($name === null || $name === '') {
                continue;
            }

            if (isset($seen[$name])) {
                $fail('Each allowance and deduction needs its own name ("'.trim((string) $c['name']).'" appears twice).');

                return;
            }

            $seen[$name] = true;
        }
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'effective_from' => 'effective date',
            'basic' => 'basic salary',
            'tax_mode' => 'tax',
            'tax_value_rupees' => 'tax amount',
            'tax_percent' => 'tax rate',
            'tax_base' => 'tax base',
            'components.*.name' => 'name',
            'components.*.type' => 'type',
            'components.*.amount' => 'amount',
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'basic.integer' => 'Enter the basic salary in whole rupees.',
            'tax_value_rupees.integer' => 'Enter the tax amount in whole rupees.',
            'components.*.amount.integer' => 'Enter the amount in whole rupees.',
            'components.*.amount.min' => 'The amount must be at least Rs 1.',
            'components.max' => 'A salary can have at most :max allowances and deductions.',
        ];
    }
}
