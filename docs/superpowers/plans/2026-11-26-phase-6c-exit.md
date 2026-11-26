# Lumino HRM Phase 6c (Exit) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** an exit runs end to end in the app: resignation or termination, manager then HR approval, the last working day on the employee record (reusing the existing employment-window logic), clearance, a final settlement through the payroll calculator that freezes the person's final month when approved, a statement and an experience letter, and the nightly status change.

**Architecture:** (1) **Records:** `resignations`, `clearances`, `final_settlements`, `final_settlement_lines`, `loan_repayments.final_settlement_id`; money in integer paisa. (2) **Actions** (`App\Actions\Exits`): one transaction each, `DB::transaction(fn, 3)`, employee row first, then resignation, then settlement, then loans; audit area `exit` in the same transaction. Routing in one class (`ExitRouting`), settlement pay through `PayInputLoader` + `PayCalculator` only, the lock in `PayrollLock`. (3) **Screens:** Resignations list, exit page, My Resignation, profile tab, dashboard cards, Settings > Exit.

**Tech Stack:** As Phases 0 to 6b (Laravel 13, PHP 8.4, Inertia + React + TypeScript, Tailwind 4, shadcn/ui, Wayfinder, spatie permission + activitylog, dompdf, Pest, Vitest). No new packages.

**Spec:** `docs/superpowers/specs/2026-11-26-phase-6c-exit-design.md` (wins where it differs) and the main design sections 8, 9.5, 9.8, 9.9, 11; Phase 5 spec for money and the lock.

**Builds on:** `App\Actions\Employees\ChangeEmployeeStatus`, `App\Actions\Leave\LeaveEmploymentTrim`, `App\Support\Attendance\{Leavers, RebuildMarks, AttendanceClock}`, `App\Support\Payroll\{PayInputLoader, PayCalculator, PayResult, PayAdjustment, LoanDue, PayrollRoster, PayrollChecks, PayrollAccess, PayslipPdf, TaxReport, Money}`, `App\Support\PayrollLock`, `App\Support\Leave\{LeaveRouting, WaitingForMe, LeaveBalances}`, `App\Support\Assets\AssetHoldings`, `App\Actions\Assets\AssignAsset`, `HrmSettingsController` + `LettersRequest` + `letters-panel.tsx`, `DashboardController`, `EmployeeProfileResource`, `resources/views/payslip.blade.php`, `routes/console.php`.

## How this phase is run (owner: keep usage low)

- **Four implementer tasks, no more.** Order: **Task 1 first**; then **Task 2 and Task 4 in parallel** (disjoint files; Task 4 builds on the Task 1 models and the stored breakdown contract below, and its tests write rows with the Task 1 helpers); then **Task 3** (it calls the Task 1 and 2 actions; it may start alongside Task 4).
- **Implementers write tests but do NOT run test suites** (no `pest`, no `vitest`, no full or focused runs). They may run `C:\php84\php.exe -l`, `npx tsc --noEmit` and `artisan wayfinder:generate --with-form`. **No per-task review.**
- **One phase-end check** by the controller: one full suite (PHP + Vitest), one browser walkthrough (section "Phase-end check"), **one Opus review of the money and lock parts only** (Task 2 files and the Task 4 tax report change), **one fix round**.
- Each task commits its own files by explicit path when done; the controller runs `artisan migrate` (additive) after Task 1 lands.

## Owner instructions that bind every task

- **No live uploads or deploys.** Localhost only; nothing on `hrm.luminocrm.online`.
- **Branch:** `main` only; never create a branch.
- **Processes and ports:** never stop a process the agent did not start; never use ports 8000 or 8173; never start, stop or restart a server or database.
- **Dev database `lumino_hrm`** holds demo data: never `migrate:fresh`, never truncate, nothing destructive; agents do not run migrations.
- Agents read and follow `.superpowers/sdd/2026-11-26-phase-6c-exit/agent-rules.md` (the controller creates it from the Phase 6b rules, with the "no test runs, no review" rule above); reports go to `.superpowers/sdd/2026-11-26-phase-6c-exit/task-N-report.md`.

## Global Constraints

