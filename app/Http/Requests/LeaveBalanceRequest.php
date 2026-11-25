<?php

namespace App\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * HR's edit of one balance row (PUT /leave/balances/{employee}/{type}/{year}). Allocated 0 to 365, adjustment −365 to
 * 365, opening used 0 to 365, each in steps of 0.5; a reason of 5 to 255 characters (plan open decision 17);
 * confirm_overdrawn optional. Who may edit whose balance, the type and the year are checked by BalanceController and
 * AdjustBalance (403 / 404); the overdrawn rule by AdjustBalance.
 */
class LeaveBalanceRequest extends FormRequest
{
    public const MAX_DAYS = 365;

    /** Access: can:leave.approve-all on the route, LeaveRouting::mayAdminister in the controller. */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $reason = $this->input('reason');

        if (is_string($reason)) {
            $this->merge(['reason' => trim($reason)]);
        }
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'allocated' => $this->daysRule(0),
            'adjustment' => $this->daysRule(-self::MAX_DAYS),
            'opening_used' => $this->daysRule(0),
            'reason' => ['bail', 'required', 'string', 'min:5', 'max:255'],
            'confirm_overdrawn' => ['sometimes', 'boolean'],
            // The figures the dialog was opened with (the stale-edit guard in AdjustBalance). Optional: all three or none.
            'original_allocated' => ['nullable', 'numeric', 'required_with:original_adjustment,original_opening_used'],
            'original_adjustment' => ['nullable', 'numeric', 'required_with:original_allocated,original_opening_used'],
            'original_opening_used' => ['nullable', 'numeric', 'required_with:original_allocated,original_adjustment'],
        ];
    }

    /**
     * The figures the form was opened with, or null when the client did not send them.
     *
     * @return array{allocated: float, adjustment: float, opening_used: float}|null
     */
    public function expected(): ?array
    {
        if (! $this->filled('original_allocated')) {
            return null;
        }

        return [
            'allocated' => (float) $this->input('original_allocated'),
            'adjustment' => (float) $this->input('original_adjustment'),
            'opening_used' => (float) $this->input('original_opening_used'),
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'opening_used' => 'opening used',
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'reason.required' => 'Give a reason for this change.',
            'reason.min' => 'The reason must be at least 5 characters.',
        ];
    }

    /** @return array<int, mixed> */
    private function daysRule(int $min): array
    {
        return [
            'bail', 'required', 'numeric', 'between:'.$min.','.self::MAX_DAYS,
            function (string $attribute, mixed $value, Closure $fail): void {
                $doubled = (float) $value * 2;

                if (abs($doubled - round($doubled)) > 1e-9) {
                    $fail('The :attribute must be a whole or half number of days.');
                }
            },
        ];
    }
}
