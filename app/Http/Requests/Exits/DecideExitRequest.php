<?php

namespace App\Http\Requests\Exits;

use App\Actions\Exits\DecideExit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Approve or reject an exit at the step the user may decide; a rejection needs a note (DecideExit refuses a missing one). */
class DecideExitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'decision' => ['required', Rule::in(['approve', 'reject'])],
            'note' => ['nullable', 'string'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'decision.required' => 'Choose approve or reject.',
            'decision.in' => 'Choose approve or reject.',
            'note.string' => DecideExit::NO_NOTE,
        ];
    }
}
