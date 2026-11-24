# Audit log

Who changed what, when, with old and new values. Admins read it at `/audit-log` (permission `audit.view`). Entries are append-only: no route, screen or scheduled job edits or deletes them.

Built on `spatie/laravel-activitylog` (table `activity_log`). Reads and writes go through `App\Models\ActivityEntry`, which throws if anything updates or deletes a row. `activitylog:clean` is never scheduled and never to be run: its action is replaced by `NeverCleanActivityLogAction` (deletes nothing) and `clean_after_days` is 36500.

## Areas

An area is the entry's `log_name`. The list lives in one place, `App\Support\Audit\Audit::AREAS` (`log name => label`), and the viewer's filter reads it. **A new phase adds its area there and nowhere else.**

| Area           | Records                                                                                                                                                             |
| -------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `organization` | Department and designation created, updated, deleted                                                                                                                |
| `users`        | Account created, name or email changed, role changed, disabled, enabled, reset link sent or failed, set-password link sent or failed, first admin created by system |
| `settings`     | Each save that changes a setting: only the changed keys, old and new                                                                                                |
| `auth`         | Signed in, Failed sign-in, Signed out, Sign-in refused: account disabled                                                                                            |

Planned (add when the phase ships): `employees`, `salary`, `payroll`, `attendance`, `leave`.

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

`Audit::record()` throws if it cannot write, so call it inside the transaction of the change it describes. `Audit::tryRecord()` never throws (it reports to the error log) and is for sign-in and sign-out only, where a logging failure must never lock anyone out. A console command or migration has no causer; the viewer shows "System". Say so in the description ("First admin created by system").

Put the values the viewer should compare side by side under `old` and `attributes` with the same keys. Anything else scalar under `ip`, `user_agent` or `attempted_email` shows as a detail line.

## Settings

`Settings::set()` logs the changed keys itself, inside its transaction. A secret setting must be listed in `config('hrm.audit.secret_settings')` (today `email.password`): only `[changed]` is recorded, never the value, not even encrypted. File settings (`config('hrm.audit.file_settings')`, today the two logos) record `[added]`, `[replaced]` or `[removed]`, never a path or contents.

## Never log

- Passwords or hashes, remember tokens, two-factor secrets and recovery codes, passkey material, password-reset or set-password tokens, the SMTP password, session ids, API keys.
- The password typed in a failed sign-in (record the attempted email, at most 255 characters, and the IP).
- Salary figures belong in a dedicated `salary` area and are visible to Admin only through this viewer: decide per phase what a salary entry may contain before logging it.

`AuditLogAction` replaces the value of any property whose name contains `password`, `token`, `secret`, `recovery`, `passkey`, `credential`, `api_key` or `remember` with `[hidden]` before saving. That is a safety net, not a substitute for the allow-list.

## Not audited here

Machine requests to `/iclock` have their own `device_logs` table and run no audit query or middleware.

## Viewer query string

`GET /audit-log`: `user_id` (a user id, or `system`), `area` (an area key), `from` and `to` (`YYYY-MM-DD`, whole days in the company display timezone, both ends inclusive), `search` (description, at most 100 characters), `per_page` (10, 25 or 50), `page`. Invalid values are ignored; a page past the end redirects to the last page. Newest first, ties broken by id.
