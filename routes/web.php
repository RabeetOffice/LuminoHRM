<?php

use App\Http\Controllers\BrandingMediaController;
use App\Http\Controllers\DepartmentController;
use App\Http\Controllers\DesignationController;
use App\Http\Controllers\Settings\HrmSettingsController;
use App\Support\Branding;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

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