- All earlier Global Constraints apply (PHP at `C:\php84\php.exe`; commit by explicit path, never `git add -A`; design system and page patterns `PageHeader`, `DataTable`, `StatusPill`, `StatusTabs`, `ConfirmDialog`, `useCan`; phone width, light and dark; naive dates as `Y-m-d`; `attendanceToday()` the only today; props carry formatted strings).
- **Namespaces:** `App\Actions\Exits`, `App\Support\Exits`, `App\Http\Controllers\Exits`, `App\Http\Requests\Exits` (never `Exit`, a PHP keyword). Pages under `resources/js/pages/exit/`.
- **Permissions:** seeded, no new names. `exit.manage` (admin), `exit.clearance` (admin, hr), `exit.approve-team` (admin, hr, manager). Settlement figures also need `payroll.view`. Out of scope is 403.
- **Money:** integer paisa, `Money::div`, `Money::rupees`, `Money::format`; entered amounts whole rupees. `final_settlements.net` is signed; every other money column unsigned.
- **Locks:** employee row `lockForUpdate` first in every exit and settlement write; then resignation row FOR UPDATE; then settlement row FOR UPDATE; then loans FOR UPDATE by id. Re-read and re-check state after the locks.
- **Audit:** `Audit::record('exit', <description>, $subject, [...], $by)` in the same transaction; money as paisa plus `*_formatted`; old and new values.
- **Mail:** none.
- **Shared files** (edit last, small step, `git diff -- <file>` first): `routes/web.php`, `routes/console.php`, `resources/js/config/navigation.ts` (+ test), `docs/audit-log.md`, `tests/Pest.php`, `config/hrm.php`. Only Task 1 edits `config/hrm.php`, `Audit`, `HrmSettingsController`, `ChangeEmployeeStatus`, `tests/Pest.php`. Only Task 2 edits `PayrollLock` and its callers, `PayInputLoader`, `AssignAsset`. Only Task 3 edits `DashboardController`, `EmployeeProfileResource`, profile tab files, `navigation.ts`. Only Task 4 edits `TaxReport`, `PayrollRoster`/`RosterResult`, `routes/console.php`.
- Every commit message ends with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

## Open items (defaults built; see spec section 7)

1 Notice and encashment stay in Settings > Payroll; Settings > Exit holds clearance items and the letter. 2 `{name}` / `{company}` are aliases. 3 Encashment leave type setting, null = "Annual". 4 Encashment taxable; overdrawn leave not deducted. 5 HR-recorded exits start at HR step, "Approve now" ticked. 6 Previous month run gate. 7 Employee sees clearance ticks and the issued letter. 8 Tax override allowed on drafts. 9 After the status change: no cancel, day only to another past date. 10 Settings > Exit Admin only.

---

## Shared interfaces (fixed here so tasks can run in parallel)

