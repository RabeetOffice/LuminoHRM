<?php

namespace App\Support\Attendance;

use App\Casts\ClockTime;
use App\Http\Requests\ShiftRequest;
use Illuminate\Support\Facades\DB;

/**
 * Creates the default shift ("General") from the Phase 1 attendance settings when there is no default shift.
 * Used by the data migration and, as a safety net, by Shift::default(). Query builder only (no model events, no audit
 * entry), so it works in a migration whatever the Shift model later becomes.
 *
 * A setting that is unusable (hand-edited row: bad time, end not after start, unknown day name) falls back to the
 * value in config('hrm.defaults.attendance'), so the shift is always valid. So does a start less than 2 hours after
 * the attendance day cutoff (ShiftRequest's rule: early clock-ins would land on the previous day): start and end then
 * both take the config defaults.
 */
final class DefaultShift
{
    public const NAME = 'General';

    /** Returns the id of the default shift, creating it when none exists. Idempotent. */
    public static function ensure(): int
    {
        return DB::transaction(function (): int {
            $existing = DB::table('shifts')->where('is_default', true)->orderBy('id')->lockForUpdate()->value('id');

            if ($existing !== null) {
                return (int) $existing;
            }

            $values = self::values();
            $now = now();

            // A shift already called "General" (made by hand) becomes the default rather than a duplicate name.
            $named = DB::table('shifts')->where('name', self::NAME)->value('id');

            if ($named !== null) {
                DB::table('shifts')->where('id', $named)->update(['is_default' => true, 'updated_at' => $now]);

                return (int) $named;
            }

            return (int) DB::table('shifts')->insertGetId([
                'name' => self::NAME,
                ...$values,
                'weekly_offs' => json_encode($values['weekly_offs']),
                'is_default' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        });
    }

    /**
     * The default shift's columns from the settings, each checked (see the class comment).
     *
     * @return array{start_time: string, end_time: string, grace_minutes: int, half_day_hours: float, weekly_offs: list<string>}
     */
    public static function values(): array
    {
        $defaults = (array) config('hrm.defaults.attendance');

        $start = self::time(settings('attendance.shift_start'));
        $end = self::time(settings('attendance.shift_end'));

        $cutoff = ClockTime::minutes((new AttendanceClock)->dayCutoff());

        if ($start === null || $end === null || ClockTime::minutes($end) <= ClockTime::minutes($start)
            || ClockTime::minutes($start) - $cutoff < ShiftRequest::CUTOFF_MARGIN_MINUTES) {
            $start = self::time($defaults['shift_start'] ?? null) ?? '09:00';
            $end = self::time($defaults['shift_end'] ?? null) ?? '18:00';
        }

        $grace = settings('attendance.grace_minutes');
        $grace = is_int($grace) && $grace >= 0 && $grace <= 600 ? $grace : (int) ($defaults['grace_minutes'] ?? 0);

        $half = settings('attendance.half_day_hours');
        $half = (is_int($half) || is_float($half)) && $half > 0 && $half < 24 ? (float) $half : (float) ($defaults['half_day_hours'] ?? 4);

        $offs = settings('attendance.weekly_offs');
        $offs = is_array($offs) ? $offs : ($defaults['weekly_offs'] ?? []);
        $days = (array) config('hrm.attendance.weekdays');
        $offs = is_array($offs)
            ? array_values(array_filter($days, fn (string $day) => in_array($day, array_map(fn ($v) => is_string($v) ? strtolower(trim($v)) : $v, $offs), true)))
            : [];

        return [
            'start_time' => "{$start}:00",
            'end_time' => "{$end}:00",
            'grace_minutes' => $grace,
            'half_day_hours' => $half,
            'weekly_offs' => $offs,
        ];
    }

    /** "H:i" for a valid "H:i" / "H:i:s" string, else null. */
    private static function time(mixed $value): ?string
    {
        if (! is_string($value) || preg_match(ClockTime::PATTERN, trim($value), $m) !== 1) {
            return null;
        }

        return "{$m[1]}:{$m[2]}";
    }
}
