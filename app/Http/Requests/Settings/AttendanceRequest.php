<?php

namespace App\Http\Requests\Settings;

use App\Http\Requests\ShiftRequest;
use App\Models\Shift;
use App\Support\Settings;
use Closure;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class AttendanceRequest extends SettingsGroupRequest
{
    public const DAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'shift_start' => ['bail', 'required', 'string', 'date_format:H:i'],
            'shift_end' => ['bail', 'required', 'string', 'date_format:H:i', 'after:shift_start'],
            'grace_minutes' => ['bail', 'required', 'integer', 'between:0,120'],
            'weekly_offs' => ['present', 'array', 'max:7'],
            'weekly_offs.*' => ['bail', 'string', 'distinct', Rule::in(self::DAYS)],
            'late_rule_count' => ['bail', 'required', 'integer', 'between:1,31'],
            'half_day_hours' => ['bail', 'required', 'integer', 'between:1,12'],
            'overtime_enabled' => ['required', 'boolean'],
            'overtime_min_minutes' => ['bail', 'required', 'integer', 'between:0,480'],
            // Null: attendance has not started, no absent / weekend / holiday day is recorded yet.
            'start_date' => ['bail', 'nullable', 'string', 'date_format:Y-m-d'],
            // A punch less than this many minutes after the previous punch is a double tap (0 = off).
            'duplicate_punch_minutes' => ['bail', 'required', 'integer', 'between:0,60'],
            // A punch before this time belongs to the previous day (its clock-out after a late night). Changing it
            // affects punches stored from then on; stored punches keep their day.
            'day_cutoff_time' => ['bail', 'required', 'string', 'date_format:H:i', 'before_or_equal:06:00'],
        ];
    }

    /**
     * The cutoff must stay at least 2 hours before every shift's start (the other side of ShiftRequest's rule), or a
     * shift's early clock-ins would be counted on the previous day. Checked only when the cutoff actually changes, so
     * saving another card of this group is never blocked by it.
     *
     * While no shift exists yet (a fresh install), the legacy shift_start of this group is what the default shift will
     * be created from (DefaultShift), so it is held to the same rule: checked when the cutoff or shift_start changes.
     *
     * @return array<int, Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->has('day_cutoff_time')) {
                    return;
                }

                $cutoff = (string) $this->input('day_cutoff_time');
                $group = Settings::group('attendance');
                $stored = $group['day_cutoff_time'] ?? null;
                $minutes = fn (string $time): int => (int) substr($time, 0, 2) * 60 + (int) substr($time, 3, 2);
                $earliest = Shift::query()->orderBy('start_time')->first(['name', 'start_time']);

                if ($earliest === null) {
                    $this->checkSettingsShiftStart($validator, $cutoff, $stored, $group['shift_start'] ?? null, $minutes);

                    return;
                }

                if ($cutoff === $stored) {
                    return;
                }

                $latest = $minutes((string) $earliest->start_time) - ShiftRequest::CUTOFF_MARGIN_MINUTES;

                if ($minutes($cutoff) > $latest) {
                    $validator->errors()->add('day_cutoff_time', $latest < 0
                        ? sprintf('The shift "%s" starts at %s, too early for any day cutoff: it must start at 02:00 or later.', $earliest->name, substr((string) $earliest->start_time, 0, 5))
                        : sprintf(
                            'The day cutoff must be %02d:%02d or earlier: at least 2 hours before the earliest shift start (%s, %s).',
                            intdiv($latest, 60), $latest % 60, $earliest->name, substr((string) $earliest->start_time, 0, 5),
                        ));
                }
            },
        ];
    }

    /**
     * No shift yet: the cutoff and the settings' shift_start (the start the default shift will be created with) must
     * be at least ShiftRequest::CUTOFF_MARGIN_MINUTES apart. The error goes on whichever of the two changed (the cutoff
     * first); nothing is checked when neither changed.
     *
     * @param  Closure(string): int  $minutes
     */
    private function checkSettingsShiftStart(Validator $validator, string $cutoff, mixed $storedCutoff, mixed $storedStart, Closure $minutes): void
    {
        if ($validator->errors()->has('shift_start')) {
            return;
        }

        $start = (string) $this->input('shift_start');
        $cutoffChanged = $cutoff !== $storedCutoff;

        if (! $cutoffChanged && $start === $storedStart) {
            return;
        }

        if ($minutes($start) - $minutes($cutoff) >= ShiftRequest::CUTOFF_MARGIN_MINUTES) {
            return;
        }

        if ($cutoffChanged) {
            $latest = max(0, $minutes($start) - ShiftRequest::CUTOFF_MARGIN_MINUTES);
            $validator->errors()->add('day_cutoff_time', sprintf(
                'The day cutoff must be %02d:%02d or earlier: at least 2 hours before the default shift start (%s).',
                intdiv($latest, 60), $latest % 60, $start,
            ));

            return;
        }

        $earliest = $minutes($cutoff) + ShiftRequest::CUTOFF_MARGIN_MINUTES;
        $validator->errors()->add('shift_start', sprintf(
            'The default shift start must be %02d:%02d or later: at least 2 hours after the day cutoff (%s).',
            intdiv($earliest, 60), $earliest % 60, $cutoff,
        ));
    }
}
