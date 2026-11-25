<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A decision on a leave request: approve or reject. A rejection needs a reason of 5 to 500 characters (the employee
 * sees it); an approval note is optional (at most 500).
 */
class LeaveDecisionRequest extends FormRequest
{
    /** Access is checked by the route middleware and LeaveRouting (who decides which step). */
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
        $reject = $this->input('decision') === 'reject';

        return [
            'decision' => ['bail', 'required', 'string', 'in:approve,reject'],
            'note' => ['bail', 'nullable', 'required_if:decision,reject', 'string', ...($reject ? ['min:5'] : []), 'max:500'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'decision.in' => 'Choose approve or reject.',
            'note.required_if' => 'Say why the request is rejected. The employee will see this note.',
            'note.min' => 'Say why the request is rejected (at least 5 characters).',
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
