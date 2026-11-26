<?php

namespace App\Models;

use App\Casts\NaiveDate;
use App\Models\Concerns\HasNaiveDateScopes;
use Carbon\CarbonImmutable;
use Database\Factories\ReviewCycleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One round of reviews for a period (a calendar quarter or month). Dates are naive "Y-m-d" (App\Casts\NaiveDate). A cycle is
 * never deleted: it is history. status is one of config('hrm.performance.cycle_states').
 *
 * @property int $id
 * @property string $name
 * @property string $period_type
 * @property string $period_key
 * @property CarbonImmutable $period_start
 * @property CarbonImmutable $period_end
 * @property CarbonImmutable $due_on
 * @property string $status
 * @property bool $self_review_enabled
 * @property int $opened_by
 * @property int|null $closed_by
 * @property Carbon|null $closed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ReviewCycle extends Model
{
    /** @use HasFactory<ReviewCycleFactory> */
    use HasFactory;

    use HasNaiveDateScopes;

    protected $fillable = [
        'name', 'period_type', 'period_key', 'period_start', 'period_end', 'due_on', 'status', 'self_review_enabled',
        'opened_by', 'closed_by', 'closed_at',
    ];

    protected $attributes = ['status' => 'open'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'period_start' => NaiveDate::class,
            'period_end' => NaiveDate::class,
            'due_on' => NaiveDate::class,
            'self_review_enabled' => 'boolean',
            'closed_at' => 'datetime',
        ];
    }

    protected function naiveDateColumn(): string
    {
        return 'period_start';
    }

    protected static function booted(): void
    {
        static::deleting(function (): void {
            throw new LogicException('A review cycle is never deleted.');
        });
    }

    public function auditLabel(): string
    {
        return $this->name;
    }

    /** @return HasMany<Review, $this> */
    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }
}
