<?php

namespace App\Http\Controllers;

use App\Actions\CreateUserAccount;
use App\Models\Employee;
use App\Support\Audit\Audit;
use App\Support\EmployeeRoles;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * POST /employees/send-links (employees.manage): "Send set-password links" for up to 50 selected employees, typically
 * right after an import (which sends none). A link is queued only for a login that is enabled and has never been used,
 * and that the sender may touch (only an Admin sends one to an Admin or HR login, EmployeeRoles). Everyone else is
 * skipped and counted. One audit entry per click (counts only); each link also gets its own `users` entry.
 */
class EmployeeLinkController extends Controller
{
    public const MAX = 50;

    public function store(Request $request, CreateUserAccount $accounts): RedirectResponse
    {
        $validated = $request->validate(
            ['ids' => ['required', 'array', 'min:1', 'max:'.self::MAX], 'ids.*' => ['integer', 'distinct']],
            ['ids.required' => 'Choose at least one employee.', 'ids.min' => 'Choose at least one employee.', 'ids.max' => 'Choose at most 50 employees at a time.'],
        );

        $by = $request->user();
        $ids = array_map('intval', $validated['ids']);
        $sent = 0;

        $employees = Employee::query()->whereKey($ids)->with('user')->get();

        foreach ($employees as $employee) {
            $account = $employee->user;

            if ($account === null || ! $account->is_active || $account->last_login_at !== null || ! EmployeeRoles::mayTouchAccount($by, $account)) {
                continue;
            }

            $sent += $accounts->sendLink($account) ? 1 : 0;
        }

        $skipped = count($ids) - $sent;

        Audit::record('employees', 'Set-password links sent', null, ['requested' => count($ids), 'queued' => $sent, 'skipped' => $skipped], $by);

        if ($sent === 0) {
            return back()->with('error', 'No links were sent. Links go only to logins that are enabled and have never been used.');
        }

        $message = "Queued set-password links for {$sent} ".($sent === 1 ? 'employee' : 'employees').'.';

        if ($skipped > 0) {
            $message .= " Skipped {$skipped}: they have signed in before, their login is disabled or missing, or only an Admin may send them a link.";
        }

        return back()->with('success', $message);
    }
}
