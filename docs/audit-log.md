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
| `attendance`   | Shift created, updated, deleted (`Shift created` ...: name, times, grace, half-day hours, weekly offs, default flag); holiday created, updated, deleted (name, date); device created, updated, deleted (name, serial number, active flag only, never the heartbeat columns). Manual correction of a day (`Attendance corrected`: the date, old and new clock-in, clock-out, status, worked / late / early / overtime minutes, manual and status-override flags, the reason, and the note such as `Correction request #12` when given) and its removal (`Attendance correction removed`: the date, the values before, and the values after the rebuild from the punches, or empty values when the day has no record any more). Correction requests (subject the employee): `Correction requested` (request id, date, the recorded and the requested clock-in / clock-out, a requested side left empty shown as null = keeps what is recorded, the reason), `Correction request approved by manager` (request id, date, step, note), `Correction request approved` (request id, date, step `hr`, `applied`: the clock-in / clock-out written, `manager_skipped: true` when HR decided before the manager, note), `Correction request rejected` (request id, date, step `manager` or `hr`, `manager_skipped` when it applies, note) and `Correction request cancelled` (request id, date); an approval also writes `Attendance corrected` with the note `Correction request #id`. Monthly grid export (`Monthly attendance exported`, no subject): the month, the row count and the filters (search, department), never row data. A re-send of a machine's punches queued from Settings > Devices (`Device command queued`, subject the device): the command id, the command (`Re-send punches`) and the from / to days. A machine user id given to an employee (on the employee form or from the Punches screen) is an `Employee updated` entry in the `employees` area (`device_user_id`, old and new). Not audited (system writes): the nightly close and the rebuilds, including the close finalising a manual row still open, and punch ingestion (it has `device_punches` and `device_logs`). |
| `leave`        | Leave type created, updated, deleted (`Leave type created` ...: name, yearly quota, paid flag, carry-forward flag and cap, active flag, system flag, colour, sort order; logged by the model). The four default types are seeded by a data migration with no entry (a system write). Leave request entries (subject the employee, properties `request` = the request id, `old` / `attributes` for edits) are listed here as each leave action is added: `Leave requested` / `Leave requested on behalf` (causer = who applied; `request`, `type`, `from`, `to`, `half`, `days`, `reason`), `Leave request cancelled` (an open request withdrawn by the requester or HR / Admin; `request`, `type`, `from`, `to`, `half`, `days`, `step` = open; when HR / Admin withdrew someone else's request also `on_behalf` = true and `note` = the reason, or "Withdrawn by {name}.", which is mailed to the employee). Decisions (causer = who decided; `request`, `type`, `from`, `to`, `half`, `step` = manager / final, `note`): `Leave approved by manager` (the request now waits for the final step), `Leave rejected` (`note` = the reason), `Leave approved` (the final approval, also a manager approval that is final with the HR step off (`final` = true); `days`, `dates` written, `recounted` {old, new} when holidays or shifts changed the count since applying, `balance_before` per year for paid types, `manager_skipped` when HR decided a request still waiting for the manager, `decided_by_applicant` when HR who applied on the employee's behalf approved it, `own_request` when the only active Admin decided their own request). HR cancelling approved leave: `Leave cancelled` (no day left) / `Leave partly cancelled` (`step` = cancel, `cancel_from`, `removed` dates, `refunded` days, `kept_locked` dates in a locked payroll month, `manual` = removed dates whose attendance row was corrected by hand (it keeps its leave status; shown on the request page until changed), `days` left (0 when none), `own_request` = true when the only active Admin cancelled their own leave, `note` = the reason). Balances: `Leave balance adjusted` (subject the employee, causer = HR / Admin; `year`, `type`, `type_id`, `reason` ("Opening balance import" for the import), `old` / `attributes` of allocated, adjustment and opening used; nothing when no figure changes), `Leave opening balances imported` (no subject; counts `rows`, `changed`, `unchanged`, `overdrawn`, `years`), `Leave balances exported` (no subject; `year`, `rows`, `filters`), `Leave allocated` (the hourly allocation job, no subject, causer System; `year`, `rows` created; only when it created rows), `Leave allocation recalculated` (a joining-date change re-pro-rated an untouched balance row; subject the employee; `year`, `type`, `type_id`, `old` / `attributes` allocated). Holidays and employment changes (Task 8; causer = who changed the holiday or the employee, subject the employee, `request`, `type`, `from`, `to`, `half`): `Leave day refunded (holiday)` (a holiday added on, or moved onto, an approved leave day; `date`, `holiday`, `refunded` days, `days` left (0 when none; the request itself keeps its original days for display), `cancelled` = true when no day was left, `manual` = [the date] when that person's attendance row on it was corrected by hand), `Leave days recounted (holiday)` (an open request covering the new holiday counted again; `date`, `holiday`, `old` / `attributes` days; when every day became an off or a holiday the request is cancelled by the system instead: `old` = {days, status}, `attributes` = {status: cancelled}, `cancelled` = true; it keeps its days for display), `Leave day refunded (weekly off)` (a shift's weekly offs changed, another default shift, or the employee moved to another shift, and approved leave days from their first unclosed attendance day are now weekly offs; causer = who saved; `removed` dates, `refunded` days, `days` left (0 when none), `cancelled` = true when no day was left (cancel reason "Every day became a weekly off."), `kept_locked`, `manual`, `automatic` = true), `Leave trimmed to employment` (the employment window shrank; `automatic` = true, `outcome` = cancelled / partly_cancelled / trimmed_start / open_cancelled, `removed` dates, `refunded` days, `kept_locked` dates in a locked payroll month, `manual` = removed dates corrected by hand, `days` left (0 when none; on `open_cancelled` the request's days and `step` = open), `reason` = "Employment ends {date}." / "Joining date moved to {date}."). Removing a holiday writes no leave entry (nothing is added back). |
| `payroll`      | Salary data (salary profiles, loans and advances, opening figures, payroll runs and lines, payslips, payroll exports). Conventions: written with `Audit::record('payroll', ...)` inside the same transaction as the change; subject = the employee for per-person records (profiles, loans, skips, repayments, opening figures) and the `PayrollRun` for run-level entries (generate, recalculate, line inputs, approve, paid, delete, exports); properties carry `old` / `attributes` for edits, every amount in paisa plus a formatted copy (`Money::format`, e.g. `"Rs 64,930"`); bank account numbers stay masked (`account_number` in `masked_attributes`). Entries are listed here as each payroll action is added (Phase 5 Tasks 4-11). Salary profiles (subject the employee, causer = Admin / HR, `profile` = the profile id): `Salary profile created` (`attributes`), `Salary profile updated` (`old` / `attributes`; nothing when nothing changes; components are replaced wholesale), `Salary profile deleted` (`old`); each snapshot holds `effective_from`, `basic` + `basic_text`, `tax_mode`, `tax_value` (paisa for fixed, basis points for percentage), `tax_base`, `tax_text` ("5% of gross taxable"), `note` and `components` [{name, type, amount, is_taxable, amount_text}]. Payslips (subject the employee, causer = Admin / HR): `Payslip resent` (`run` = the run id, `month`, `item` = the payroll item id; the email is queued again whatever `payslip_emailed_at` says). Generating and emailing payslips after approval and downloads write no entry. Exports (causer = who downloaded; counts only, never a figure or an account number; written before the file is built): `Bank sheet exported` (subject the run; `month`, `rows` = transfer rows, `outside_rows` = lines paid outside the sheet), `Payroll summary exported` (subject the run; `month`, `status`, `rows`), `Tax report exported` (no subject; `tax_year`, `label` "2026-27", `rows`). |

There is no separate `salary` area: salary figures are recorded in `payroll`, and only Admins (`audit.view`) read the log.

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
- Salary figures are recorded only in the `payroll` area (visible to Admin only through this viewer), never in another area's entry.

`AuditLogAction` replaces the value of any property whose name ENDS in the word `password`, `token`, `secret`, `hash`, `codes`, `passkey`, `credential(s)`, `remember` or `api_key` (words split on `_`, `.` and camelCase, so `reset_token` is hidden and `token_count` is not) with `[hidden]` before saving. That is a safety net, not a substitute for the allow-list.

## Not audited here

Machine requests to `/iclock` have their own `device_logs` table and run no audit middleware; punch ingestion writes no audit entry. One exception: the first contact of a serial listed in `HRM_DEVICE_SERIALS` creates its device row, and that writes a "Device created" activity entry (area `attendance`) with no causer (shown as System).

## Viewer query string

`GET /audit-log`: `user_id` (a user id, or `system`), `area` (an area key), `from` and `to` (`YYYY-MM-DD`, whole days in the company display timezone, both ends inclusive), `search` (description, at most 100 characters), `per_page` (10, 25 or 50), `page`. Invalid values are ignored; a page past the end redirects to the last page. Newest first, ties broken by id.
