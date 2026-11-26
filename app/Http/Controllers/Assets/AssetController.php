<?php

namespace App\Http\Controllers\Assets;

use App\Actions\Assets\ChangeAssetStatus;
use App\Actions\Assets\DeleteAsset;
use App\Actions\Assets\SaveAsset;
use App\Http\Controllers\Controller;
use App\Http\Requests\Assets\AssetRequest;
use App\Http\Requests\Assets\AssetStatusRequest;
use App\Models\Asset;
use App\Models\AssetAssignment;
use App\Models\AssetCategory;
use App\Models\Employee;
use App\Models\User;
use App\Support\Assets\AssetRules;
use App\Support\Assets\AssetScope;
use App\Support\Attendance\AttendanceClock;
use App\Support\ListQuery;
use App\Support\Payroll\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The asset register: the list (HR and Admin see everything with costs; a Manager gets a read-only list of what their
 * own team holds and never sees a cost), the asset page (HR and Admin only) and the writes. Add, edit and status:
 * can:assets.assign (Admin, HR); delete: can:assets.manage (Admin). Every write goes through its action (one transaction,
 * audited); refusals come back as validation errors on the field.
 */
class AssetController extends Controller
{
    /** People the assign dialog lists up front; with more it searches as you type. */
    public const EMPLOYEE_LIST_MAX = 500;

