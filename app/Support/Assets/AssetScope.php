<?php

namespace App\Support\Assets;

use App\Models\Asset;
use App\Models\AssetAssignment;
use App\Models\Employee;
use App\Models\User;
use App\Support\EmployeeAccess;
use App\Support\ListQuery;
use Illuminate\Database\Eloquent\Builder;

/**
 * Which assets a user may see in the list. HR and Admin (assets.assign) see the whole register. A Manager
 * (assets.view-team) sees only the assets that are held right now by people EmployeeAccess lets them see: their own
 * reports and themself. Anyone else sees nothing.
 */
class AssetScope
{
    /** Rows per page on the list. */
    public const PER_PAGE = 25;

    /** True when the user sees the whole register (and its costs and filters); false for the read-only team view. */
    public static function seesAll(User $user): bool
    {
        return $user->can('assets.assign');
    }

    /** @return 'all'|'team' */
    public static function scopeName(User $user): string
    {
        return self::seesAll($user) ? 'all' : 'team';
    }

    /**
     * @param  Builder<Asset>  $query
     * @return Builder<Asset>
     */
    public static function assets(User $user, Builder $query): Builder
    {
        if (self::seesAll($user)) {
            return $query;
        }

        if (! $user->can('assets.view-team')) {
            return $query->whereRaw('1 = 0');
        }

        $people = (new EmployeeAccess($user))->scope(Employee::query())->select('employees.id');

        return $query->whereIn('assets.id', self::heldBy($people));
    }

    /**
     * Narrows to assets whose code, name or serial contains $term, or whose current holder's name or code does
     * (wildcards in the term are literal).
     *
     * @param  Builder<Asset>  $query
     * @return Builder<Asset>
     */
    public static function search(Builder $query, string $term): Builder
    {
        $term = trim($term);

        if ($term === '') {
            return $query;
        }

        $pattern = '%'.ListQuery::escapeLike($term).'%';

        return $query->where(function (Builder $w) use ($pattern) {
            foreach (['assets.asset_code', 'assets.name', 'assets.serial_number'] as $column) {
                $w->orWhereRaw("LOWER({$column}) LIKE ? ESCAPE '!'", [$pattern]);
            }

            $holders = Employee::query()->select('employees.id')->where(function (Builder $e) use ($pattern) {
                foreach (['first_name', 'last_name', 'employee_code'] as $column) {
                    $e->orWhereRaw("LOWER({$column}) LIKE ? ESCAPE '!'", [$pattern]);
                }
                // "||" is string concatenation on SQLite but a logical OR on MariaDB, so the full name is built per driver.
                $fullName = $e->getModel()->getConnection()->getDriverName() === 'sqlite'
                    ? "first_name || ' ' || last_name"
                    : "CONCAT(first_name, ' ', last_name)";
                $e->orWhereRaw("LOWER({$fullName}) LIKE ? ESCAPE '!'", [$pattern]);
            });

            $w->orWhereIn('assets.id', self::heldBy($holders));
        });
    }

    /**
     * The asset ids that are out with the given employees right now.
     *
     * @param  Builder<Employee>  $employees  a query selecting employee ids
     * @return Builder<AssetAssignment>
     */
    private static function heldBy(Builder $employees): Builder
    {
        return AssetAssignment::query()->open()->whereIn('employee_id', $employees)->select('asset_id');
    }
}
