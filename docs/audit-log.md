# Audit log

Who changed what, when, with old and new values. Admins read it at `/audit-log` (permission `audit.view`). Entries are append-only: no route, screen or scheduled job edits or deletes them.

Built on `spatie/laravel-activitylog` (table `activity_log`). Reads and writes go through `App\Models\ActivityEntry`, which throws if anything updates or deletes a row. `activitylog:clean` is never scheduled and never to be run: its action is replaced by `NeverCleanActivityLogAction` (deletes nothing) and `clean_after_days` is 36500.

The package is pinned to `~5.1.5` in `composer.json`. `AuditLogAction` overrides protected package methods (`transformChanges`, `beforeActivityLogged`, `save`) to keep old/new values in `properties`. **Upgrading the package requires the test "fails loudly if the package integration stops putting model changes into properties" in `tests/Feature/Audit/AuditLogTest.php` to pass.**

There is no way to delete a user account anywhere in the app (the starter kit's "delete my account" was removed): an Admin disables accounts, nobody removes them.

## Areas

An area is the entry's `log_name`. The list lives in one place, `App\Support\Audit\Audit::AREAS` (`log name => label`), and the viewer's filter reads it. **A new phase adds its area there and nowhere else.**

| Area           | Records                                                                                                                                                                                                                                                                   |
| -------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `organization` | Department and designation created, updated, deleted                                                                                                                                                                                                                      |
| `users`        | Account created, name or email changed, role changed, disabled, enabled, reset link sent or failed, set-password link sent or failed, first admin created by system                                                                                                       |
| `settings`     | Each save that changes a setting: only the changed keys, old and new                                                                                                                                                                                                      |
| `auth`         | Signed in, Failed sign-in (at most 20 per IP address per hour, then one marker entry), Signed out, Sign-in refused: account disabled, Password changed, Password reset, Two-factor authentication enabled / disabled, Recovery codes regenerated, Passkey added / removed |
| `employees`    | Employee created, updated, status changed, deleted: one combined entry per save (`Employee created`, `Employee updated`, `Employee status changed`, `Employee deleted`) listing each changed field with old and new, contact and bank fields included; `cnic`, `dob` and `account_number` are recorded as `[changed]` only, and a delete keeps the name and code. Photo added, changed or removed, recorded as a fact (`[added]`, `[replaced]`, `[removed]`), never a path. Document added or removed (`Employee document added`, `Employee document removed`): type and title only, never contents, file paths or file names. Import run (`Employees imported`): counts only (created, updated, failed, logins disabled, linked existing); `Employee import failed` with the row count when a run is rolled back; never row data. Export run (`Employees exported`): who ran it, the row count, the filters and the number of columns. Set-password links sent in bulk (`Set-password links sent`): counts only (requested, queued, skipped). The login account's own changes (name, email, role, enabled or disabled) are separate entries in the `users` area. |
| `attendance`   | Shift created, updated, deleted (`Shift created` ...: name, times, grace, half-day hours, weekly offs, default flag); holiday created, updated, deleted (name, date); device created, updated, deleted (name, serial number, active flag only, never the heartbeat columns). Manual correction of a day (`Attendance corrected`: the date, old and new clock-in, clock-out, status, worked / late / early / overtime minutes, manual and status-override flags, the reason, and the note such as `Correction request #12` when given) and its removal (`Attendance correction removed`: the date, the values before, and the values after the rebuild from the punches, or empty values when the day has no record any more). Correction requests (subject the employee): `Correction requested` (request id, date, the recorded and the requested clock-in / clock-out, a requested side left empty shown as null = keeps what is recorded, the reason), `Correction request approved by manager` (request id, date, step, note), `Correction request approved` (request id, date, step `hr`, `applied`: the clock-in / clock-out written, `manager_skipped: true` when HR decided before the manager, note), `Correction request rejected` (request id, date, step `manager` or `hr`, `manager_skipped` when it applies, note) and `Correction request cancelled` (request id, date); an approval also writes `Attendance corrected` with the note `Correction request #id`. Later Phase 3 tasks add device commands and the attendance export. Punch ingestion is not audited (it has `device_punches` and `device_logs`). |

Planned (add when the phase ships): `salary`, `payroll`, `leave`.

## A model opts in

```php
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Thing extends Model
{
    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('organization')            // an area from Audit::AREAS
            ->logOnly(['name', 'department_id'])    // explicit allow-list, never logAll() / logFillable()
            ->logOnlyDirty()                        // only what changed
            ->dontLogEmptyChanges()                 // a save that changes nothing logs nothing
            ->setDescriptionForEvent(fn (string $event) => "Thing {$event}");
    }

    // Optional: the name shown for this record, kept with the entry so it survives deletion.
    public function auditLabel(): string
    {
        return $this->name;
    }
}
```

Rules for a model:

- Allow-list with `logOnly([...])`. A column added later is then not logged by accident.
- Restrict events with `protected static array $recordEvents = ['updated'];` when creation or deletion is logged explicitly elsewhere (as `User` does).
- Change entries store `properties = {"old": {...}, "attributes": {...}}`. Request IP and a user agent (255 characters at most) are added for web requests.
- Wrap the write in `DB::transaction(...)`. The entry is written by a model event inside it, so if the entry cannot be saved the change rolls back. An audited action that cannot be logged must not happen.

## A non-model action

```php
use App\Support\Audit\Audit;

DB::transaction(function () use ($user, $actor) {
    $user->syncRoles([$role]);

    Audit::record(
        'users',                                     // area
        "Role changed from {$from} to {$role}",      // description
        $user,                                       // subject (optional)
        ['old' => ['role' => $from], 'attributes' => ['role' => $role]],   // properties (optional)
        $actor,                                      // causer (optional; default is the signed-in user, or nobody)
    );
});
```

There are two categories of entry, and each uses one function:

1. **Rollback is still possible** (the change and its entry are in one transaction): `Audit::record()`, which throws if it cannot write, so the change rolls back. An audited action that cannot be logged must not happen.
2. **Rollback is impossible, or the entry must never block the action**: `Audit::tryRecord()` (never throws; it `report()`s to the error log). Use it for sign-in, sign-out and self-service security events (a logging failure must never lock anyone out), and for entries written after an email has been sent (reset link sent or refused, set-password link sent or failed): the mail cannot be taken back, so a logging failure must not turn it into an error page.

A console command or migration has no causer; the viewer shows "System". Say so in the description ("First admin created by system").

Put the values the viewer should compare side by side under `old` and `attributes` with the same keys. Anything else scalar under `ip`, `user_agent` or `attempted_email` shows as a detail line.

## Settings

`Settings::set()` logs the changed keys itself, inside its transaction. A secret setting must be listed in `config('hrm.audit.secret_settings')` (today `email.password`): only `[changed]` is recorded, never the value, not even encrypted. File settings (`config('hrm.audit.file_settings')`, today the two logos) record `[added]`, `[replaced]` or `[removed]`, never a path or contents.

## Masked attributes

A model attribute whose value must never be stored is listed in `config('hrm.audit.masked_attributes')` (today `cnic`, `dob`, `account_number`). It still goes in the model's `logOnly` allow-list, so a change is noticed, but `AuditLogAction` replaces any non-null value under that name, old and new, with `[changed]` before saving. Settings secrets keep their own list (`secret_settings`) because they are not model attributes. Add a new sensitive column to `masked_attributes` AND to the model's `logOnly`.

## Never log

- Passwords or hashes, remember tokens, two-factor secrets and recovery codes, passkey material, password-reset or set-password tokens, the SMTP password, session ids, API keys.
- The password typed in a failed sign-in (record the attempted email, at most 255 characters, and the IP).
- Salary figures belong in a dedicated `salary` area and are visible to Admin only through this viewer: decide per phase what a salary entry may contain before logging it.

`AuditLogAction` replaces the value of any property whose name ENDS in the word `password`, `token`, `secret`, `hash`, `codes`, `passkey`, `credential(s)`, `remember` or `api_key` (words split on `_`, `.` and camelCase, so `reset_token` is hidden and `token_count` is not) with `[hidden]` before saving. That is a safety net, not a substitute for the allow-list.

## Not audited here

Machine requests to `/iclock` have their own `device_logs` table and run no audit query or middleware.

## Viewer query string

`GET /audit-log`: `user_id` (a user id, or `system`), `area` (an area key), `from` and `to` (`YYYY-MM-DD`, whole days in the company display timezone, both ends inclusive), `search` (description, at most 100 characters), `per_page` (10, 25 or 50), `page`. Invalid values are ignored; a page past the end redirects to the last page. Newest first, ties broken by id.
