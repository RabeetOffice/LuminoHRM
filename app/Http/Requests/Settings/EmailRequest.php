<?php

namespace App\Http\Requests\Settings;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\Rule;

class EmailRequest extends SettingsGroupRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            // A host name or IP address only: no scheme, path or spaces.
            'host' => ['bail', 'nullable', 'string', 'max:255', 'regex:/^[A-Za-z0-9]([A-Za-z0-9.\-]*[A-Za-z0-9])?\z/'],
            'port' => ['bail', 'required_with:host', 'nullable', 'integer', 'between:1,65535'],
            'username' => ['bail', 'nullable', 'string', 'max:255'],
            'password' => ['bail', 'nullable', 'string', 'max:255'],
            'clear_password' => ['sometimes', 'boolean'],
            'encryption' => ['bail', 'required', 'string', Rule::in(['tls', 'ssl', 'none'])],
            'from_address' => ['bail', 'required_with:host', 'nullable', 'email', 'max:255'],
            'from_name' => ['bail', 'nullable', 'string', 'max:100'],
        ];
    }

    /**
     * The password is encrypted here and only when one was typed. A blank field keeps the
     * stored one; "clear_password" removes it. It never leaves this method in plain text.
     *
     * @return array<string, mixed>
     */
    public function settingsValues(): array
    {
        $values = $this->safe()->except(['password', 'clear_password']);

        // A blank port with no host keeps the default rather than storing null.
        if (($values['port'] ?? null) === null) {
            unset($values['port']);
        }

        $typed = $this->validated('password');

        if ($this->boolean('clear_password')) {
            $values['password'] = '';
        } elseif (is_string($typed) && $typed !== '') {
            $values['password'] = Crypt::encryptString($typed);
        }

        return $values;
    }
}
