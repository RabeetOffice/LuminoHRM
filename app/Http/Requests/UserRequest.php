<?php

namespace App\Http\Requests;

use App\Models\User;
use App\Support\Names;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create and edit an account: name, email and one role. Nothing else is read
 * from the request; is_active, password and verification have their own code paths.
 */
class UserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('users.manage') ?? false;
    }

    protected function prepareForValidation(): void
    {
        $merge = [];

        if (is_string($this->input('name'))) {
            $merge['name'] = Names::normalise($this->input('name'));
        }

        if (is_string($this->input('email'))) {
            $merge['email'] = mb_strtolower(trim($this->input('email')));
        }

        $this->merge($merge);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['bail', 'required', 'string', 'max:255'],
            'email' => ['bail', 'required', 'string', 'email', 'max:255', $this->emailIsFree()],
            'role' => ['bail', 'required', 'string', Rule::in(config('hrm.roles'))],
        ];
    }

    /** Case-insensitive uniqueness, ignoring the account being edited. */
    private function emailIsFree(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $query = User::query()->whereRaw('LOWER(email) = ?', [mb_strtolower((string) $value)]);

            if ($editing = $this->route('user')) {
                $query->whereKeyNot($editing instanceof User ? $editing->getKey() : $editing);
            }

            if ($query->exists()) {
                $fail('A user with this email already exists.');
            }
        };
    }
}
