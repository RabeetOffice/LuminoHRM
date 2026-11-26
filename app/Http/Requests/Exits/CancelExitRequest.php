<?php

namespace App\Http\Requests\Exits;

use App\Actions\Exits\CancelExit;
use Illuminate\Foundation\Http\FormRequest;

/** The reason an approved exit is cancelled (CancelExit checks its length). */
class CancelExitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return ['reason' => ['required', 'string']];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['reason.required' => CancelExit::NO_REASON];
    }
}
