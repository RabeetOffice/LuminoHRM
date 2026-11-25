<?php

namespace App\Http\Controllers\Leave;

use App\Actions\Leave\AdjustBalance;
use App\Exports\LeaveOpeningTemplateExport;
use App\Http\Controllers\Controller;
use App\Http\Controllers\EmployeeImportController;
use App\Imports\EmployeesImport;
use App\Imports\ImportFileException;
use App\Imports\LeaveOpeningImport;
use App\Models\Employee;
use App\Models\LeaveType;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\EmployeeImportStore;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Excel as ExcelType;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

/**
 * Leave > Balances > Import opening balances (can:leave.approve-all on every route): template -> upload -> preview of
 * every row -> run. The go-live figures: per employee, paid type and year, the days used so far that year.
 *
 * Same pattern as the employee import: the upload is checked as a file (LeaveOpeningImport::read) and stored under a
 * random 60-minute token for its uploader (EmployeeImportStore, kind "leave-opening", pruned by hrm:prune-imports; its
 * own slot, so a pending employee-import preview is kept). The run raises the time limit like the employee run, and any
 * unexpected failure shows "The import failed" rather than an error page. The preview re-reads it and checks every row (LeaveOpeningImport::check), and so
 * does the run, which saves all rows or none in ONE transaction: each row sets opening_used through
 * AdjustBalance::setOpeningUsed (reason "Opening balance import", overdrawn confirmed: the preview flagged those rows),
 * which writes a "Leave balance adjusted" entry per row it changes. One more entry "Leave opening balances imported"
 * (counts only) per run. Setting, not adding: importing the same file again changes nothing. No password hashing and no
 * per-row heavy query: the people and types are read once; each row is a lock, a save and an audit entry at most.
 */
class BalanceImportController extends Controller
{
    public function show(Request $request): Response
    {
        $token = $request->query('token');
        $preview = null;
        $expired = false;

        if ($token !== null) {
            $path = EmployeeImportStore::path($token, $request->user(), EmployeeImportStore::KIND_LEAVE_OPENING);

            try {
                $preview = $path === null ? null : $this->preview((string) $token, $path, $request->user());
            } catch (ImportFileException) {
                $preview = null;
            }

            $expired = $preview === null;
        }

        return Inertia::render('leave/balances-import', [
            'preview' => $preview,
            'expired' => $expired,
            'limits' => ['rows' => LeaveOpeningImport::MAX_ROWS, 'megabytes' => LeaveOpeningImport::MAX_KILOBYTES / 1024],
        ]);
    }

    public function template(): BinaryFileResponse
    {
        return Excel::download(new LeaveOpeningTemplateExport, LeaveOpeningTemplateExport::FILE_NAME, ExcelType::XLSX);
    }

    public function upload(Request $request): RedirectResponse
    {
        $request->validate(
            ['file' => ['required', 'file', 'max:'.LeaveOpeningImport::MAX_KILOBYTES]],
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
            (new LeaveOpeningImport)->read((string) $file->getRealPath());
        } catch (ImportFileException $e) {
            return back()->withErrors(['file' => $e->getMessage()]);
        }

        $token = EmployeeImportStore::put($file, $request->user(), EmployeeImportStore::KIND_LEAVE_OPENING);

        return redirect()->route('leave.balances.import', ['token' => $token]);
    }

