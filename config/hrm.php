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

    /*
    | Addresses (IPs or CIDR ranges) of reverse proxies / CDNs whose X-Forwarded-For and X-Forwarded-Proto are believed.
    | Comma-separated in HRM_TRUSTED_PROXIES. Empty (the default) trusts none, so request()->ip() is the connecting address.
    | "*", "**", "REMOTE_ADDR", 0.0.0.0/0 and ::/0 are ignored: trusting everyone would let any visitor forge the address written to the audit log and device_logs.
    */
    'trusted_proxies' => array_values(array_filter(
        array_map('trim', explode(',', (string) env('HRM_TRUSTED_PROXIES', ''))),
        // "trust everything" entries are ignored, whatever the setting says
        fn (string $entry) => $entry !== '' && ! in_array($entry, ['*', '**', 'REMOTE_ADDR', '0.0.0.0/0', '::/0'], true),
    )),

    /*
    | Nightly database backup (php artisan hrm:backup-database). Files go to the "local" disk (private, never under public/).
    | exclude_data: tables whose structure is dumped but whose rows are not (transient, or holding encrypted tokens).
    | keep_days: backups dated more than this many days ago are deleted. rows_per_insert: rows per INSERT statement.
    */
    'backup' => [
        'exclude_data' => ['cache', 'cache_locks', 'sessions', 'jobs', 'job_batches', 'failed_jobs'],
        'keep_days' => 14,
        'rows_per_insert' => 200,
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

    /*
    | Settings groups: group => permissions of which a user needs ANY ONE to open and save it.
    | The order is the order of the left-hand list on the Settings screen. To add a group
    | (for example "zkteco" in Phase 3): add it here, add its keys under 'defaults', add a
    | FormRequest in App\Http\Requests\HrmSettings and register it in HrmSettingsController::REQUESTS,
    | and add a panel in resources/js/components/settings. No other code changes.
    */
    'settings_groups' => [
        'company' => ['settings.manage'],
        'branding' => ['settings.manage'],
        'attendance' => ['settings.manage', 'settings.attendance-leave'],
        'leave' => ['settings.manage', 'settings.attendance-leave'],
        'payroll' => ['settings.manage'],
        'email' => ['settings.manage'],
        'letters' => ['settings.manage'],
    ],

    /*
    | Settings the audit log never records a value for. Secret: only "[changed]" is recorded (a new secret setting
    | must be listed here). File: the entry says added / replaced / removed, never a path or contents.
    */
    'audit' => [
        'secret_settings' => ['email.password'],
        'file_settings' => ['branding.logo', 'branding.logo_dark'],
    ],

    // Date formats offered in Settings > Company (PHP date() syntax). The stored value must be one of these.
    'date_formats' => ['d M Y', 'd/m/Y', 'm/d/Y', 'Y-m-d', 'd-m-Y', 'M d, Y'],

    /*
    | Default for every setting, and the list of keys a group may store: Settings::set() ignores
    | anything not declared here. A default's PHP type is the stored type (15 stays an int).
    | Read with settings('group.key'). A default null means "nothing saved yet".
    */
    'defaults' => [
        'company' => [
            'name' => 'Lumino Digital',
            'address' => '',
            'currency' => 'PKR',
            'timezone' => 'Asia/Karachi',
            'date_format' => 'd M Y',
        ],
        'branding' => [
            'accent' => '#10b77f',
            // Paths on the private disk, written by the Branding settings only.
            'logo' => null,
            'logo_dark' => null,
        ],
        'attendance' => [
            'shift_start' => '09:00',
            'shift_end' => '18:00',
            'grace_minutes' => 15,
            'weekly_offs' => ['saturday', 'sunday'],
            'late_rule_count' => 3,
            'half_day_hours' => 4,
            'overtime_enabled' => false,
            'overtime_min_minutes' => 30,
        ],
        'leave' => [
            'hr_step_enabled' => true,
            'carry_forward_cap' => 0,
            'prorate_new_joiners' => true,
        ],
        'payroll' => [
            'day_pay_basis' => 'calendar_days', // calendar_days | thirty | working_days
            'default_tax_mode' => 'none',
            'leave_encashment' => false,
            'notice_period_days' => 30,
            'payslip_footer' => '',
        ],
        'email' => [
            // An empty host means "use the mailer from .env".
            'host' => '',
            'port' => 587,
            'username' => '',
            'password' => '', // encrypted with Crypt, never sent to the browser
            'encryption' => 'tls', // tls | ssl | none
            'from_address' => '',
            'from_name' => '',
        ],
        'letters' => [
            'experience_template' => "To whom it may concern,\n\nThis is to certify that {employee_name} worked with {company_name} as {designation} in the {department} department from {joining_date} to {last_working_day}.\n\nDuring this time we found the employee diligent and dependable. We wish them every success.\n\nSincerely,\nHuman Resources\n{company_name}",
        ],
    ],

];
