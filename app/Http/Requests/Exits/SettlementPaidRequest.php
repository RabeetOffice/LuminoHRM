<?php

namespace App\Http\Requests\Exits;

use App\Actions\Exits\ExitGuards;
use Illuminate\Foundation\Http\FormRequest;

/** The day the settlement was paid (not in the future, not before approval: MarkSettlementPaid checks). */
class SettlementPaidRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return ['paid_on' => ['required', 'date_format:Y-m-d']];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'paid_on.required' => ExitGuards::BAD_DATE,
            'paid_on.date_format' => ExitGuards::BAD_DATE,
        ];
    }
}
