<?php

use App\Http\Controllers\Attendance\DailyController;
use App\Http\Controllers\Attendance\MonthlyController;
use App\Http\Controllers\Attendance\ShiftController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\BrandingMediaController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DepartmentController;
use App\Http\Controllers\DesignationController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\EmployeeExportController;
use App\Http\Controllers\EmployeeFileController;
use App\Http\Controllers\EmployeeImportController;
use App\Http\Controllers\EmployeeLinkController;
use App\Http\Controllers\HolidayController;
use App\Http\Controllers\Leave\LeaveTypeController;
use App\Http\Controllers\MyProfileController;
use App\Http\Controllers\OrganizationChartController;
use App\Http\Controllers\Settings\HrmSettingsController;
use App\Http\Controllers\UserController;
use App\Support\Branding;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard')->name('home');

// Company logos (the login page shows them, so no auth). Served from the private disk, only
// names the app generated. No session, cookies or CSRF, so the response stays cacheable.
Route::get('media/branding/{filename}', BrandingMediaController::class)
    ->where('filename', Branding::FILENAME_PATTERN)
    ->withoutMiddleware('web')
    ->name('media.branding');

Route::middleware(['auth', 'verified'])->group(function () {
    // The dashboard's figures are worked out for the viewer (DashboardController); a figure they may not have is absent.
    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // My Profile: the signed-in person's own employee record, read-only (an empty state when the account is not linked).
    Route::get('my/profile', [MyProfileController::class, 'show'])->name('my.profile');

    // My Attendance: the signed-in person's own month (no person in the URL; an empty state when not linked).
    Route::get('my/attendance', [\App\Http\Controllers\Attendance\MyAttendanceController::class, 'show'])->name('my.attendance');

    // My Leaves: the signed-in person's own balances and requests (no person in the URL; an empty state when not linked).
    Route::get('my/leaves', [\App\Http\Controllers\Leave\MyLeaveController::class, 'show'])->name('my.leaves');

    // My Payslips: the signed-in person's own payslips of approved / paid runs (any other item id = 403).
    Route::get('my/payslips', [\App\Http\Controllers\MyPayslipController::class, 'index'])->name('my.payslips');
    Route::get('my/payslips/{item}', [\App\Http\Controllers\MyPayslipController::class, 'download'])->whereNumber('item')->name('my.payslips.download');

    // Payroll > Payslips: every payslip of approved / paid runs (Admin / HR; a draft's or excluded line = 404).
    Route::prefix('payroll/payslips')->name('payroll.payslips.')->controller(\App\Http\Controllers\Payroll\PayslipController::class)->group(function () {
        Route::middleware('can:payroll.view')->group(function () {
            Route::get('/', 'index')->name('index');
            Route::get('{item}', 'download')->whereNumber('item')->name('download');
        });
        Route::post('{item}/resend', 'resend')->whereNumber('item')->middleware('can:payroll.run')->name('resend');
    });

    // Applying for leave (own record, or on behalf by HR / Admin), its live preview, the attachment and cancelling while
    // open: any signed-in user; LeaveApplicationController / ApplyForLeave check the rights (out of reach = 403).
    Route::prefix('leave/requests')->name('leave.requests.')->controller(\App\Http\Controllers\Leave\LeaveApplicationController::class)->group(function () {
        Route::post('/', 'store')->middleware('throttle:30,1,leave-apply')->name('store');
        Route::get('preview', 'preview')->middleware('throttle:60,1,leave-preview')->name('preview');
        Route::get('{leaveRequest}/attachment', 'attachment')->whereNumber('leaveRequest')->name('attachment');
        Route::post('{leaveRequest}/cancel', 'cancel')->whereNumber('leaveRequest')->name('cancel');
    });

    // Overview > Calendar: holidays, approved leave (scoped by LeaveRouting) and birthdays, for every signed-in user.
    Route::get('calendar', [\App\Http\Controllers\Leave\CalendarController::class, 'index'])->name('calendar');

    // Departments & Designations. Authorisation is enforced here, on the server.
    Route::middleware('can:org.manage')->prefix('organization')->group(function () {
        Route::get('/', [DepartmentController::class, 'index'])->name('organization.index');
        Route::resource('departments', DepartmentController::class)->only(['store', 'update', 'destroy']);
        Route::resource('designations', DesignationController::class)->only(['store', 'update', 'destroy']);
    });

    // Employees. Who may open the list is checked in the controller (view-all OR view-team) and every row goes
    // through EmployeeAccess. Static routes (create, export, import ...) are added ABOVE the wildcard route,
    // which must stay last and numeric so it never swallows them: employees/{employee} ->whereNumber('employee').
    Route::get('employees', [EmployeeController::class, 'index'])->name('employees.index');

    // Employee import and export (static URLs, registered before the numeric {employee} routes below).
    Route::prefix('employees')->name('employees.')->group(function () {
        Route::middleware('can:employees.manage')->group(function () {
            Route::get('import', [EmployeeImportController::class, 'show'])->name('import');
            Route::get('import/template', [EmployeeImportController::class, 'template'])->name('import.template');
            Route::post('import/preview', [EmployeeImportController::class, 'upload'])->name('import.preview');
            Route::post('import/run', [EmployeeImportController::class, 'run'])->name('import.run');
            Route::post('send-links', [EmployeeLinkController::class, 'store'])->name('send-links');
        });
        // Every column (CNIC, bank ...) leaves in the file, so only the see-everyone permission may export.
        Route::get('export', EmployeeExportController::class)->middleware('can:employees.view-all')->name('export');
    });

    // Writing employees. Static routes first, then the numeric wildcard (so "create" is never read as an id).
    Route::middleware('can:employees.manage')->prefix('employees')->name('employees.')->group(function () {
        Route::get('create', [EmployeeController::class, 'create'])->name('create');
        Route::get('manager-options', [EmployeeController::class, 'managerOptions'])->name('manager-options');
        Route::post('/', [EmployeeController::class, 'store'])->name('store');
        Route::get('{employee}/edit', [EmployeeController::class, 'edit'])->whereNumber('employee')->name('edit');
        Route::put('{employee}', [EmployeeController::class, 'update'])->whereNumber('employee')->name('update');
        Route::patch('{employee}/status', [EmployeeController::class, 'status'])->whereNumber('employee')->name('status');
    });
    // Delete is Admin only (and the controller refuses a record with history).
    Route::delete('employees/{employee}', [EmployeeController::class, 'destroy'])
        ->whereNumber('employee')->middleware('can:employees.delete')->name('employees.destroy');

    // Employee photos and documents. Private disk only; EmployeeFileController asks EmployeeAccess before any file
    // leaves it (photo: canView; document: the `documents` field group; writes: the edit-record rule). Uploads share
    // one counter of 30 a minute per user.
    Route::prefix('employees/{employee}')->name('employees.')->group(function () {
        Route::get('photo', [EmployeeFileController::class, 'showPhoto'])->whereNumber('employee')->name('photo.show');
        Route::get('documents/{document}', [EmployeeFileController::class, 'downloadDocument'])
            ->whereNumber(['employee', 'document'])->name('documents.download');

        Route::middleware('can:employees.manage')->group(function () {
            Route::post('photo', [EmployeeFileController::class, 'updatePhoto'])
                ->whereNumber('employee')->middleware('throttle:30,1,employee-uploads')->name('photo.update');
            Route::delete('photo', [EmployeeFileController::class, 'destroyPhoto'])->whereNumber('employee')->name('photo.destroy');
            Route::post('documents', [EmployeeFileController::class, 'storeDocument'])
                ->whereNumber('employee')->middleware('throttle:30,1,employee-uploads')->name('documents.store');
            Route::delete('documents/{document}', [EmployeeFileController::class, 'destroyDocument'])
                ->whereNumber(['employee', 'document'])->name('documents.destroy');
        });
    });

    // The profile page. Any signed-in user may ask; EmployeeAccess::canView decides (403), and a person who only has
    // their own record is sent to My Profile. Registered after every static /employees/... route above.
    Route::get('employees/{employee}', [EmployeeController::class, 'show'])->whereNumber('employee')->name('employees.show');

    // Organization chart. The controller checks view-all OR view-team (a plain Employee gets 403); the people come
    // only from EmployeeAccess::scope.
    Route::get('organization-chart', [OrganizationChartController::class, 'index'])->name('organization-chart.index');

    // Attendance > Daily. The list: view-all or view-team (checked in the controller), scoped by EmployeeAccess. Manual
    // correction and its removal: attendance.edit, and the controller checks the scope and the date (403).
    Route::get('attendance/daily', [DailyController::class, 'index'])->name('attendance.daily');
    Route::middleware('can:attendance.edit')->prefix('attendance/daily')->name('attendance.daily.')->group(function () {
        Route::put('{employee}/{date}', [DailyController::class, 'update'])
            ->whereNumber('employee')->where('date', '\d{4}-\d{2}-\d{2}')->name('update');
        Route::delete('{employee}/{date}', [DailyController::class, 'reset'])
            ->whereNumber('employee')->where('date', '\d{4}-\d{2}-\d{2}')->name('reset');
    });

    // Attendance > Monthly: the grid (view-all or view-team, checked in the controller, scoped by EmployeeAccess) and its
    // .xlsx (attendance.view-all only).
    Route::get('attendance/monthly', [MonthlyController::class, 'index'])->name('attendance.monthly');
    Route::get('attendance/monthly/export', [MonthlyController::class, 'export'])
        ->middleware('can:attendance.view-all')->name('attendance.monthly.export');

    // Attendance > Shifts (Admin, HR). The default shift is changed only through make-default.
    Route::middleware('can:attendance.edit')->prefix('attendance/shifts')->name('attendance.shifts.')->group(function () {
        Route::get('/', [ShiftController::class, 'index'])->name('index');
        Route::post('/', [ShiftController::class, 'store'])->name('store');
        Route::put('{shift}', [ShiftController::class, 'update'])->whereNumber('shift')->name('update');
        Route::delete('{shift}', [ShiftController::class, 'destroy'])->whereNumber('shift')->name('destroy');
        Route::post('{shift}/make-default', [ShiftController::class, 'makeDefault'])->whereNumber('shift')->name('make-default');
    });

    // Attendance > Raw Punches: every punch the machines sent (Admin, HR). Giving an unknown machine user ID to an
    // employee also needs attendance.edit (and employees.manage, checked in the controller): it is an employee update.
    Route::get('attendance/punches', [\App\Http\Controllers\Attendance\PunchController::class, 'index'])
        ->middleware('can:attendance.view-all')->name('attendance.punches');
    Route::post('attendance/punches/assign', [\App\Http\Controllers\Attendance\PunchController::class, 'assign'])
        ->middleware('can:attendance.edit')->name('attendance.punches.assign');

    // Attendance > Correction Requests. The list: view-all (everyone's) or view-team (own and direct reports'), checked
    // in the controller. Submitting: any signed-in user, for their own record. Who decides which step and who may cancel
    // is checked by CorrectionRouting (403 outside scope); HR's step also needs attendance.edit.
    Route::prefix('attendance/corrections')->name('attendance.corrections.')->group(function () {
        Route::get('/', [\App\Http\Controllers\Attendance\CorrectionController::class, 'index'])->name('index');
        Route::post('/', [\App\Http\Controllers\Attendance\CorrectionController::class, 'store'])->name('store');
        Route::put('{correction}/manager-decide', [\App\Http\Controllers\Attendance\CorrectionController::class, 'managerDecide'])
            ->whereNumber('correction')->name('manager-decide');
        Route::put('{correction}/hr-decide', [\App\Http\Controllers\Attendance\CorrectionController::class, 'hrDecide'])
            ->whereNumber('correction')->middleware('can:attendance.edit')->name('hr-decide');
        Route::post('{correction}/cancel', [\App\Http\Controllers\Attendance\CorrectionController::class, 'cancel'])
            ->whereNumber('correction')->name('cancel');
    });

    // Leave > Holidays: the company holiday calendar (Admin, HR: the attendance and leave rules permission).
    Route::middleware('can:settings.attendance-leave')->prefix('leave/holidays')->name('holidays.')->group(function () {
        Route::get('/', [HolidayController::class, 'index'])->name('index');
        Route::post('/', [HolidayController::class, 'store'])->name('store');
        Route::put('{holiday}', [HolidayController::class, 'update'])->whereNumber('holiday')->name('update');
        Route::delete('{holiday}', [HolidayController::class, 'destroy'])->whereNumber('holiday')->name('destroy');
    });

    // Leave > Leave Types (Admin, HR: the attendance and leave rules permission). The model audits each change.
    Route::middleware('can:settings.attendance-leave')->prefix('leave/types')->name('leave.types.')->group(function () {
        Route::get('/', [LeaveTypeController::class, 'index'])->name('index');
        Route::post('/', [LeaveTypeController::class, 'store'])->name('store');
        Route::put('{leaveType}', [LeaveTypeController::class, 'update'])->whereNumber('leaveType')->name('update');
        Route::patch('{leaveType}/active', [LeaveTypeController::class, 'setActive'])->whereNumber('leaveType')->name('active');
        Route::delete('{leaveType}', [LeaveTypeController::class, 'destroy'])->whereNumber('leaveType')->name('destroy');
    });

    // Leave > Requests: the list (leave.approve-all or leave.approve-team, checked in the controller), the detail page
    // (the requester or anyone LeaveRouting lets see it), the decisions and HR's cancellation of approved leave. Who
    // decides which step is LeaveRouting's (403 outside scope). Ids are numeric, so Task 5's static "preview" route
    // under the same prefix never matches here.
    Route::prefix('leave/requests')->name('leave.requests.')->group(function () {
        Route::get('/', [\App\Http\Controllers\Leave\LeaveRequestController::class, 'index'])->name('index');
        Route::get('{leaveRequest}', [\App\Http\Controllers\Leave\LeaveRequestController::class, 'show'])
            ->whereNumber('leaveRequest')->name('show');
        Route::put('{leaveRequest}/manager-decide', [\App\Http\Controllers\Leave\LeaveRequestController::class, 'managerDecide'])
            ->whereNumber('leaveRequest')->name('manager-decide');
        Route::put('{leaveRequest}/final-decide', [\App\Http\Controllers\Leave\LeaveRequestController::class, 'finalDecide'])
            ->whereNumber('leaveRequest')->middleware('can:leave.approve-all')->name('final-decide');
        Route::post('{leaveRequest}/cancel-approved', [\App\Http\Controllers\Leave\LeaveRequestController::class, 'cancelApproved'])
            ->whereNumber('leaveRequest')->middleware('can:leave.approve-all')->name('cancel-approved');
    });

    // Leave > Balances: the grid (leave.approve-all, or leave.approve-team read-only for the own team; checked in the
    // controller, scoped by EmployeeAccess), HR's edit of a balance row (LeaveRouting::mayAdminister, 403), the .xlsx,
    // and the go-live opening-balance import (HR / Admin). Static URLs before the numeric edit route.
    Route::prefix('leave/balances')->name('leave.balances.')->group(function () {
        Route::get('/', [\App\Http\Controllers\Leave\BalanceController::class, 'index'])->name('index');

        Route::middleware('can:leave.approve-all')->group(function () {
            Route::get('export', [\App\Http\Controllers\Leave\BalanceController::class, 'export'])->name('export');
            Route::get('import', [\App\Http\Controllers\Leave\BalanceImportController::class, 'show'])->name('import');
            Route::get('import/template', [\App\Http\Controllers\Leave\BalanceImportController::class, 'template'])->name('import.template');
            Route::post('import/preview', [\App\Http\Controllers\Leave\BalanceImportController::class, 'upload'])->name('import.preview');
            Route::post('import/run', [\App\Http\Controllers\Leave\BalanceImportController::class, 'run'])->name('import.run');
            Route::put('{employee}/{type}/{year}', [\App\Http\Controllers\Leave\BalanceController::class, 'update'])
                ->whereNumber(['employee', 'type'])->where('year', '\d{4}')->name('update');
        });
    });

    // Payroll > Salary Profiles: salary figures are Admin / HR only (payroll.view to read, payroll.run to write; who may
    // edit whose salary is PayrollAccess::mayEditSalary in SaveSalaryProfile, 403). A profile of someone else is 404.
    Route::prefix('payroll/salary-profiles')->name('payroll.salary-profiles.')->group(function () {
        Route::middleware('can:payroll.view')->group(function () {
            Route::get('/', [\App\Http\Controllers\Payroll\SalaryProfileController::class, 'index'])->name('index');
            Route::get('{employee}', [\App\Http\Controllers\Payroll\SalaryProfileController::class, 'show'])->whereNumber('employee')->name('show');
        });

        Route::middleware('can:payroll.run')->group(function () {
            Route::post('{employee}', [\App\Http\Controllers\Payroll\SalaryProfileController::class, 'store'])->whereNumber('employee')->name('store');
            Route::put('{employee}/{profile}', [\App\Http\Controllers\Payroll\SalaryProfileController::class, 'update'])
                ->whereNumber(['employee', 'profile'])->name('update');
            Route::delete('{employee}/{profile}', [\App\Http\Controllers\Payroll\SalaryProfileController::class, 'destroy'])
                ->whereNumber(['employee', 'profile'])->name('destroy');
        });
    });

    // Users and roles. No delete route: accounts are disabled, never removed.
    Route::middleware('can:users.manage')->prefix('users')->name('users.')->group(function () {
        Route::get('/', [UserController::class, 'index'])->name('index');
        Route::post('/', [UserController::class, 'store'])->name('store');
        Route::put('{user}', [UserController::class, 'update'])->name('update');
        Route::patch('{user}/active', [UserController::class, 'toggle'])->name('toggle');
        Route::post('{user}/send-reset', [UserController::class, 'sendReset'])->name('send-reset');
    });

    // Audit log: read-only. There is no route to change or delete an entry.
    Route::get('audit-log', [AuditLogController::class, 'index'])->middleware('can:audit.view')->name('audit-log.index');

    // Settings. Per-group authorisation lives in the group's FormRequest and the controller.
    Route::prefix('hrm-settings')->name('hrm-settings.')->group(function () {
        Route::get('/', [HrmSettingsController::class, 'index'])->name('index');
        Route::post('email/test', [HrmSettingsController::class, 'testEmail'])
            ->middleware(['can:settings.manage', 'throttle:5,1'])
            ->name('email.test');
        // Settings > ZKTeco Device (Admin). Registered before the {group} route.
        Route::middleware('can:settings.manage')->prefix('devices')->name('devices.')->controller(\App\Http\Controllers\Settings\DeviceController::class)->group(function () {
            Route::get('/', 'index')->name('index');
            Route::post('/', 'store')->name('store');
            Route::put('{device}', 'update')->whereNumber('device')->name('update');
            Route::patch('{device}/active', 'toggle')->whereNumber('device')->name('toggle');
            Route::delete('{device}', 'destroy')->whereNumber('device')->name('destroy');
            Route::post('{device}/resend', 'resend')->whereNumber('device')->name('resend');
        });
        Route::put('{group}', [HrmSettingsController::class, 'update'])->name('update');
    });
});

require __DIR__.'/settings.php';
