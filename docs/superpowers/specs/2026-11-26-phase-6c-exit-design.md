# Lumino HRM Phase 6c (Exit): design

Date: 2026-11-26. Builds on the main design, `docs/superpowers/specs/2026-11-23-lumino-hrm-design.md` (sections 8, 9.5, 9.8, 9.9, 11), on Phases 3 to 6b as built (`main`), and on the Phase 5 spec (`2026-11-25-phase-5-payroll-design.md`) for money, the pay calculator, the payroll lock and audit. Where this document and the main design differ, this one wins. This is the last module of v1.

## 1. Intent

A person leaves through one recorded path instead of emails and a spreadsheet. The employee resigns in the app (or HR records a resignation or termination), the manager and then HR approve, the last working day goes onto the employee record and everything that already follows that date (attendance window, leave trimming, payroll roster) follows it. HR works through a clearance checklist, prepares the final settlement with the same calculator payroll uses, Admin approves it, the person gets a statement and an experience letter, and the night after the last day the status changes and the login is disabled.

Success (phase gate): one real leaver is run end to end (resignation, approvals, clearance, settlement approved and marked paid, letter issued, status changed overnight), the settlement matches HR's hand calculation, and the payroll run of that month lists the person as settled separately and pays them nothing.

## 2. Goals, non-goals, anti-goals

**Goals**

- Resignation by the employee; resignation or termination recorded by HR; manager then HR approval; withdraw, reject, change last day, cancel; notice shown against the requested day.
- Clearance checklist with three built-in items and custom items from Settings > Exit, each recording who ticked it and when.
- Final settlement for the final month through `PayInputLoader` + `PayCalculator`, plus leave encashment (off by default), HR lines and full loan recovery; draft, approved, paid; PDF statement; counted in the yearly tax report.
- An approved settlement freezes that person's attendance, leave and salary records for the final month (`PayrollLock` per employee).
- Experience letter PDF from a template; nightly status change; Resignations list, exit page, My Resignation, profile tab, dashboard counts; navigation entries ready.

**Non-goals (v1)**

- Email notices (none in v1), exit interviews, gratuity / provident fund payout rules, automatic notice-period deduction, paying a negative settlement back from the employee, re-opening an approved settlement, settlements for anyone without an approved exit, bulk exits.

**Anti-goals (failure even if it works)**

- A person paid twice for the final month (run plus settlement), or paid for a day they were deducted for.
- A manager or another employee seeing any settlement figure, by screen, prop, URL or PDF.
- An approved settlement changing afterwards: through an attendance correction, a leave decision, a profile or loan edit, a settings change, a re-render of the PDF, or a last-day change.
- A loan recovered twice (by the settlement and a later run), or a rupee lost to rounding.
- A login disabled, or a status changed, before the last working day has passed.

## 3. Constraints

- All Phase 0 to 6b constraints hold: localhost only, no deploys; PHP at `C:\php84\php.exe`; work on `main`; commit by explicit path; naive dates (`Y-m-d` strings); `AttendanceClock::attendanceToday()` is the only "today"; audit inside the same transaction; 403 for out-of-scope ids; privileged (Admin / HR) people touched only by Admin; nobody decides their own.
- **Money** is integer paisa through `App\Support\Payroll\Money`; every computed amount goes through `Money::div` (half up to the whole rupee) once; no float.
- **Permissions** already seeded, no new names: `exit.manage` (admin): approve settlements, everything; `exit.clearance` (admin, hr): record, final approval, clearance, settlement drafts, letters; `exit.approve-team` (admin, hr, manager): the manager step for direct reports. Everyone may resign and see their own exit. Settlement figures additionally need `payroll.view` (Admin, HR), which every `exit.clearance` holder has.
- **Transactions:** `DB::transaction(fn, 3)`; every exit and settlement write locks the **employee row first** (`lockForUpdate`), then the resignation row, then the settlement row, then loans by id. Reads that decide anything happen after the locks. No path takes a settlement or resignation row before the employee row.
- **Reuse, do not duplicate:** the last working day is written only through the extracted `EmploymentEnd` step (section 5.3), which is what `ChangeEmployeeStatus` already does (payroll-lock range check, `RebuildMarks::outsideEmployment`, `LeaveEmploymentTrim::apply`). Settlement pay is `PayInputLoader::load` + `PayCalculator::calculate`, never a second calculator.
- Namespace segment `Exits` (`App\Actions\Exits`, `App\Support\Exits`, `App\Http\Controllers\Exits`), never `Exit` (a PHP keyword).