    public function run(Request $request, AdjustBalance $adjust): RedirectResponse
    {
        $user = $request->user();
        $token = $request->input('token');

        if (! is_string($token) || strlen($token) > 100) {
            return redirect()->route('leave.balances.import')->with('error', EmployeeImportController::REFUSED);
        }

        // A double click must not run twice: the second request is refused while the first holds the lock.
        $lock = Cache::lock('leave-opening-import:'.sha1($token), EmployeeImportController::LOCK_SECONDS);

        if (! $lock->get()) {
            return redirect()->route('leave.balances.import', ['token' => $token])->with('error', EmployeeImportController::BUSY);
        }

        try {
            try {
                $this->extendTimeLimit();

                $path = EmployeeImportStore::path($token, $user, EmployeeImportStore::KIND_LEAVE_OPENING);

                if ($path === null) {
                    return redirect()->route('leave.balances.import')->with('error', EmployeeImportController::REFUSED);
                }

                $import = new LeaveOpeningImport;

                try {
                    $rows = $import->check($import->read($path)['rows'], $user);
                } catch (ImportFileException) {
                    EmployeeImportStore::forget($token);

                    return redirect()->route('leave.balances.import')->with('error', EmployeeImportController::REFUSED);
                }

                if (array_filter($rows, fn (array $row) => $row['errors'] !== []) !== []) {
                    return redirect()->route('leave.balances.import', ['token' => $token])->with('error', EmployeeImportController::CHANGED);
                }

                try {
                    $counts = $this->save($rows, $adjust, $user);
                } catch (AuthorizationException) {
                    return redirect()->route('leave.balances.import', ['token' => $token])->with('error', EmployeeImportController::CHANGED);
                }
            } catch (Throwable $e) {
                // Anything unexpected (the time-limit call, a re-check, the save): "Import failed", never a 500. The
                // upload is kept (a failed run never consumes it), so the same file can be run again.
                report($e);

                return redirect()->route('leave.balances.import', ['token' => $token])->with('error', EmployeeImportController::FAILED);
            }

            EmployeeImportStore::forget($token);
        } finally {
            $lock->release();
        }

        $noun = $counts['changed'] === 1 ? 'balance' : 'balances';

        return redirect()->route('leave.balances.index')->with(
            'success',
            "Imported {$counts['rows']} rows: {$counts['changed']} {$noun} updated, {$counts['unchanged']} already up to date.",
        );
    }

    /** Gives a long run room to finish, like the employee import (overridable in tests). */
    protected function extendTimeLimit(): void
    {
        EmployeeImportController::extendRunTimeLimit();
    }

    /**
     * @param  list<array{line: int, data: array{employee_id: int, type_id: int, year: int, days: float}|null, warnings: list<string>}>  $rows
     * @return array{rows: int, changed: int, unchanged: int, overdrawn: int}
     */
    private function save(array $rows, AdjustBalance $adjust, User $user): array
    {
        $employees = Employee::query()->whereKey(array_map(fn (array $r) => $r['data']['employee_id'], $rows))->get()->keyBy('id');
        $types = LeaveType::query()->get()->keyBy('id');

        return DB::transaction(function () use ($rows, $adjust, $user, $employees, $types): array {
            $changed = 0;
            $overdrawn = 0;
            $years = [];

            foreach ($rows as $row) {
                $data = $row['data'];
                $adjust->setOpeningUsed(
                    $employees[$data['employee_id']],
                    $types[$data['type_id']],
                    $data['year'],
                    $data['days'],
                    AdjustBalance::OPENING_IMPORT_REASON,
                    true,
                    $user,
                );
                $changed += $adjust->changed() ? 1 : 0;
                $overdrawn += $row['warnings'] !== [] ? 1 : 0;
                $years[$data['year']] = true;
            }

            $counts = ['rows' => count($rows), 'changed' => $changed, 'unchanged' => count($rows) - $changed, 'overdrawn' => $overdrawn];
            $years = array_keys($years);
            sort($years);

            Audit::record('leave', 'Leave opening balances imported', null, [...$counts, 'years' => $years], $user);

            return $counts;
        });
    }

    /**
     * What the preview page shows: per row its number, the key fields, its errors and warnings; never anything else.
     *
     * @return array<string, mixed>
     *
     * @throws ImportFileException
     */
    private function preview(string $token, string $path, User $user): array
    {
        $import = new LeaveOpeningImport;
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
                'warnings' => count(array_filter($rows, fn (array $row) => $row['errors'] === [] && $row['warnings'] !== [])),
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