    public function index(Request $request): Response|RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->canAny(['assets.assign', 'assets.view-team']), 403);

        $all = AssetScope::seesAll($user);
        $search = ListQuery::stringParam($request, 'search');
        $category = $this->idParam($request, 'category');
        // The team view has no status or condition filter: whatever the URL says is ignored.
        $status = $all ? $this->choiceParam($request, 'status', config('hrm.assets.statuses')) : '';
        $condition = $all ? $this->choiceParam($request, 'condition', config('hrm.assets.conditions')) : '';

        $query = AssetScope::assets($user, Asset::query())
            // Explicit columns: a team viewer's query never reads the cost.
            ->select(['assets.id', 'assets.asset_code', 'assets.name', 'assets.asset_category_id', 'assets.serial_number', 'assets.condition', 'assets.status', ...($all ? ['assets.cost'] : [])])
            ->with(['category:id,name', 'openAssignment:id,asset_id,employee_id,assigned_on', 'openAssignment.employee:id,employee_code,first_name,last_name'])
            ->when($category !== null, fn ($q) => $q->where('assets.asset_category_id', $category))
            ->when($status !== '', fn ($q) => $q->where('assets.status', $status))
            ->when($condition !== '', fn ($q) => $q->where('assets.condition', $condition));
        $query = AssetScope::search($query, $search)->orderBy('assets.asset_code')->orderBy('assets.id');

        $paginator = $query->paginate(AssetScope::PER_PAGE, ['*'], 'page', ListQuery::page($request, 'page'));

        if ($redirect = ListQuery::clampRedirect($request, ['page' => $paginator])) {
            return $redirect;
        }

        return Inertia::render('assets/index', [
            'assets' => [
                'data' => $paginator->getCollection()->map(fn (Asset $a) => $this->listRow($a, $all))->values()->all(),
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
            'filters' => ['search' => $search, 'category' => $category, 'status' => $status, 'condition' => $condition],
            'options' => [
                'categories' => $this->categories(),
                'statuses' => $all ? config('hrm.assets.statuses') : [],
                'conditions' => $all ? config('hrm.assets.conditions') : [],
            ],
            'scope' => AssetScope::scopeName($user),
            'can' => ['create' => $all, 'import' => $all, 'export' => $all],
        ]);
    }

    public function show(Request $request, Asset $asset): Response
    {
        $user = $request->user();
        abort_unless($user->can('assets.assign'), 403);

        $asset->load('category:id,name');
        $assignments = AssetAssignment::query()
            ->where('asset_id', $asset->id)
            ->with('employee:id,employee_code,first_name,last_name')
            ->orderByDesc('assigned_on')
            ->orderByDesc('id')
            ->get();
        $names = User::query()
            ->whereIn('id', $assignments->pluck('assigned_by')->merge($assignments->pluck('returned_by'))->filter()->unique()->all())
            ->pluck('name', 'id');
        $open = $assignments->first(fn (AssetAssignment $a) => $a->returned_on === null);

        $statusOptions = $open !== null ? [] : array_values(array_filter(
            ['available', 'in_repair', 'retired'],
            fn (string $to) => AssetRules::statusRefusal($user, $asset->status, $to, false, $asset) === null,
        ));
        $manage = $user->can('assets.manage');

        $employees = Employee::query()
            ->whereIn('status', ['active', 'probation'])
            ->orderBy('first_name')->orderBy('last_name')->orderBy('id')
            ->limit(self::EMPLOYEE_LIST_MAX + 1)
            ->get(['id', 'employee_code', 'first_name', 'last_name']);

        return Inertia::render('assets/show', [
            'asset' => [
                'id' => $asset->id,
                'asset_code' => $asset->asset_code,
                'name' => $asset->name,
                'asset_category_id' => $asset->asset_category_id,
                'category' => $asset->category?->name,
                'serial_number' => $asset->serial_number,
                'purchase_date' => $asset->purchase_date?->format('Y-m-d'),
                'cost' => $asset->cost,
                'cost_formatted' => $asset->cost === null ? null : Money::format($asset->cost),
                'condition' => $asset->condition,
                'status' => $asset->status,
                'notes' => $asset->notes,
            ],
            'holder' => $open === null ? null : [
                'assignment_id' => $open->id,
                'assigned_on' => $open->assigned_on->format('Y-m-d'),
                ...$this->person($open->employee),
            ],
            'history' => $assignments->map(fn (AssetAssignment $a) => [
                'id' => $a->id,
                'employee' => $this->person($a->employee),
                'assigned_on' => $a->assigned_on->format('Y-m-d'),
                'returned_on' => $a->returned_on?->format('Y-m-d'),
                'condition_out' => $a->condition_out,
                'return_condition' => $a->return_condition,
                'note' => $a->note,
                'return_note' => $a->return_note,
                'assigned_by' => $a->assigned_by === null ? null : ($names[$a->assigned_by] ?? null),
                'returned_by' => $a->returned_by === null ? null : ($names[$a->returned_by] ?? null),
            ])->values()->all(),
            'can' => [
                'edit' => true,
                'assign' => $asset->status === 'available' && $open === null,
                'return' => $open !== null,
                'status' => $statusOptions !== [],
                'delete' => $manage && $assignments->isEmpty(),
                'retire' => $manage,
            ],
            'status_options' => $statusOptions,
            'categories' => $this->categories(),
            'employees' => $employees->take(self::EMPLOYEE_LIST_MAX)->map(fn (Employee $e) => $this->person($e))->values()->all(),
            'employees_truncated' => $employees->count() > self::EMPLOYEE_LIST_MAX,
            'today' => app(AttendanceClock::class)->attendanceToday(),
        ]);
    }

    /** Search-as-you-type for the assign dialog when there are more people than it lists up front. */
    public function employeeOptions(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('assets.assign'), 403);

        $people = Employee::query()
            ->whereIn('status', ['active', 'probation'])
            ->search(ListQuery::stringParam($request, 'q'))
            ->orderBy('first_name')->orderBy('last_name')->orderBy('id')
            ->limit(20)
            ->get(['id', 'employee_code', 'first_name', 'last_name']);

        return response()->json(['employees' => $people->map(fn (Employee $e) => $this->person($e))->values()->all()]);
    }

    public function store(AssetRequest $request, SaveAsset $save): RedirectResponse
    {
        $asset = $save->create($request->user(), $request->assetData());

        return back()->with('success', "Asset {$asset->asset_code} added.");
    }

    public function update(AssetRequest $request, Asset $asset, SaveAsset $save): RedirectResponse
    {
        $save->update($request->user(), $asset, $request->assetData());

        return back()->with('success', 'Asset updated.');
    }

    public function status(AssetStatusRequest $request, Asset $asset, ChangeAssetStatus $change): RedirectResponse
    {
        $change->handle($request->user(), $asset, $request->validated('status'));

        return back()->with('success', 'Status updated.');
    }

    public function destroy(Request $request, Asset $asset, DeleteAsset $delete): RedirectResponse
    {
        abort_unless($request->user()->can('assets.manage'), 403);

        $delete->handle($request->user(), $asset);

        // The asset page no longer exists, so go back to the list.
        return redirect()->route('assets.index')->with('success', 'Asset deleted.');
    }

    /** @return array<int, array{id: int, name: string}> */
    private function categories(): array
    {
        return AssetCategory::query()->orderBy('sort_order')->orderBy('name')->get(['id', 'name'])
            ->map(fn (AssetCategory $c) => ['id' => $c->id, 'name' => $c->name])->all();
    }

    /** @return array{id: int, code: string, name: string} */
    private function person(Employee $employee): array
    {
        return ['id' => $employee->id, 'code' => $employee->employee_code, 'name' => $employee->full_name];
    }

    /** @return array<string, mixed> */
    private function listRow(Asset $asset, bool $all): array
    {
        $open = $asset->openAssignment;

        return [
            'id' => $asset->id,
            'asset_code' => $asset->asset_code,
            'name' => $asset->name,
            'category' => $asset->category?->name,
            'serial_number' => $asset->serial_number,
            'condition' => $asset->condition,
            'status' => $asset->status,
            'holder' => $open?->employee === null ? null : $this->person($open->employee),
            'held_since' => $open?->assigned_on->format('Y-m-d'),
            ...($all ? ['cost' => $asset->cost] : []),
        ];
    }

    private function idParam(Request $request, string $key): ?int
    {
        $value = $request->query($key);

        return is_scalar($value) && ctype_digit((string) $value) && strlen((string) $value) < 10 ? (int) $value : null;
    }

    /** @param  list<string>  $allowed */
    private function choiceParam(Request $request, string $key, array $allowed): string
    {
        $value = $request->query($key);

        return is_string($value) && in_array($value, $allowed, true) ? $value : '';
    }
}
