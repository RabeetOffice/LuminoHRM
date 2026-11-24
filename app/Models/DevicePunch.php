<?php

namespace App\Models;

use App\Casts\NaiveDateTime;
use App\Support\Attendance\AttendanceClock;
use Carbon\CarbonImmutable;
use Database\Factories\DevicePunchFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use LogicException;

/**
 * One punch as the machine sent it. Stored once (unique device_id + device_user_id + punch_time) and never edited.
 *
 * punch_time is the machine's local wall-clock time, stored and read back exactly as received (App\Casts\NaiveDateTime:
 * no zone conversion by Eloquent; the DATETIME column is not converted by the database session either).
 * punch_date is the attendance day of punch_time (AttendanceClock::attendanceDateFor: its date, or the day before when it is
 * before the day cutoff): it is set here on every save from the stored punch_time string and cannot be written on its own.
 * It is kept as the plain "Y-m-d" string.
 *
 * employee_id is the employee whose device_user_id matched when the punch was stored (null for an unknown user).
 * Not audited: punches have device_logs and this table.
 *
 * @property int $id
 * @property int $device_id
 * @property string $device_user_id
 * @property int|null $employee_id
 * @property CarbonImmutable $punch_time
 * @property string $punch_date "Y-m-d"
 * @property string|null $verify_type
 * @property string|null $status_code
 * @property string $source device | import | manual
 * @property Carbon|null $created_at
 */
class DevicePunch extends Model
{
    /** @use HasFactory<DevicePunchFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = ['device_id', 'device_user_id', 'employee_id', 'punch_time', 'verify_type', 'status_code', 'source'];

    protected $attributes = ['source' => 'device'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'punch_time' => NaiveDateTime::class,
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (DevicePunch $punch): void {
            $raw = $punch->getAttributes()['punch_time'] ?? null;

            if (! is_string($raw) || $raw === '') {
                throw new InvalidArgumentException('A punch needs a punch_time.');
            }

            if (! in_array($punch->source, (array) config('hrm.attendance.punch_sources'), true)) {
                throw new InvalidArgumentException("Unknown punch source [{$punch->source}].");
            }

            // Derived, never set on its own: the attendance day of exactly the stored string (its date, or the day
            // before when it is before the day cutoff).
            $punch->attributes['punch_date'] = app(AttendanceClock::class)->attendanceDateFor($raw);
        });

        static::updating(function (DevicePunch $punch): void {
            if ($punch->isDirty(['device_id', 'device_user_id', 'punch_time'])) {
                throw new LogicException('A stored punch is never changed.');
            }
        });
    }

    /** @return BelongsTo<Device, $this> */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
