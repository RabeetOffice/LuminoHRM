<?php

return [

    // Local development only: shared password for LocalTestLoginsSeeder (never set in production).
    'local_test_password' => (string) env('LOCAL_TEST_PASSWORD', ''),

    'devices' => [
        // Bootstrap only: a serial listed here (comma-separated in HRM_DEVICE_SERIALS) that has no devices row gets one
        // on its first /iclock request. From then on the devices table decides (an inactive row is rejected).
        'allowed_serials' => array_values(array_filter(
            array_map('trim', explode(',', (string) env('HRM_DEVICE_SERIALS', ''))),
            fn (string $serial) => $serial !== '',
        )),

        // Largest /iclock request body accepted (bytes). A bigger one gets a plain-text 413 before it is parsed.
        'max_body_bytes' => (int) env('HRM_DEVICE_MAX_BODY_BYTES', 4194304),
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
        // Owner ruling (26 Nov): HR may change the review rules too.
        'performance' => ['performance.manage'],
        'email' => ['settings.manage'],
        // Owner ruling (26 Nov): Settings > Exit (clearance items, experience letter) is editable by Admin and HR.
        'exit' => ['exit.clearance'],
    ],

    /*
    | Settings the audit log never records a value for. Secret: only "[changed]" is recorded (a new secret setting
    | must be listed here). File: the entry says added / replaced / removed, never a path or contents.
    */
    'audit' => [
        'secret_settings' => ['email.password'],
        'file_settings' => ['branding.logo', 'branding.logo_dark'],
        // Model attributes (any area) whose value is never recorded: when set, only "[changed]" is stored, old and new.
        'masked_attributes' => ['cnic', 'dob', 'account_number'],
    ],

    // Allowed values for the Employees module (validated on save and import; stored as plain strings).
    'employees' => [
        'statuses' => ['active', 'probation', 'inactive', 'resigned', 'terminated'],
        'employment_types' => ['full_time', 'part_time', 'contract', 'intern'],
        'genders' => ['male', 'female', 'other'],
        'document_types' => ['cnic', 'contract', 'offer_letter', 'certificate', 'other'],
    ],

    // Allowed values for the Attendance module (stored as plain strings). Status precedence and rules: see the Phase 3 plan.
    'attendance' => [
        'statuses' => ['present', 'late', 'half_day', 'absent', 'on_leave', 'holiday', 'weekend'],
        'correction_statuses' => ['pending', 'manager_approved', 'approved', 'rejected', 'cancelled'],
        'punch_sources' => ['device', 'import', 'manual'],
        'command_statuses' => ['queued', 'sent', 'done', 'failed'],
        // Lowercase English day names, Monday first: the only values a shift's weekly_offs may hold.
        'weekdays' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'],
    ],

    // Allowed values for the Leave module (stored as plain strings). Rules: see the Phase 4 plan.
    'leave' => [
        // pending = waiting for the manager, manager_approved = waiting for HR.
        'statuses' => ['pending', 'manager_approved', 'approved', 'rejected', 'cancelled'],
        'open_statuses' => ['pending', 'manager_approved'],
        // A half-day request: am = first half, pm = second half (leave_requests.half, attendances.leave_half).
        'halves' => ['am', 'pm'],
        // leave_days.half: a whole day or one half.
        'day_halves' => ['full', 'am', 'pm'],
        // A leave type's calendar colour: palette names the frontend maps to colours.
        'colours' => ['emerald', 'sky', 'amber', 'rose', 'violet', 'teal', 'orange', 'slate'],
    ],

    // Allowed values for the Payroll module (stored as plain strings). Rules: see the Phase 5 plan and design.
    'payroll' => [
        // draft -> approved (= locked) -> paid. An approved or paid month is frozen (App\Support\PayrollLock).
        'run_statuses' => ['draft', 'approved', 'paid'],
        'locked_statuses' => ['approved', 'paid'],
        // salary_profiles.tax_value: 0 (none), paisa a month (fixed), basis points (percentage, 500 = 5.00%).
        'tax_modes' => ['none', 'fixed', 'percentage'],
        // What a percentage tax is taken of.
        'tax_bases' => ['gross_taxable', 'basic'],
        'component_types' => ['allowance', 'deduction'],
        'loan_types' => ['loan', 'advance'],
        // "Cleared" is not a status: an active loan with nothing remaining (remaining is derived, never stored).
        'loan_statuses' => ['active', 'cancelled'],
        'adjustment_types' => ['addition', 'deduction'],
        // Allowances plus fixed deductions on one salary profile.
        'max_components' => 20,
    ],

    // Allowed values for the Assets module (stored as plain strings). Rules: see the Phase 6a design and plan.
    'assets' => [
        'conditions' => ['new', 'good', 'fair', 'poor', 'damaged'],
        // available -> assigned (by an assignment) -> available again (by a return); in_repair and retired are set by hand.
        'statuses' => ['available', 'assigned', 'in_repair', 'retired'],
        'code_prefix' => 'AST-',
    ],

    // Allowed values for the Exit module (stored as plain strings). Rules: see the Phase 6c design and plan.
    'exit' => [
        'types' => ['resignation', 'termination'],
        // pending (with the manager) -> manager_approved (with HR) -> approved; rejected, withdrawn and cancelled end it.
        'statuses' => ['pending', 'manager_approved', 'approved', 'rejected', 'withdrawn', 'cancelled'],
        'open_statuses' => ['pending', 'manager_approved', 'approved'],
        'settlement_statuses' => ['draft', 'approved', 'paid'],
        // An approved or paid settlement freezes the person's final month (PayrollLock).
        'frozen_statuses' => ['approved', 'paid'],
        'clearance_kinds' => ['assets', 'access', 'dues', 'custom'],
        'line_kinds' => ['bonus', 'notice_shortfall', 'other'],
        'max_lines' => 20,
        'max_clearance_items' => 20,
        // The dashboard lists approved exits whose last working day falls in the next this many days.
        'upcoming_days' => 30,
        'max_days_ahead' => 365,
    ],

    // Allowed values for the Performance module (stored as plain strings). Rules: see the Phase 6b design and plan.
    'performance' => [
        // self_review -> manager_review -> ready_to_close -> closed; skipped is a side state (the table: App\Support\Performance\ReviewStates).
        'review_states' => ['self_review', 'manager_review', 'ready_to_close', 'closed', 'skipped'],
        'cycle_states' => ['open', 'closed'],
        'period_types' => ['quarterly', 'monthly'],
        // A cycle's due date defaults to this many days after its period ends.
        'default_due_days' => 14,
        // Score labels: the lowest final score (hundredths) of each label, highest first.
        'labels' => [
            ['min' => 450, 'label' => 'Outstanding'],
            ['min' => 350, 'label' => 'Exceeds'],
            ['min' => 250, 'label' => 'Meets'],
            ['min' => 150, 'label' => 'Below'],
            ['min' => 0, 'label' => 'Unsatisfactory'],
        ],
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
            // "Y-m-d"; null = attendance has not started: no absent / weekend / holiday day is recorded yet.
            'start_date' => null,
            // A punch less than this many minutes after the previously kept punch is a double tap and is dropped.
            'duplicate_punch_minutes' => 5,
            // "H:i": a punch before this time belongs to the previous day (its clock-out after a late night).
            'day_cutoff_time' => '04:00',
            // Overtime pay (payroll, only while overtime_enabled). multiple: hourly pay (monthly gross / basis days /
            // overtime_hours_per_day) x overtime_multiplier_percent / 100; fixed: overtime_fixed_rate rupees an hour.
            'overtime_rate_mode' => 'multiple', // multiple | fixed
            'overtime_multiplier_percent' => 150,
            'overtime_fixed_rate' => 0, // whole rupees an hour
            'overtime_hours_per_day' => 8,
        ],
        'leave' => [
            'hr_step_enabled' => true,
            'prorate_new_joiners' => true,
        ],
        'payroll' => [
            'day_pay_basis' => 'calendar_days', // calendar_days | thirty | working_days
            'default_tax_mode' => 'none',
            'leave_encashment' => false,
            'notice_period_days' => 30,
            // The leave type paid out on exit (a leave type id). null = the type named "Annual".
            'encashment_leave_type_id' => null,
            'payslip_footer' => '',
            // First month of the tax year (7 = July to June); a tax year is named by its start year ("2026-27").
            'tax_year_start_month' => 7,
        ],
        'performance' => [
            // quarterly | monthly: the kind of period a new cycle covers. Cycles already opened keep theirs.
            'review_period' => 'quarterly',
            // Whether a new cycle starts with the person rating themselves. Copied onto each cycle when it opens.
            'self_review_enabled' => true,
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
        'exit' => [
            // Custom clearance items added after the built-in three (assets, access, dues), in order.
            'clearance_items' => [],
            'experience_template' => "To whom it may concern,\n\nThis is to certify that {employee_name} worked with {company_name} as {designation} in the {department} department from {joining_date} to {last_working_day}.\n\nDuring this time we found the employee diligent and dependable. We wish them every success.\n\nSincerely,\nHuman Resources\n{company_name}",
        ],
    ],

];
