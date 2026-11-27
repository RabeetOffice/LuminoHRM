<?php

/*
|--------------------------------------------------------------------------
| What changed in Lumino HRM, newest first
|--------------------------------------------------------------------------
| System > Updates (Admins only) shows this list, and the newest version here
| is the version of the code on the server. After new code arrives the Admins
| see "an update is ready" until someone clicks Update system.
|
| Every change a person can see adds a line here, in plain words:
|   new       something that was not there before
|   improved  something that works or looks better
|   fixed     something that was wrong
|   removed   something that is gone
| Raise the last number for fixes (1.1.1), the middle one for features (1.2.0).
*/

return [
    [
        'version' => '1.1.0',
        'date' => '2026-11-27',
        'title' => 'Updates screen and first sign-in password change',
        'changes' => [
            ['new', 'System › Updates (Admins): what changed in each version, and an "Update system" button. New code still arrives by itself; changes to the database now wait for an Admin to apply them.'],
            ['new', 'Update system backs up the database first and changes nothing if the backup cannot be made. Each update is kept in the update history with its step-by-step details.'],
            ['new', 'A "New" badge on Updates in the menu when an update is waiting.'],
            ['new', 'The first Admin, made with a temporary password at installation, is asked to choose their own password at the first sign-in.'],
        ],
    ],
    [
        'version' => '1.0.6',
        'date' => '2026-11-26',
        'title' => 'Exit: resignations, clearance and final settlement',
        'changes' => [
            ['new', 'Resignations & Clearance: record a resignation or termination, approve it, and follow the clearance checklist (assets returned, access removed, dues cleared).'],
            ['new', 'My Resignation: everyone can resign from their own account and withdraw before the final approval.'],
            ['new', 'Final settlement: last salary, leave encashment and loan recovery worked out, approved and marked paid, with a settlement statement PDF.'],
            ['new', 'Experience letters as PDF, and an exit tab on the employee profile.'],
            ['improved', 'New Lumino Digital logos on the sign-in page, the dashboard banner, the favicons and the PDFs.'],
        ],
    ],
    [
        'version' => '1.0.5',
        'date' => '2026-11-26',
        'title' => 'Assets and performance reviews',
        'changes' => [
            ['new', 'Assets: the asset register with categories, assigning and returning assets, the assignment history, and import and export.'],
            ['new', 'My Assets, and an assets tab on the employee profile and the dashboard.'],
            ['new', 'Performance: KPIs per designation or person, review cycles, self and manager reviews with scoring.'],
            ['new', 'My Reviews, a reviews tab on the profile and a reviews card on the dashboard.'],
        ],
    ],
    [
        'version' => '1.0.4',
        'date' => '2026-11-26',
        'title' => 'Payroll',
        'changes' => [
            ['new', 'Salary profiles with allowances, deductions and tax, and a salary tab on the employee profile.'],
            ['new', 'Payroll runs: generate a month, check it, edit lines, approve and mark it paid. An approved month locks its attendance and leave.'],
            ['new', 'Payslip PDFs by email, My Payslips, the bank transfer sheet, the payroll summary and the tax report.'],
            ['new', 'Loans and advances with monthly instalments, and opening figures for the first month.'],
            ['improved', 'A lighter, glass look for the whole app, with smooth page changes.'],
        ],
    ],
    [
        'version' => '1.0.3',
        'date' => '2026-11-25',
        'title' => 'Leave',
        'changes' => [
            ['new', 'Leave types, leave rules and the holiday calendar.'],
            ['new', 'Apply for leave (whole or half days), My Leaves, and approval by the manager and HR, with emails.'],
            ['new', 'Leave balances with yearly allocation, carry-over, adjustments and an opening balance import.'],
            ['new', 'The company calendar with holidays, approved leave and birthdays.'],
            ['improved', 'Approved leave shows in attendance, and leave is refunded when a holiday or weekly off falls on it.'],
        ],
    ],
    [
        'version' => '1.0.2',
        'date' => '2026-11-25',
        'title' => 'Attendance',
        'changes' => [
            ['new', 'Punches from the ZKTeco machine are received and turned into attendance days, with shifts, late marks, half days and overtime.'],
            ['new', 'Daily and monthly attendance, with manual corrections and an export.'],
            ['new', 'Correction requests, approved by the manager and HR.'],
            ['new', 'Raw punches, device settings, My Attendance, attendance numbers on the dashboard and an attendance tab on the profile.'],
        ],
    ],
    [
        'version' => '1.0.1',
        'date' => '2026-11-24',
        'title' => 'Employees',
        'changes' => [
            ['new', 'The employees list with filters, tabs and a grid view.'],
            ['new', 'Add and edit employees, with a sign-in account for each person.'],
            ['new', 'The employee profile with photos and documents.'],
            ['new', 'Excel import and export of employees, the organization chart and My Profile.'],
        ],
    ],
    [
        'version' => '1.0.0',
        'date' => '2026-11-24',
        'title' => 'Foundation',
        'changes' => [
            ['new', 'Sign-in with roles (Admin, HR, Manager, Employee) and accounts that can be disabled.'],
            ['new', 'The dashboard and the sidebar menu.'],
            ['new', 'Departments and designations, the settings screen, and Users & Roles.'],
            ['new', 'The audit log: who changed what, and when.'],
            ['new', 'A database backup every night.'],
        ],
    ],
];
