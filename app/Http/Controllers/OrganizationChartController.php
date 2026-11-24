<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Support\EmployeeAccess;
use App\Support\EmployeeFiles;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The organization chart. The people come ONLY from EmployeeAccess::scope (Admin and HR: everyone; a Manager: themself
 * and their direct reports), and only active and probation employees are drawn. Each node carries the name, code,
 * designation, department and photo route and nothing else (no email, phone, CNIC, dates or status).
 *
 * `manager_id` in a node is rewritten to the nearest ancestor that is ALSO in the chart: a person whose manager is
 * hidden (inactive, resigned, terminated, or outside the viewer's scope) hangs under the first shown person above
 * them, or becomes a root when there is none. The walk keeps a visited set and a depth cap, so a reporting loop in old
 * or imported data cannot hang the request. A loop among shown people is left in the data; the page breaks it
 * (resources/js/lib/org-tree.ts).
 */
class OrganizationChartController extends Controller
{
    /** The statuses drawn on the chart. */
    public const STATUSES = ['active', 'probation'];

    /** How far up a hidden chain is followed before giving up (a root). Far deeper than any real company. */
    public const MAX_DEPTH = 1000;

    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user->canAny(['employees.view-all', 'employees.view-team']), 403);

        // One query for the people, two for their departments and designations (eager loaded).
        $people = (new EmployeeAccess($user))->scope(Employee::query())
            ->whereIn('status', self::STATUSES)
            ->select(['id', 'employee_code', 'first_name', 'last_name', 'department_id', 'designation_id', 'manager_id', 'photo_path', 'updated_at'])
            ->with(['department:id,name', 'designation:id,name'])
            ->orderBy('id')
            ->get();

        $shown = array_fill_keys($people->pluck('id')->all(), true);
        $parents = $this->parentsIfNeeded($people->all(), $shown);

        $nodes = $people->map(function (Employee $e) use ($shown, $parents) {
            $manager = self::nearestShown($e->manager_id, $shown, $parents);

            return [
                'id' => $e->id,
                'name' => $e->full_name,
                'code' => $e->employee_code,
                'designation' => $e->designation?->name,
                'department' => $e->department?->name,
                'photo_url' => EmployeeFiles::photoUrl($e),
                // Never the person themself (a loop through hidden people can lead back to them).
                'manager_id' => $manager === $e->id ? null : $manager,
            ];
        })->values()->all();

        return Inertia::render('organization-chart/index', ['nodes' => $nodes]);
    }

    /**
     * The whole id => manager_id map, read only when some shown person's manager is not shown (one extra, flat
     * query of two integer columns; it never leaves the server). Otherwise an empty map, since nothing needs walking.
     *
     * @param  array<int, Employee>  $people
     * @param  array<int, true>  $shown
     * @return array<int, int|null>
     */
    private function parentsIfNeeded(array $people, array $shown): array
    {
        foreach ($people as $e) {
            if ($e->manager_id !== null && ! isset($shown[$e->manager_id])) {
                return Employee::query()->pluck('manager_id', 'id')
                    ->map(fn ($id) => $id === null ? null : (int) $id)
                    ->all();
            }
        }

        return [];
    }

    /**
     * The first id on the chain starting at $managerId (the manager, then their manager ...) that is shown, or null.
     * Stops at a repeated id (a loop) and after MAX_DEPTH steps.
     *
     * @param  array<int, true>  $shown
     * @param  array<int, int|null>  $parents
     */
    public static function nearestShown(?int $managerId, array $shown, array $parents): ?int
    {
        $current = $managerId;
        $seen = [];

        for ($step = 0; $current !== null && $step < self::MAX_DEPTH; $step++) {
            if (isset($shown[$current])) {
                return $current;
            }

            if (isset($seen[$current])) {
                return null;
            }

            $seen[$current] = true;
            $current = $parents[$current] ?? null;
        }

        return null;
    }
}
