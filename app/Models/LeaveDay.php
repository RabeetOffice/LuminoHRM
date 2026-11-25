<?php

namespace App\Models;

use App\Casts\NaiveDate;
use App\Models\Concerns\HasNaiveDateScopes;
use Carbon\CarbonImmutable;
use Database\Factories\LeaveDayFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * One counted day of an approved leave request: the ledger balances (used), attendance (on leave, full or half) and
 * payroll (unpaid days) read. Unique per employee, date and half; an am and a pm half may share a date.
 *
 * date is stored as "Y-m-d" (App\Casts\NaiveDate): query it with onDate() / betweenDates() or "Y-m-d" strings.
 * Rows are written and deleted, never updated (no updated_at). Not audited by the model.
 *
 * @property int $id
 * @property int $leave_request_id
 * @property int $employee_id
 * @property int $leave_type_id
 * @property CarbonImmutable $date
 * @property float $amount 1.0 or 0.5
 * @property string $half full | am | pm
 * @property Carbon|null $created_at
 */
class LeaveDay extends Model
{
    /** @use HasFactory<LeaveDayFactory> */
    use HasFactory;

    use HasNaiveDateScopes;

    public const UPDATED_AT = null;

    protected $fillable = ['leave_request_id', 'employee_id', 'leave_type_id', 'date', 'amount', 'half'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'date' => NaiveDate::class,
            'amount' => 'float',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (LeaveDay $day): void {
            if (! in_array($day->half, (array) config('hrm.leave.day_halves'), true)) {
                throw new InvalidArgumentException("Unknown leave day half [{$day->half}].");
            }
        });
    }

    /** @return BelongsTo<LeaveRequest, $this> */
    public function request(): BelongsTo
    {
        return $this->belongsTo(LeaveRequest::class, 'leave_request_id');
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
}
