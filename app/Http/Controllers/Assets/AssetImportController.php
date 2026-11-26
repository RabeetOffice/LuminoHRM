<?php

namespace App\Http\Controllers\Assets;

use App\Actions\Assets\AssignAsset;
use App\Actions\Assets\ChangeAssetStatus;
use App\Actions\Assets\SaveAsset;
use App\Exports\AssetTemplateExport;
use App\Http\Controllers\Controller;
use App\Http\Controllers\EmployeeImportController;
use App\Imports\AssetRegisterImport;
use App\Imports\EmployeesImport;
use App\Imports\ImportFileException;
use App\Imports\PayrollOpeningWorkbook;
use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\Employee;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\EmployeeImportStore;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Excel as ExcelType;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

/**
 * Assets > Import (can:assets.assign on every route): template -> upload -> preview of every row -> run. The same pattern
 * as the go-live payroll imports (Payroll\OpeningImportController): the upload is checked as a file and kept under a
 * random 60-minute token for its uploader in its own slot (EmployeeImportStore kind "assets-register"); the preview
 * re-reads and checks every row (AssetRegisterImport::check), and so does the run.
 *
 * The run saves all rows or none, in ONE transaction (DB::transaction(fn, 3)). Lock order, the app-wide one (employee
 * rows before asset rows, never the other way):
 *   1. every employee named by the file, FOR UPDATE, ascending id;
 *   2. the categories named, shared, ascending id;
 *   3. the existing assets named (by code or serial), FOR UPDATE, ascending id;
 * then the rows are checked again under those locks (plain reads; asset_assignments is never read with a lock, see
 * AssignAsset) and applied in file order through the same actions as the screens (SaveAsset::within,
 * ChangeAssetStatus::within, AssignAsset::within: one audit entry each), and one entry for the run, `Assets imported`
 * (counts only). A row that is no longer valid at the run (an assignment made after the preview, say) saves nothing and
 * sends the person back to the preview. A cache lock lets one run go at a time; an unexpected failure shows "The import
 * failed" and keeps the upload.
 */
class AssetImportController extends Controller
{
    public const SLOT = 'assets-register';

    public const LOCK = 'assets-import';

    public function show(Request $request): Response
    {
        $token = $request->query('token');
        $preview = null;
        $expired = false;

        if ($token !== null) {
            $path = EmployeeImportStore::path($token, $request->user(), self::SLOT);

            try {
                $preview = $path === null ? null : $this->preview((string) $token, $path, $request->user());
            } catch (ImportFileException) {
                $preview = null;
            }

            $expired = $preview === null;
        }

        return Inertia::render('assets/import', [
            'preview' => $preview,
            'expired' => $expired,
            'limits' => ['rows' => PayrollOpeningWorkbook::MAX_ROWS, 'megabytes' => PayrollOpeningWorkbook::MAX_KILOBYTES / 1024],
        ]);
    }

    public function template(): BinaryFileResponse
    {
        return Excel::download(new AssetTemplateExport, AssetTemplateExport::FILE_NAME, ExcelType::XLSX);
    }

    public function upload(Request $request): RedirectResponse
    {
        $request->validate(
            ['file' => ['required', 'file', 'max:'.PayrollOpeningWorkbook::MAX_KILOBYTES]],
            [
                'file.required' => 'Choose an .xlsx file to upload.',
                'file.file' => 'The file could not be uploaded. Try again.',
                'file.uploaded' => 'The file could not be uploaded. It may be larger than 5 MB.',
                'file.max' => 'The file is larger than 5 MB.',
            ],
        );

        $file = $request->file('file');

        if (strtolower($file->getClientOriginalExtension()) !== 'xlsx') {
            return back()->withErrors(['file' => EmployeesImport::NOT_XLSX]);
        }

        try {
            (new AssetRegisterImport)->read((string) $file->getRealPath());
        } catch (ImportFileException $e) {
            return back()->withErrors(['file' => $e->getMessage()]);
        }

        $token = EmployeeImportStore::put($file, $request->user(), self::SLOT);

        return redirect()->route('assets.import', ['token' => $token]);
    }

    public function run(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $token = $request->input('token');
        $page = fn (array $query = []) => redirect()->route('assets.import', $query);

        if (! is_string($token) || strlen($token) > 100) {
            return $page()->with('error', EmployeeImportController::REFUSED);
        }

        // One run at a time, whatever the upload token: two runs must not both decide "new" for the same serial.
        $lock = Cache::lock(self::LOCK, EmployeeImportController::LOCK_SECONDS);

        if (! $lock->get()) {
            return $page(['token' => $token])->with('error', EmployeeImportController::BUSY);
        }

        try {
            try {
                $this->extendTimeLimit();

                $path = EmployeeImportStore::path($token, $user, self::SLOT);

                if ($path === null) {
                    return $page()->with('error', EmployeeImportController::REFUSED);
                }

                try {
                    $import = new AssetRegisterImport;
                    $parsed = $import->read($path)['rows'];
                    $rows = $import->check($parsed, $user);
                    $counts = self::hasErrors($rows) ? null : $this->save($import, $parsed, $rows, $user);
                } catch (ImportFileException) {
                    EmployeeImportStore::forget($token);

                    return $page()->with('error', EmployeeImportController::REFUSED);
                } catch (AuthorizationException|ValidationException) {
                    return $page(['token' => $token])->with('error', EmployeeImportController::CHANGED);
                }

                if ($counts === null) {
                    return $page(['token' => $token])->with('error', EmployeeImportController::CHANGED);
                }
            } catch (Throwable $e) {
                // Anything unexpected: "Import failed", never a 500. The upload is kept, so the same file can be run again.
                report($e);

                return $page(['token' => $token])->with('error', EmployeeImportController::FAILED);
            }

            EmployeeImportStore::forget($token);
        } finally {
            $lock->release();
        }

        return redirect()->route('assets.index')->with(
            'success',
            "Imported {$counts['rows']} rows: {$counts['created']} added, {$counts['updated']} updated, {$counts['unchanged']} already up to date.",
        );
    }

