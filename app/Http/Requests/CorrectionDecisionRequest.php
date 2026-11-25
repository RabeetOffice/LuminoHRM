<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** A decision on a correction request: approve or reject. A rejection needs a note (it goes to the requester). */
class CorrectionDecisionRequest extends FormRequest
{
    /** Access is checked by the route middleware and CorrectionRouting (who decides which step). */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $note = $this->input('note');

        $this->merge(['note' => is_string($note) ? (trim($note) === '' ? null : trim($note)) : $note]);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'decision' => ['bail', 'required', 'string', 'in:approve,reject'],
            'note' => ['bail', 'nullable', 'required_if:decision,reject', 'string', 'max:500'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'decision.in' => 'Choose approve or reject.',
            'note.required_if' => 'Say why the request is rejected. The employee will see this note.',
        ];
    }

    public function approves(): bool
    {
        return $this->validated('decision') === 'approve';
    }

    public function note(): ?string
    {
        $note = $this->validated('note');

        return is_string($note) ? $note : null;
    }
}
