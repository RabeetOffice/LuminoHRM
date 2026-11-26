<?php

namespace App\Http\Requests\Settings;

use Illuminate\Validation\Rule;

class PayrollRequest extends SettingsGroupRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'day_pay_basis' => ['bail', 'required', 'string', Rule::in(['calendar_days', 'thirty', 'working_days'])],
            'default_tax_mode' => ['bail', 'required', 'string', Rule::in(['none', 'fixed', 'percentage'])],
            'leave_encashment' => ['required', 'boolean'],
            'notice_period_days' => ['bail', 'required', 'integer', 'between:0,365'],
            'payslip_footer' => ['bail', 'nullable', 'string', 'max:500'],
            // First month of the tax year (7 = July to June).
            'tax_year_start_month' => ['bail', 'required', 'integer', 'between:1,12'],
        ];
    }
}
