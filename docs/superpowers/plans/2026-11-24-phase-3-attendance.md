# Lumino HRM Phase 3 (Attendance) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Punches from the ZKTeco MB20 are stored once each, turned into a daily attendance record per employee, and shown on daily, monthly and personal screens, with shifts, manual corrections, correction requests and dashboard numbers.

**Architecture:** Three layers kept apart. (1) **Ingest**: the `/iclock` endpoints from Phase 0 now store each punch line in `device_punches` and only then acknowledge it. (2) **Calculate**: a pure `DayCalculator` turns one employee's punches for one day, plus their shift, holidays and settings, into an attendance result; a rebuild service writes it to `attendances` unless that day was corrected by hand. (3) **Show**: screens read `attendances` only. Receiving a punch never calculates anything; it marks the employee-day as needing a rebuild, and a scheduled job does the work.

**Tech Stack:** As Phases 0–2 (Laravel 13, PHP 8.4, Inertia + React + TypeScript, Tailwind 4, shadcn/ui, spatie permission + activitylog, maatwebsite/excel, Pest, Vitest), plus Recharts through the shadcn chart component for the dashboard chart.

**Spec:** `docs/superpowers/specs/2026-11-23-lumino-hrm-design.md` — sections 8 (roles), 9.1, 9.3, 9.9 (ZKTeco device), 10 (integration), 11 (Attendance group), 13 (risks). Machine-link facts: `docs/decisions/2026-11-phase-0-machine-link.md`.

**Builds on:** Phase 0–1 reports in `.superpowers/sdd/2026-11-23-phase-0-1-proof-and-foundation/` (task-2 `/iclock` logging endpoints and `device_logs`; task-7 settings store and the attendance defaults; task-9 audit convention; task-10 scheduler; final-fix-report for the rejected-request cap) and Phase 2 reports in `.superpowers/sdd/2026-11-24-phase-2-employees/` (task-1 `Employee`, `EmployeeAccess`, `ListQuery`; task-2 list conventions and `DataTable` grid/empty props; task-3 `EmployeeRules`, the employee form; task-4 profile tabs; task-6 Excel export helpers `TextCells`; task-8 dashboard controller and tiles).

## Owner instructions that bind every task

- **No live uploads.** Everything is built and tested on localhost. No deploy step, no hosting tool, no `scripts/deploy.ps1`. The real MB20 stays on BioTime; punches are simulated locally (Task 7 adds the simulator).
- **Focused tests.** While working, run only the test files covering the change. One full PHP + Vitest run at the end of a task only when the task changes shared code; otherwise the controller runs the full suite once at the end of the phase.
- **Subagents:** Opus 5.5 at medium effort by default; a cheaper model for mechanical work.

## Global Constraints

