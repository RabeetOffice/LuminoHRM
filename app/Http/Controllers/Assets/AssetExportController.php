<?php

namespace App\Http\Controllers\Assets;

use App\Exports\AssetsExport;
use App\Http\Controllers\Controller;
use App\Imports\AssetRegisterImport;
use App\Models\Asset;
use App\Support\Assets\AssetScope;
use App\Support\Attendance\AttendanceClock;
use App\Support\Audit\Audit;
use App\Support\ListQuery;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Excel as ExcelType;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * GET /assets/export (can:assets.assign): the asset register as .xlsx with the list's filters (search, category, status,
 * condition; the same meaning as on the list) and every matching row. One audit entry per export, `Assets exported`,
 * written before the file is built: the row count, the filters and the number of columns; never row data.
 */
class AssetExportController extends Controller
{
    public function __invoke(Request $request): BinaryFileResponse
    {
        $user = $request->user();
        abort_unless($user->can('assets.assign'), 403);

        $search = ListQuery::stringParam($request, 'search');
        $category = $request->query('category');
        $category = is_scalar($category) && ctype_digit((string) $category) && strlen((string) $category) < 10 ? (int) $category : null;
        $status = $this->choice($request, 'status', AssetRegisterImport::configList('hrm.assets.statuses'));
        $condition = $this->choice($request, 'condition', AssetRegisterImport::configList('hrm.assets.conditions'));

        $query = AssetScope::assets($user, Asset::query())
            ->select('assets.*')
            ->when($category !== null, fn ($q) => $q->where('assets.asset_category_id', $category))
            ->when($status !== '', fn ($q) => $q->where('assets.status', $status))
            ->when($condition !== '', fn ($q) => $q->where('assets.condition', $condition));
        $query = AssetScope::search($query, $search)->orderBy('assets.asset_code')->orderBy('assets.id');

        // Before the file is built: an export that cannot be logged does not happen.
        Audit::record('assets', 'Assets exported', null, [
            'rows' => (clone $query)->count(),
            'filters' => ['search' => $search, 'category' => $category, 'status' => $status, 'condition' => $condition],
            'columns' => count(AssetsExport::headers()),
        ], $user);

        $today = app(AttendanceClock::class)->attendanceToday();

        return Excel::download(new AssetsExport($query), "assets-{$today}.xlsx", ExcelType::XLSX);
    }

    /** @param  list<string>  $allowed */
    private function choice(Request $request, string $key, array $allowed): string
    {
        $value = $request->query($key);

        return is_string($value) && in_array($value, $allowed, true) ? $value : '';
    }
}
