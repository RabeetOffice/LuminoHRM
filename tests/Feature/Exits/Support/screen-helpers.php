<?php

// Shared helpers for the Exit screen tests (Phase 6c Task 3): required by each file, so they are guarded.

use App\Models\Employee;
use App\Models\User;
use App\Support\Settings;
use Carbon\CarbonImmutable;

if (! function_exists('xsFreeze')) {
    /** "Now" is 2026-11-25 14:00 in Karachi and attendance starts on 2026-01-01. */
    function xsFreeze(): void
    {
        test()->travelTo(CarbonImmutable::parse('2026-11-25 14:00:00', 'Asia/Karachi'));
        Settings::set('attendance', ['start_date' => '2026-01-01']);
    }
}

if (! function_exists('xsPeople')) {
    /**
     * An Admin, an HR person, a manager and the manager's report, a second employee of nobody's, each with a login and an
     * employee record joined on 2026-01-01.
     *
     * @return array{admin: User, adminEmp: Employee, hr: User, hrEmp: Employee, manager: User, managerEmp: Employee, employee: User, employeeEmp: Employee, other: User, otherEmp: Employee}
     */
    function xsPeople(): array
    {
        $make = function (string $role, string $first, array $attrs = []): array {
            $user = userWithRole($role);

            return [$user, employeeFor($user, ['first_name' => $first, 'last_name' => 'Tester', 'joining_date' => '2026-01-01', ...$attrs])];
        };

        [$admin, $adminEmp] = $make('admin', 'Adnan');
        [$hr, $hrEmp] = $make('hr', 'Hina');
        [$manager, $managerEmp] = $make('manager', 'Omar');
        [$employee, $employeeEmp] = $make('employee', 'Sara', ['manager_id' => $managerEmp->id]);
        [$other, $otherEmp] = $make('employee', 'Zain');

        return compact('admin', 'adminEmp', 'hr', 'hrEmp', 'manager', 'managerEmp', 'employee', 'employeeEmp', 'other', 'otherEmp');
    }
}
