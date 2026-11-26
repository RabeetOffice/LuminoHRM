<?php

namespace App\Http\Requests\Performance;

use App\Actions\Performance\SkipReview;
use Illuminate\Foundation\Http\FormRequest;

/** HR skips a review: a reason (required, up to 500). */
class SkipRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'max:500']];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'reason.required' => SkipReview::REASON_REQUIRED,
            'reason.string' => SkipReview::REASON_REQUIRED,
            'reason.max' => SkipReview::REASON_TOO_LONG,
        ];
    }
}
