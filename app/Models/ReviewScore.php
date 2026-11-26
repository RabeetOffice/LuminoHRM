<?php

namespace App\Models;

use Database\Factories\ReviewScoreFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One KPI line of a review: the frozen copy (name, description, weight, kpi_id, review_id) taken when the review was created,
 * plus the ratings and comments people add. The copy can never be updated; only ratings and comments can.
 *
 * @property int $id
 * @property int $review_id
 * @property int|null $kpi_id
 * @property string $name
 * @property string|null $description
 * @property int $weight
 * @property int $sort_order
 * @property int|null $self_rating
 * @property int|null $manager_rating
 * @property string|null $self_comment
 * @property string|null $manager_comment
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ReviewScore extends Model
{
    /** @use HasFactory<ReviewScoreFactory> */
    use HasFactory;

    /** The frozen columns. */
    public const SNAPSHOT = ['review_id', 'kpi_id', 'name', 'description', 'weight'];

    protected $fillable = [
        'review_id', 'kpi_id', 'name', 'description', 'weight', 'sort_order', 'self_rating', 'manager_rating', 'self_comment', 'manager_comment',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'weight' => 'integer',
            'sort_order' => 'integer',
            'self_rating' => 'integer',
            'manager_rating' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (ReviewScore $score): void {
            if ($score->isDirty(self::SNAPSHOT)) {
                throw new LogicException('The KPI copy on a review cannot be changed.');
            }
        });
    }

    /** @return BelongsTo<Review, $this> */
    public function review(): BelongsTo
    {
        return $this->belongsTo(Review::class);
    }

    /** @return BelongsTo<Kpi, $this> */
    public function kpi(): BelongsTo
    {
        return $this->belongsTo(Kpi::class);
    }
}
