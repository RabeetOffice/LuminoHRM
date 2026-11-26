<?php

namespace App\Http\Requests\Assets;

use App\Actions\Assets\AssignAsset;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Change the hand-over note and the return note of an assignment (the only fields that can change once it exists).
 */
class AssignmentNoteRequest extends FormRequest
{
    /** Access is enforced by the can:assets.assign route middleware. */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'note' => ['nullable', 'string', 'max:'.AssignAsset::NOTE_MAX],
            'return_note' => ['nullable', 'string', 'max:'.AssignAsset::NOTE_MAX],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'note.max' => AssignAsset::NOTE_TOO_LONG,
            'return_note.max' => AssignAsset::NOTE_TOO_LONG,
        ];
    }
}
