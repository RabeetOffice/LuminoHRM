<?php

namespace App\Http\Requests;

use App\Models\Designation;
use App\Support\Names;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

class DesignationRequest extends FormRequest
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
        /** @var Designation|null $designation */
        $designation = $this->route('designation');

        return [
            'department_id' => ['required', 'integer', 'exists:departments,id'],
            'name' => [
                'required', 'string', 'max:100',
                function (string $attribute, mixed $value, Closure $fail) use ($designation): void {
                    $taken = Designation::query()
                        ->where('department_id', (int) $this->input('department_id'))
                        ->whereRaw('LOWER(name) = ?', [Names::key($value)])
                        ->when($designation, fn ($q) => $q->whereKeyNot($designation->id))
                        ->exists();

                    if ($taken) {
                        $fail('This department already has a designation with this name.');
                    }
                },
            ],
        ];
    }
}
