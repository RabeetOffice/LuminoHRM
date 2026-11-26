<?php

namespace App\Http\Requests\Performance;

use App\Actions\Performance\SendBackReview;
use Illuminate\Foundation\Http\FormRequest;

/** HR sends a review back: a note for the manager (required, up to 500). */
class SendBackRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return ['note' => ['required', 'string', 'max:500']];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'note.required' => SendBackReview::NOTE_REQUIRED,
            'note.string' => SendBackReview::NOTE_REQUIRED,
            'note.max' => SendBackReview::NOTE_TOO_LONG,
        ];
    }
}
