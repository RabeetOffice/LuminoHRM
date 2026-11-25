<?php

namespace App\Http\Requests;

use App\Models\LeaveType;
use App\Support\Names;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Add or edit a leave type. Name required, at most 60 characters, unique ignoring case and spacing (checked here, not
 * by the database collation: SQLite and MariaDB compare case differently). Yearly quota 0 to 365 in steps of 0.5 for a
 * paid type; an unpaid type has no quota and no carry forward (both stored as 0 / off, whatever was sent). The
 * carry-forward cap (0.5 to 365, steps of 0.5) is read only when carry forward is on. Quota and cap are stored rounded
 * to the nearest half day. Colour from config('hrm.leave.colours').
 *
 * Rules that depend on the stored type (the system Unpaid type stays unpaid; the paid flag is fixed once the type has
 * leave history) are checked by LeaveTypeController under a row lock.
 */
class LeaveTypeRequest extends FormRequest
{
    public const MAX_DAYS = 365;

    /** Access is enforced by the can:settings.attendance-leave route middleware. */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['name' => Names::normalise($this->input('name'))]);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        /** @var LeaveType|null $type */
        $type = $this->route('leaveType');
        $paid = $this->boolean('is_paid');
        $carries = $paid && $this->boolean('carry_forward');

        return [
            'name' => [
                'bail', 'required', 'string', 'max:60',
                function (string $attribute, mixed $value, Closure $fail) use ($type): void {
                    if (! is_string($value)) {
                        return;
                    }

                    $taken = LeaveType::query()
                        ->whereRaw('LOWER(name) = ?', [Names::key($value)])
                        ->when($type, fn ($q) => $q->whereKeyNot($type->id))
                        ->exists();

                    if ($taken) {
                        $fail('A leave type with this name already exists.');
                    }
                },
            ],
            'is_paid' => ['required', 'boolean'],
            'yearly_quota' => $paid ? $this->daysRule('yearly quota') : ['nullable'],
            'carry_forward' => ['required', 'boolean'],
            // With carry forward on, a cap of 0 would carry nothing: at least half a day (turn carry forward off instead).
            'carry_forward_cap' => $carries ? $this->daysRule('carry-forward cap', 0.5) : ['nullable'],
            'is_active' => ['required', 'boolean'],
            'colour' => ['bail', 'required', 'string', Rule::in(config('hrm.leave.colours'))],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'colour.in' => 'Choose one of the colours shown.',
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'yearly_quota' => 'yearly quota',
            'carry_forward_cap' => 'carry-forward cap',
            'is_paid' => 'paid',
            'is_active' => 'active',
        ];
    }

    /**
     * The values to store, in the shapes the model expects. Unpaid: quota 0, no carry forward. No carry forward: cap 0.
     *
     * @return array{name: string, yearly_quota: float, is_paid: bool, carry_forward: bool, carry_forward_cap: float, is_active: bool, colour: string}
     */
    public function typeAttributes(): array
    {
        /** @var array{name: string, colour: string} $v */
        $v = $this->validated();
        $paid = $this->boolean('is_paid');
        $carries = $paid && $this->boolean('carry_forward');

        return [
            'name' => $v['name'],
            'yearly_quota' => $paid ? self::nearestHalf($this->input('yearly_quota')) : 0.0,
            'is_paid' => $paid,
            'carry_forward' => $carries,
            'carry_forward_cap' => $carries ? self::nearestHalf($this->input('carry_forward_cap')) : 0.0,
            'is_active' => $this->boolean('is_active'),
            'colour' => $v['colour'],
        ];
    }

    /** A validated day amount stored as an exact multiple of 0.5 (7.4999999 → 7.5). */
    private static function nearestHalf(mixed $value): float
    {
        return round((float) $value * 2) / 2;
    }

    /**
     * A number of days: $min (0 by default) to 365 in steps of 0.5.
     *
     * @return array<int, mixed>
     */
    private function daysRule(string $label, float $min = 0): array
    {
        return [
            'bail', 'required', 'numeric', 'between:'.$min.','.self::MAX_DAYS,
            function (string $attribute, mixed $value, Closure $fail) use ($label): void {
                $doubled = (float) $value * 2;

                if (abs($doubled - round($doubled)) > 1e-9) {
                    $fail("The {$label} must be a whole or half number of days.");
                }
            },
        ];
    }
}
