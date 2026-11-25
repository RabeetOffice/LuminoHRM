<?php

namespace App\Models;

use Database\Factories\LeaveRequestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * An employee's request for leave: a date range, or one date as a half day (am = first half, pm = second half).
 * Statuses (config('hrm.leave.statuses')): pending (waiting for the manager), manager_approved (waiting for HR),
 * approved, rejected, cancelled. Who decides which step: App\Support\Leave\LeaveRouting.
 *
 * Final approval writes one LeaveDay per counted day; those rows, not this one, are the ledger balances, attendance and
 * payroll read. Approved leave cancelled part-way keeps status approved with cancelled_from set (isPartlyCancelled).
 *
 * from_date / to_date / cancelled_from are DATE columns cast to Carbon ("Y-m-d" when serialised). Query them with
 * "Y-m-d" strings only, never a Carbon. Not audited by the model: the leave actions write their own entries.
 *
 * @property int $id
 * @property int $employee_id
 * @property int $leave_type_id
 * @property Carbon $from_date
 * @property Carbon $to_date
 * @property bool $is_half_day
 * @property string|null $half am | pm (only on a half day)
 * @property float $days counted days (rewritten at final approval)
 * @property string $reason
 * @property string|null $attachment_path
 * @property string|null $attachment_name
 * @property array<string, float>|null $balance_before available days per year at apply time, e.g. {"2026": 9.5}
 * @property string $status
 * @property int|null $applied_by
 * @property int|null $manager_decided_by
 * @property Carbon|null $manager_decided_at
 * @property bool $manager_skipped
 * @property int|null $hr_decided_by
 * @property Carbon|null $hr_decided_at
 * @property string|null $decision_note
 * @property int|null $cancelled_by
 * @property Carbon|null $cancelled_at
 * @property string|null $cancel_reason
 * @property Carbon|null $cancelled_from
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class LeaveRequest extends Model
{
    /** @use HasFactory<LeaveRequestFactory> */
    use HasFactory;

    protected $fillable = [
        'employee_id', 'leave_type_id', 'from_date', 'to_date', 'is_half_day', 'half', 'days', 'reason',
        'attachment_path', 'attachment_name', 'balance_before', 'status', 'applied_by', 'manager_decided_by',
        'manager_decided_at', 'manager_skipped', 'hr_decided_by', 'hr_decided_at', 'decision_note', 'cancelled_by',
        'cancelled_at', 'cancel_reason', 'cancelled_from',
    ];

    protected $attributes = [
        'status' => 'pending',
        'is_half_day' => false,
        'manager_skipped' => false,
        'days' => 0,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'from_date' => 'date:Y-m-d',
            'to_date' => 'date:Y-m-d',
            'cancelled_from' => 'date:Y-m-d',
            'is_half_day' => 'boolean',
            'days' => 'float',
            'balance_before' => 'array',
            'manager_decided_at' => 'datetime',
            'manager_skipped' => 'boolean',
            'hr_decided_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (LeaveRequest $request): void {
            if (! in_array($request->status, (array) config('hrm.leave.statuses'), true)) {
                throw new InvalidArgumentException("Unknown leave request status [{$request->status}].");
            }

            if ($request->half !== null && ! in_array($request->half, (array) config('hrm.leave.halves'), true)) {
                throw new InvalidArgumentException("Unknown leave half [{$request->half}].");
            }
        });
    }

    /** Waiting for a decision (pending or manager_approved). */
    public function isOpen(): bool
    {
        return in_array($this->status, (array) config('hrm.leave.open_statuses'), true);
    }

    /** Approved, then cancelled from a date on: the days before cancelled_from stay. */
    public function isPartlyCancelled(): bool
    {
        return $this->status === 'approved' && $this->cancelled_from !== null;
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return BelongsTo<LeaveType, $this> */
    public function type(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class, 'leave_type_id');
    }

    /**
     * The counted days written at final approval.
     *
     * @return HasMany<LeaveDay, $this>
     */
    public function days(): HasMany
    {
        return $this->hasMany(LeaveDay::class);
    }

    /** @return BelongsTo<User, $this> */
    public function appliedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applied_by');
    }

    /** @return BelongsTo<User, $this> */
    public function managerDecider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_decided_by');
    }

    /** @return BelongsTo<User, $this> */
    public function hrDecider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'hr_decided_by');
    }

    /** @return BelongsTo<User, $this> */
    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }
}
