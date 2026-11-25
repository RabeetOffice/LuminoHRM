<?php

namespace App\Http\Controllers;

use App\Actions\Employees\ImportEmployees;
use App\Exceptions\AccountRuleViolation;
use App\Exports\EmployeeTemplateExport;
use App\Imports\EmployeeImportValidator;
use App\Imports\EmployeeSheet;
use App\Imports\EmployeesImport;
use App\Imports\ImportFileException;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\EmployeeImportStore;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Excel as ExcelType;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

/**
 * Excel import of employees (employees.manage): template -> upload -> preview of every row -> run.
 *
 * The upload is checked as a file (EmployeesImport) and stored under a random token for its uploader
 * (EmployeeImportStore). The preview page re-reads it and checks every row against the current data
 * (EmployeeImportValidator); so does the run, which saves all rows or none (ImportEmployees).
 */
class EmployeeImportController extends Controller
{
    public const REFUSED = 'This import is no longer available. It may have expired, been used already or belong to someone else. Upload the file again.';

    public const CHANGED = 'Some rows are no longer valid because the data changed since the preview. Nothing was imported. Fix the rows marked below and upload the file again.';

    public const FAILED = 'The import failed and nothing was saved. Please try again.';

    public const BUSY = 'An import is still finishing or was interrupted. Try again in a few minutes.';

    /**
     * Seconds PHP may spend on one run: comfortably under the host's 360 s request cap, so PHP stops the run (and the
     * database rolls it back) before the host kills the request.
     */
    public const RUN_SECONDS = 300;

    /**
     * The run lock lives slightly longer than a run may. If the request is killed, its finally block never releases the
     * lock; it then expires on its own shortly after the run could have ended, and the upload (kept: a failed run never
     * consumes it) can be imported again.
     */
    public const LOCK_SECONDS = self::RUN_SECONDS + 30;

    public function show(Request $request): Response
    {
        $token = $request->query('token');
        $preview = null;
        $expired = false;

        if ($token !== null) {
            $path = EmployeeImportStore::path($token, $request->user());

            try {
                $preview = $path === null ? null : $this->preview((string) $token, $path, $request->user());
            } catch (ImportFileException) {
                $preview = null;
            }

            $expired = $preview === null;
        }

        return Inertia::render('employees/import', [
            'preview' => $preview,
            'expired' => $expired,
            'limits' => ['rows' => EmployeeSheet::MAX_ROWS, 'megabytes' => EmployeeSheet::MAX_KILOBYTES / 1024],
        ]);
    }

    public function template(): BinaryFileResponse
    {
        return Excel::download(new EmployeeTemplateExport, EmployeeTemplateExport::FILE_NAME, ExcelType::XLSX);
    }

    public function upload(Request $request): RedirectResponse
    {
        $request->validate(
            ['file' => ['required', 'file', 'max:'.EmployeeSheet::MAX_KILOBYTES]],
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
            (new EmployeesImport)->read((string) $file->getRealPath());
        } catch (ImportFileException $e) {
            return back()->withErrors(['file' => $e->getMessage()]);
        }

        $token = EmployeeImportStore::put($file, $request->user());

        return redirect()->route('employees.import', ['token' => $token]);
    }

    public function run(Request $request, ImportEmployees $import): RedirectResponse
    {
        $user = $request->user();
        $token = $request->input('token');

        if (! is_string($token) || strlen($token) > 100) {
            return redirect()->route('employees.import')->with('error', self::REFUSED);
        }

        // A double click must not import twice: the second request does not wait, it is refused (and once the first
        // has finished, the token no longer exists). A run killed mid-way leaves the lock until it expires.
        $lock = Cache::lock('employee-import:'.sha1($token), self::LOCK_SECONDS);

        if (! $lock->get()) {
            return redirect()->route('employees.import', ['token' => $token])->with('error', self::BUSY);
        }

        try {
            $this->extendTimeLimit();

            $path = EmployeeImportStore::path($token, $user);

            if ($path === null) {
                return redirect()->route('employees.import')->with('error', self::REFUSED);
            }

            try {
                $rows = (new EmployeeImportValidator)->check((new EmployeesImport)->read($path)['rows'], $user);
            } catch (ImportFileException) {
                EmployeeImportStore::forget($token);

                return redirect()->route('employees.import')->with('error', self::REFUSED);
            }

            if (array_filter($rows, fn (array $row) => $row['errors'] !== []) !== []) {
                return redirect()->route('employees.import', ['token' => $token])->with('error', self::CHANGED);
            }

            try {
                $created = $import->handle($rows, $user);
            } catch (AccountRuleViolation $e) {
                return redirect()->route('employees.import', ['token' => $token])->with('error', $e->getMessage().' Nothing was imported.');
            } catch (Throwable $e) {
                report($e);
                Audit::tryRecord('employees', 'Employee import failed', null, ['rows' => count($rows)], $user);

                return redirect()->route('employees.import', ['token' => $token])->with('error', self::FAILED);
            }

            EmployeeImportStore::forget($token);
        } finally {
            $lock->release();
        }

        $noun = $created === 1 ? 'employee' : 'employees';

        return redirect()->route('employees.index')->with(
            'success',
            "Imported {$created} {$noun}. Login links were not sent — select employees on the list and choose 'Send set-password links'.",
        );
    }

    /**
     * What the preview page shows: per row its number, the key fields and its errors; never any other cell.
     *
     * @return array<string, mixed>
     *
     * @throws ImportFileException
     */
    private function preview(string $token, string $path, User $user): array
    {
        $file = (new EmployeesImport)->read($path);
        $rows = (new EmployeeImportValidator)->check($file['rows'], $user);
        $errors = count(array_filter($rows, fn (array $row) => $row['errors'] !== []));

        return [
            'token' => $token,
            'notices' => $file['notices'],
            'totals' => ['rows' => count($rows), 'ready' => count($rows) - $errors, 'errors' => $errors],
            'rows' => array_map(fn (array $row) => [
                'line' => $row['line'],
                ...$row['display'],
                'errors' => $row['errors'],
            ], $rows),
        ];
    }

    /** Gives a long import room to finish (overridable in tests; see extendRunTimeLimit). */
    protected function extendTimeLimit(): void
    {
        self::extendRunTimeLimit();
    }

    /**
     * Shared by every import run (employees, leave opening balances): raises PHP's time limit to RUN_SECONDS. Never
     * fatal: a host may disable set_time_limit(), and the run lock must still be released.
     */
    public static function extendRunTimeLimit(): void
    {
        // Not in the console (tests, tinker): a limit there would also bound everything that runs after this.
        if (app()->runningInConsole() || ! function_exists('set_time_limit')) {
            return;
        }

        try {
            @set_time_limit(self::RUN_SECONDS);
        } catch (Throwable) {
            // Ignored: the import runs under the host's own limit.
        }
    }
}
