<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Data migration: production runs `migrate --force` but never seeds. Idempotent: does nothing when a default shift exists.
// Creates "General" from the attendance settings (or their config defaults). Query builder only, no model events or
// audit entry. A frozen copy of the ensure() logic of the DefaultShift support class as of Phase 3 Task 1, so later
// changes to that class (or to the Shift model) never change what this migration writes.
return new class extends Migration
{
    private const NAME = 'General';

    private const TIME = '/^([01]\d|2[0-3]):([0-5]\d)(:([0-5]\d))?$/';

    private const WEEK = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

    public function up(): void
    {
        DB::transaction(function (): void {
            if (DB::table('shifts')->where('is_default', true)->lockForUpdate()->exists()) {
                return;
            }

            $now = now();

            // A shift already called "General" (made by hand) becomes the default rather than a duplicate name.
            $named = DB::table('shifts')->where('name', self::NAME)->value('id');

            if ($named !== null) {
                DB::table('shifts')->where('id', $named)->update(['is_default' => true, 'updated_at' => $now]);

                return;
            }

            DB::table('shifts')->insert([
                'name' => self::NAME,
                ...$this->values(),
                'is_default' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        });
    }

    public function down(): void
    {
        // Intentionally empty: the table itself is dropped by the schema migration's down().
    }

    /**
     * The shift columns from the settings; an unusable setting falls back to config('hrm.defaults.attendance').
     *
     * @return array<string, mixed>
     */
    private function values(): array
    {
        $defaults = (array) config('hrm.defaults.attendance');

        $start = $this->time(settings('attendance.shift_start'));
        $end = $this->time(settings('attendance.shift_end'));

        if ($start === null || $end === null || $this->minutes($end) <= $this->minutes($start)) {
            $start = $this->time($defaults['shift_start'] ?? null) ?? '09:00';
            $end = $this->time($defaults['shift_end'] ?? null) ?? '18:00';
        }

        $grace = settings('attendance.grace_minutes');
        $grace = is_int($grace) && $grace >= 0 && $grace <= 600 ? $grace : (int) ($defaults['grace_minutes'] ?? 0);

        $half = settings('attendance.half_day_hours');
        $half = (is_int($half) || is_float($half)) && $half > 0 && $half < 24 ? (float) $half : (float) ($defaults['half_day_hours'] ?? 4);

        $offs = settings('attendance.weekly_offs');
        $offs = is_array($offs) ? $offs : ($defaults['weekly_offs'] ?? []);
        $given = is_array($offs) ? array_map(fn ($v) => is_string($v) ? strtolower(trim($v)) : $v, $offs) : [];
        $offs = array_values(array_filter(self::WEEK, fn (string $day) => in_array($day, $given, true)));

        return [
            'start_time' => "{$start}:00",
            'end_time' => "{$end}:00",
            'grace_minutes' => $grace,
            'half_day_hours' => $half,
            'weekly_offs' => json_encode($offs),
        ];
    }

    private function time(mixed $value): ?string
    {
        if (! is_string($value) || preg_match(self::TIME, trim($value), $m) !== 1) {
            return null;
        }

        return "{$m[1]}:{$m[2]}";
    }

    private function minutes(string $time): int
    {
        [$h, $i] = explode(':', $time);

        return (int) $h * 60 + (int) $i;
    }
};