## 4. Approaches considered

How the final month is paid and frozen.

- **A. Pay the final month in the normal run, settlement adds only extras.** One calculator path, but the run would need to know about loans in full, encashment and HR lines per leaver, and the run's approval would freeze the month for everyone on the run's timetable, not the leaver's. Rejected; Phase 5 already skips leavers in their final month.
- **B. A separate settlement record that feeds the run's own loader and calculator with a shorter window, and is its own per-employee lock (chosen).** `PayInputLoader` gains two optional per-person inputs (window end, loans in full); the calculator is unchanged; the settlement stores the `PayResult` figures and breakdown exactly as a payroll line does; `PayrollLock` gains per-employee variants that treat an approved settlement as a lock on that person's final month.
- **C. A hand-entered settlement form.** Flexible, untestable, and diverges from payroll. Rejected.

## 5. Decided behaviour

### 5.1 Exit records and states

- One `resignations` row per exit, `type` `resignation` or `termination`. At most one open exit per person (status `pending`, `manager_approved`, `approved`), guarded by `open_employee_id` (holds the employee id while open, NULL otherwise, unique; a model hook keeps it in step, as `asset_assignments.open_asset_id`).
- States: `pending` (waiting for the manager) → `manager_approved` (waiting for HR) → `approved` (final). Ends: `rejected` (by either step, note required), `withdrawn` (by the employee, before final approval), `cancelled` (by HR, after approval, before the settlement is approved). Ended rows are history and never change.
- Stage labels for people: "With manager", "With HR", "Approved", "Rejected", "Withdrawn", "Cancelled"; after approval the exit page also shows "Clearance", "Settlement draft", "Settlement approved", "Paid", "Left" (status applied).

### 5.2 Submitting, recording, deciding

- **Employee resigns** (My Resignation): needs an employee record with status `active` or `probation` and no open exit. Last working day: today (`attendanceToday()`) or later, at most 365 days ahead, not before the joining date. Reason required, up to 1,000 characters. `submitted_on` = today. The current `payroll.notice_period_days` is copied onto the row.
- **HR records** (`exit.clearance`): type, last working day (not before the joining date, at most 365 days ahead; a past day is allowed for an absconding or back-recorded case, the payroll-lock check decides), reason, submitted on (default today, not in the future). The exit starts at the HR step (`manager_approved`, `manager_skipped` true). The form has "Approve now" (ticked by default) which runs the final approval in the same request when the recorder may decide it. HR cannot record for themselves; an Admin or HR person's exit is recorded by Admin only.
- **Manager step** is skipped (the exit starts at `manager_approved`, `manager_skipped` true) when: HR or Admin recorded it; it is a termination; the person has no manager; the manager is the person; the manager has no login or a disabled one; the manager lacks `exit.approve-team`; or the manager holds `exit.clearance` (decides once, as final). Worked out by `ExitRouting`, mirroring `LeaveRouting`.
- **Final step** (`exit.clearance`): never the person themselves; for a person whose login is HR, an Admin; for an Admin, another Admin, or the person only while they are the sole active Admin (audited `own: true`). A final approver may also decide an exit still waiting for the manager (sets `manager_skipped`).
- **Reject** at either step needs a note (1 to 500 characters), shown to the employee.
- **Withdraw:** the employee, while `pending` or `manager_approved`. HR may not withdraw on their behalf (they reject instead).
- **Notice:** `notice given = days from submitted_on to last_working_day` (calendar days, last day included, so a same-day resignation is 1); shown as "30 days notice" or "12 days short of notice (30 required)". No automatic deduction; HR may add a "Notice shortfall" line in the settlement (5.5), helped by a hint showing one day's pay.

### 5.3 Final approval and the last working day

