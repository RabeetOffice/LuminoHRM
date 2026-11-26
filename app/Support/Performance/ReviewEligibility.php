<?php

namespace App\Support\Performance;

use App\Models\Employee;
use App\Models\Kpi;
use App\Models\Review;
use Illuminate\Support\Collection;

/**
 * Who can be reviewed in a period. A person qualifies on the period rules when their status is active or probation and they
 * joined on or before the period end, and (with a cycle) they are not already in it. Of those, the ones with at least one
 * active KPI (their designation's or their own) are eligible; the rest are "no KPIs" so HR can fix the KPIs and add them.
 *
 * forPeriod() runs a fixed number of queries whatever the head count: the people, then their active KPIs in one go.
 */
class ReviewEligibility
{
    /** The statuses that can be reviewed. */
    public const STATUSES = ['active', 'probation'];

    /**
     * The people who qualify on the period rules, split by whether they have active KPIs. `kpis` holds each eligible
     * person's active KPIs (designation ones first), keyed by employee id, so the caller copies them without another query.
     *
     * @param  list<int>|null  $employeeIds  limit the check to these people (Add people); null for everyone
     * @return array{eligible: Collection<int, Employee>, no_kpis: Collection<int, Employee>, kpis: array<int, Collection<int, Kpi>>}
     */
    public static function forPeriod(string $periodEnd, ?int $cycleId = null, ?array $employeeIds = null): array
    {
        $people = Employee::query()
            ->whereIn('status', self::STATUSES)
            ->where('joining_date', '<=', $periodEnd)
            ->when($employeeIds !== null, fn ($q) => $q->whereKey($employeeIds))
            ->when($cycleId !== null, fn ($q) => $q->whereNotExists(fn ($sub) => $sub->selectRaw('1')
                ->from((new Review)->getTable())
                ->whereColumn('reviews.employee_id', 'employees.id')
                ->where('reviews.review_cycle_id', $cycleId)))
            ->orderBy('first_name')->orderBy('last_name')->orderBy('id')
            ->get();

        $kpis = self::kpisFor($people);

        [$eligible, $noKpis] = $people->partition(fn (Employee $e) => $kpis[$e->id]->isNotEmpty());

        return [
            'eligible' => $eligible->values(),
            'no_kpis' => $noKpis->values(),
            'kpis' => array_filter($kpis, fn (Collection $list) => $list->isNotEmpty()),
        ];
    }

    /**
     * The active KPIs of one person as they are now: their designation's, then their own, each by name then id.
     *
     * @return Collection<int, Kpi>
     */
    public static function activeKpisFor(Employee $employee): Collection
    {
        return self::kpisFor(collect([$employee]))[$employee->id];
    }

    /**
     * Every person's active KPIs, in one query.
     *
     * @param  Collection<int, Employee>  $people
     * @return array<int, Collection<int, Kpi>>
     */
    private static function kpisFor(Collection $people): array
    {
        if ($people->isEmpty()) {
            return [];
        }

        $designationIds = $people->pluck('designation_id')->filter()->unique()->values()->all();
        $employeeIds = $people->pluck('id')->all();

        $all = Kpi::query()
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereIn('designation_id', $designationIds)->orWhereIn('employee_id', $employeeIds))
            ->orderBy('name')->orderBy('id')
            ->get();

        $byDesignation = $all->whereNotNull('designation_id')->groupBy('designation_id');
        $byEmployee = $all->whereNotNull('employee_id')->groupBy('employee_id');

        $result = [];

        foreach ($people as $person) {
            $result[$person->id] = collect($byDesignation->get($person->designation_id, []))
                ->concat($byEmployee->get($person->id, []))
                ->values();
        }

        return $result;
    }
}
