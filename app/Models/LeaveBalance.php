<?php

namespace App\Models;

use Database\Factories\LeaveBalanceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One employee's yearly balance of one paid leave type (unique employee + type + year). Only the inputs are stored:
 * remaining = allocated + carried + adjustment - opening_used - used, where carried and used are worked out when read
 * (App\Support\Leave\LeaveBalances), so a balance can never drift from the leave days behind it.
 *
 * Not audited by the model: the actions that change a row (adjustment, opening import) write their own entry.
 *
 * @property int $id
 * @property int $employee_id
 * @property int $leave_type_id
 * @property int $year
 * @property float $allocated
 * @property float $adjustment signed: HR corrections
 * @property float $opening_used days used this year before go-live
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class LeaveBalance extends Model
{
    /** @use HasFactory<LeaveBalanceFactory> */
    use HasFactory;

    protected $fillable = ['employee_id', 'leave_type_id', 'year', 'allocated', 'adjustment', 'opening_used'];

    protected $attributes = ['allocated' => 0, 'adjustment' => 0, 'opening_used' => 0];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'allocated' => 'float',
            'adjustment' => 'float',
            'opening_used' => 'float',
        ];
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
