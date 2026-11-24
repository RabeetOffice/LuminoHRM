<?php

namespace App\Http\Requests;

use App\Actions\Employees\ChangeEmployeeStatus;
use App\Models\Employee;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * Body of PATCH /employees/{employee}/status: the new status and, for resigned and terminated, the last working day
 * (not before the joining date, not more than a year ahead). A day sent with active or probation is ignored.
 */
class EmployeeStatusRequest extends FormRequest
{
    /** How far ahead a last working day may be (a notice period is months, not years). */
    public const MAX_DAYS_AHEAD = 365;

    public function authorize(): bool
    {
        return $this->user()?->can('employees.manage') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $needsDay = in_array($this->input('status'), ChangeEmployeeStatus::NEEDS_DAY, true);
        $disabling = in_array($this->input('status'), ChangeEmployeeStatus::DISABLING, true);

        return [
            'status' => ['bail', 'required', 'string', Rule::in(config('hrm.employees.statuses'))],
            'last_working_day' => [
                'bail',
                $needsDay ? 'required' : 'nullable',
                ...($disabling ? ['date_format:Y-m-d', $this->withinRange()] : []),
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['last_working_day.required' => 'Enter the last working day.'];
    }

    private function withinRange(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $employee = $this->route('employee');

            try {
                $day = Carbon::createFromFormat('!Y-m-d', (string) $value);
            } catch (Throwable) {
                return;
            }

            if ($employee instanceof Employee && $day !== null && $day->lt($employee->joining_date->copy()->startOfDay())) {
                $fail('The last working day cannot be before the joining date.');

                return;
            }

            if ($day !== null && $day->gt(Carbon::today()->addDays(self::MAX_DAYS_AHEAD))) {
                $fail('The last working day cannot be more than a year from today.');
            }
        };
    }
}
