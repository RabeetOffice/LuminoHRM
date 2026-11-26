<?php

namespace App\Models;

use App\Casts\NaiveDate;
use App\Support\Exits\ExitStates;
use Carbon\CarbonImmutable;
use Database\Factories\ResignationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

/**
 * One exit: a resignation by the employee or a resignation / termination recorded by HR. Open while pending,
 * manager_approved or approved; the other statuses end it and the row is then history.
 *
 * Guards (whoever writes the row):
 *  - open_employee_id is set on every save: the employee id while the status is open, NULL once it has ended. Its unique
 *    index is what stops two open exits for one person, so the model never lets it drift from the status.
 *  - an ended row (rejected, withdrawn, cancelled) never changes again; the check reads the status the model was loaded
 *    with. Actions re-read the row FOR UPDATE before they decide anything, so this is a backstop for history.
 *  - a row is never deleted.
 *
 * @property int $id
 * @property int $employee_id
 * @property string $type
 * @property CarbonImmutable $submitted_on
 * @property CarbonImmutable $last_working_day
 * @property string $reason
 * @property int $notice_period_days
 * @property string $status
 * @property int|null $open_employee_id
 * @property int|null $recorded_by
 * @property bool $manager_skipped
 * @property int|null $manager_decided_by
 * @property CarbonImmutable|null $manager_decided_at
 * @property int|null $final_decided_by
 * @property CarbonImmutable|null $final_decided_at
 * @property bool $own_decision
 * @property string|null $decision_note
 * @property CarbonImmutable|null $withdrawn_at
 * @property int|null $cancelled_by
 * @property CarbonImmutable|null $cancelled_at
 * @property string|null $cancel_reason
 * @property CarbonImmutable|null $status_applied_at
 * @property string|null $status_error
 * @property string|null $letter_text
 * @property int|null $letter_issued_by
 * @property CarbonImmutable|null $letter_issued_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Employee $employee
 */
class Resignation extends Model
{
    /** @use HasFactory<ResignationFactory> */
    use HasFactory;

    protected $fillable = [
        'employee_id', 'type', 'submitted_on', 'last_working_day', 'reason', 'notice_period_days', 'status',
        'open_employee_id', 'recorded_by', 'manager_skipped', 'manager_decided_by', 'manager_decided_at',
        'final_decided_by', 'final_decided_at', 'own_decision', 'decision_note', 'withdrawn_at', 'cancelled_by',
        'cancelled_at', 'cancel_reason', 'status_applied_at', 'status_error', 'letter_text', 'letter_issued_by',
        'letter_issued_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'submitted_on' => NaiveDate::class,
            'last_working_day' => NaiveDate::class,
            'notice_period_days' => 'integer',
            'manager_skipped' => 'boolean',
            'own_decision' => 'boolean',
            'manager_decided_at' => 'datetime',
            'final_decided_at' => 'datetime',
            'withdrawn_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'status_applied_at' => 'datetime',
            'letter_issued_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Resignation $resignation): void {
            $resignation->open_employee_id = ExitStates::isOpen((string) $resignation->status) ? $resignation->employee_id : null;
        });

        static::updating(function (Resignation $resignation): void {
            $was = $resignation->getOriginal('status');

            if (is_string($was) && ExitStates::isEnded($was)) {
                throw new LogicException('This exit has ended and cannot change.');
            }
        });

        static::deleting(function (): void {
            throw new LogicException('An exit is never deleted.');
        });
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return HasMany<Clearance, $this> */
    public function clearances(): HasMany
    {
        return $this->hasMany(Clearance::class)->orderBy('sort_order');
    }

    /** @return HasOne<FinalSettlement, $this> */
    public function settlement(): HasOne
    {
        return $this->hasOne(FinalSettlement::class);
    }

    /** @return BelongsTo<User, $this> */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function isOpen(): bool
    {
        return ExitStates::isOpen((string) $this->status);
    }

    /** "With manager", "With HR", "Approved", "Rejected", "Withdrawn" or "Cancelled". */
    public function stageLabel(): string
    {
        return ExitStates::label((string) $this->status);
    }
}