- All Phase 0–2 Global Constraints still apply (PHP at `C:\php84\php.exe`; no shell-outs; product name "Lumino HRM"; the reference product's name never in code or copy; reserved URL prefixes; never ports 8000 or 8173; never stop a process you did not start; commit by explicit path).
- **One time authority.** Punch times are the machine's local wall-clock times and are stored exactly as received, as naive `DATETIME` values (no zone conversion on the way in or out). "Which calendar day is it" and "now" for attendance come from `settings('company.timezone')` (default `Asia/Karachi`) through one helper, `AttendanceClock`. The handshake's `TimeZone=` value is derived from that zone's current UTC offset. Nothing in attendance uses the browser's zone or raw `now()`.
- **A punch is stored once and acknowledged only after it is stored.** Unique key (`device_id`, `device_user_id`, `punch_time`). A re-sent punch is not an error and counts as acknowledged. If the database write fails, the reply is not `OK`, so the machine re-sends.
- **Ingest stays cheap and separate.** The `/iclock` routes keep no session, cookies, CSRF or auth, and reply in plain text exactly as in Phase 0 for every existing test. Ingest writes punches and a "needs rebuild" marker; it never computes attendance.
- **Manual wins.** A day with `is_manual = true` is never overwritten by a rebuild. Clearing the manual flag is an explicit action.
- **Permissions (already seeded, no new names):** `attendance.view-all` (admin, hr), `attendance.view-team` (admin, hr, manager), `attendance.edit` (admin, hr), `settings.manage` (admin: device settings), `settings.attendance-leave` (admin, hr: attendance rules, already built). Everyone sees their own attendance. A Manager sees direct reports only (`EmployeeAccess`).
- **Statuses** (exact values): `present`, `late`, `half_day`, `absent`, `on_leave`, `holiday`, `weekend`. Markers stored as minutes: `late_minutes`, `early_minutes`, `overtime_minutes`. `on_leave` is produced only when the Leave phase supplies approved leave; this phase leaves the hook and never produces it.
- **Rules** (settings keys from Phase 1; shift values override the defaults per shift):
  - First punch of the day = clock-in, last = clock-out, punches between are kept but ignored.
  - Late: clock-in after shift start + grace minutes. `late_minutes` = minutes after shift start.
  - Early departure: clock-out before shift end. `early_minutes` = minutes before shift end.
  - Half day: worked hours below the shift's half-day threshold.
  - Overtime: minutes worked after shift end, recorded only when at least `attendance.overtime_min_minutes`.
  - One punch only: clock-out missing. Counts as Present (or Late), `needs_attention = true`, flagged only once the day has been closed.
  - No punches on a working day: Absent, set by the nightly close, never for today.
  - Weekly off day or holiday: status `weekend` / `holiday`; if punches exist the times are recorded and all worked minutes count as overtime.
  - Attendance-exempt employee: Present on every working day, no late/early/absent, punches still recorded if any.
  - No record before the joining date or after the last working day; none for `inactive`, `resigned`, `terminated` employees after the status change date.
- **Status precedence for one day:** manual > holiday > weekend > (exempt → present) > punches → half_day / late / present > absent.
- Night shifts crossing midnight are out of scope: a shift's end must be after its start on the same day.
- Money: none. Payroll reads `attendances` in Phase 5.
- Audit (area `attendance`): manual corrections (old/new times and status, reason), correction requests and their decisions, shift create/update/delete, device add/remove/command, holiday changes, attendance export. Punch ingestion is not audited (it has `device_punches` and `device_logs`).
- Every commit message ends with: `Co-Authored-By: Claude Fable 5.2 <noreply@anthropic.com>`

## Review Focus

1. **The machine re-sends, sends out of order, sends a punch for an unknown user, or sends while a rebuild runs** → every punch stored exactly once, none lost, the reply always matches what was stored. Tests in Task 2.
2. **A punch arrives for a day that HR already corrected by hand, or for a past, closed day** → the manual record is untouched; a closed non-manual day is rebuilt. Tests in Task 3.
3. **Edges of the day rules:** clock-in exactly at start + grace; one punch; two punches a minute apart; punches on a weekend or holiday; an employee who joined mid-month; a shift changed mid-month; an exempt employee. Tests in Task 3.
4. **A Manager or Employee asks for someone else's attendance, punches or correction by changing an id or a date in the URL** → 403/404, nothing leaked. Tests in Tasks 5, 6, 8, 9.
5. **Day boundaries and time zones:** a punch at 23:59 and at 00:01; the nightly close running late or twice; the server clock in UTC while the company zone is Asia/Karachi. Tests in Tasks 2, 3.

---

## File map

| Path | Responsibility |
|---|---|
| `database/migrations/*_create_attendance_tables.php` | `shifts`, `devices`, `device_punches`, `device_commands`, `attendances`, `attendance_corrections`, `holidays`, `employees.shift_id`, `employees.attendance_exempt` |
| `app/Models/{Shift,Device,DevicePunch,DeviceCommand,Attendance,AttendanceCorrection,Holiday}.php` | Records |
| `app/Support/Attendance/AttendanceClock.php` | The one source of "today" and "now" in the company zone |
| `app/Support/Attendance/DayCalculator.php` (+ `DayInput`, `DayResult`) | Pure day rules |
| `app/Support/Attendance/AttendanceRebuilder.php` | Loads inputs, calls the calculator, writes `attendances` |
| `app/Support/Attendance/PunchIngestor.php` | Parses ATTLOG lines, stores punches, marks days for rebuild |
| `app/Http/Controllers/Iclock/IclockController.php` | Machine endpoints (extended) |
| `app/Console/Commands/{RebuildAttendance,CloseAttendanceDay,SimulatePunches}.php` | Scheduled jobs and the local simulator |
| `app/Http/Controllers/Attendance/{DailyController,MonthlyController,PunchController,ShiftController,CorrectionController,MyAttendanceController}.php` | Screens |
| `app/Http/Controllers/Settings/DeviceController.php` | ZKTeco device settings |
| `app/Http/Controllers/HolidayController.php` | Holiday calendar |
| `app/Exports/MonthlyAttendanceExport.php` | Excel |
| `resources/js/pages/attendance/{daily,monthly,punches,shifts,corrections}.tsx`, `my/attendance.tsx`, `holidays/index.tsx`, `hrm-settings/devices.tsx` | Pages |
| `resources/js/components/attendance/*` | Status symbol, legend, day cell, correction dialog |

---

### Task 1: Schema, models, clock and shifts data

**Files:** the migration; the seven models with factories; `AttendanceClock`; `config/hrm.php` (attendance enums); modify `Employee` (relation `shift()`, cast `attendance_exempt`), `EmployeeRules` / the employee form request (accept `shift_id`, `attendance_exempt`), `EmployeeProfileResource` (Employment group: shift name, exempt flag for Admin/HR), `tests/Pest.php` helpers.
**Tests:** `tests/Feature/Attendance/SchemaTest.php`, `tests/Unit/Attendance/AttendanceClockTest.php`.

**Interfaces — Produces:**
- `shifts`: `id`, `name` (unique), `start_time` (TIME), `end_time` (TIME), `grace_minutes` (smallint), `half_day_hours` (decimal 4,2), `weekly_offs` (JSON array of lowercase day names), `is_default` (bool), timestamps. Exactly one default shift; a data migration creates "General" from the Phase 1 settings (`attendance.shift_start`, `shift_end`, `grace_minutes`, `half_day_hours`, `weekly_offs`). An employee with `shift_id` null uses the default shift.
- `devices`: `id`, `name`, `serial_no` (unique), `is_active` (bool), `last_seen_at` (nullable datetime, app time), `last_stamp` (nullable string), `last_punch_at` (nullable naive datetime), timestamps.
- `device_punches`: `id`, `device_id` (FK restrict), `device_user_id` (string 20), `employee_id` (nullable FK, null on delete), `punch_time` (naive DATETIME), `punch_date` (DATE, derived from `punch_time`, indexed), `verify_type` (nullable string 10), `status_code` (nullable string 10), `source` (`device|import|manual`), `created_at`. Unique (`device_id`, `device_user_id`, `punch_time`). Index (`employee_id`, `punch_date`), (`device_user_id`).
- `device_commands`: `id`, `device_id`, `command` (text), `status` (`queued|sent|done|failed`), `sent_at`, `result` (nullable text), `created_by`, timestamps.
- `attendances`: `id`, `employee_id` (FK restrict), `date` (DATE), `shift_id` (nullable, the shift used), `clock_in`, `clock_out` (nullable naive DATETIME), `worked_minutes`, `late_minutes`, `early_minutes`, `overtime_minutes` (unsigned smallint, default 0), `status` (enum string), `is_manual` (bool), `needs_attention` (bool), `needs_rebuild` (bool), `closed` (bool), `note` (nullable string 255), timestamps. Unique (`employee_id`, `date`). Index (`date`, `status`), (`needs_rebuild`).
- `attendance_corrections`: `id`, `employee_id`, `date`, `requested_in`, `requested_out` (nullable TIME), `reason` (string 500), `status` (`pending|manager_approved|approved|rejected|cancelled`), `manager_id`, `manager_decided_at`, `hr_id`, `hr_decided_at`, `decision_note`, timestamps.
- `holidays`: `id`, `name`, `date` (DATE, unique), timestamps.
- `employees.shift_id` (nullable FK, null on delete), `employees.attendance_exempt` (bool, default false).
- `AttendanceClock`: `zone(): DateTimeZone` (from `settings('company.timezone')`, falling back to `config('app.timezone')` when unusable), `now(): CarbonImmutable` (wall-clock in that zone), `today(): CarbonImmutable` (date at 00:00 in that zone), `utcOffsetHours(): int|float` (for the handshake), `toDateString(DateTimeInterface|string $naive): string`.
- `Shift::forEmployee(Employee $e): Shift` (own or default); `Shift::default(): Shift`.
- `config('hrm.attendance.statuses')`, `.correction_statuses`, `.punch_sources`.
- Test helpers: `shiftFor(...)`, `punch(Employee|string $who, string $at, ?Device $device = null): DevicePunch`.

**Key tests:** migration on SQLite and (once, locally) MariaDB including `migrate:fresh`; default shift created from settings and never duplicated; unique punch key; `punch_date` always equals the date part of `punch_time`; `AttendanceClock` with the server in UTC and the company zone Asia/Karachi just before and after local midnight, and with an unusable setting; the employee form accepts and stores shift and exempt flag; a Manager's profile props for a report do not gain anything restricted.

- [ ] Tests → implement → commit `feat: attendance schema, clock and default shift`.

---

### Task 2: Punch ingestion on `/iclock`

**Files:** `PunchIngestor`; modify `IclockController`, `routes/iclock.php` (no new routes needed), `config/hrm.php`.
**Tests:** extend `tests/Feature/Iclock/IclockEndpointsTest.php` (every Phase 0 test must still pass unchanged); add `tests/Feature/Iclock/PunchIngestTest.php`, `tests/Unit/Attendance/PunchIngestorTest.php`.

**Interfaces — Produces:**
- Device registration: a serial is accepted when an **active** `devices` row has it. Bootstrap: a serial listed in `HRM_DEVICE_SERIALS` with no row gets a row created on first contact (name "MB20 (<serial>)"). Unknown serials keep the Phase 0 rejection, cap and logging.
- Handshake reply: same lines as Phase 0, except `ATTLOGStamp=` carries `devices.last_stamp` when set (else `None`), and `TimeZone=` comes from `AttendanceClock::utcOffsetHours()`.
- `POST /iclock/cdata?table=ATTLOG`: `PunchIngestor::ingest(Device $device, string $body, ?string $stamp): IngestResult { int $stored, int $duplicates, int $skipped }` inside ONE transaction: parse each line (PIN, `Y-m-d H:i:s`, status, verify; extra fields ignored; malformed lines skipped and counted); insert-or-ignore on the unique key; set `employee_id` by matching `device_user_id` to `employees.device_user_id`; upsert `attendances(needs_rebuild = true)` rows for each affected employee-day (create a bare row when none exists; never touch other columns of an existing row); update `devices.last_seen_at`, `last_punch_at`, `last_stamp`. Reply `OK: <stored + duplicates>\n` only after commit. On any exception: roll back, reply `ERROR: retry\n` with HTTP 500-class status **as plain text**, log the exception class.
- Punch times in the future by more than 24 h or before 2000-01-01 are skipped (clock faults), counted, and written to `device_logs.summary`.
- `GET /iclock/getrequest`: returns the oldest `queued` command for the device as `C:<id>:<command>\n` and marks it `sent`; otherwise `OK\n`. `POST /iclock/devicecmd`: parses `ID=<id>&Return=<code>…` lines and marks the command `done` (Return 0) or `failed`.
- `OPERLOG` uploads: stored in `device_logs` only (as Phase 0); reply `OK\n`.
- When an employee's `device_user_id` is set or changed (employee form), unmatched punches with that id attach to the employee and their days are marked for rebuild; punches previously attached through the old id are detached (`employee_id = null`) and their days marked too. `PunchIngestor::relink(Employee $e, ?string $oldId): void`, called from the employee update action.

**Key tests:** the same body sent twice stores once and both replies say `OK: n`; lines in reverse time order; a batch mixing new, duplicate and malformed lines; unknown user id stored with `employee_id` null; relink attaches old punches and marks days; a forced insert failure replies with the error text and stores nothing (then a retry stores everything once); two concurrent requests with overlapping lines; punches at 23:59:59 and 00:00:00 land on different `punch_date`s; handshake stamp round-trip; a registered-by-env serial creates its device row once; an inactive device is rejected; command queue lifecycle; no cookies or session on any reply; a punch for a manually corrected day marks nothing destructive (the row keeps `is_manual`).

- [ ] Tests → implement → commit `feat: store machine punches with idempotent acknowledgement`.

---

### Task 3: Day calculator, rebuild and nightly close

**Files:** `DayInput`, `DayResult`, `DayCalculator`, `AttendanceRebuilder`, `RebuildAttendance` (`hrm:attendance-rebuild`), `CloseAttendanceDay` (`hrm:attendance-close`), `routes/console.php` (two schedule entries in their own block).
**Tests:** `tests/Unit/Attendance/DayCalculatorTest.php` (table-driven), `tests/Feature/Attendance/RebuildTest.php`, `tests/Feature/Attendance/CloseDayTest.php`.

**Interfaces — Produces:**
- `DayInput` (readonly): `date`, `shiftStart`, `shiftEnd`, `graceMinutes`, `halfDayHours`, `weeklyOffs`, `isHoliday`, `isExempt`, `punches` (sorted list of naive datetimes), `closed` (bool: the day is over), `overtimeMinMinutes`, `onLeave` (bool, always false in this phase).
- `DayResult` (readonly): `status`, `clockIn`, `clockOut`, `workedMinutes`, `lateMinutes`, `earlyMinutes`, `overtimeMinutes`, `needsAttention`.
- `DayCalculator::calculate(DayInput): ?DayResult` — pure, no I/O, no clock. Returns null when there is nothing to record yet (an open working day with no punches).
- `AttendanceRebuilder::rebuild(Employee $e, string $date): void` — skips when the row `is_manual`; skips dates before joining or after the last working day (deleting a non-manual row there); loads the shift in force (`Shift::forEmployee`), the holiday flag, the exempt flag, that day's punches; writes the result and clears `needs_rebuild`; sets `closed` when `date < AttendanceClock::today()`. `rebuildPending(int $limit = 500): int` processes rows with `needs_rebuild` oldest first.
- `hrm:attendance-rebuild` — every minute, `withoutOverlapping`: `rebuildPending()`.
- `hrm:attendance-close {date?}` — daily at 00:30 company time (also safe to run again, and for an explicit past date): for every employee who should have a record on that date and has none, create it (Absent, Weekend, Holiday, or Present for exempt); mark the day's rows `closed`; set `needs_attention` on single-punch days; then `rebuildPending`. Idempotent. A catch-up loop closes any of the previous 7 days that were never closed.
- Rule details (pin these in the unit test table): late when clock-in is strictly after start + grace; `late_minutes` counted from shift start; worked minutes = clock-out − clock-in (0 with one punch); half day when worked minutes < half-day hours × 60 and there are two punches; with one punch the status is `present` or `late` and `needs_attention` only when `closed`; overtime = max(0, clock-out − shift end) recorded only if ≥ `overtimeMinMinutes`; on a weekend/holiday with two punches overtime = worked minutes; exempt + working day = `present` with zero markers; holiday beats weekend in the status when both apply.

**Key tests:** the full rules table including every edge in Review Focus 3; rebuild keeps a manual row byte-for-byte; a late punch for a closed day is rebuilt; joining mid-month produces no rows before the joining date; changing an employee's shift rebuilds only future/pending days (past closed days keep the stored `shift_id` and values unless explicitly rebuilt with `hrm:attendance-rebuild --from --to --employee`); close run twice changes nothing; close with the server in UTC picks the right local date; the 7-day catch-up; 55 employees × 31 days closes within a reasonable query budget (assert query count is linear with a small constant, not per-employee-per-day lookups).

- [ ] Tests → implement → commit `feat: attendance day rules, rebuild and nightly close`.

---

### Task 4: Shifts and holidays screens

**Files:** `ShiftController`, `HolidayController`, requests, `resources/js/pages/attendance/shifts.tsx`, `resources/js/pages/holidays/index.tsx`, employee form (shift select + "Attendance exempt" checkbox on the Employment tab), `navigation.ts`.
**Tests:** `tests/Feature/Attendance/ShiftTest.php`, `tests/Feature/HolidayTest.php`.

**Interfaces — Produces:** routes `attendance.shifts.index|store|update|destroy|make-default` under `/attendance/shifts` (`attendance.edit`), `holidays.index|store|update|destroy` under `/leave/holidays` (`settings.attendance-leave`). Shift rules: name unique; end after start; grace 0–120; half-day hours 1–12; weekly offs a subset of day names; the default shift cannot be deleted; a shift used by employees cannot be deleted ("Move this shift's employees first."); changing a shift's times marks today and future pending days of its employees for rebuild, not closed days. Holiday rules: unique date; adding, moving or removing a holiday marks that date's non-manual rows for rebuild. Navigation: Attendance → Shifts and Leave → Holidays set `ready: true`.

**Key tests:** role matrix on every route; each rule; rebuild marking; the employee form saving shift and exempt flag (HR allowed, Manager not).

- [ ] Tests → implement → screenshots → commit `feat: shifts and holiday calendar`.

---

### Task 5: Daily attendance and manual correction

**Files:** `DailyController`, `ManualCorrectionRequest`, `resources/js/pages/attendance/daily.tsx`, `components/attendance/{status-symbol,correction-dialog}.tsx`, `navigation.ts`.
**Tests:** `tests/Feature/Attendance/DailyTest.php`.

**Look:** title "Daily Attendance", a date picker with previous/next day and "Today", summary chips (Present, Late, Half Day, Absent, On Leave, Holiday/Weekend, Needs attention), filters (search, department, status, "Needs attention only"), table: employee (avatar, name, code), shift, clock-in, clock-out, worked, late, early, overtime, status pill, a "manual" marker, and an Edit action for those allowed.

**Interfaces — Produces:** `attendance.daily` (`GET /attendance/daily?date=`; `attendance.view-all` or `attendance.view-team`, scoped by `EmployeeAccess`; dates in the future are refused; default today). `attendance.daily.update` (`PUT /attendance/daily/{employee}/{date}`; `attendance.edit`): fields `clock_in`, `clock_out` (times on that date, optional), `status` (optional override among the statuses except `on_leave`), `reason` (required, 5–255). Saves `is_manual = true`, recalculates the minute fields from the entered times with `DayCalculator` unless a status override is given, writes the audit entry in the same transaction. `attendance.daily.reset` (`DELETE …`): clears the manual flag and rebuilds from punches (audited). Employees who should have no record that day are not listed.

**Key tests:** scope per role (a Manager sees reports only; an Employee 403); summary counts match the scope; correction recalculation and override; reason required; future date refused; reset restores the punch-based values; the audit entry holds old and new values; ids/dates in the URL for people outside scope → 403.

- [ ] Tests → implement → screenshots → commit `feat: daily attendance with manual correction`.

---

### Task 6: Monthly grid and Excel export

**Files:** `MonthlyController`, `MonthlyAttendanceExport`, `resources/js/pages/attendance/monthly.tsx`, `components/attendance/{day-cell,legend}.tsx`, `navigation.ts`.
**Tests:** `tests/Feature/Attendance/MonthlyTest.php`, `MonthlyExportTest.php`.

**Look (reference attendance records page):** month and year pickers, department filter, search; a legend (✓ Present, L Late, ½ Half Day, ✕ Absent, 🚩 On Leave, ★ Holiday, ⊘ Weekend, – Not yet / no record, plus dot markers for Early and Overtime and a ring for "manual"); a grid of employees × days with a sticky first column (avatar, name, designation), day number and weekday in the header, and a totals column "present-equivalent / working days" (half day counts 0.5). Hovering or focusing a cell shows times and minutes. Clicking a cell (for those with `attendance.edit`) opens the Task 5 correction dialog.

**Interfaces — Produces:** `attendance.monthly` (`GET /attendance/monthly?month=YYYY-MM`; same permissions and scope as daily; paginated by employee, 25 per page). `attendance.monthly.export` (`GET /attendance/monthly/export?month=`; `attendance.view-all`): `.xlsx`, one row per employee, one column per day with the status letter, then totals (present, late, half day, absent, weekend/holiday, worked hours, late minutes, overtime minutes); string cells, formula-safe; audited. One query for the month's rows (no per-cell queries).

**Key tests:** scope; correct symbols for a seeded month; totals; month boundaries (28/29/30/31 days); future months refused; export contents and permission; query count independent of the number of days.

- [ ] Tests → implement → screenshots → commit `feat: monthly attendance grid and export`.

---

### Task 7: Raw punches, device settings and the local simulator

**Files:** `PunchController`, `Settings/DeviceController`, `SimulatePunches` (`hrm:simulate-punches`), `resources/js/pages/attendance/punches.tsx`, `resources/js/pages/hrm-settings/devices.tsx` (a new "ZKTeco Device" group in the Settings screen), `navigation.ts`, `docs/attendance-local-testing.md`.
**Tests:** `tests/Feature/Attendance/PunchesTest.php`, `tests/Feature/Settings/DeviceSettingsTest.php`, `tests/Feature/Console/SimulatePunchesTest.php`.

**Interfaces — Produces:**
- `attendance.punches` (`GET /attendance/punches`; `attendance.view-all`): every punch, newest first, filters (date range, device, employee, "Unknown IDs only"), columns time, machine user id, employee (or an "Unknown" pill), device, verify type, source. `attendance.punches.assign` (`POST`; `attendance.edit`): set `device_user_id` on a chosen employee for an unknown id (through the employee update path, so relink and audit happen).
- Settings → ZKTeco Device (`settings.manage`): list devices with status (online if `last_seen_at` within 5 minutes), last contact, last punch, punches today; add (name + serial), rename, activate/deactivate, remove (only when it has no punches); "Unknown user IDs" list with counts; "Sync log" = last 7 days of `device_logs` for the device (time, type, summary); "Re-send punches" queues the command `DATA QUERY ATTLOG StartTime=<from>\tEndTime=<to>` for a date range (max 31 days). A prune entry keeps `device_logs` for 7 days (rejected rows too).
- `hrm:simulate-punches {--date=today} {--employees=all} {--late=10} {--absent=5} {--missing-out=3} {--serial=}`: **refuses to run unless `APP_ENV` is `local` or `testing`**. Builds realistic ATTLOG lines for employees that have a `device_user_id` (assigning sequential ids to those without, on request with `--assign-ids`) and POSTs them through the real `/iclock/cdata` handler in-process, so the whole path is exercised. Prints what it sent. `docs/attendance-local-testing.md` explains how to try attendance on localhost without the machine (simulate, then run `hrm:attendance-rebuild` and `hrm:attendance-close`), and how to point the real MB20 at a test server later.

**Key tests:** role matrix; filters; assign unknown id relinks and rebuilds; device add/remove rules; online/offline status; command queued and picked up by `getrequest`; simulator refuses in production env, produces stored punches and correct day statuses after rebuild.

- [ ] Tests → implement → screenshots → commit `feat: raw punches, device settings and punch simulator`.

---

### Task 8: Correction requests

**Files:** `CorrectionController`, requests, `resources/js/pages/attendance/corrections.tsx`, a "Request correction" dialog on My Attendance (Task 9 mounts it), `navigation.ts`, mail notifications.
**Tests:** `tests/Feature/Attendance/CorrectionRequestTest.php`.

**Interfaces — Produces:** `attendance.corrections.index` (HR/Admin: all; Manager: own team's; with tabs Pending, Approved, Rejected), `attendance.corrections.store` (any employee, for their own record, a past date within the last 31 days, not a day already manually corrected, one open request per day), `…manager-decide` (the reporting manager, or skipped when the employee has no manager or the manager is the requester: then HR decides directly), `…hr-decide` (`attendance.edit`): approving applies the requested times through the Task 5 manual-correction code path (so `is_manual`, recalculation and audit are identical) with the note "Correction request #id"; `…cancel` (the requester while pending). Emails (queued): to the approver on submit, to the requester on decision.

**Key tests:** the whole flow for employee→manager→HR and for the no-manager path; nobody can decide their own request; a Manager cannot see or decide requests outside their team; approval writes the same result as a manual correction; duplicates and out-of-window dates refused; cancellation rules; mails queued to the right people only.

- [ ] Tests → implement → screenshots → commit `feat: attendance correction requests`.

---

### Task 9: My Attendance, dashboard numbers and profile tab

**Files:** `MyAttendanceController`, `resources/js/pages/my/attendance.tsx`, `DashboardController` + `dashboard.tsx` + `lib/dashboard.ts`, `EmployeeProfileResource` + profile view (an "Attendance" tab), `navigation.ts`.
**Tests:** `tests/Feature/MyAttendanceTest.php`, `tests/Feature/DashboardAttendanceTest.php`, Vitest for chart data shaping.

**Interfaces — Produces:**
- `my.attendance` (`GET /my/attendance?month=`; any signed-in user with an employee record): month calendar of own days with the same symbols, a summary row (present, late, half day, absent, worked hours, late minutes), the day list with times, and "Request correction" on eligible days.
- Dashboard (scoped like the employee count): tiles Present Today, Absent Today, Late Today with real numbers (today's provisional figures: Absent Today = people with no punch yet who are expected today, labelled "Not in yet" before the day closes); banner line "N present today · last punch received HH:MM"; cards "Late Arrivals Today" (name, clock-in, minutes late) and "Attendance, last 30 days" (stacked bars present/late/absent per day, from closed days); an Employee sees their own month summary instead of the company tiles.
- Profile page: an "Attendance" tab (current month summary + link to the monthly grid filtered to that person) for viewers allowed to see that person's attendance.
- Navigation: Attendance (Daily, Monthly, Correction Requests, Shifts, Raw Punches) and My Attendance set `ready: true` as their pages exist.

**Key tests:** own data only on My Attendance; dashboard numbers per role and scope; "Not in yet" vs Absent around the close; the 30-day series; no data about people outside scope in any props.

- [ ] Tests → implement → screenshots → commit `feat: my attendance, dashboard attendance numbers and profile tab`.

---

### Task 10: Phase check on localhost (controller)

- [ ] One full PHP + Vitest run, type check, build, PHPStan.
- [ ] Walk the flow on `http://localhost:8173` with simulated punches: simulate a day, rebuild, close, correct one day by hand, raise and approve a correction request, export a month, check each role's view.
- [ ] Whole-phase review; fix wave; update `docs/audit-log.md` and the README.
- [ ] **On hold until the owner asks:** uploading to the live site, and the spec's gate ("one full week of HRM attendance matches the machine's own log").

---

## Self-review notes

- Spec 9.3 coverage: punches from the machine → Task 2; first/last punch → Task 3; shifts → Tasks 1, 4; statuses and markers → Task 3; overtime → Task 3; missing clock-out → Tasks 3, 5; manual correction with reason and audit → Task 5; correction requests → Task 8; screens Daily, Monthly, Correction Requests, Shifts, Raw Punches, My Attendance → Tasks 4–9; Excel monthly report → Task 6; exempt employees → Tasks 1, 3; day computed in the company zone → `AttendanceClock`.
- Spec 10 coverage: endpoints and replies → Task 2; registered serials, unknown rejection → Task 2 (Phase 0 cap kept); duplicates ignored and still OK → Task 2; unmatched punches listed and attachable → Tasks 2, 7; receive marks, scheduler rebuilds every minute, 00:30 close → Tasks 2, 3; manual never overwritten → Task 3; device settings screen, unknown IDs, sync log, re-send → Task 7. BioTime history import (10.6) is not in this plan; it needs the owner's decision on whether and from what date.
- Final-review notes from Phase 0–1 addressed here: one time authority; acknowledge after a durable write; dedupe key.
- `on_leave` is a status value now and a hook in `DayInput`; the Leave phase fills it.
