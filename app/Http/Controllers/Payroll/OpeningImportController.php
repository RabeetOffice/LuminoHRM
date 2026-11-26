<?php

namespace App\Http\Controllers\Payroll;

use App\Actions\Payroll\SaveLoan;
use App\Actions\Payroll\SaveOpeningBalance;
use App\Exports\OpeningLoansTemplateExport;
use App\Exports\OpeningTaxTemplateExport;
use App\Http\Controllers\Controller;
use App\Http\Controllers\EmployeeImportController;
use App\Imports\EmployeesImport;
use App\Imports\ImportFileException;
use App\Imports\OpeningLoansImport;
use App\Imports\OpeningTaxImport;
use App\Imports\PayrollOpeningWorkbook;
use App\Models\Employee;
use App\Models\Loan;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\EmployeeImportStore;
use App\Support\Payroll\Money;
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
 * Payroll > Opening Figures > Import (can:payroll.run on every route), {kind} = tax | loans: template -> upload ->
 * preview of every row -> run. The go-live figures: opening tax (OpeningTaxImport) and loans already running
 * (OpeningLoansImport).
 *
 * The same pattern as the leave opening-balance import (Leave\BalanceImportController): the upload is checked as a
 * file and stored under a random 60-minute token for its uploader in its own slot (EmployeeImportStore kind
 * "payroll-opening-tax" / "payroll-opening-loans", so a pending employee or leave preview, or the other payroll one, is
 * kept); the preview re-reads and checks every row, and so does the run, which saves all rows or none in ONE
 * transaction through the same actions as the forms (SaveOpeningBalance: "Opening tax saved" per changed row;
 * SaveLoan: "Loan created" / "Loan updated" per changed row) plus one entry per run ("Opening tax imported" /
 * "Opening loans imported", counts only). Setting, not adding: importing the same file again changes nothing. The run
 * raises the time limit (EmployeeImportController::extendRunTimeLimit), is guarded against a double click by a cache
 * lock, and any unexpected failure shows "The import failed" rather than an error page.
 */
class OpeningImportController extends Controller
{
    public const KIND_TAX = 'payroll-opening-tax';

    public const KIND_LOANS = 'payroll-opening-loans';

    public function show(Request $request, string $kind): Response
    {
        $token = $request->query('token');
        $preview = null;
        $expired = false;

        if ($token !== null) {
            $path = EmployeeImportStore::path($token, $request->user(), self::slot($kind));

            try {
                $preview = $path === null ? null : $this->preview($kind, (string) $token, $path, $request->user());
            } catch (ImportFileException) {
                $preview = null;
            }

            $expired = $preview === null;
        }

        return Inertia::render('payroll/opening/import', [
            'kind' => $kind,
            'preview' => $preview,
            'expired' => $expired,
            'limits' => ['rows' => PayrollOpeningWorkbook::MAX_ROWS, 'megabytes' => PayrollOpeningWorkbook::MAX_KILOBYTES / 1024],
        ]);
    }

    public function template(string $kind): BinaryFileResponse
    {
        return $kind === 'tax'
            ? Excel::download(new OpeningTaxTemplateExport, OpeningTaxTemplateExport::FILE_NAME, ExcelType::XLSX)
            : Excel::download(new OpeningLoansTemplateExport, OpeningLoansTemplateExport::FILE_NAME, ExcelType::XLSX);
    }

