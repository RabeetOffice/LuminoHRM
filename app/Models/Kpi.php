<?php

namespace App\Models;

use Database\Factories\KpiFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * A KPI: something people are rated on. It belongs to a designation (everyone holding it) or to one employee, never both and
 * never neither. weight is a relative whole number, 1 to 100. Written through App\Actions\Performance\SaveKpi, which audits
 * each change. Editing or deactivating a KPI never touches a review that already copied it (the copy is review_scores).
 *
 * @property int $id
 * @property string $name
 * @property string|null $description
 * @property int $weight
 * @property bool $is_active
 * @property int|null $designation_id
 * @property int|null $employee_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Kpi extends Model
{
    /** @use HasFactory<KpiFactory> */
    use HasFactory;

    protected $fillable = ['name', 'description', 'weight', 'is_active', 'designation_id', 'employee_id'];

    protected $attributes = ['is_active' => true];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'weight' => 'integer',
            'is_active' => 'boolean',
            'designation_id' => 'integer',
            'employee_id' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Kpi $kpi): void {
            if (($kpi->designation_id === null) === ($kpi->employee_id === null)) {
                throw new LogicException('A KPI belongs to exactly one of a designation or an employee.');
            }
        });

        static::deleting(function (Kpi $kpi): void {
            // Read from the database, not from a loaded relation.
            if (ReviewScore::query()->where('kpi_id', $kpi->getKey())->exists()) {
                throw new LogicException('A KPI that reviews have used cannot be deleted.');
            }
        });
    }

    /** Last known name, stored with each audit entry so it survives deletion. */
    public function auditLabel(): string
    {
        return $this->name;
    }

    /** @return BelongsTo<Designation, $this> */
    public function designation(): BelongsTo
    {
        return $this->belongsTo(Designation::class);
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return HasMany<ReviewScore, $this> */
    public function scores(): HasMany
    {
        return $this->hasMany(ReviewScore::class);
    }
}
