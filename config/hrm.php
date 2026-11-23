<?php

return [

    'devices' => [
        // Serial numbers of attendance machines allowed to call /iclock.
        // Comma-separated in HRM_DEVICE_SERIALS. Replaced by the devices table in Phase 3.
        'allowed_serials' => array_values(array_filter(
            array_map('trim', explode(',', (string) env('HRM_DEVICE_SERIALS', ''))),
            fn (string $serial) => $serial !== '',
        )),
    ],

    // First Admin, created by AccessSync only when no admin exists. Read via config so it works with config:cache.
    'admin' => [
        'email' => env('HRM_ADMIN_EMAIL'),
        'password' => env('HRM_ADMIN_PASSWORD'),
    ],

    // Permission => roles that hold it. Single source of truth for access control.
    // "My Space" pages need no permission: every signed-in user has them.
    'permissions' => [
        'org.manage' => ['admin', 'hr'],
        'employees.view-all' => ['admin', 'hr'],
        'employees.view-team' => ['admin', 'hr', 'manager'],
        'employees.manage' => ['admin', 'hr'],
        'employees.delete' => ['admin'],
        'attendance.view-all' => ['admin', 'hr'],
        'attendance.view-team' => ['admin', 'hr', 'manager'],
        'attendance.edit' => ['admin', 'hr'],
        'leave.approve-all' => ['admin', 'hr'],
        'leave.approve-team' => ['admin', 'hr', 'manager'],
        'payroll.view' => ['admin', 'hr'],
        'payroll.run' => ['admin', 'hr'],
        'payroll.approve' => ['admin'],
        'performance.manage' => ['admin', 'hr'],
        'performance.review-team' => ['admin', 'hr', 'manager'],
        'assets.manage' => ['admin'],
        'assets.assign' => ['admin', 'hr'],
        'assets.view-team' => ['admin', 'hr', 'manager'],
        'exit.manage' => ['admin'],
        'exit.clearance' => ['admin', 'hr'],
        'exit.approve-team' => ['admin', 'hr', 'manager'],
        'settings.manage' => ['admin'],
        'settings.attendance-leave' => ['admin', 'hr'],
        'users.manage' => ['admin'],
        'audit.view' => ['admin'],
    ],

    // Every role is created even if it holds no permission (employee).
    'roles' => ['admin', 'hr', 'manager', 'employee'],

];
