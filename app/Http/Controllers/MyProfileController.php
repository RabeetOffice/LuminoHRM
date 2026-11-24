<?php

namespace App\Http\Controllers;

use App\Http\Resources\EmployeeProfileResource;
use App\Models\Employee;
use App\Support\EmployeeAccess;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * My Profile: the signed-in person's OWN employee record, read-only, for every role. The record is looked up from
 * the account's own employee_id (never from the request), and built by the same EmployeeProfileResource as the
 * profile page, so field visibility is the one rule in EmployeeAccess (own record: all six groups; machine ID only
 * for view-all, role and login only for employees.manage).
 *
 * Props: employee (the resource, or null when the account is not linked), tabs, showLinkHint (true for people who
 * manage employees, who can fix an unlinked account). Nothing to edit: no `can`, no status options, no document types.
 */
class MyProfileController extends Controller
{
    public function show(Request $request): Response
    {
        $user = $request->user();
        $access = new EmployeeAccess($user);
        $employee = $user->employee_id === null ? null : Employee::query()->find($user->employee_id);

        if ($employee === null) {
            return Inertia::render('my/profile', [
                'employee' => null,
                'tabs' => [],
                'showLinkHint' => $access->canManage(),
            ]);
        }

        $profile = new EmployeeProfileResource($employee, $access, $user);

        return Inertia::render('my/profile', [
            'employee' => $profile->resolve($request),
            'tabs' => $profile->tabs(),
            'showLinkHint' => false,
        ]);
    }
}