    /** Gives a long run room to finish, like the employee import (overridable in tests). */
    protected function extendTimeLimit(): void
    {
        EmployeeImportController::extendRunTimeLimit();
    }

    /** @param  list<array{errors: list<string>}>  $rows */
    private static function hasErrors(array $rows): bool
    {
        return array_filter($rows, fn (array $row) => $row['errors'] !== []) !== [];
    }

    /**
     * The ids a checked file names: employees (every holder), categories and existing assets, each ascending.
     *
     * @param  list<array{data: array<string, mixed>|null}>  $rows
     * @return array{employees: list<int>, categories: list<int>, assets: list<int>}
     */
    private static function ids(array $rows): array
    {
        $ids = ['employees' => [], 'categories' => [], 'assets' => []];

        foreach ($rows as $row) {
            $data = $row['data'] ?? [];

            foreach (['employees' => 'holder_id', 'categories' => 'category_id', 'assets' => 'asset_id'] as $set => $key) {
                if (($data[$key] ?? null) !== null) {
                    $ids[$set][(int) $data[$key]] = true;
                }
            }
        }

        return array_map(function (array $set): array {
            $list = array_keys($set);
            sort($list);

            return $list;
        }, $ids);
    }

    /**
     * @param  list<array{line: int, cells: array<string, mixed>}>  $parsed
     * @param  list<array{line: int, action: string, data: array<string, mixed>|null}>  $checked
     * @return array{rows: int, created: int, updated: int, unchanged: int, assigned: int}
     */
    private function save(AssetRegisterImport $import, array $parsed, array $checked, User $user): array
    {
        $ids = self::ids($checked);

        return DB::transaction(function () use ($import, $parsed, $user, $ids): array {
            // 1. Every employee in the file, ascending id. 2. The categories, shared. 3. The existing assets, ascending id.
            $employees = $ids['employees'] === [] ? collect()
                : Employee::query()->whereKey($ids['employees'])->orderBy('id')->lockForUpdate()->get()->keyBy('id');

            if ($ids['categories'] !== []) {
                AssetCategory::query()->whereKey($ids['categories'])->orderBy('id')->sharedLock()->get(['id']);
            }

            $assets = $ids['assets'] === [] ? collect()
                : Asset::query()->whereKey($ids['assets'])->orderBy('id')->lockForUpdate()->get()->keyBy('id');

            // Checked again under the locks: what the preview said may no longer hold. Anything that would now need a row
            // the transaction has not locked (an asset added with the same serial since) counts as a change too.
            $rows = $import->check($parsed, $user);
            $again = self::ids($rows);

            if (self::hasErrors($rows)
                || array_diff($again['employees'], $ids['employees']) !== []
                || array_diff($again['categories'], $ids['categories']) !== []
                || array_diff($again['assets'], $ids['assets']) !== []) {
                throw ValidationException::withMessages(['file' => EmployeeImportController::CHANGED]);
            }

            $save = app(SaveAsset::class);
            $status = app(ChangeAssetStatus::class);
            $assign = app(AssignAsset::class);
            $counts = ['rows' => count($rows), 'created' => 0, 'updated' => 0, 'unchanged' => 0, 'assigned' => 0];

            foreach ($rows as $row) {
                /** @var array{asset_id: int|null, fields: array<string, mixed>, status: string|null, employee_id: int|null, assigned_on: string|null} $data */
                $data = $row['data'];

                if ($row['action'] === 'none') {
                    $counts['unchanged']++;

                    continue;
                }

                if ($data['asset_id'] === null) {
                    $asset = $save->within($user, null, $data['fields']);
                    $counts['created']++;
                } else {
                    $asset = $assets[$data['asset_id']];

                    if ($data['fields'] !== []) {
                        $save->within($user, $asset, $data['fields']);
                    }

                    $counts['updated']++;
                }

                if ($data['status'] !== null) {
                    $status->within($user, $asset, $data['status']);
                }

                if ($data['employee_id'] !== null) {
                    $assign->within($user, $asset, $employees[$data['employee_id']], (string) $data['assigned_on'], null);
                    $counts['assigned']++;
                }
            }

            Audit::record('assets', 'Assets imported', null, $counts, $user);

            return $counts;
        }, 3);
    }

    /**
     * What the preview page shows: per row its number, the fields as read, the action, its errors and warnings.
     *
     * @return array<string, mixed>
     *
     * @throws ImportFileException
     */
    private function preview(string $token, string $path, User $user): array
    {
        $this->extendTimeLimit();

        $import = new AssetRegisterImport;
        $file = $import->read($path);
        $rows = $import->check($file['rows'], $user);
        $errors = count(array_filter($rows, fn (array $row) => $row['errors'] !== []));

        return [
            'token' => $token,
            'notices' => $file['notices'],
            'totals' => [
                'rows' => count($rows),
                'ready' => count($rows) - $errors,
                'errors' => $errors,
                'unchanged' => count(array_filter($rows, fn (array $row) => $row['errors'] === [] && $row['unchanged'])),
            ],
            'rows' => array_map(fn (array $row) => [
                'line' => $row['line'],
                ...$row['display'],
                'errors' => $row['errors'],
                'warnings' => $row['warnings'],
                'unchanged' => $row['unchanged'],
            ], $rows),
        ];
    }
}
