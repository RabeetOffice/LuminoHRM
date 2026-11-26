<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /payroll/runs: the month to generate ("YYYY-MM"). The allowed window (up to the current month, after the latest
 * approved one) is GeneratePayrollRun's; access is can:payroll.run on the route.
 */
class PayrollGenerateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'month' => ['bail', 'required', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['month.required' => 'Choose a month.', 'month.regex' => 'Choose a month.'];
    }
}
