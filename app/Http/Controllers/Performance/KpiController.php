<?php

namespace App\Http\Controllers\Performance;

use App\Actions\Performance\SaveKpi;
use App\Http\Controllers\Controller;
use App\Http\Requests\Performance\KpiRequest;
use App\Models\Designation;
use App\Models\Employee;
use App\Models\Kpi;
use App\Support\ListQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Performance > KPIs (performance.manage: Admin and HR, enforced by the routes). The list, and add / change / delete through
 * SaveKpi (one transaction, audited); refusals come back as validation errors on the field.
 */
class KpiController extends Controller
{
    public function index(Request $request): Response|RedirectResponse
    {
        $search = ListQuery::stringParam($request, 'search');
        $target = $this->choice($request, 'target', ['designation', 'employee']);
        $active = $this->choice($request, 'active', ['1', '0']);

        $query = Kpi::query()
            ->with(['designation:id,name', 'employee:id,employee_code,first_name,last_name'])
            ->withCount('scores')
            ->when($search !== '', function ($q) use ($search) {
                $like = '%'.ListQuery::escapeLike($search).'%';

                $q->where(function ($w) use ($like, $search) {
                    $w->whereRaw("LOWER(kpis.name) LIKE ? ESCAPE '!'", [$like])
                        ->orWhereHas('designation', fn ($d) => $d->whereRaw("LOWER(name) LIKE ? ESCAPE '!'", [$like]))
                        ->orWhereHas('employee', fn ($e) => $e->search($search));
                });
            })
            ->when($target === 'designation', fn ($q) => $q->whereNotNull('designation_id'))
            ->when($target === 'employee', fn ($q) => $q->whereNotNull('employee_id'))
            ->when($active !== '', fn ($q) => $q->where('is_active', $active === '1'))
            ->orderBy('name')
            ->orderBy('id');

        $paginator = $query->paginate(ListQuery::perPage($request), ['*'], 'page', ListQuery::page($request, 'page'));

        if ($redirect = ListQuery::clampRedirect($request, ['page' => $paginator])) {
            return $redirect;
        }

        return Inertia::render('performance/kpis', [
            'kpis' => [
                'data' => $paginator->getCollection()->map(fn (Kpi $k) => $this->row($k))->values()->all(),
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
            'filters' => ['search' => $search, 'target' => $target, 'active' => $active],
            'options' => [
                'designations' => Designation::query()->orderBy('name')->orderBy('id')->get(['id', 'name'])
                    ->map(fn (Designation $d) => ['id' => $d->id, 'name' => $d->name])->all(),
                'employees' => Employee::query()->whereIn('status', ['active', 'probation'])
                    ->orderBy('first_name')->orderBy('last_name')->orderBy('id')
                    ->get(['id', 'employee_code', 'first_name', 'last_name'])
                    ->map(fn (Employee $e) => ['id' => $e->id, 'code' => $e->employee_code, 'name' => $e->full_name])->all(),
            ],
        ]);
    }

    public function store(KpiRequest $request, SaveKpi $save): RedirectResponse
    {
        $kpi = $save->create($request->user(), $request->kpiData());

        return back()->with('success', "KPI {$kpi->name} added.");
    }

    public function update(KpiRequest $request, Kpi $kpi, SaveKpi $save): RedirectResponse
    {
        $save->update($request->user(), $kpi, $request->kpiData());

        return back()->with('success', 'KPI updated.');
    }

    public function destroy(Request $request, Kpi $kpi, SaveKpi $save): RedirectResponse
    {
        $save->delete($request->user(), $kpi);

        return back()->with('success', 'KPI deleted.');
    }

    /** @return array<string, mixed> */
    private function row(Kpi $kpi): array
    {
        $uses = (int) $kpi->scores_count;

        return [
            'id' => $kpi->id,
            'name' => $kpi->name,
            'description' => $kpi->description,
            'weight' => $kpi->weight,
            'active' => $kpi->is_active,
            'target' => $kpi->designation_id !== null
                ? ['type' => 'designation', 'id' => $kpi->designation_id, 'name' => $kpi->designation?->name]
                : ['type' => 'employee', 'id' => $kpi->employee_id, 'name' => $kpi->employee?->full_name, 'code' => $kpi->employee?->employee_code],
            'uses' => $uses,
            'can_delete' => $uses === 0,
        ];
    }

    /** @param  list<string>  $allowed */
    private function choice(Request $request, string $key, array $allowed): string
    {
        $value = $request->query($key);

        return is_string($value) && in_array($value, $allowed, true) ? $value : '';
    }
}
