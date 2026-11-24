<?php

use App\Models\ActivityEntry;
use App\Support\Audit\AuditLogAction;
use App\Support\Audit\NeverCleanActivityLogAction;

/*
| Audit log. The app only ever appends: see docs/audit-log.md before changing anything here.
*/
return [

    /*
     * Always on, deliberately not read from .env: an audited action must leave an entry.
     */
    'enabled' => true,

    /*
     * Used only by the package's `activitylog:clean` command. The command must never be run (and is not
     * scheduled): the audit trail is kept for good. This value is 100 years, and the clean action below is
     * replaced by one that deletes nothing, so an accidental run removes no rows.
     */
    'clean_after_days' => 36500,

    /*
     * Entries written without a log name use this one. Every real entry names its area
     * (organization, users, settings, auth ...), see App\Support\Audit\Audit::AREAS.
     */
    'default_log_name' => 'default',

    /*
     * You can specify an auth driver here that gets user models.
     * If this is null we'll use the current Laravel auth driver.
     */
    'default_auth_driver' => null,

    /*
     * If set to true, the subject relationship on activities
     * will include soft deleted models.
     */
    'include_soft_deleted_subjects' => false,

    /*
     * Append-only model: updating or deleting an entry through Eloquent throws.
     */
    'activity_model' => ActivityEntry::class,

    /*
     * Every model opts in with an explicit allow-list (logOnly), so this stays empty.
     * Secrets are additionally scrubbed by AuditLogAction.
     */
    'default_except_attributes' => [],

    /*
     * Entries are written inside the request's transaction, so they must not be buffered.
     */
    'buffer' => [
        'enabled' => false,
    ],

    'actions' => [
        // Adds request context, keeps the old/new shape in `properties`, scrubs secrets.
        'log_activity' => AuditLogAction::class,
        // Deletes nothing.
        'clean_log' => NeverCleanActivityLogAction::class,
    ],
];
