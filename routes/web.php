<?php

use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\BrandingMediaController;
use App\Http\Controllers\DepartmentController;
use App\Http\Controllers\DesignationController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\EmployeeExportController;
use App\Http\Controllers\EmployeeImportController;
use App\Http\Controllers\EmployeeLinkController;
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
    Route::inertia('dashboard', 'dashboard')->name('dashboard');

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

    // The profile page. Any signed-in user may ask; EmployeeAccess::canView decides (403), and a person who only has
    // their own record is sent to My Profile. Registered after every static /employees/... route above.
    Route::get('employees/{employee}', [EmployeeController::class, 'show'])->whereNumber('employee')->name('employees.show');

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
        Route::put('{group}', [HrmSettingsController::class, 'update'])->name('update');
    });
});

require __DIR__.'/settings.php';
