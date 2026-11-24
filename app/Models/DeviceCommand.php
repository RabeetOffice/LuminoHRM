<?php

namespace App\Models;

use Database\Factories\DeviceCommandFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A command queued for a machine (handed out on its next getrequest, result posted to devicecmd).
 * Not audited by itself: the action that queues it records the entry (area attendance).
 *
 * @property int $id
 * @property int $device_id
 * @property string $command
 * @property string $status queued | sent | done | failed (config('hrm.attendance.command_statuses'))
 * @property Carbon|null $sent_at app time
 * @property string|null $result
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class DeviceCommand extends Model
{
    /** @use HasFactory<DeviceCommandFactory> */
    use HasFactory;

    protected $fillable = ['device_id', 'command', 'status', 'sent_at', 'result', 'created_by'];

    protected $attributes = ['status' => 'queued'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }

    /** @return BelongsTo<Device, $this> */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
