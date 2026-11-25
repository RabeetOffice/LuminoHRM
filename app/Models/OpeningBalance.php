<?php

namespace App\Models;

use Database\Factories\OpeningBalanceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Tax figures of an employee for a tax year before go-live (paisa): tax deducted and taxable income to date. Unique per
 * employee and tax year; tax_year is the start year (2026 = July 2026 to June 2027, shown "2026-27"). Feeds the yearly
 * tax report.
 *
 * @property int $id
 * @property int $employee_id
 * @property int $tax_year
 * @property int $tax_deducted
 * @property int $taxable_income
 * @property string|null $note
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class OpeningBalance extends Model
{
    /** @use HasFactory<OpeningBalanceFactory> */
    use HasFactory;

    protected $fillable = ['employee_id', 'tax_year', 'tax_deducted', 'taxable_income', 'note'];

    protected $attributes = ['tax_deducted' => 0, 'taxable_income' => 0];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'tax_year' => 'integer',
            'tax_deducted' => 'integer',
            'taxable_income' => 'integer',
        ];
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
