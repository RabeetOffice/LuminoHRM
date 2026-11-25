<?php

namespace App\Support\Leave;

use App\Models\LeaveRequest;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Builder;

/**
 * The "Waiting for me" list of Leave → Requests and the dashboard's Pending count: the open requests in the user's
 * LeaveRouting::scope that wait at the step the user decides. A manager sees their reports' requests waiting for them;
 * HR / Admin (leave.approve-all) see the requests waiting at the final step only. A request still waiting for its
 * manager is NOT waiting for HR: it stays under "All", where HR may still "Decide as HR (skips manager)".
 *
 * Queries: the open requests (1), their employees (1), and LeaveRouting::prime / the privileged map / the Admin count
 * (a fixed few, only when there is a request), whatever the number of requests.
 */
final class WaitingForMe
{
    /** Whether this request belongs on the user's "Waiting for me" list (the routing's caches are used as they are). */
    public static function includes(LeaveRouting $routing, User $user, LeaveRequest $request): bool
    {
        $mine = $routing->decidableStage($user, $request);

        return $mine !== null && $mine === $routing->stage($request);
    }

    /**
     * The ids of the requests waiting for this user.
     *
     * @param  (Closure(Builder<LeaveRequest>): Builder<LeaveRequest>)|null  $filter  narrows the query (list filters)
     * @return list<int>
     */
    public static function ids(User $user, ?Closure $filter = null, ?LeaveRouting $routing = null): array
    {
        $routing ??= new LeaveRouting;

        $query = $routing->scope(LeaveRequest::query(), $user)
            ->whereIn('status', (array) config('hrm.leave.open_statuses'));

        if ($filter !== null) {
            $query = $filter($query);
        }

        $requests = $query->with('employee:id,manager_id')
            ->get(['id', 'employee_id', 'status', 'manager_decided_by']);

        $routing->prime($requests);

        return array_values($requests
            ->filter(fn (LeaveRequest $r): bool => self::includes($routing, $user, $r))
            ->map(fn (LeaveRequest $r): int => (int) $r->id)
            ->all());
    }

    /** How many requests wait for this user (the dashboard's Pending figure). */
    public static function count(User $user): int
    {
        return count(self::ids($user));
    }
}
