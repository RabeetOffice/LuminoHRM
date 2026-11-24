<?php

namespace App\Http\Requests;

use App\Models\Holiday;
use App\Support\Names;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Add or edit a company holiday: a name and one calendar date ("Y-m-d"), one holiday per date. */
class HolidayRequest extends FormRequest
{
    /** Access is enforced by the can:settings.attendance-leave route middleware. */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['name' => Names::normalise($this->input('name'))]);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        /** @var Holiday|null $holiday */
        $holiday = $this->route('holiday');

        return [
            'name' => ['bail', 'required', 'string', 'max:100'],
            'date' => [
                'bail', 'required', 'string', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:2100-12-31',
                Rule::unique('holidays', 'date')->ignore($holiday?->id),
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'date.unique' => 'There is already a holiday on this date.',
        ];
    }
}
