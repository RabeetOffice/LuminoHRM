<?php

namespace App\Models;

use App\Casts\NaiveDate;
use App\Casts\NaiveDateValue;
use App\Models\Concerns\HasNaiveDateScopes;
use Database\Factories\SalaryProfileFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * One salary profile of an employee, in force from effective_from until the next profile's effective_from. A change is
 * a new row (history is kept); unique per employee and effective date. Components (allowances, fixed deductions) hang
 * off it. Money in integer paisa.
 *
 * effective_from is stored as "Y-m-d" (App\Casts\NaiveDate): query it with onDate() / betweenDates() (they read
 * effective_from here) or "Y-m-d" strings. Not audited by the model: the actions write their own payroll entries.
 *
 *   $profile->gross();          // basic + every allowance, a month
 *   $profile->taxableGross();   // basic + taxable allowances, a month
 *
 * @property int $id
 * @property int $employee_id
 * @property NaiveDateValue $effective_from
 * @property int $basic paisa a month
 * @property string $tax_mode none | fixed | percentage
 * @property int $tax_value paisa a month (fixed), basis points (percentage), 0 (none)
 * @property string|null $tax_base gross_taxable | basic (percentage only)
 * @property string|null $note
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class SalaryProfile extends Model
{
    /** @use HasFactory<SalaryProfileFactory> */
    use HasFactory;

    use HasNaiveDateScopes;

    protected $fillable = ['employee_id', 'effective_from', 'basic', 'tax_mode', 'tax_value', 'tax_base', 'note', 'created_by'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'effective_from' => NaiveDate::class,
            'basic' => 'integer',
            'tax_value' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (SalaryProfile $profile): void {
            if (! in_array($profile->tax_mode, (array) config('hrm.payroll.tax_modes'), true)) {
                throw new InvalidArgumentException("Unknown tax mode [{$profile->tax_mode}].");
            }

            if ($profile->tax_base !== null && ! in_array($profile->tax_base, (array) config('hrm.payroll.tax_bases'), true)) {
                throw new InvalidArgumentException("Unknown tax base [{$profile->tax_base}].");
            }
        });
    }

    protected function naiveDateColumn(): string
    {
        return 'effective_from';
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Allowances and fixed deductions, in their display order.
     *
     * @return HasMany<SalaryComponent, $this>
     */
    public function components(): HasMany
    {
        return $this->hasMany(SalaryComponent::class)->orderBy('sort_order')->orderBy('id');
    }

    /** @return HasMany<SalaryComponent, $this> */
    public function allowances(): HasMany
    {
        return $this->components()->where('type', 'allowance');
    }

    /** @return HasMany<SalaryComponent, $this> */
    public function deductions(): HasMany
    {
        return $this->components()->where('type', 'deduction');
    }

    /** Basic plus every allowance, a month (paisa). Uses the loaded components when they are loaded. */
    public function gross(): int
    {
        return $this->basic + $this->allowanceTotal(false);
    }

    /** Basic plus the taxable allowances, a month (paisa). */
    public function taxableGross(): int
    {
        return $this->basic + $this->allowanceTotal(true);
    }

    private function allowanceTotal(bool $taxableOnly): int
    {
        $components = $this->relationLoaded('components') ? $this->components : $this->components()->get();

        $total = 0;
        foreach ($components as $component) {
            if ($component->type === 'allowance' && (! $taxableOnly || $component->is_taxable)) {
                $total += $component->amount;
            }
        }

        return $total;
    }
}
