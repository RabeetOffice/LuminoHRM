<?php

namespace App\Http\Requests;

use App\Models\Department;
use App\Support\Names;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

class DepartmentRequest extends FormRequest
{
    /** Access is enforced by the can:org.manage route middleware. */
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
        /** @var Department|null $department */
        $department = $this->route('department');

        return [
            'name' => [
                'bail', 'required', 'string', 'max:100',
                function (string $attribute, mixed $value, Closure $fail) use ($department): void {
                    if (! is_string($value)) {
                        return;
                    }

                    $taken = Department::query()
                        ->whereRaw('LOWER(name) = ?', [Names::key($value)])
                        ->when($department, fn ($q) => $q->whereKeyNot($department->id))
                        ->exists();

                    if ($taken) {
                        $fail('A department with this name already exists.');
                    }
                },
            ],
        ];
    }
}
