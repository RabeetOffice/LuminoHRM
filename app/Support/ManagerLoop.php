<?php

namespace App\Support;

use App\Models\Employee;

/**
 * Keeps the reporting line a tree: nobody is, directly or through others, their own manager.
 *
 * Both walks keep a visited set and a hard depth cap, so a loop that already exists in corrupt data (or a
 * runaway chain) ends the walk instead of hanging the request.
 */
final class ManagerLoop
{
    /** Longest chain either walk follows. Real organisations are a few levels deep. */
    public const MAX_DEPTH = 100;

    /**
     * Would making $managerId the manager of $employeeId put the employee above themselves?
     * Walks up from the proposed manager; true when the walk reaches the employee, or runs past the depth cap
     * (a chain that deep cannot be trusted). A loop elsewhere in the data that does not include the employee
     * is not a new cycle, so the walk just stops.
     */
    public static function wouldCreateCycle(int $employeeId, ?int $managerId, int $maxDepth = self::MAX_DEPTH): bool
    {
        if ($managerId === null) {
            return false;
        }

        $visited = [];
        $current = $managerId;

        for ($depth = 0; $depth <= $maxDepth; $depth++) {
            if ($current === $employeeId) {
                return true;
            }

            if (isset($visited[$current])) {
                return false;
            }
            $visited[$current] = true;

            $next = Employee::query()->whereKey($current)->value('manager_id');

            if ($next === null) {
                return false;
            }

            $current = (int) $next;
        }

        return true;
    }

    /**
     * Everyone below $employeeId at any depth (the people who cannot be chosen as their manager).
     *
     * @return list<int>
     */
    public static function descendantIds(int $employeeId, int $maxDepth = self::MAX_DEPTH): array
    {
        $seen = [$employeeId => true];
        $found = [];
        $level = [$employeeId];

        for ($depth = 0; $depth < $maxDepth && $level !== []; $depth++) {
            $next = [];

            foreach (Employee::query()->whereIn('manager_id', $level)->pluck('id') as $id) {
                $id = (int) $id;

                if (! isset($seen[$id])) {
                    $seen[$id] = true;
                    $found[] = $id;
                    $next[] = $id;
                }
            }

            $level = $next;
        }

        return $found;
    }
}