- In one transaction after the employee row lock: set the resignation `approved`, then `EmploymentEnd::apply($employee, $lastWorkingDay, $by)`, then create the clearance rows (5.4).
- `EmploymentEnd` (new, `App\Actions\Employees`) is the body that `ChangeEmployeeStatus` already runs for a day change, extracted unchanged: refuse when `(old, new]` touches a locked month (`PayrollLock::rangeTouchesLockedFor`), save `last_working_day`, `RebuildMarks::outsideEmployment`, `LeaveEmploymentTrim::apply` (approved leave after the day is removed and refunded, open requests past it cancelled, audited `Leave trimmed to employment`). `ChangeEmployeeStatus` calls it; behaviour there does not change.
- The status stays `active` / `probation` until the nightly job (5.8). An active person with a last working day is already handled everywhere (`Leavers`, `MonthGrid`, `PayrollRoster`: settled separately in that month, absent after it).
- **Profile guard:** while a person has an open exit, `ChangeEmployeeStatus` refuses any change that would set, move or clear the last working day: "This person has an open exit. Change the last working day on the exit page." A status change that keeps the day is allowed.
- **Changes after approval** (`exit.clearance`, until the settlement is approved): change the last working day (same rules as recording; through `EmploymentEnd`; a draft settlement is marked stale and must be recalculated, and its month follows the day) or cancel the exit (reason required; `EmploymentEnd::apply($employee, null, $by)`; the draft settlement and its lines are deleted, clearance rows kept as history). Leave already trimmed is not restored (flash: "Leave cancelled for the old last day stays cancelled; apply again if needed."). Once the nightly job has changed the status, cancel is refused and the day may only move to another date before today.

### 5.4 Clearance

- Rows are created at final approval: "Assets returned" (`assets`), "System access removed" (`access`), "Dues cleared" (`dues`), then each custom item of `settings('exit.clearance_items')` in order (snapshot; later settings edits do not touch existing exits).
- `assets` is never ticked by hand: it shows ticked ("Nothing held") when `AssetHoldings::holdsAny` is false and unticked with the held asset codes otherwise. It is stored (`cleared_at`, `cleared_by` NULL = automatic) only when the settlement is approved.
- The others are ticked and unticked by `exit.clearance` (not for one's own exit; an Admin / HR person's only by Admin), each storing `cleared_by` and `cleared_at`, audited. After the settlement is approved, clearance is frozen.
- Clearance complete = every hand item ticked and nothing held. Required to approve the settlement.
- While an approved exit's settlement is approved, assigning an asset to that person is refused: "This person's final settlement is approved, so no asset can be assigned."

### 5.5 Final settlement

- **Prepare** (`exit.clearance` and `PayrollAccess::mayEditSalary`): once the exit is approved; one settlement per exit. `month` = the month of the last working day. Draft only: HR adds or removes lines, may override the tax (amount and reason, as payroll), recalculates. Every edit recalculates.
- **Calculation** (`SettlementCalculator`): `PayInputLoader::load($month, [$employee], [$id => ['window_to' => $lastWorkingDay, 'loans_in_full' => true, 'adjustments' => $lines, 'tax_override' => …]])` then `PayCalculator::calculate`. So: pay window max(joining date, 1st) to the last working day; earnings, fixed deductions and fixed tax prorated by calendar days of the month; absences, half days, lates, unpaid leave and overtime exactly as a run; tax mode of the profile in force on the last working day; percentage tax on the same base as a run.
  - `window_to` (new, optional): the window end instead of the month end. `loans_in_full` (new, optional): each active loan's due amount is its whole remaining balance, skips ignored. Neither is ever passed by a payroll run, so runs do not change.
  - **Leave encashment** (only when `settings('payroll.leave_encashment')`, default off): unused days = max(0, `remaining`) of the encashment leave type (`settings('payroll.encashment_leave_type_id')`; null = the type named "Annual", ignoring case; none found is a warning and no encashment) in the leave year of the last working day, read after the trim. Amount = `R(monthly gross of the profile in force on the last day × halves, 2 × basis days)`. Added as a taxable addition "Leave encashment (6.5 days)". Off: no line, and the breakdown says "Leave encashment is off".
  - **HR lines** (up to 20): kind `bonus` (addition), `notice_shortfall` (deduction), `other` (addition or deduction); label (1 to 100), amount (whole rupees, 1 to 10,000,000), additions taxable yes by default.
  - **Loans:** each active loan's remaining balance, oldest first, recovered up to what the net allows (the calculator's existing "installment reduced" rule); a shortfall is a warning "Loan balance Rs 10,155 is more than the settlement can recover; the rest stays on the loan." and stays on the loan.
  - **Negative net is allowed:** the calculator's `negative_net` blocking is ignored for settlements; shown as "Owed by employee: Rs 4,200"; nothing is paid and no loan is recovered.
  - The `PayResult` is stored as on a payroll line (`basic … net`, `breakdown`) plus `encashment_days` (halves), `encashment_amount`, `loan_shortfall`.
