<?php

namespace App\Models;

use Database\Factories\ReviewFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One person's review in one cycle. status is one of config('hrm.performance.review_states'). self_score and final_score are
 * integer hundredths (412 = 4.12), computed only by App\Support\Performance\ReviewScoring. A review is never deleted.
 *
 * @property int $id
 * @property int $review_cycle_id
 * @property int $employee_id
 * @property int|null $reviewer_id
 * @property string $status
 * @property int|null $self_score
 * @property int|null $final_score
 * @property string|null $manager_comment
 * @property string|null $send_back_note
 * @property string|null $skip_reason
 * @property string|null $skipped_from
 * @property Carbon|null $self_submitted_at
 * @property Carbon|null $manager_submitted_at
 * @property Carbon|null $closed_at
 * @property int|null $closed_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Review extends Model
{
    /** @use HasFactory<ReviewFactory> */
    use HasFactory;

    protected $fillable = [
        'review_cycle_id', 'employee_id', 'reviewer_id', 'status', 'self_score', 'final_score', 'manager_comment', 'send_back_note',
        'skip_reason', 'skipped_from', 'self_submitted_at', 'manager_submitted_at', 'closed_at', 'closed_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'self_score' => 'integer',
            'final_score' => 'integer',
            'self_submitted_at' => 'datetime',
            'manager_submitted_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(function (): void {
            throw new LogicException('A review is never deleted.');
        });
    }

    /** @return BelongsTo<ReviewCycle, $this> */
    public function cycle(): BelongsTo
    {
        return $this->belongsTo(ReviewCycle::class, 'review_cycle_id');
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return BelongsTo<Employee, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'reviewer_id');
    }

    /** @return HasMany<ReviewScore, $this> */
    public function scores(): HasMany
    {
        return $this->hasMany(ReviewScore::class)->orderBy('sort_order')->orderBy('id');
    }
}
