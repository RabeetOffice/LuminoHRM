<?php

namespace App\Http\Requests\Auth;

use App\Concerns\PasswordValidationRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/** The first sign-in password change: the temporary password, then a new one under the app's usual password rules. */
class FirstLoginPasswordRequest extends FormRequest
{
    use PasswordValidationRules;

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'current_password' => $this->currentPasswordRules(),
            'password' => [...$this->passwordRules(), 'different:current_password'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'password.different' => 'Choose a password that is different from the temporary one.',
        ];
    }
}
