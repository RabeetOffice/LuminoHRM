<?php

namespace App\Models;

use Database\Factories\DepartmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Department extends Model
{
    /** @use HasFactory<DepartmentFactory> */
    use HasFactory;

    protected $fillable = ['name'];

    /** @return HasMany<Designation, $this> */
    public function designations(): HasMany
    {
        return $this->hasMany(Designation::class);
    }

    /**
     * Why this department cannot be deleted right now, or null when it can.
     * Phase 2 adds employees and the department head here.
     */
    public function deleteBlocker(): ?string
    {
        if ($this->designations()->exists()) {
            return "Move or delete this department's designations first.";
        }

        return null;
    }
}
