<?php

namespace App\Http\Controllers\Payroll;

use App\Actions\Payroll\SaveOpeningBalance;
use App\Http\Controllers\Controller;
use App\Http\Requests\OpeningBalanceRequest;
use App\Imports\OpeningTaxImport;
use App\Models\Employee;
use App\Models\OpeningBalance;
use App\Models\User;
use App\Support\Payroll\PayrollAccess;
use App\Support\Payroll\TaxYear;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Payroll > Opening Figures (can:payroll.view to read, can:payroll.run to save): per tax year, each person's tax
 * deducted and taxable income to date before go-live (paisa in props), edited one person at a time in a dialog
 * (SaveOpeningBalance; PayrollAccess::mayEditSalary, else 403), plus links to the opening-tax and opening-loans
 * imports and to Leave > Balances > Import for the leave used before go-live.
 *
 * The tax years offered are the current one and the previous one (OpeningTaxImport::acceptedYears); any other year in
 * the URL falls back to the current one (page) or is a 404 (save). People listed: everyone not resigned or terminated,
 * plus anyone with figures saved for the year. A fixed number of queries.
 */
class OpeningFiguresController extends Controller
{
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $years = OpeningTaxImport::acceptedYears();
        $start = SaveOpeningBalance::startMonth();
        $year = (int) $request->query('tax_year');
        $year = in_array($year, $years, true) ? $year : $years[0];

        $saved = OpeningBalance::query()->where('tax_year', $year)->get()->keyBy('employee_id');

        $employees = Employee::query()
            ->with('user.roles')
            ->where(fn ($q) => $q->whereNotIn('status', ['resigned', 'terminated'])->orWhereIn('id', $saved->keys()->all()))
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->orderBy('id')
            ->get(['id', 'employee_code', 'first_name', 'last_name', 'status']);

        $mayRun = $user->can('payroll.run');

        $rows = $employees->map(function (Employee $e) use ($saved, $user, $mayRun) {
            /** @var OpeningBalance|null $row */
            $row = $saved->get($e->id);

            return [
                'employee' => ['id' => $e->id, 'name' => $e->full_name, 'code' => $e->employee_code],
                'taxable_income' => $row === null ? 0 : $row->taxable_income,
                'tax_deducted' => $row === null ? 0 : $row->tax_deducted,
                'note' => $row?->note,
                'saved' => $row !== null,
                'can_edit' => $mayRun && PayrollAccess::mayEditSalary($user, $e),
            ];
        })->values();

        return Inertia::render('payroll/opening/index', [
            'tax_year' => $year,
            'tax_year_label' => TaxYear::label($year, $start),
            'years' => array_map(fn (int $y) => ['value' => $y, 'label' => TaxYear::label($y, $start)], $years),
            'rows' => $rows->all(),
            'totals' => [
                'people' => $saved->count(),
                'taxable_income' => (int) $saved->sum('taxable_income'),
                'tax_deducted' => (int) $saved->sum('tax_deducted'),
            ],
            'can' => [
                'import' => $mayRun,
                'leave_import' => $user->can('leave.approve-all'),
            ],
        ]);
    }

    public function update(OpeningBalanceRequest $request, Employee $employee, int $taxYear, SaveOpeningBalance $save): RedirectResponse
    {
        abort_unless(in_array($taxYear, OpeningTaxImport::acceptedYears(), true), 404);

        $employee->loadMissing('user.roles');
        $save->save($request->user(), $employee, $taxYear, $request->taxDeducted(), $request->taxableIncome(), $request->input('note'));

        return back()->with('success', $save->changed()
            ? "Opening figures for {$employee->full_name} saved."
            : 'Nothing changed.');
    }
}
