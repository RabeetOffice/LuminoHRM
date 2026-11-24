<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Support\EmployeeAccess;
use App\Support\EmployeeFiles;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The dashboard. Every figure is worked out here, for the viewer, and a figure the viewer may not have is ABSENT from
 * the props (not zero, not empty):
 *
 *   employeeCount  {scope: 'company'|'team', total, probation}  for employees.view-all / employees.view-team only.
 *                  Active + probation employees inside EmployeeAccess::scope (Admin/HR: the company; a Manager:
 *                  their own record + direct reports). A plain Employee gets no key and no tile.
 *   birthdays      [{id, name, designation, photo_url}]  for employees.view-all only (the date of birth is
 *                  restricted). Active + probation people whose birthday is today in the company's display zone;
 *                  29 February birthdays show on 28 February in other years. Never the date or the year.
 */
class DashboardController extends Controller
{
    /** The statuses counted as "employed". */
    public const COUNTED = ['active', 'probation'];

    public function index(Request $request): Response
    {
        $user = $request->user();
        $access = new EmployeeAccess($user);
        $props = [];

        if ($user->canAny(['employees.view-all', 'employees.view-team'])) {
            $props['employeeCount'] = $this->employeeCount($access, $user->can('employees.view-all'));
        }

        if ($user->can('employees.view-all')) {
            $props['birthdays'] = $this->birthdays($access);
        }

        return Inertia::render('dashboard', $props);
    }

    /** @return array{scope: string, total: int, probation: int} */
    private function employeeCount(EmployeeAccess $access, bool $company): array
    {
        $byStatus = $access->scope(Employee::query())
            ->whereIn('status', self::COUNTED)
            ->toBase()
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return [
            'scope' => $company ? 'company' : 'team',
            'total' => (int) $byStatus->sum(),
            'probation' => (int) ($byStatus['probation'] ?? 0),
        ];
    }

    /** @return list<array{id: int, name: string, designation: string|null, photo_url: string|null}> */
    private function birthdays(EmployeeAccess $access): array
    {
        $today = Date::now()->setTimezone($this->displayZone());
        $month = (int) $today->format('n');
        $day = (int) $today->format('j');
        // Born on 29 February: celebrated on 28 February when this year has no 29th.
        $leaplings = $month === 2 && $day === 28 && ! $today->isLeapYear();

        $people = $access->scope(Employee::query())
            ->whereIn('status', self::COUNTED)
            ->whereNotNull('dob')
            ->where(function (Builder $q) use ($month, $day, $leaplings) {
                $q->where(fn (Builder $w) => $w->whereMonth('dob', $month)->whereDay('dob', $day));

                if ($leaplings) {
                    $q->orWhere(fn (Builder $w) => $w->whereMonth('dob', 2)->whereDay('dob', 29));
                }
            })
            // dob is only filtered on; it is never selected, so it cannot slip into the props.
            ->select(['id', 'first_name', 'last_name', 'designation_id', 'photo_path', 'updated_at'])
            ->with('designation:id,name')
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->orderBy('id')
            ->get();

        return array_values($people->map(fn (Employee $e) => [
            'id' => $e->id,
            'name' => $e->full_name,
            'designation' => $e->designation?->name,
            'photo_url' => EmployeeFiles::photoUrl($e),
        ])->all());
    }

    /** The company display timezone, or the app timezone when the setting is unusable. */
    private function displayZone(): string
    {
        $zone = (string) settings('company.timezone', config('app.timezone'));

        try {
            new \DateTimeZone($zone);
        } catch (\Throwable) {
            return (string) config('app.timezone');
        }

        return $zone;
    }
}
