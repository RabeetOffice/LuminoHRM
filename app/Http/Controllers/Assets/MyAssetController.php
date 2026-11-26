<?php

namespace App\Http\Controllers\Assets;

use App\Http\Controllers\Controller;
use App\Models\AssetAssignment;
use App\Models\Employee;
use App\Models\User;
use App\Support\Assets\AssetHoldings;
use App\Support\ListQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Inertia\Inertia;
use Inertia\Response;

/**
 * My Assets: what the signed-in person holds now and what they have given back, for every role.
 *
 *   GET /my/assets?page=N
 *
 * The record is the account's own employee_id; nothing in the URL names a person. A user without an employee record
 * gets empty lists (employee = null). A row carries the asset code, name, category, serial number (it identifies the
 * device), the dates, both conditions and the notes. The cost is never read or sent, whoever asks.
 *
 * Props: employee {name} | null, holding (every open assignment, oldest first), history (returned ones, newest return
 * first, 20 per page).
 */
class MyAssetController extends Controller
{
    private const PER_PAGE = 20;

    public function show(Request $request): Response|RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $employee = $user->employee_id === null ? null : Employee::query()->find($user->employee_id);

        if ($employee === null) {
            return Inertia::render('my/assets', [
                'employee' => null,
                'holding' => [],
                'history' => $this->page([], null),
            ]);
        }

        $paginator = AssetAssignment::query()
            ->where('employee_id', $employee->id)
            ->whereNotNull('returned_on')
            ->with(['asset:id,asset_code,name,serial_number,asset_category_id', 'asset.category:id,name'])
            ->orderByDesc('returned_on')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE, ['*'], 'page', ListQuery::page($request, 'page'));

        if ($redirect = ListQuery::clampRedirect($request, ['page' => $paginator])) {
            return $redirect;
        }

        return Inertia::render('my/assets', [
            'employee' => ['name' => $employee->full_name],
            'holding' => AssetHoldings::openFor($employee)->map(fn (AssetAssignment $a) => $this->row($a))->values()->all(),
            'history' => $this->page($paginator->getCollection()->map(fn (AssetAssignment $a) => $this->row($a))->values()->all(), $paginator),
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  LengthAwarePaginator<int, AssetAssignment>|null  $paginator
     * @return array<string, mixed>
     */
    private function page(array $rows, ?LengthAwarePaginator $paginator): array
    {
        return [
            'data' => $rows,
            'current_page' => $paginator?->currentPage() ?? 1,
            'last_page' => $paginator?->lastPage() ?? 1,
            'per_page' => $paginator?->perPage() ?? self::PER_PAGE,
            'total' => $paginator?->total() ?? 0,
            'from' => $paginator?->firstItem(),
            'to' => $paginator?->lastItem(),
        ];
    }

    /** @return array<string, mixed> */
    private function row(AssetAssignment $a): array
    {
        return [
            'id' => $a->id,
            'code' => $a->asset->asset_code,
            'name' => $a->asset->name,
            'category' => $a->asset->category?->name,
            'serial_number' => $a->asset->serial_number,
            'assigned_on' => $a->assigned_on->format('Y-m-d'),
            'returned_on' => $a->returned_on?->format('Y-m-d'),
            'condition_out' => $a->condition_out,
            'return_condition' => $a->return_condition,
        ];
    }
}
