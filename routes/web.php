<?php

use App\Http\Controllers\Assets\AssetController;
use App\Http\Controllers\Assets\AssetExportController;
use App\Http\Controllers\Assets\AssetImportController;
use App\Http\Controllers\Assets\AssignmentController;
use App\Http\Controllers\Assets\MyAssetController;
use App\Http\Controllers\Attendance\CorrectionController;
use App\Http\Controllers\Attendance\DailyController;
use App\Http\Controllers\Attendance\MonthlyController;
use App\Http\Controllers\Attendance\MyAttendanceController;
use App\Http\Controllers\Attendance\PunchController;
use App\Http\Controllers\Attendance\ShiftController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\Auth\FirstLoginPasswordController;
use App\Http\Controllers\BrandingMediaController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DepartmentController;
use App\Http\Controllers\DesignationController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\EmployeeExportController;
use App\Http\Controllers\EmployeeFileController;
use App\Http\Controllers\EmployeeImportController;
use App\Http\Controllers\EmployeeLinkController;
use App\Http\Controllers\Exits\ClearanceController;
use App\Http\Controllers\Exits\ExitController;
use App\Http\Controllers\Exits\ExitDocumentController;
use App\Http\Controllers\Exits\MyResignationController;
use App\Http\Controllers\Exits\SettlementController;
use App\Http\Controllers\HolidayController;
use App\Http\Controllers\Leave\BalanceController;
use App\Http\Controllers\Leave\BalanceImportController;
use App\Http\Controllers\Leave\CalendarController;
use App\Http\Controllers\Leave\LeaveApplicationController;
use App\Http\Controllers\Leave\LeaveRequestController;
use App\Http\Controllers\Leave\LeaveTypeController;
use App\Http\Controllers\Leave\MyLeaveController;
use App\Http\Controllers\MyPayslipController;
use App\Http\Controllers\MyProfileController;
use App\Http\Controllers\OrganizationChartController;
use App\Http\Controllers\Payroll\LoanController;
use App\Http\Controllers\Payroll\OpeningFiguresController;
use App\Http\Controllers\Payroll\OpeningImportController;
use App\Http\Controllers\Payroll\PayrollExportController;
use App\Http\Controllers\Payroll\PayrollItemController;
use App\Http\Controllers\Payroll\PayrollRunController;
use App\Http\Controllers\Payroll\PayslipController;
use App\Http\Controllers\Payroll\SalaryProfileController;
use App\Http\Controllers\Payroll\TaxReportController;
use App\Http\Controllers\Performance\CycleController;
use App\Http\Controllers\Performance\KpiController;
use App\Http\Controllers\Performance\MyReviewController;
use App\Http\Controllers\Performance\ReviewController;
use App\Http\Controllers\Performance\ReviewListController;
use App\Http\Controllers\Settings\AssetCategoryController;
use App\Http\Controllers\Settings\DeviceController;
use App\Http\Controllers\Settings\HrmSettingsController;
use App\Http\Controllers\System\UpdatesController;
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

// First sign-in with a password somebody else chose (the first Admin's, from the env seed). EnsurePasswordChanged sends
// every other page here until the person has picked their own.
Route::middleware('auth')->prefix('first-login')->name('first-login.')->controller(FirstLoginPasswordController::class)->group(function () {
    Route::get('password', 'show')->name('password');
    Route::put('password', 'update')->middleware('throttle:6,1')->name('password.update');
});