- **Models** (Task 1): `Resignation` (`employee()`, `clearances()`, `settlement()` hasOne, `recordedBy()`; casts dates `NaiveDate`; `isOpen()`, `stageLabel()`; `saving` hook keeps `open_employee_id`), `Clearance` (`resignation()`, `clearedBy()`), `FinalSettlement` (`resignation()`, `employee()`, `lines()`, `repayments()`; `breakdown`/`summary` array casts; `isFrozen()` = approved or paid), `FinalSettlementLine`; `Employee::resignations()`, `Employee::openResignation()` (hasOne where `open_employee_id` not null); `LoanRepayment::finalSettlement()`.
- **Stored settlement breakdown** (written by Task 2, read by Task 4): exactly `PayResult::breakdown` (version `PayCalculator::BREAKDOWN_VERSION`) plus a top-level `settlement` key: `{last_working_day, exit_type, encashment: {enabled, type_name, days, amount}|null, lines: [{kind, type, label, amount, is_taxable}], loans: [{loan_id, type, remaining, recovered}], loan_shortfall, owed_by_employee (int, 0 when net >= 0)}`. Figures columns mirror `PayResult::toItemAttributes()`.
- **`config('hrm.exit')`** (Task 1): `types` [resignation, termination]; `statuses` [pending, manager_approved, approved, rejected, withdrawn, cancelled]; `open_statuses` [pending, manager_approved, approved]; `settlement_statuses` [draft, approved, paid]; `frozen_statuses` [approved, paid]; `clearance_kinds` [assets, access, dues, custom]; `line_kinds` [bonus, notice_shortfall, other]; `max_lines` 20; `max_clearance_items` 20; `upcoming_days` 30; `max_days_ahead` 365.
- **Pest helpers** (Task 1, `tests/Pest.php`): `resignationRow(Employee $e, array $attrs = [])`, `approvedExit(Employee $e, string $lastDay, array $attrs = [])` (writes the row, the employee's `last_working_day` and the clearance rows directly), `settlementRow(Resignation $r, array $attrs = [])` (figures default to zero, breakdown with the `settlement` key), `lockSettlement(Employee $e, string $month)` (an approved settlement row for that person-month).
- **Messages** are class constants on the action that raises them; tests assert the exact text.

---

### Task 1: Schema, models, settings and the exit workflow

**Model:** Sonnet (medium).

**Files:** Create `database/migrations/2026_11_26_400000_create_exit_tables.php` (four tables + `loan_repayments.final_settlement_id` nullable FK restrict + unique (loan_id, final_settlement_id)); `database/migrations/2026_11_26_400100_move_letter_template_to_exit_settings.php` (data: settings rows group `letters` → group `exit`, same key; idempotent; down reverses); `app/Models/{Resignation,Clearance,FinalSettlement,FinalSettlementLine}.php` + factories; `app/Support/Exits/{ExitRouting,ExitNotice,ExitStates}.php`; `app/Actions/Exits/{SubmitResignation,RecordExit,DecideExit,WithdrawResignation,ChangeExitLastDay,CancelExit,SeedClearance}.php`; `app/Actions/Employees/EmploymentEnd.php`; `app/Http/Requests/Settings/ExitRequest.php`; `resources/js/components/settings/exit-panel.tsx`. Delete `app/Http/Requests/Settings/LettersRequest.php`, `resources/js/components/settings/letters-panel.tsx`. Modify `config/hrm.php` (the `exit` block; `settings_groups`: `letters` → `exit` (`settings.manage`); `defaults.exit` = `clearance_items` `[]`, `experience_template` (moved text); `defaults.payroll.encashment_leave_type_id` null), `app/Http/Controllers/Settings/HrmSettingsController.php` (REQUESTS, LABELS "Exit", placeholders prop), `app/Http/Requests/Settings/PayrollRequest.php` + `resources/js/components/settings/payroll-panel.tsx` (encashment leave type picker, nullable, must exist and be paid), `resources/js/types/hrm-settings.ts`, `app/Actions/Employees/ChangeEmployeeStatus.php`, `app/Models/{Employee,LoanRepayment}.php`, `app/Support/Audit/Audit.php` (`'exit' => 'Exit'`), `docs/audit-log.md` (an `exit` section), `tests/Pest.php` (helpers), `tests/Feature/Settings/SettingsTest.php` (letters → exit).

**Public signatures:**
- `EmploymentEnd::apply(Employee $locked, ?string $lastWorkingDay, ?User $by): bool` (caller holds the employee row FOR UPDATE inside its transaction; returns whether the day changed); `notices(): list<string>`. Body moved from `ChangeEmployeeStatus` unchanged: range check with `PayrollLock::rangeTouchesLocked` (Task 2 switches it to `rangeTouchesLockedFor`), save, `RebuildMarks::outsideEmployment`, `LeaveEmploymentTrim::apply`.
- `ChangeEmployeeStatus::handle(Employee, string $status, ?string $lastWorkingDay, ?User $by)`: `$by` null = system (skip `mayTouchAccount`; `SetUserActive` with null actor; audit causer none). Uses `EmploymentEnd`. Refuses a day change while `openResignation` exists.
- `ExitRouting` (one instance per request): `managerApprover(Employee): ?User`, `skipsManager(Resignation): bool`, `stage(Resignation): ?string` (`manager` | `final` | null), `mayDecideManager(User, Resignation)`, `mayDecideFinal(User, Resignation)`, `mayRecordFor(User, Employee)`, `mayManage(User, Resignation)` (change day, cancel, clearance, settlement draft: `exit.clearance`, not own, Admin / HR person only by Admin), `scope(Builder<Resignation>, User)`, `findVisible(User, int): ?Resignation`, `waitingFor(User): Builder<Resignation>`.
- `ExitNotice::given(string $submittedOn, string $lastDay): int`, `shortBy(int $required, int $given): int`, `label(Resignation): string` ("30 days notice", "12 days short of notice (30 required)").
- Actions (`handle(...)` returns the fresh `Resignation`): `SubmitResignation::handle(User $by, string $lastDay, string $reason)`; `RecordExit::handle(User $by, Employee $e, string $type, string $lastDay, string $reason, string $submittedOn, bool $approveNow)`; `DecideExit::approve(User $by, Resignation $r)` (manager or final, by stage), `DecideExit::reject(User $by, Resignation $r, string $note)`; `WithdrawResignation::handle(User $by, Resignation $r)`; `ChangeExitLastDay::handle(User $by, Resignation $r, string $lastDay)`; `CancelExit::handle(User $by, Resignation $r, string $reason)` (deletes a draft settlement and its lines, audited); `SeedClearance::for(Resignation $r): void` (called inside the final approval).

**Refusals (exact text):**
- "Only employees with an employee record can resign." / "You already have an open resignation." / "{name} already has an open exit." / "You cannot record an exit for yourself."
- "The last working day cannot be before today." (employee) / "The last working day cannot be before the joining date ({date})." / "The last working day can be at most 365 days ahead."
- "Enter a reason." / "Enter a note to reject this exit." / "This exit is not waiting for your decision." / "You cannot decide your own exit." / "Only an Admin can decide the exit of an Admin or HR person."
- "This resignation can no longer be withdrawn; ask HR." / "This exit has ended and cannot change." / "The final settlement is approved, so this exit can no longer change." / "{name} has already left (status {Status}), so the exit cannot be cancelled." / "The status has already changed, so the last working day can only move to a date before today."
- Profile guard: "This person has an open exit. Change the last working day on the exit page."
- Payroll lock: `PayrollLock::message()` under `last_working_day` (unchanged wording).
- Settings: "Clearance items must be 1 to 80 characters." / "Each clearance item must be different." / "At most 20 clearance items."

**Audit:** `Resignation submitted`, `Exit recorded`, `Exit approved by manager`, `Exit approved` (own flag, employee `last_working_day` old/new), `Exit rejected`, `Resignation withdrawn`, `Exit last working day changed`, `Exit cancelled`, `Settlement draft deleted` (from cancel); plus the reused `Employee status changed` / `Leave trimmed to employment`.

**Tests to write:** `tests/Feature/Exits/SchemaTest.php` (columns, open unique, letters migration moves the stored value), `ExitWorkflowTest.php` (submit / record / manager approve / final approve / reject with note / withdraw / one open exit / every refusal above), `ExitRoutingTest.php` (each skip reason; termination skips; HR-recorded skips; HR person's exit by Admin; sole Admin own decision audited; manager scope only direct reports), `ExitLastDayTest.php` (approval sets `last_working_day` through `EmploymentEnd`: approved leave after the day removed and refunded, open requests cancelled, attendance marks set; locked-month refusal; change day re-trims; cancel clears the day; profile guard), `ChangeEmployeeStatusTest` additions (null actor works; behaviour unchanged otherwise), `ExitSettingsTest.php` (exit group, items list, placeholders incl. aliases, Payroll encashment type).

**Steps:**
- [ ] Migrations, models, factories, config, Audit area, audit-log section.
- [ ] Extract `EmploymentEnd`; point `ChangeEmployeeStatus` at it; nullable actor; profile guard.
- [ ] `ExitRouting`, `ExitNotice`, `ExitStates`; the seven actions.
- [ ] Settings: `ExitRequest`, panel, Payroll picker, controller wiring, data migration.
- [ ] Pest helpers; tests; commit.

---

### Task 2: Clearance, settlement calculation, approve / paid, and the lock

**Model:** Opus (medium).

**Files:** Create `app/Support/Exits/{ClearanceStatus,LeaveEncashment,SettlementCalculator,SettlementResult,SettlementChecks,SettlementAccess}.php`; `app/Actions/Exits/{TickClearanceItem,PrepareSettlement,RecalculateSettlement,SaveSettlementLine,RemoveSettlementLine,OverrideSettlementTax,ApproveSettlement,MarkSettlementPaid}.php`. Modify `app/Support/PayrollLock.php`, `app/Support/Payroll/PayInputLoader.php`, `app/Actions/Assets/AssignAsset.php`, `app/Actions/Employees/EmploymentEnd.php` (switch to `rangeTouchesLockedFor`), and the callers of spec 5.6: `app/Actions/Attendance/{ApplyManualCorrection,CorrectionRequests}.php`, `app/Support/Attendance/{CorrectionEligibility,AttendanceRebuilder,RebuildMarks,PunchIngestor}.php`, `app/Actions/Leave/{ApplyForLeave,LeaveDecisions,LeaveDayRemoval,HolidayLeaveRefund}.php`, `app/Support/Leave/LeaveRouting.php` (`earliestCancellableDate` only), `app/Actions/Employees/UpdateEmployee.php` (joining date check only), `app/Actions/Payroll/{SaveSalaryProfile,SaveLoan,LoanActions}.php`, `app/Http/Controllers/Attendance/{DailyController,MonthlyController}.php`, `app/Http/Controllers/Leave/LeaveRequestController.php` (lock flags only).

**Public signatures:**
- `PayrollLock::isLockedFor(int $employeeId, string $month): bool`; `lockedMonthsFor(int $employeeId, array $months): list<string>`; `firstLockedFor(int $employeeId, iterable $dates): ?string`; `rangeTouchesLockedFor(int $employeeId, string $from, string $to): ?string`; `settledMonths(array $employeeIds, array $months): array<int, list<string>>` (one query); `isSettled(int $employeeId, string $month): bool`; `messageFor(string $month, bool $settled): string`; `settledMessage(string $month): string` = "The final settlement for {Month Year} is approved, so this can no longer change." Each For-method = month-level check OR an approved / paid settlement row; settlement rows read by `employee_id` (+ month) only, status filtered in PHP, `sharedLock()` inside a transaction. Docblock states the lock order employee → payroll_runs → final_settlements.
- `PayInputLoader::load()` item inputs gain optional `window_to` ("Y-m-d" inside the month, on or after the window start) and `loans_in_full` (bool: each loan's installment = remaining, `skipped` false). Defaults leave runs unchanged.
- `ClearanceStatus::for(Resignation): array{items: list<array{id, kind, label, cleared, cleared_by, cleared_at, automatic, held?}>, complete: bool, missing: list<string>}` (assets item live from `AssetHoldings::openFor` until frozen).
- `LeaveEncashment::for(Employee, string $lastDay, array $profileSegment, int $basisDays): ?array{type_name, halves, amount}` (null when the setting is off); `leaveType(): ?LeaveType`.
- `SettlementCalculator::calculate(FinalSettlement $s): SettlementResult` (reads lines, tax override, encashment; calls the loader and the calculator; drops `negative_net` from blocking; computes `loan_shortfall` = remaining total − recovered; returns figures, breakdown with the `settlement` key, warnings, blocking).
- `SettlementChecks::for(FinalSettlement): list<array{key, severity, message}>`, `blocking(FinalSettlement): list<string>` (keys `not_approved_exit`, `last_day_not_passed`, `clearance_incomplete`, `no_profile`, `missing_days`, `missing_clock_out`, `pending_days`, `open_leave`, `previous_month_unpaid`), reusing `PayInputLoader::missingDays` and the same attendance / leave queries as `PayrollChecks`, scoped to one person and window.
- `SettlementAccess::mayView(User, FinalSettlement)`, `mayEdit(User, FinalSettlement)` (`exit.clearance` + `PayrollAccess::mayEditSalary`), `mayApprove(User, FinalSettlement)` (`exit.manage`, own only when `PayrollAccess::soleActiveAdmin`).
- Actions: `TickClearanceItem::handle(User, Clearance, bool $cleared)`; `PrepareSettlement::handle(User, Resignation): FinalSettlement`; `RecalculateSettlement::handle(User, FinalSettlement): FinalSettlement`; `SaveSettlementLine::handle(User, FinalSettlement, array $data)`; `RemoveSettlementLine::handle(User, FinalSettlementLine)`; `OverrideSettlementTax::handle(User, FinalSettlement, ?int $rupees, ?string $reason)`; `ApproveSettlement::handle(User, FinalSettlement): FinalSettlement`; `MarkSettlementPaid::handle(User, FinalSettlement, string $paidOn)`. Every write: employee row FOR UPDATE → resignation → settlement FOR UPDATE → (approve) loans FOR UPDATE by id; never `PayrollLock` on its own settlement row.
- `ChangeExitLastDay` (Task 1) updates a draft settlement's `month` and sets `stale = true` directly; Task 2's `RecalculateSettlement` clears `stale`, and `ApproveSettlement` refuses a stale draft ("The last working day changed. Recalculate the settlement first.").

**Refusals (exact text):**
- "The exit must be approved before a settlement is prepared." / "A settlement already exists for this exit." / "You cannot prepare or edit your own settlement." / "Only an Admin can edit the settlement of an Admin or HR person."
- "This settlement is approved and can no longer change." / "At most 20 lines." / "Enter a label." / "Enter an amount between 1 and 10,000,000 rupees." / "Enter a reason for the tax override."
- Approve: "The last working day has not passed yet." / "Clearance is not complete: {items}." / "{name} has no salary profile for {Month Year}." / "Attendance for the final month is not complete: {n} days still open." / "Leave requests touching {Month Year} are still open." / "Approve the payroll run for {Month Year} first." / "You cannot approve your own settlement while another Admin can." / "Figures changed since the last calculation. Review them and approve again."
- Paid: "Only an approved settlement can be marked paid." / "The paid date cannot be in the future or before the approval."
- Clearance: "Assets returned is ticked automatically when nothing is held." / "Clearance is frozen once the settlement is approved."
- Assets: "This person's final settlement is approved, so no asset can be assigned."
- Lock refusals in every switched writer use `PayrollLock::messageFor()`.

**Audit:** `Clearance item ticked` / `unticked`; `Settlement prepared`; `Settlement recalculated` (figures old/new, paisa + formatted); `Settlement line added` / `removed`; `Settlement tax overridden`; `Settlement approved` (all figures, loans recovered per loan, shortfall, own flag); `Settlement marked paid`.

**Tests to write:** `tests/Unit/Exits/SettlementCalculatorTest.php` (the three worked examples of spec 5.5; joiner leaving in the joining month; mid-month profile change; lates up to the last day; fixed tax prorated; encashment off, on, no type, overdrawn; negative net with loan untouched; shortfall warning), `tests/Unit/Payroll/PayInputLoaderWindowTest.php` (`window_to`, `loans_in_full`; a run without them unchanged), `tests/Feature/Exits/SettlementWorkflowTest.php` (prepare / lines / override / recalc / approve / paid, every refusal, figures-changed refusal, repayments written with the link and remaining derived to zero, run of the next month deducts nothing), `ClearanceTest.php` (auto assets item, ticks with who / when, frozen after approval, asset assign refused), `SettlementLockTest.php` (for a settled person-month every switched writer refuses or skips: manual correction, correction request apply, leave apply / approve / cancel, rebuild and marks leave the day untouched, punch ingest marks nothing, salary profile in the month, loan skip / repayment, joining date; another person in the same month is unaffected; a draft settlement locks nothing), `SettlementLockOrderTest.php` (statement-order technique of `tests/Feature/Assets/AssignmentLockOrderTest.php`: approve = employee FOR UPDATE, resignation, settlement FOR UPDATE, loans; a writer = employee, payroll_runs shared, final_settlements shared), `SettlementPrivacyTest.php` (Manager and Employee: no access to any settlement action).

**Steps:**
- [ ] `PayrollLock` per-employee API with docblock; switch every caller listed (month-level behaviour preserved).
- [ ] Loader options; `ClearanceStatus`, `LeaveEncashment`, `SettlementCalculator`, `SettlementChecks`, `SettlementAccess`.
- [ ] The eight actions; `AssignAsset` guard.
- [ ] Tests; commit.

---

### Task 3: All screens

**Model:** Sonnet (medium).

**Files:** Create `app/Http/Controllers/Exits/{ExitController,MyResignationController,ClearanceController,SettlementController}.php`; `app/Http/Requests/Exits/{SubmitResignationRequest,RecordExitRequest,DecideExitRequest,ChangeLastDayRequest,CancelExitRequest,SettlementLineRequest,SettlementTaxRequest,SettlementPaidRequest}.php`; `app/Support/Exits/{ExitView,ExitsWaiting,DashboardExits}.php`; `resources/js/pages/exit/{index,show}.tsx`; `resources/js/pages/my/resignation.tsx`; `resources/js/components/exit/{exit-flow,exit-request-card,record-exit-dialog,decide-dialog,change-day-dialog,cancel-dialog,clearance-panel,settlement-panel,settlement-line-dialog,letter-card,exit-tab}.tsx`; `resources/js/lib/exit.ts` (+ `exit.test.ts`: stage labels and pill tones, notice label, money and "Owed by employee" formatting). Modify `routes/web.php`, `resources/js/config/navigation.ts` (+ test: entry ready; My Resignation in My Space), `app/Http/Controllers/DashboardController.php` + `resources/js/pages/dashboard.tsx` (cards), `app/Http/Resources/EmployeeProfileResource.php` + the profile view and types (Exit tab).

**Public signatures:**
- `ExitView::for(User, Resignation): array` builds the page props by role; settlement and clearance keys are **absent** for anyone without `exit.clearance` + `payroll.view`; the employee's own view carries `clearance` (read-only) and `statement_url` only once approved, `letter_url` once issued; managers get request, flow, notice and their decide action only.
- `ExitsWaiting::count(User): int` and `ids(User): list<int>` (manager step for managers; final step + approved exits with incomplete clearance for `exit.clearance`; settlements ready to approve for `exit.manage`).
- `DashboardExits::upcoming(int $days = 30): array{count, names, more}`.
- Routes (Wayfinder names under `exit.*` and `my.resignation.*`): `GET /exit`, `POST /exit` (record), `GET /exit/{resignation}`, `POST /exit/{resignation}/decide`, `PUT /exit/{resignation}/last-day`, `POST /exit/{resignation}/cancel`, `PUT /exit/clearance/{clearance}`, `POST /exit/{resignation}/settlement`, `POST /exit/settlements/{settlement}/recalculate`, `POST|DELETE /exit/settlements/{settlement}/lines[/{line}]`, `PUT /exit/settlements/{settlement}/tax`, `POST /exit/settlements/{settlement}/approve`, `POST /exit/settlements/{settlement}/paid`, `GET|POST /my/resignation`, `POST /my/resignation/{resignation}/withdraw`. All ids through `ExitRouting::findVisible` (403 on null).

**Refusals:** the Task 1 and 2 messages, shown as field errors or a flash; the list and page show the stage and the reason an action is unavailable instead of hiding it silently (e.g. "Waiting for the last working day to pass").

**Audit:** none of its own (actions audit).

**Tests to write:** `tests/Feature/Exits/ExitScreensTest.php` (list tabs, filters, fixed query count, record dialog, page per role), `ExitVisibilityTest.php` (the spec 5.9 matrix: settlement keys absent for Manager and for the employee before approval; manager 403 on a non-report; employee 403 on another's exit; HR on own exit has no edit actions), `MyResignationTest.php` (form, submit, withdraw, downloads only when available), `ExitDashboardTest.php` (counts per role, upcoming leavers), profile Exit tab test, Vitest `exit.test.ts`, navigation test update.

**Steps:**
- [ ] Controllers, requests, `ExitView`, routes; regenerate Wayfinder.
- [ ] Pages and components (exit list, exit page with panels, My Resignation).
- [ ] Dashboard cards, profile tab, navigation.
- [ ] Tests; `npx tsc --noEmit`; commit.

---

### Task 4: PDFs, nightly status job, tax report and the run's settled list

**Model:** Sonnet (medium).

**Files:** Create `app/Support/Exits/{SettlementStatementPdf,ExperienceLetter}.php`; `app/Actions/Exits/IssueExperienceLetter.php`; `app/Http/Controllers/Exits/ExitDocumentController.php`; `resources/views/pdf/{settlement,experience-letter}.blade.php`; `app/Console/Commands/ApplyExitStatus.php`. Modify `routes/console.php` (`hrm:exit-apply-status` hourly at :50, `withoutOverlapping(30)`), `routes/web.php` (document routes), `app/Support/Payroll/TaxReport.php` (+ `years()`), `app/Support/Payroll/{PayrollRoster,RosterResult}.php` (settled entries gain `settlement`: `approved` | `paid` | `draft` | null) and the settled-separately list on the run page (one line of text per person).

**Public signatures:**
- `SettlementStatementPdf::render(FinalSettlement): string`, `store(FinalSettlement): string`, `pathOrRender(FinalSettlement): string`, `filename(FinalSettlement): string` ("Final settlement - {code} - {Month Year}.pdf"), `content(FinalSettlement): array` (from the stored breakdown and summary only). Refuses a draft (`LogicException`). Same disk and helpers as `PayslipPdf` (reuse its static formatters; extract a small shared trait only if needed, without changing payslip output).
- `ExperienceLetter::placeholders(): list<string>` (the six plus aliases), `render(string $template, Resignation $r): string`, `pdf(Resignation $r): string` (from the stored `letter_text`).
- `IssueExperienceLetter::handle(User $by, Resignation $r): Resignation` (employee row → resignation row FOR UPDATE; stores `letter_text`, `letter_issued_by/at`).
- Routes: `GET /exit/settlements/{settlement}/statement` (`exit.clearance` + `payroll.view`), `GET /my/resignation/{resignation}/statement` (own, approved / paid only), `POST /exit/{resignation}/letter` (issue), `GET /exit/{resignation}/letter`, `GET /my/resignation/{resignation}/letter` (own, issued only).
- `hrm:exit-apply-status {--date=}`: for approved exits with `last_working_day < attendanceToday()` and `status_applied_at` null, oldest first, one transaction each: `ChangeEmployeeStatus::handle($e, resigned|terminated, $lastDay, null)`, set `status_applied_at`, clear `status_error`, audit `Exit status applied`; a refusal is logged, stored in `status_error`, retried next run; exit code failure when any failed.
- `TaxReport::for()`: adds approved / paid settlements whose month is in the tax year, taxable = breakdown `tax.gross_taxable_pay` (fallback `taxable_pay`), tax = `tax`, summed into that employee's month (a person never has both a line and a settlement in one month; if both exist, both are added and the report is still correct). One extra query.

**Refusals (exact text):** "The statement is available once the settlement is approved." / "The exit must be approved before a letter is issued." / "You cannot issue your own experience letter." / "No experience letter has been issued yet."

**Audit:** `Experience letter issued` (re-issue: old and new text length, not the text), `Exit status applied`.

**Tests to write:** `tests/Feature/Exits/SettlementStatementTest.php` (draft refused; content from stored figures; identical after a profile or settings change; owed-by-employee wording; 403 for Manager and another employee), `ExperienceLetterTest.php` (placeholders and aliases, date format, issue rules, employee download only after issue), `ApplyExitStatusTest.php` (not before the day after the last day; resigned vs terminated; login disabled; idempotent; HR already set the status; last-Admin refusal stored and retried; cancelled or pending exits ignored), `TaxReportSettlementTest.php` (settlement counted in its month and year, draft not counted), roster test (settled list shows the settlement status).

**Steps:**
- [ ] Statement PDF + view; letter renderer, action, PDF + view; document controller and routes.
- [ ] Command and schedule.
- [ ] Tax report and roster settled status.
- [ ] Tests; commit.

---

## Phase-end check (controller, once)

- [ ] `C:\php84\php.exe artisan migrate` (additive) on the dev database after Task 1.
- [ ] One full suite: `C:\php84\php.exe vendor\bin\pest` and `npx vitest run`; `npx tsc --noEmit`.
- [ ] One browser walkthrough on localhost: employee resigns (notice short shown) → manager approves → HR approves (leave after the day trimmed) → HR ticks clearance (assets blocked until returned) → HR prepares settlement with encashment on, a bonus and a loan → Admin approves → correction and leave in that month refused → statement PDF → letter issued → `hrm:exit-apply-status --date=` after the day → login disabled → the month's payroll run lists the person as settled separately. Also: Manager sees no figures; HR records a termination.
- [ ] One Opus review, money and lock only: `PayrollLock` and every switched caller, `PayInputLoader` options, `SettlementCalculator`, `SettlementChecks`, `ApproveSettlement`, loan repayments, `TaxReport`. Review focus = spec section 8, items 1 to 3.
- [ ] One fix round for the suite failures and the review findings; rerun the failing files and the review's files; commit.
