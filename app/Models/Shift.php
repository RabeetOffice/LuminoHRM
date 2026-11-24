<?php

namespace App\Models;

use App\Casts\ClockTime;
use App\Support\Attendance\DefaultShift;
use Database\Factories\ShiftFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A working pattern: start and end ("H:i", same day; no shifts across midnight in Phase 3), grace minutes, the
 * half-day threshold in hours and the weekly off days (lowercase English day names).
 *
 * Exactly one shift is the default; an employee with no shift_id works the default shift (Shift::forEmployee()).
 * Change the default only through Shift::makeDefault(): saving a second row with is_default = true throws.
 *
 * Every save is checked here (whoever writes it): valid times with the end after the start, known day names
 * (lower-cased, de-duplicated, Monday first), grace and half-day within range.
 *
 * @property int $id
 * @property string $name
 * @property string $start_time "H:i"
 * @property string $end_time "H:i"
 * @property int $grace_minutes
 * @property string $half_day_hours decimal string, e.g. "4.00"
 * @property list<string> $weekly_offs
 * @property bool $is_default
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Shift extends Model
{
    /** @use HasFactory<ShiftFactory> */
    use HasFactory, LogsActivity;

    protected $fillable = ['name', 'start_time', 'end_time', 'grace_minutes', 'half_day_hours', 'weekly_offs', 'is_default'];

    protected $attributes = [
        'is_default' => false,
        'grace_minutes' => 0,
        'weekly_offs' => '[]',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'start_time' => ClockTime::class,
            'end_time' => ClockTime::class,
            'grace_minutes' => 'integer',
            'half_day_hours' => 'decimal:2',
            'weekly_offs' => 'array',
            'is_default' => 'boolean',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('attendance')
            ->logOnly(['name', 'start_time', 'end_time', 'grace_minutes', 'half_day_hours', 'weekly_offs', 'is_default'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->setDescriptionForEvent(fn (string $event) => "Shift {$event}");
    }

    public function auditLabel(): string
    {
        return $this->name;
    }

    protected static function booted(): void
    {
        static::saving(function (Shift $shift): void {
            $shift->assertValid();

            if ($shift->is_default && static::query()->where('is_default', true)->whereKeyNot($shift->getKey() ?? 0)->exists()) {
                throw new LogicException('Another shift is already the default. Use Shift::makeDefault().');
            }
        });

        static::deleting(function (Shift $shift): void {
            if ($shift->is_default) {
                throw new LogicException('The default shift cannot be deleted. Make another shift the default first.');
            }
        });
    }

    /** @return HasMany<Employee, $this> */
    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    /** The default shift; created from the attendance settings if (unexpectedly) there is none. */
    public static function default(): self
    {
        $default = static::query()->where('is_default', true)->orderBy('id')->first();

        return $default ?? static::query()->findOrFail(DefaultShift::ensure());
    }

    /** The employee's own shift, or the default shift when they have none. */
    public static function forEmployee(Employee $employee): self
    {
        if ($employee->shift_id !== null) {
            $own = $employee->relationLoaded('shift') ? $employee->shift : $employee->shift()->first();

            if ($own !== null) {
                return $own;
            }
        }

        return static::default();
    }

    /** Makes $shift the one default, in one transaction (every other shift loses the flag). Returns it refreshed. */
    public static function makeDefault(Shift $shift): self
    {
        return DB::transaction(function () use ($shift): Shift {
            $locked = static::query()->whereKey($shift->getKey())->lockForUpdate()->firstOrFail();
            static::query()->where('is_default', true)->lockForUpdate()->get(['id']);

            foreach (static::query()->where('is_default', true)->whereKeyNot($locked->getKey())->get() as $other) {
                $other->is_default = false;
                $other->save();
            }

            if (! $locked->is_default) {
                $locked->is_default = true;
                $locked->save();
            }

            return $shift->refresh();
        });
    }

    /**
     * Lower-cased, known, de-duplicated day names in week order (Monday first). Throws on an unknown name.
     *
     * @param  array<mixed>  $days
     * @return list<string>
     */
    public static function normaliseWeeklyOffs(array $days): array
    {
        $week = (array) config('hrm.attendance.weekdays');
        $given = [];

        foreach ($days as $day) {
            $name = is_string($day) ? strtolower(trim($day)) : null;

            if ($name === null || ! in_array($name, $week, true)) {
                throw new InvalidArgumentException('Unknown weekly off day ['.(is_scalar($day) ? $day : get_debug_type($day)).'].');
            }

            $given[] = $name;
        }

        return array_values(array_intersect($week, $given));
    }

    /** The shift's length in minutes (end minus start). */
    public function minutes(): int
    {
        return ClockTime::minutes($this->end_time) - ClockTime::minutes($this->start_time);
    }

    private function assertValid(): void
    {
        if (trim((string) $this->name) === '') {
            throw new InvalidArgumentException('A shift needs a name.');
        }

        if (! isset($this->attributes['start_time'], $this->attributes['end_time'])) {
            throw new InvalidArgumentException('A shift needs a start and an end time.');
        }

        if (ClockTime::minutes($this->end_time) <= ClockTime::minutes($this->start_time)) {
            throw new InvalidArgumentException('A shift must end after it starts on the same day.');
        }

        $grace = (int) $this->grace_minutes;
        if ($grace < 0 || $grace > 600) {
            throw new InvalidArgumentException('Grace minutes must be between 0 and 600.');
        }

        $half = (float) $this->half_day_hours;
        if ($half <= 0 || $half >= 24) {
            throw new InvalidArgumentException('Half-day hours must be more than 0 and less than 24.');
        }

        $offs = $this->getAttributeValue('weekly_offs'); // whatever was written, before trusting it is a list

        if (! is_array($offs)) {
            throw new InvalidArgumentException('Weekly offs must be a list of day names.');
        }

        $normalised = static::normaliseWeeklyOffs($offs);

        if ($normalised !== $offs) {
            $this->weekly_offs = $normalised;
        }
    }
}