Route::middleware(['auth', 'verified'])->group(function () {
    // The dashboard's figures are worked out for the viewer (DashboardController); a figure they may not have is absent.
    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // My Profile: the signed-in person's own employee record, read-only (an empty state when the account is not linked).
    Route::get('my/profile', [MyProfileController::class, 'show'])->name('my.profile');

    // My Attendance: the signed-in person's own month (no person in the URL; an empty state when not linked).
    Route::get('my/attendance', [MyAttendanceController::class, 'show'])->name('my.attendance');

    // My Leaves: the signed-in person's own balances and requests (no person in the URL; an empty state when not linked).
    Route::get('my/leaves', [MyLeaveController::class, 'show'])->name('my.leaves');

    // My Assets: the signed-in person's own assets (every role; the account's own employee record only).
    Route::get('my/assets', [MyAssetController::class, 'show'])->name('my.assets');

    // My Reviews: the signed-in person's own reviews (every role; the account's own employee record only, no person in the URL).
    Route::get('my/reviews', [MyReviewController::class, 'show'])->name('my.reviews');

    // My Resignation: the signed-in person's own exit (every role; no person in the URL). Resign, and withdraw before the final
    // approval. The statement and letter downloads are registered with the exit documents below.
    Route::get('my/resignation', [MyResignationController::class, 'show'])->name('my.resignation');
    Route::post('my/resignation', [MyResignationController::class, 'store'])->name('my.resignation.store');
    Route::post('my/resignation/{resignation}/withdraw', [MyResignationController::class, 'withdraw'])->whereNumber('resignation')->name('my.resignation.withdraw');

    // My Payslips: the signed-in person's own payslips of approved / paid runs (any other item id = 403).
    Route::get('my/payslips', [MyPayslipController::class, 'index'])->name('my.payslips');
    Route::get('my/payslips/{item}', [MyPayslipController::class, 'download'])->whereNumber('item')->name('my.payslips.download');

    // Payroll > Payslips: every payslip of approved / paid runs (Admin / HR; a draft's or excluded line = 404).
    Route::prefix('payroll/payslips')->name('payroll.payslips.')->controller(PayslipController::class)->group(function () {
        Route::middleware('can:payroll.view')->group(function () {
            Route::get('/', 'index')->name('index');
            Route::get('{item}', 'download')->whereNumber('item')->name('download');
        });
        Route::post('{item}/resend', 'resend')->whereNumber('item')->middleware('can:payroll.run')->name('resend');
    });

    // Applying for leave (own record, or on behalf by HR / Admin), its live preview, the attachment and cancelling while
    // open: any signed-in user; LeaveApplicationController / ApplyForLeave check the rights (out of reach = 403).
    Route::prefix('leave/requests')->name('leave.requests.')->controller(LeaveApplicationController::class)->group(function () {
        Route::post('/', 'store')->middleware('throttle:30,1,leave-apply')->name('store');
        Route::get('preview', 'preview')->middleware('throttle:60,1,leave-preview')->name('preview');
        Route::get('{leaveRequest}/attachment', 'attachment')->whereNumber('leaveRequest')->name('attachment');
        Route::post('{leaveRequest}/cancel', 'cancel')->whereNumber('leaveRequest')->name('cancel');
    });

    // Overview > Calendar: holidays, approved leave (scoped by LeaveRouting) and birthdays, for every signed-in user.
    Route::get('calendar', [CalendarController::class, 'index'])->name('calendar');

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

    // Performance > KPIs (Admin, HR). Phase 6b adds cycles and reviews beside it.
    Route::middleware('can:performance.manage')->prefix('performance/kpis')->name('performance.kpis.')->controller(KpiController::class)->group(function () {
        Route::get('/', 'index')->name('index');
        Route::post('/', 'store')->name('store');
        Route::put('{kpi}', 'update')->whereNumber('kpi')->name('update');
        Route::delete('{kpi}', 'destroy')->whereNumber('kpi')->name('destroy');
    });

    // Performance > a review's workflow (signed in only: ReviewAccess decides, 403 otherwise; each action re-checks the actor
    // under its locks). Static performance/reviews/... routes go above these.
    Route::prefix('performance/reviews/{review}')->name('performance.reviews.')->controller(ReviewController::class)->group(function () {
        Route::get('/', 'show')->whereNumber('review')->name('show');
        Route::put('self', 'selfDraft')->whereNumber('review')->name('self-draft');
        Route::post('self/submit', 'selfSubmit')->whereNumber('review')->name('self-submit');
        Route::post('waive', 'waive')->whereNumber('review')->name('waive');
        Route::put('manager', 'managerDraft')->whereNumber('review')->name('manager-draft');
        Route::post('manager/submit', 'managerSubmit')->whereNumber('review')->name('manager-submit');
        Route::post('send-back', 'sendBack')->whereNumber('review')->name('send-back');
        Route::post('close', 'close')->whereNumber('review')->name('close');
        Route::post('reopen', 'reopen')->whereNumber('review')->name('reopen');
        Route::post('skip', 'skip')->whereNumber('review')->name('skip');
        Route::post('restore', 'restore')->whereNumber('review')->name('restore');
        Route::put('reviewer', 'reviewer')->whereNumber('review')->name('reviewer');
    });

    // Performance > review cycles (Admin, HR): open, add people, change the due date, close. Task 4 adds the pages.
    Route::middleware('can:performance.manage')->prefix('performance/cycles')->name('performance.cycles.')->controller(CycleController::class)->group(function () {
        Route::post('/', 'store')->name('store');
        Route::post('{cycle}/people', 'people')->whereNumber('cycle')->name('people');
        Route::put('{cycle}/due-date', 'dueDate')->whereNumber('cycle')->name('due-date');
        Route::post('{cycle}/close', 'close')->whereNumber('cycle')->name('close');
        Route::get('{cycle}', 'show')->whereNumber('cycle')->name('show');
    });

    // Performance > Reviews: HR and Admin see the cycles, a manager the team list (checked in the controller, 403 otherwise).
    Route::get('performance/reviews', [ReviewListController::class, 'index'])->name('performance.reviews.index');

    // Exit > Resignations & Clearance. The list and the page are for exit.clearance and exit.approve-team (the controller checks and
    // scopes with ExitRouting: 403 for an id out of scope); each write re-checks the actor under its locks. Static paths first.
    Route::controller(ExitController::class)->name('exit.')->group(function () {
        Route::get('exit', 'index')->name('index');
        Route::get('exit/employee-options', 'employeeOptions')->name('employee-options');
        Route::post('exit', 'store')->name('store');
        Route::get('exit/{resignation}', 'show')->whereNumber('resignation')->name('show');
        Route::post('exit/{resignation}/decide', 'decide')->whereNumber('resignation')->name('decide');
        Route::put('exit/{resignation}/last-day', 'lastDay')->whereNumber('resignation')->name('last-day');
        Route::post('exit/{resignation}/cancel', 'cancel')->whereNumber('resignation')->name('cancel');
    });
    Route::put('exit/clearance/{clearance}', [ClearanceController::class, 'update'])->middleware('can:exit.clearance')->whereNumber('clearance')->name('exit.clearance.update');
    // Final settlement writes: exit.clearance and payroll.view (Admin, HR); the actions check SettlementAccess.
    Route::middleware(['can:exit.clearance', 'can:payroll.view'])->controller(SettlementController::class)->group(function () {
        Route::post('exit/{resignation}/settlement', 'prepare')->whereNumber('resignation')->name('exit.settlement.prepare');
        Route::prefix('exit/settlements/{settlement}')->where(['settlement' => '[0-9]+'])->name('exit.settlements.')->group(function () {
            Route::post('recalculate', 'recalculate')->name('recalculate');
            Route::post('lines', 'addLine')->name('lines.store');
            Route::delete('lines/{line}', 'removeLine')->whereNumber('line')->name('lines.destroy');
            Route::put('tax', 'tax')->name('tax');
            Route::post('approve', 'approve')->name('approve');
            Route::post('paid', 'paid')->name('paid');
        });
    });

    // Assets: the register's writes (Task 4 adds the pages). Add, edit and status: Admin and HR; delete: Admin only.
    Route::middleware('can:assets.assign')->prefix('assets')->name('assets.')->controller(AssetController::class)->group(function () {
        Route::post('/', 'store')->name('store');
        Route::put('{asset}', 'update')->whereNumber('asset')->name('update');
        Route::post('{asset}/status', 'status')->whereNumber('asset')->name('status');
    });
    Route::delete('assets/{asset}', [AssetController::class, 'destroy'])->middleware('can:assets.manage')->whereNumber('asset')->name('assets.destroy');

    // Assets: hand-overs (Admin, HR). No route edits an assignment's dates or holder, and none deletes one.
    Route::middleware('can:assets.assign')->prefix('assets')->name('assets.')->controller(AssignmentController::class)->group(function () {
        Route::post('{asset}/assign', 'assign')->whereNumber('asset')->name('assign');
        Route::post('assignments/{assignment}/return', 'return')->whereNumber('assignment')->name('assignments.return');
        Route::patch('assignments/{assignment}/note', 'note')->whereNumber('assignment')->name('assignments.note');
    });

    // Assets: the list (Admin, HR: everything; Manager: what their team holds, read-only; checked in the controller) and
    // the asset page (Admin, HR). Static `assets/...` paths come before `assets/{asset}`.
    Route::get('assets', [AssetController::class, 'index'])->name('assets.index');
    Route::get('assets/employee-options', [AssetController::class, 'employeeOptions'])->middleware('can:assets.assign')->name('assets.employee-options');
    // The Assignments list (Admin, HR: all; Manager: their team, read-only; checked in the controller).
    Route::get('assets/assignments', [AssignmentController::class, 'index'])->name('assets.assignments.index');
    // The register import (template -> upload -> preview -> run) and export (Admin, HR).
    Route::middleware('can:assets.assign')->prefix('assets')->name('assets.')->group(function () {
        Route::prefix('import')->name('import')->controller(AssetImportController::class)->group(function () {
            Route::get('/', 'show');
            Route::get('template', 'template')->name('.template');
            Route::post('preview', 'upload')->name('.preview');
            Route::post('run', 'run')->name('.run');
        });
        Route::get('export', AssetExportController::class)->name('export');
    });
    Route::get('assets/{asset}', [AssetController::class, 'show'])->middleware('can:assets.assign')->whereNumber('asset')->name('assets.show');

    // Attendance > Raw Punches: every punch the machines sent (Admin, HR). Giving an unknown machine user ID to an
    // employee also needs attendance.edit (and employees.manage, checked in the controller): it is an employee update.
    Route::get('attendance/punches', [PunchController::class, 'index'])
        ->middleware('can:attendance.view-all')->name('attendance.punches');
    Route::post('attendance/punches/assign', [PunchController::class, 'assign'])
        ->middleware('can:attendance.edit')->name('attendance.punches.assign');

    // Attendance > Correction Requests. The list: view-all (everyone's) or view-team (own and direct reports'), checked
    // in the controller. Submitting: any signed-in user, for their own record. Who decides which step and who may cancel
    // is checked by CorrectionRouting (403 outside scope); HR's step also needs attendance.edit.
    Route::prefix('attendance/corrections')->name('attendance.corrections.')->group(function () {
        Route::get('/', [CorrectionController::class, 'index'])->name('index');
        Route::post('/', [CorrectionController::class, 'store'])->name('store');
        Route::put('{correction}/manager-decide', [CorrectionController::class, 'managerDecide'])
            ->whereNumber('correction')->name('manager-decide');
        Route::put('{correction}/hr-decide', [CorrectionController::class, 'hrDecide'])
            ->whereNumber('correction')->middleware('can:attendance.edit')->name('hr-decide');
        Route::post('{correction}/cancel', [CorrectionController::class, 'cancel'])
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
        Route::get('/', [LeaveRequestController::class, 'index'])->name('index');
        Route::get('{leaveRequest}', [LeaveRequestController::class, 'show'])
            ->whereNumber('leaveRequest')->name('show');
        Route::put('{leaveRequest}/manager-decide', [LeaveRequestController::class, 'managerDecide'])
            ->whereNumber('leaveRequest')->name('manager-decide');
        Route::put('{leaveRequest}/final-decide', [LeaveRequestController::class, 'finalDecide'])
            ->whereNumber('leaveRequest')->middleware('can:leave.approve-all')->name('final-decide');
        Route::post('{leaveRequest}/cancel-approved', [LeaveRequestController::class, 'cancelApproved'])
            ->whereNumber('leaveRequest')->middleware('can:leave.approve-all')->name('cancel-approved');
    });

    // Leave > Balances: the grid (leave.approve-all, or leave.approve-team read-only for the own team; checked in the
    // controller, scoped by EmployeeAccess), HR's edit of a balance row (LeaveRouting::mayAdminister, 403), the .xlsx,
    // and the go-live opening-balance import (HR / Admin). Static URLs before the numeric edit route.
    Route::prefix('leave/balances')->name('leave.balances.')->group(function () {
        Route::get('/', [BalanceController::class, 'index'])->name('index');

        Route::middleware('can:leave.approve-all')->group(function () {
            Route::get('export', [BalanceController::class, 'export'])->name('export');
            Route::get('import', [BalanceImportController::class, 'show'])->name('import');
            Route::get('import/template', [BalanceImportController::class, 'template'])->name('import.template');
            Route::post('import/preview', [BalanceImportController::class, 'upload'])->name('import.preview');
            Route::post('import/run', [BalanceImportController::class, 'run'])->name('import.run');
            Route::put('{employee}/{type}/{year}', [BalanceController::class, 'update'])
                ->whereNumber(['employee', 'type'])->where('year', '\d{4}')->name('update');
        });
    });

    // Payroll > Salary Profiles: salary figures are Admin / HR only (payroll.view to read, payroll.run to write; who may
    // edit whose salary is PayrollAccess::mayEditSalary in SaveSalaryProfile, 403). A profile of someone else is 404.
    Route::prefix('payroll/salary-profiles')->name('payroll.salary-profiles.')->group(function () {
        Route::middleware('can:payroll.view')->group(function () {
            Route::get('/', [SalaryProfileController::class, 'index'])->name('index');
            Route::get('{employee}', [SalaryProfileController::class, 'show'])->whereNumber('employee')->name('show');
        });

        Route::middleware('can:payroll.run')->group(function () {
            Route::post('{employee}', [SalaryProfileController::class, 'store'])->whereNumber('employee')->name('store');
            Route::put('{employee}/{profile}', [SalaryProfileController::class, 'update'])
                ->whereNumber(['employee', 'profile'])->name('update');
            Route::delete('{employee}/{profile}', [SalaryProfileController::class, 'destroy'])
                ->whereNumber(['employee', 'profile'])->name('destroy');
        });
    });

    // Payroll > Loans & Advances: Admin / HR only (payroll.view reads, payroll.run writes, payroll.approve cancels; whose
    // loan a user may change is PayrollAccess::mayEditSalary in SaveLoan / LoanActions, 403).
    Route::prefix('payroll/loans')->name('payroll.loans.')->controller(LoanController::class)->group(function () {
        Route::middleware('can:payroll.view')->group(function () {
            Route::get('/', 'index')->name('index');
            Route::get('{loan}', 'show')->whereNumber('loan')->name('show');
        });

        Route::middleware('can:payroll.run')->group(function () {
            Route::post('/', 'store')->name('store');
            Route::put('{loan}', 'update')->whereNumber('loan')->name('update');
            Route::post('{loan}/skip', 'skip')->whereNumber('loan')->name('skip');
            Route::delete('{loan}/skip/{month}', 'unskip')->whereNumber('loan')->where('month', '\d{4}-\d{2}')->name('unskip');
            Route::post('{loan}/repayments', 'repay')->whereNumber('loan')->name('repay');
        });

        Route::post('{loan}/cancel', 'cancel')->whereNumber('loan')->middleware('can:payroll.approve')->name('cancel');
    });

    // Payroll > Opening Figures: opening tax per person and tax year (form), and the go-live imports of opening tax and
    // opening loans ({kind} tax | loans). Static URLs before the numeric edit route.
    Route::prefix('payroll/opening')->name('payroll.opening.')->group(function () {
        Route::get('/', [OpeningFiguresController::class, 'index'])->middleware('can:payroll.view')->name('index');

        Route::middleware('can:payroll.run')->group(function () {
            Route::prefix('import/{kind}')->where(['kind' => 'tax|loans'])->name('import')
                ->controller(OpeningImportController::class)->group(function () {
                    Route::get('/', 'show');
                    Route::get('template', 'template')->name('.template');
                    Route::post('preview', 'upload')->name('.preview');
                    Route::post('run', 'run')->name('.run');
                });
            Route::put('{employee}/{taxYear}', [OpeningFiguresController::class, 'update'])
                ->whereNumber('employee')->where('taxYear', '\d{4}')->name('update');
        });
    });

    // Payroll > Payroll Runs: the list, generating a month, the run page and its actions, and the lines' drawer and
    // inputs. Admin / HR only (payroll.view reads, payroll.run writes, payroll.approve approves; the actions check who
    // may edit whose line and who may approve, 403). A line, adjustment or loan of another run / person is 404.
    Route::prefix('payroll/runs')->name('payroll.runs.')->controller(PayrollRunController::class)->group(function () {
        Route::middleware('can:payroll.view')->group(function () {
            Route::get('/', 'index')->name('index');
            Route::get('{run}', 'show')->whereNumber('run')->name('show');
        });

        Route::middleware('can:payroll.run')->group(function () {
            Route::post('/', 'store')->name('store');
            Route::post('{run}/recalculate', 'recalculate')->whereNumber('run')->name('recalculate');
            Route::post('{run}/paid', 'paid')->whereNumber('run')->name('paid');
            Route::delete('{run}', 'destroy')->whereNumber('run')->name('destroy');
        });

        Route::post('{run}/approve', 'approve')->whereNumber('run')->middleware('can:payroll.approve')->name('approve');
    });

    Route::prefix('payroll/runs/{run}/items/{item}')->name('payroll.items.')->whereNumber(['run', 'item'])->scopeBindings()
        ->controller(PayrollItemController::class)->group(function () {
            Route::get('/', 'show')->middleware('can:payroll.view')->name('show');

            Route::middleware('can:payroll.run')->group(function () {
                Route::post('adjustments', 'storeAdjustment')->name('adjustments.store');
                Route::delete('adjustments/{adjustment}', 'destroyAdjustment')->whereNumber('adjustment')->name('adjustments.destroy');
                Route::put('tax', 'tax')->name('tax');
                Route::put('exclude', 'exclude')->name('exclude');
                Route::put('loans/{loan}', 'loanSkip')->whereNumber('loan')->withoutScopedBindings()->name('loan-skip');
            });
        });

    // Payroll downloads and the yearly tax report (Admin / HR only). The bank sheet is for approved / paid runs (a draft
    // is 404 in the controller); the summary is for any run.
    Route::get('payroll/runs/{run}/bank-sheet', [PayrollExportController::class, 'bankSheet'])
        ->whereNumber('run')->middleware('can:payroll.run')->name('payroll.runs.bank-sheet');
    Route::get('payroll/runs/{run}/summary', [PayrollExportController::class, 'summary'])
        ->whereNumber('run')->middleware('can:payroll.view')->name('payroll.runs.summary-export');
    Route::middleware('can:payroll.view')->prefix('payroll/tax-report')->name('payroll.tax-report')
        ->controller(TaxReportController::class)->group(function () {
            Route::get('/', 'index');
            Route::get('export', 'export')->name('.export');
        });

    // Exit documents (Phase 6c). Admin / HR: the settlement statement (exit.clearance + payroll.view) and the experience letter
    // (exit.clearance). The employee's own copies are under my/resignation (the controller insists the exit is theirs).
    Route::get('exit/settlements/{settlement}/statement', [ExitDocumentController::class, 'statement'])
        ->whereNumber('settlement')->middleware(['can:exit.clearance', 'can:payroll.view'])->name('exit.settlements.statement');
    Route::post('exit/{resignation}/letter', [ExitDocumentController::class, 'issue'])
        ->whereNumber('resignation')->middleware('can:exit.clearance')->name('exit.letter.issue');
    Route::get('exit/{resignation}/letter', [ExitDocumentController::class, 'letter'])
        ->whereNumber('resignation')->middleware('can:exit.clearance')->name('exit.letter');
    Route::get('my/resignation/{resignation}/statement', [ExitDocumentController::class, 'myStatement'])
        ->whereNumber('resignation')->name('my.resignation.statement');
    Route::get('my/resignation/{resignation}/letter', [ExitDocumentController::class, 'myLetter'])
        ->whereNumber('resignation')->name('my.resignation.letter');

    // Users and roles. No delete route: accounts are disabled, never removed.
    Route::middleware('can:users.manage')->prefix('users')->name('users.')->group(function () {
        Route::get('/', [UserController::class, 'index'])->name('index');
        Route::post('/', [UserController::class, 'store'])->name('store');
        Route::put('{user}', [UserController::class, 'update'])->name('update');
        Route::patch('{user}/active', [UserController::class, 'toggle'])->name('toggle');
        Route::post('{user}/send-reset', [UserController::class, 'sendReset'])->name('send-reset');
    });

    // System > Updates (Admin): what changed in each version, and "Update system", which backs up the database and applies
    // the waiting database changes. New code arrives by itself on every push; its database changes wait for this button.
    Route::middleware('can:settings.manage')->prefix('system/updates')->name('system.updates.')->controller(UpdatesController::class)->group(function () {
        Route::get('/', 'index')->name('index');
        Route::post('/', 'run')->middleware('throttle:6,1')->name('run');
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
        Route::middleware('can:settings.manage')->prefix('devices')->name('devices.')->controller(DeviceController::class)->group(function () {
            Route::get('/', 'index')->name('index');
            Route::post('/', 'store')->name('store');
            Route::put('{device}', 'update')->whereNumber('device')->name('update');
            Route::patch('{device}/active', 'toggle')->whereNumber('device')->name('toggle');
            Route::delete('{device}', 'destroy')->whereNumber('device')->name('destroy');
            Route::post('{device}/resend', 'resend')->whereNumber('device')->name('resend');
        });
        // Settings > Asset categories (Admin, HR). Registered before the {group} route.
        Route::middleware('can:assets.assign')->prefix('asset-categories')->name('asset-categories.')->controller(AssetCategoryController::class)->group(function () {
            Route::get('/', 'index')->name('index');
            Route::post('/', 'store')->name('store');
            Route::put('{category}', 'update')->whereNumber('category')->name('update');
            Route::delete('{category}', 'destroy')->whereNumber('category')->name('destroy');
        });
        Route::put('{group}', [HrmSettingsController::class, 'update'])->name('update');
    });
});

require __DIR__.'/settings.php';
