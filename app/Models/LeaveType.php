<?php

namespace App\Models;

use Database\Factories\LeaveTypeFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A kind of leave (Annual, Sick, ...). Paid types have a yearly quota and one balance row per employee and year;
 * unpaid types have no balance and are never limited. Exactly one unpaid type is seeded as is_system: it is the type
 * staff are pointed to when a paid balance is short, and it can never be deleted, made paid or deactivated (guarded
 * here). is_system is not mass assignable and never changes on an existing type.
 *
 * Changes are audited by the model (area leave, "Leave type created / updated / deleted"). Day amounts are multiples
 * of 0.5, cast to float.
 *
 * @property int $id
 * @property string $name
 * @property float $yearly_quota
 * @property bool $is_paid
 * @property bool $carry_forward
 * @property float $carry_forward_cap
 * @property bool $is_active
 * @property bool $is_system
 * @property string $colour config('hrm.leave.colours')
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class LeaveType extends Model
{
    /** @use HasFactory<LeaveTypeFactory> */
    use HasFactory, LogsActivity;

    /** The audited columns: every column except the id and the timestamps. */
    private const AUDITED = ['name', 'yearly_quota', 'is_paid', 'carry_forward', 'carry_forward_cap', 'is_active', 'is_system', 'colour', 'sort_order'];

    /** Every audited column except is_system: that flag is written only by the seed migration (query builder) or a factory. */
    protected $fillable = ['name', 'yearly_quota', 'is_paid', 'carry_forward', 'carry_forward_cap', 'is_active', 'colour', 'sort_order'];

    protected $attributes = [
        'yearly_quota' => 0,
        'is_paid' => true,
        'carry_forward' => false,
        'carry_forward_cap' => 0,
        'is_active' => true,
        'is_system' => false,
        'sort_order' => 0,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'yearly_quota' => 'float',
            'is_paid' => 'boolean',
            'carry_forward' => 'boolean',
            'carry_forward_cap' => 'float',
            'is_active' => 'boolean',
            'is_system' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (LeaveType $type): void {
            if (! in_array($type->colour, (array) config('hrm.leave.colours'), true)) {
                throw new InvalidArgumentException("Unknown leave colour [{$type->colour}].");
            }

            if ($type->exists && $type->isDirty('is_system')) {
                throw new InvalidArgumentException('The system flag of a leave type cannot be changed.');
            }

            if ($type->is_system && $type->is_paid) {
                throw new InvalidArgumentException('The system unpaid leave type cannot be made paid.');
            }

            if ($type->is_system && ! $type->is_active) {
                throw new InvalidArgumentException('Unpaid leave must stay active; it is what staff use when their balance runs out.');
            }
        });

        static::deleting(function (LeaveType $type): void {
            if ($type->is_system) {
                throw new InvalidArgumentException('The system unpaid leave type cannot be deleted.');
            }
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('leave')
            ->logOnly(self::AUDITED)
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->setDescriptionForEvent(fn (string $event) => "Leave type {$event}");
    }

    public function auditLabel(): string
    {
        return $this->name;
    }

    public function isUnpaid(): bool
    {
        return ! $this->is_paid;
    }

    /**
     * @param  Builder<LeaveType>  $query
     * @return Builder<LeaveType>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** @return HasMany<LeaveBalance, $this> */
    public function balances(): HasMany
    {
        return $this->hasMany(LeaveBalance::class);
    }

    /** @return HasMany<LeaveRequest, $this> */
    public function requests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class);
    }
}
