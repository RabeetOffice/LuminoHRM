<?php

namespace App\Models;

use App\Casts\NaiveDateTime;
use Carbon\CarbonImmutable;
use Database\Factories\DeviceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * An attendance machine (ZKTeco MB20) known by its serial number.
 *
 * last_seen_at: when the server last heard from it (app time). last_stamp: the machine's ATTLOGStamp.
 * last_punch_at: the newest punch time received, naive machine time (App\Casts\NaiveDateTime).
 * Only name, serial number and the active flag are audited (area attendance); the heartbeat columns are not.
 *
 * @property int $id
 * @property string $name
 * @property string $serial_no
 * @property bool $is_active
 * @property Carbon|null $last_seen_at
 * @property string|null $last_stamp
 * @property CarbonImmutable|null $last_punch_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Device extends Model
{
    /** @use HasFactory<DeviceFactory> */
    use HasFactory, LogsActivity;

    protected $fillable = ['name', 'serial_no', 'is_active', 'last_seen_at', 'last_stamp', 'last_punch_at'];

    protected $attributes = ['is_active' => true];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'last_seen_at' => 'datetime',
            'last_punch_at' => NaiveDateTime::class,
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('attendance')
            ->logOnly(['name', 'serial_no', 'is_active'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->setDescriptionForEvent(fn (string $event) => "Device {$event}");
    }

    public function auditLabel(): string
    {
        return "{$this->name} ({$this->serial_no})";
    }

    /** @return HasMany<DevicePunch, $this> */
    public function punches(): HasMany
    {
        return $this->hasMany(DevicePunch::class);
    }

    /** @return HasMany<DeviceCommand, $this> */
    public function commands(): HasMany
    {
        return $this->hasMany(DeviceCommand::class);
    }
}
