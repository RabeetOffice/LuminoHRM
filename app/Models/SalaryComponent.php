<?php

namespace App\Models;

use Database\Factories\SalaryComponentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * An allowance (taxable or not) or a fixed deduction of a salary profile: a monthly amount in paisa. A deduction is
 * never taxable (forced false on save). Deleted with its profile.
 *
 * @property int $id
 * @property int $salary_profile_id
 * @property string $name
 * @property string $type allowance | deduction
 * @property int $amount paisa a month
 * @property bool $is_taxable
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class SalaryComponent extends Model
{
    /** @use HasFactory<SalaryComponentFactory> */
    use HasFactory;

    protected $fillable = ['salary_profile_id', 'name', 'type', 'amount', 'is_taxable', 'sort_order'];

    protected $attributes = ['is_taxable' => false, 'sort_order' => 0];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'is_taxable' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (SalaryComponent $component): void {
            if (! in_array($component->type, (array) config('hrm.payroll.component_types'), true)) {
                throw new InvalidArgumentException("Unknown salary component type [{$component->type}].");
            }

            if ($component->type === 'deduction') {
                $component->is_taxable = false;
            }
        });
    }

    /** @return BelongsTo<SalaryProfile, $this> */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(SalaryProfile::class, 'salary_profile_id');
    }
}