- **Worked example** (Phase 5's profile: basic 60,000, house rent 24,000 taxable, medical 6,000 not taxable, provident fund 3,000, tax 5% of gross taxable; November 2026, 30 days; calendar-days basis): last working day 18 November, one absent day, encashment on with 6.5 Annual days left, bonus 10,000, notice shortfall 5,000, loan remaining 80,000. Earned basic 36,000, house rent 14,400, medical 3,600; encashment R(90,000 × 13, 60) = 19,500; gross 54,000 + 19,500 + 10,000 = 83,500; attendance deduction R(90,000 × 2, 60) = 3,000 (taxable share 2,800); provident fund 1,800; tax R((50,400 − 2,800 + 19,500 + 10,000) × 500, 10,000) = 3,855; before loans 83,500 − 3,000 − 1,800 − 5,000 − 3,855 = 69,845; loan recovered 69,845, shortfall 10,155, **net 0**. With a loan of 20,000: net **49,845**. With encashment off and no loan or lines: 54,000 − 3,000 − 1,800 − R(47,600 × 500, 10,000) 2,380 = **46,820**.
- **Approve** (`exit.manage`; not one's own settlement unless the sole active Admin, audited `own_settlement: true`). Blocking checks (listed on the panel, refused with the first): the last working day has passed (`attendanceToday()` after it); clearance complete; no salary profile in force; days of the final month up to the last day still missing, needing attention or being calculated; open leave requests touching the month; the month before the final month has no approved or paid run while payroll is live (any approved run exists) and the person was in it. Inside the transaction: employee row FOR UPDATE, resignation row, settlement row FOR UPDATE, loans FOR UPDATE by id; recalculate; if any figure differs from the stored one, save, commit and refuse "Figures changed since the last calculation. Review them and approve again."; else snapshot the company header and footer, write one `loan_repayments` row per recovered loan (`final_settlement_id`, month = settlement month), store the clearance `assets` tick, set `approved`, audit.
- **Paid** (`exit.clearance`): approved → paid with a paid-on date (not in the future, not before approval); informational. With net 0 or below the button reads "Mark settled".
- Approved and paid settlements are never edited, recalculated or deleted. A mistake is corrected outside the system (v1).

### 5.6 The lock for an approved settlement

- `PayrollLock` gains per-employee variants: `isLockedFor(int $employeeId, string $month)`, `lockedMonthsFor(int, array)`, `firstLockedFor(int, iterable $dates)`, `rangeTouchesLockedFor(int, string, string)`, `settledMonths(array $employeeIds, array $months): array<int, list<string>>` (one query, for batch readers), and `messageFor(string $month, bool $settled)`. Each is true when the month is locked (as today) **or** that person has an `approved` or `paid` settlement for that month. Message: "The final settlement for November 2026 is approved, so this can no longer change."
- Reading follows the existing rules: inside a transaction a shared lock on the rows read; select settlement rows by `employee_id` (and month list) only and filter the status in PHP, so a draft settlement row is share-locked too (approval of that draft then waits).
- **Lock order:** employee row → `payroll_runs` (shared) → `final_settlements` (shared). Settlement approval takes employee FOR UPDATE then settlement FOR UPDATE and never reads `PayrollLock`, so no cycle: per-employee writers already wait on the employee row; writers that lock no employee row (rebuilder, ingestor, rebuild marks) share-lock the settlement row and serialise with the approval's FOR UPDATE. No settlement action share-locks its own settlement row and then upgrades it.
- **Writers switched to the per-employee check** (the month-level check stays inside it): `ApplyManualCorrection`, `CorrectionRequests` (apply), `CorrectionEligibility`, `AttendanceRebuilder` (process, rebuildRange, closeDay per pair), `RebuildMarks`, `PunchIngestor` (marks), `ApplyForLeave`, `LeaveDecisions` (approve and cancel), `LeaveDayRemoval`, `HolidayLeaveRefund`, `LeaveRouting::earliestCancellableDate`, `UpdateEmployee` (joining date), `EmploymentEnd`, `SaveSalaryProfile`, `SaveLoan`, `LoanActions` (skip, repayment), and the read-only flags in `Attendance\DailyController`, `Attendance\MonthlyController`, `Leave\LeaveRequestController`. Holidays stay month-level (company-wide): adding one in a settled month is allowed, and the rebuilder leaves the settled person's days as they were paid.
- **Payroll runs:** unchanged. The roster already puts the person in "Settled separately" for the final month; Phase 6c adds the settlement status to that list ("Settlement approved", "Settlement draft", "No settlement yet") on the run page.

### 5.7 Documents

- **Statement PDF** (`SettlementStatementPdf`), shaped like the payslip: company header (snapshot at approval), employee, exit type, last working day, period, earnings (incl. encashment and bonus lines), deductions (attendance, fixed, HR lines, tax), loan recovery and shortfall, net or "Owed by employee", attendance summary. Rendered only for approved / paid settlements from the stored breakdown, stored on the private disk at `settlements/{month}/{code}-{id}.pdf`, re-rendered identically when missing. HR and Admin download from the exit page; the employee from My Resignation.
- **Experience letter** (`exit.clearance`, once the exit is approved, not for one's own exit): "Issue letter" renders `settings('exit.experience_template')` with `{employee_name}` (alias `{name}`), `{designation}`, `{department}`, `{joining_date}`, `{last_working_day}`, `{company_name}` (alias `{company}`), dates in the company date format; stores the rendered text and issue time on the resignation (re-issue replaces it, audited). PDF: company header, date, the text. The employee downloads it from My Resignation once issued. Default template: the one already in `config/hrm.php`.

### 5.8 Status change at night

- `hrm:exit-apply-status`, hourly at :50 (the company zone is a runtime setting, as the attendance close). For each approved exit whose last working day is before `attendanceToday()` and whose `status_applied_at` is empty: `ChangeEmployeeStatus::handle($employee, 'resigned' | 'terminated', $lastWorkingDay, null)` (null actor = the system; the day is unchanged so no lock check and an idempotent trim), which disables the login. Then `status_applied_at` is set. A refusal (the last active Admin) is logged, stored as `status_error` on the exit page, and retried each run. Idempotent.
- A cancelled exit restores nothing (nothing changed yet; cancel is refused once the status changed).

### 5.9 Who sees what

| | Admin | HR | Manager (direct reports) | Employee (own) |
|---|---|---|---|---|
| Resignations list | all | all | team | no (My Resignation) |
| Exit request, flow, notice, notes | yes | yes | yes | yes |
| Decide manager step | as reporting manager | as reporting manager | yes | no |
| Final decision, record, change day, cancel | yes | yes (not own; Admin / HR people by Admin) | no | no |
| Clearance | tick | tick | absent | items and ticks, read-only |
| Settlement figures | all; approve | all; edit drafts | **never** (keys absent) | own statement once approved |
| Experience letter | issue | issue | no | download once issued |

Out of scope ids are 403. Data a viewer may not see is absent from the props, not null.

### 5.10 Screens

- **Exit > Resignations & Clearance** (`/exit`; `exit.clearance` or `exit.approve-team`): Waiting for me / Open / All / Ended tabs; table (person, type pill, submitted, last day, notice, stage, settlement status for HR); filters (search, department, type); "Record exit" (HR). 25 per page, fixed query count.
- **Exit page** (`/exit/{id}`): header with person and stage; request card (type, dates, reason, notice); flow (manager step, HR step, with who and when); actions by role; then for HR / Admin: clearance panel, settlement panel (figures, lines, warnings, checks, recalculate, approve, mark paid, statement), letter card, status-applied line.
- **My Resignation** (`/my/resignation`, everyone with an employee record): the form when no open exit; otherwise status, flow, withdraw, clearance ticks, statement and letter downloads when available; ended exits listed below.
- **Employee profile > Exit tab** (whoever may see the profile; figures only for `payroll.view`): open exit summary and history.
- **Dashboard:** "Exits waiting for you" (count; manager step for managers, final step plus incomplete clearance for HR, settlements ready to approve for Admin); "Upcoming leavers" for `exit.clearance` (approved exits with last day in the next 30 days, up to five names).
- **Settings > Exit** (replaces "Letter templates"; `settings.manage`): custom clearance items (editable ordered list, up to 20, 1 to 80 characters each, unique ignoring case) and the experience letter template. Notice period and leave encashment stay in Settings > Payroll (where they already are), which gains the encashment leave type picker.
- Navigation: "Resignations & Clearance" becomes ready; My Space gains "My Resignation".

### 5.11 Audit

Area `exit` (`Audit::AREAS`, section in `docs/audit-log.md`). Money in paisa plus a formatted copy (`net: 6984500, net_formatted: "Rs 69,845"`). Old and new values on every change.

- `Resignation submitted`, `Exit recorded` (type, last day, reason, notice), `Exit approved by manager`, `Exit approved` (own flag; the employee's `last_working_day` old and new), `Exit rejected` (step, note), `Resignation withdrawn`, `Exit last working day changed`, `Exit cancelled` (reason), `Exit status applied` (by the job, causer none).
- `Clearance item ticked`, `Clearance item unticked`.
- `Settlement prepared`, `Settlement recalculated` (figures old and new), `Settlement line added` / `removed`, `Settlement tax overridden`, `Settlement approved` (all figures, loans recovered, shortfall, own flag), `Settlement marked paid`, `Settlement draft deleted` (with a cancel).
- `Experience letter issued`.
- Existing entries keep coming from reused steps (`Employee status changed`, `Leave trimmed to employment`, settings saves).

## 6. Data (outline; the plan fixes columns)

- `resignations`: employee (restrict), type, submitted_on, last_working_day, reason, notice_period_days, status, open_employee_id (nullable unique), recorded_by, manager_skipped, manager_decided_by / at, final_decided_by / at, own_decision, decision_note, withdrawn_at, cancelled_by / at, cancel_reason, status_applied_at, status_error, letter_text, letter_issued_by / at.
- `clearances`: resignation (cascade), kind (`assets` / `access` / `dues` / `custom`), label, sort_order, cleared_by, cleared_at; unique (resignation, sort_order).
- `final_settlements`: resignation (unique), employee, month, status (`draft` / `approved` / `paid`), the payroll-line figures (`basic`, `allowances`, `overtime`, `additions`, `gross`, `attendance_deduction`, `fixed_deductions`, `other_deductions`, `loan_deduction`, `taxable_pay`, `tax`, signed `net`), `encashment_days`, `encashment_amount`, `loan_shortfall`, tax override fields, breakdown (JSON), stale, calculated_at, prepared_by, approved_by / at, own_settlement, paid_on, paid_by, summary (JSON company snapshot), statement_path; index (employee, month).
- `final_settlement_lines`: settlement (cascade), kind, type, label, amount, is_taxable, created_by.
- `loan_repayments.final_settlement_id` (nullable, restrict) with unique (loan, final_settlement).
- Settings: `exit.clearance_items` (`[]`), `exit.experience_template` (moved from `letters.experience_template` by a data migration), `payroll.encashment_leave_type_id` (null). Existing `payroll.notice_period_days` (30) and `payroll.leave_encashment` (false) are used as they are.

## 7. Open items (not decided by the owner; the stated default is built)

1. **Settings home.** Notice period and encashment already live in Settings > Payroll; they stay there. Settings > Exit holds the clearance items and the letter (the "Letter templates" group is renamed). Default: as stated.
2. **Placeholders.** The existing template uses `{employee_name}` and `{company_name}`; the brief's `{name}` and `{company}` are accepted as aliases.
3. **Encashment leave type.** No leave type is marked "annual"; a Payroll setting picks it, null meaning the type named "Annual".
4. **Encashment is taxable**, and an overdrawn leave balance is not deducted (only positive remaining is paid).
5. **HR-recorded exits** start at the HR step with "Approve now" ticked; a past last working day is allowed (lock check decides).
6. **Previous month gate:** settlement approval waits for the previous month's payroll run once payroll is live.
7. **Employee sees** their clearance ticks and downloads the experience letter once issued.
8. **Tax override** is allowed on a settlement draft, as on a payroll line.
9. **After the status changes** a cancel is refused and the last day may only move to another past date.
10. **Settings > Exit** is Admin only (`settings.manage`), like the letter template today.

## 8. Review focus

1. Double pay: the final month in a run and a settlement; last-day changes moving the person in and out of a draft run; recalculation at approval.
2. Lock: every writer of section 5.6 refused for the settled person-month and allowed for others; statement order employee → runs → settlement; approval racing an attendance correction, a leave approval, a rebuild, a loan edit.
3. Money: the worked examples; encashment on and off; full loan recovery with shortfall; negative net; tax on encashment and lines; joiner leaving in the joining month.
4. Privacy: Manager and Employee on every exit route, prop, PDF; HR on their own and on an Admin's settlement.
5. Workflow edges: skip rules, own decisions, withdraw after final approval, one open exit, cancel after the status change, the nightly job's idempotence and the last-Admin refusal.

## 8a. Left to the builder

Page layouts within the design system; exact file names beyond the plan; PDF styling (one A4 page, DejaVu Sans, as the payslip); test structure, provided every refusal, worked example and review-focus case is covered.