    public function upload(Request $request, string $kind): RedirectResponse
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
            self::import($kind)->read((string) $file->getRealPath());
        } catch (ImportFileException $e) {
            return back()->withErrors(['file' => $e->getMessage()]);
        }

        $token = EmployeeImportStore::put($file, $request->user(), self::slot($kind));

        return redirect()->route('payroll.opening.import', ['kind' => $kind, 'token' => $token]);
    }

    public function run(Request $request, string $kind): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $token = $request->input('token');
        $page = fn (array $query = []) => redirect()->route('payroll.opening.import', ['kind' => $kind, ...$query]);

        if (! is_string($token) || strlen($token) > 100) {
            return $page()->with('error', EmployeeImportController::REFUSED);
        }

        // One run at a time per import kind, whatever the upload token: two uploads of the same file must not both
        // decide "new" for the same loan.
        $lock = Cache::lock('payroll-opening-import:'.$kind, EmployeeImportController::LOCK_SECONDS);

        if (! $lock->get()) {
            return $page(['token' => $token])->with('error', EmployeeImportController::BUSY);
        }

        try {
            try {
                $this->extendTimeLimit();

                $path = EmployeeImportStore::path($token, $user, self::slot($kind));

                if ($path === null) {
                    return $page()->with('error', EmployeeImportController::REFUSED);
                }

                try {
                    if ($kind === 'tax') {
                        $import = new OpeningTaxImport;
                        $rows = $import->check($import->read($path)['rows'], $user);
                        $counts = self::hasErrors($rows) ? null : $this->saveTax($rows, $user);
                    } else {
                        $import = new OpeningLoansImport;
                        $rows = $import->check($import->read($path)['rows'], $user);
                        $counts = self::hasErrors($rows) ? null : $this->saveLoans($rows, $user);
                    }
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

        $what = $kind === 'tax' ? 'opening tax figures' : 'loans';

        return redirect()->route($kind === 'tax' ? 'payroll.opening.index' : 'payroll.loans.index')->with(
            'success',
            "Imported {$counts['rows']} rows: {$counts['changed']} {$what} saved, {$counts['unchanged']} already up to date.",
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
     * The rows by employee, then by file line: every run takes the employee row locks in the same order, so two writers
     * (this import and a payroll approval) cannot wait on each other in a circle.
     *
     * @template T of array{line: int, data: array<string, mixed>|null}
     *
     * @param  list<T>  $rows
     * @return list<T>
     */
    private static function inEmployeeOrder(array $rows): array
    {
        usort($rows, fn (array $a, array $b) => [$a['data']['employee_id'], $a['line']] <=> [$b['data']['employee_id'], $b['line']]);

        return $rows;
    }

    private static function slot(string $kind): string
    {
        return $kind === 'tax' ? self::KIND_TAX : self::KIND_LOANS;
    }

    private static function import(string $kind): OpeningTaxImport|OpeningLoansImport
    {
        return $kind === 'tax' ? new OpeningTaxImport : new OpeningLoansImport;
    }

    /**
     * @param  list<array{line: int, data: array{employee_id: int, tax_year: int, taxable_income: int, tax_deducted: int}|null}>  $rows
     * @return array{rows: int, changed: int, unchanged: int}
     */
    private function saveTax(array $rows, User $user): array
    {
        $rows = self::inEmployeeOrder($rows);
        $employees = Employee::query()->with('user.roles')->whereKey(array_map(fn (array $r) => $r['data']['employee_id'], $rows))->get()->keyBy('id');
        $save = new SaveOpeningBalance;

        return DB::transaction(function () use ($rows, $user, $employees, $save): array {
            $changed = 0;
            $years = [];

            foreach ($rows as $row) {
                $data = $row['data'];
                $save->save($user, $employees[$data['employee_id']], $data['tax_year'], $data['tax_deducted'], $data['taxable_income'], null, true);
                $changed += $save->changed() ? 1 : 0;
                $years[$data['tax_year']] = true;
            }

            $counts = ['rows' => count($rows), 'changed' => $changed, 'unchanged' => count($rows) - $changed];
            $years = array_keys($years);
            sort($years);

            Audit::record('payroll', 'Opening tax imported', null, [...$counts, 'tax_years' => $years], $user);

            return $counts;
        }, 3);
    }

    /**
     * @param  list<array{line: int, data: array{employee_id: int, loan_id: int|null, type: string, amount: int, opening_repaid: int, installment: int, start_month: string, note?: string}|null}>  $rows
     * @return array{rows: int, changed: int, unchanged: int}
     */
    private function saveLoans(array $rows, User $user): array
    {
        $rows = self::inEmployeeOrder($rows);
        $employees = Employee::query()->with('user.roles')->whereKey(array_map(fn (array $r) => $r['data']['employee_id'], $rows))->get()->keyBy('id');

        return DB::transaction(function () use ($rows, $user, $employees): array {
            $created = 0;
            $updated = 0;
            $total = 0;

            foreach ($rows as $row) {
                $data = $row['data'];
                $save = new SaveLoan;
                // New or update is decided again inside the save, under the employee row lock.
                $loan = $save->import($user, $employees[$data['employee_id']], $data);

                if ($loan->wasRecentlyCreated) {
                    $created++;
                    $total += $data['amount'] - $data['opening_repaid'];
                } else {
                    $updated += $save->changed() ? 1 : 0;
                }
            }

            $counts = ['rows' => count($rows), 'changed' => $created + $updated, 'unchanged' => count($rows) - $created - $updated];

            Audit::record('payroll', 'Opening loans imported', null, [
                ...$counts,
                'created' => $created,
                'updated' => $updated,
                'remaining_created' => $total,
                'remaining_created_formatted' => Money::format($total),
            ], $user);

            return $counts;
        }, 3);
    }

    /**
     * What the preview page shows: per row its number, the key fields, its errors; never anything else.
     *
     * @return array<string, mixed>
     *
     * @throws ImportFileException
     */
    private function preview(string $kind, string $token, string $path, User $user): array
    {
        $this->extendTimeLimit();

        $import = self::import($kind);
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
                'warnings' => $row['warnings'] ?? [],
                'unchanged' => $row['unchanged'],
            ], $rows),
        ];
    }
}
