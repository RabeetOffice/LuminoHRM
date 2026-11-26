<?php

namespace App\Http\Requests\Performance;

use App\Actions\Performance\ChangeReviewer;
use Illuminate\Foundation\Http\FormRequest;

/** HR changes who reviews: reviewer_id, an employee id, or null for "HR". Who may be chosen is ChangeReviewer's rule. */
class ReviewerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return ['reviewer_id' => ['present', 'nullable', 'integer']];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'reviewer_id.present' => ChangeReviewer::UNKNOWN,
            'reviewer_id.integer' => ChangeReviewer::UNKNOWN,
        ];
    }

    public function reviewerId(): ?int
    {
        $id = $this->input('reviewer_id');

        return $id === null || $id === '' ? null : (int) $id;
    }
}
