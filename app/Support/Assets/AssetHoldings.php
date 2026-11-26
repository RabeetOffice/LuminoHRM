<?php

namespace App\Support\Assets;

use App\Models\AssetAssignment;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Collection;

/**
 * What an employee holds right now. The one place that answers "does this person still have company assets?".
 *
 * This is the Phase 6c Exit hook: the exit checklist asks `holdsAny()` / `openFor()` before it lets someone leave. It
 * works for any employee status (a Resigned or Terminated person who never returned a laptop still holds it) and never
 * looks at the employee's status or the asset's status, only at the open assignment rows (returned_on IS NULL).
 */
class AssetHoldings
{
    /**
     * The open assignments of the employee, each with its asset and the asset's category loaded, oldest hand-over first
     * (then by id). Three queries whatever the number of rows.
     *
     * @return Collection<int, AssetAssignment>
     */
    public static function openFor(Employee $employee): Collection
    {
        return AssetAssignment::query()
            ->where('employee_id', $employee->getKey())
            ->open()
            ->with('asset.category')
            ->orderBy('assigned_on')
            ->orderBy('id')
            ->get();
    }

    /** True when at least one asset is still out with the employee: a single `exists` query. */
    public static function holdsAny(Employee $employee): bool
    {
        return AssetAssignment::query()
            ->where('employee_id', $employee->getKey())
            ->open()
            ->exists();
    }
}
