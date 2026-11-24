<?php

namespace App\Http\Requests;

use App\Models\Shift;
use App\Support\Names;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Add or edit a shift. Same-day shifts only (the end must be after the start); grace 0 to 120 minutes; half day 1 to
 * 12 hours and no longer than the shift; weekly offs a subset of the lowercase day names. Which shift is the default is not set here
 * (ShiftController::makeDefault).
 */
class ShiftRequest extends FormRequest
{
    /** Access is enforced by the can:attendance.edit route middleware. */
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
        /** @var Shift|null $shift */
        $shift = $this->route('shift');

        return [
            'name' => [
                'bail', 'required', 'string', 'max:100',
                function (string $attribute, mixed $value, Closure $fail) use ($shift): void {
                    if (! is_string($value)) {
                        return;
                    }

                    $taken = Shift::query()
                        ->whereRaw('LOWER(name) = ?', [Names::key($value)])
                        ->when($shift, fn ($q) => $q->whereKeyNot($shift->id))
                        ->exists();

                    if ($taken) {
                        $fail('A shift with this name already exists.');
                    }
                },
            ],
            'start_time' => ['bail', 'required', 'string', 'date_format:H:i'],
            'end_time' => ['bail', 'required', 'string', 'date_format:H:i', 'after:start_time'],
            'grace_minutes' => ['bail', 'required', 'integer', 'between:0,120'],
            'half_day_hours' => ['bail', 'required', 'numeric', 'decimal:0,2', 'between:1,12'],
            'weekly_offs' => ['present', 'array', 'max:7'],
            'weekly_offs.*' => ['bail', 'string', 'distinct', Rule::in(config('hrm.attendance.weekdays'))],
        ];
    }

    /**
     * Once each field is valid on its own: the half-day threshold cannot be longer than the shift itself, or every
     * full day worked would count as a half day.
     *
     * @return array<int, Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $errors = $validator->errors();

                if ($errors->hasAny(['start_time', 'end_time', 'half_day_hours'])) {
                    return;
                }

                $minutes = fn (string $time): int => (int) substr($time, 0, 2) * 60 + (int) substr($time, 3, 2);
                $length = $minutes((string) $this->input('end_time')) - $minutes((string) $this->input('start_time'));

                if ((float) $this->input('half_day_hours') * 60 > $length) {
                    $hours = rtrim(rtrim(number_format($length / 60, 2, '.', ''), '0'), '.');
                    $errors->add('half_day_hours', "The half day cannot be longer than the shift ({$hours} hours).");
                }
            },
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'end_time.after' => 'The shift must end after it starts, on the same day.',
            'weekly_offs.*.in' => 'Choose days of the week.',
            'weekly_offs.*.distinct' => 'Each day can be chosen once.',
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'start_time' => 'start time',
            'end_time' => 'end time',
            'grace_minutes' => 'grace period',
            'half_day_hours' => 'half-day hours',
            'weekly_offs' => 'weekly offs',
        ];
    }

    /**
     * The values to store, in the shapes the model expects.
     *
     * @return array{name: string, start_time: string, end_time: string, grace_minutes: int, half_day_hours: string, weekly_offs: list<string>}
     */
    public function shiftAttributes(): array
    {
        /** @var array{name: string, start_time: string, end_time: string, grace_minutes: int|string, half_day_hours: int|float|string, weekly_offs: array<int, string>} $v */
        $v = $this->validated();

        return [
            'name' => $v['name'],
            'start_time' => $v['start_time'],
            'end_time' => $v['end_time'],
            'grace_minutes' => (int) $v['grace_minutes'],
            'half_day_hours' => number_format((float) $v['half_day_hours'], 2, '.', ''),
            'weekly_offs' => Shift::normaliseWeeklyOffs($v['weekly_offs']),
        ];
    }
}
