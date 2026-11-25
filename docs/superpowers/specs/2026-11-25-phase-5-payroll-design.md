# Lumino HRM Phase 5 (Payroll): design

Date: 2026-11-25. Builds on the main design, `docs/superpowers/specs/2026-11-23-lumino-hrm-design.md` (sections 8, 9.1, 9.2, 9.3, 9.5, 9.8, 9.9, 9.10, 11, 12, 13, 14), on Phase 3 Attendance and Phase 4 Leave as built (branch `phase-4`). Where this document and the main design differ, this one wins. Main design open item 13 is answered: the pay cycle is the calendar month, paid in the first days of the next month.

## 1. Intent

HR stops working out salaries in a spreadsheet. At the start of each month HR generates the previous month's payroll from the attendance and leave the system already holds, reviews and adjusts it, Admin approves it, every employee gets a payslip by email, and the bank receives one transfer sheet. Once approved, the month is frozen: nothing in attendance, leave or salary can change what was paid.

Success (phase gate): one month is run in parallel with the current manual payroll and every employee's net pay matches, or differs only for a reason HR agrees the system has right.

## 2. Goals, non-goals, anti-goals

**Goals**

- Salary profiles with history (basic, allowances taxable or not, fixed deductions, tax mode), loans and advances with installments, opening figures at go-live (tax to date, loans in progress), monthly runs (draft, review, approve, paid), payslip PDFs emailed after approval, a bank transfer sheet, a monthly summary, a yearly tax report, My Payslips, the Salary tab on the profile, and the "Payroll This Month" dashboard tile.
- `PayrollLock::isLocked()` becomes real: an approved month refuses every change to attendance, leave, holidays, employment dates and salary profiles that would touch it.

**Non-goals (v1)**

- Final settlement, leave encashment and notice period handling: Phase 6 (Exit). This phase only skips leavers in their final month and lists them as "settled separately".
- Statutory automation (income tax slabs, EOBI, provident fund, social security): entered as fixed deductions or through the tax modes (main design 9.5).
- Per-bank transfer file formats, direct bank integration, salary paid through the system's own money movement. The system produces a sheet; HR uploads it to the bank.
- Disbursing loans or advances through payroll: a loan is paid out outside the system and only recovered through payroll.
- Arrears calculation for back-dated raises: a back-dated raise into a locked month is an adjustment in the next run, entered by HR.
- Re-opening an approved run (see 5.6).

**Anti-goals (failure even if it works)**

- An employee or manager sees anyone's salary figures, by screen, URL, prop, PDF or email (main design section 3).
- A locked run or an old payslip changes after the fact: through a profile edit, a settings change, a loan edit, an attendance correction, a leave decision or a re-render of the PDF.
- A rupee appears or disappears through rounding or floating-point arithmetic.
- Someone is paid twice for a month (normal run plus final settlement) or paid for a day they were already deducted for.

## 3. Constraints

- All Phase 0–4 constraints hold: localhost only, no deploys; PHP at `C:\php84\php.exe`; focused tests while working; commit by explicit path; naive dates (`Y-m-d` strings, `onDate` / `betweenDates`), `AttendanceClock::attendanceToday()` as the only "today"; audit inside the same transaction; mails queued after commit; 403 for out-of-scope ids; privileged (Admin / HR) accounts touched only by Admin (`EmployeeRoles::mayTouchAccount`); nobody approves their own.
- **Money is integer paisa** (1 rupee = 100) in `bigInteger` columns and PHP `int` everywhere; never a float, never `decimal` arithmetic in PHP. This replaces the main design's `decimal(12,2)` (SQLite in tests stores decimals as floats). Percentages are integer basis points (5.00% = 500).
- **Money transactions** follow the Phase 4 lessons: `DB::transaction(fn, 3)`; the first statement locks the row that serialises the action (the employee row for per-person writes; the `payroll_runs` row for run-level writes; a run-level write never locks employee rows); reads that decide money happen after that lock.
- Permissions already seeded (no new names): `payroll.view` (admin, hr), `payroll.run` (admin, hr), `payroll.approve` (admin). Everyone sees their own payslips. Managers have no payroll access at all.
- Shared hosting: no binaries, no `exec`, queue drained by the once-a-minute scheduler. PDFs with `barryvdh/laravel-dompdf` (named in the main design's stack table, not yet installed; pure PHP).

## 4. Approaches considered

How a payroll line is worked out and kept.

- **A. Compute on read:** the run stores only who is in it; screens and payslips recompute from profiles, attendance and leave. Small storage, but any later change rewrites history: the anti-goal.
- **B. One pure calculator, inputs gathered once, results frozen (chosen).** A loader gathers each person's inputs for the month (attendance grid, unpaid leave by date, salary profiles in force, loans due, HR's line inputs, settings) into a `PayInput`; a pure `PayCalculator` turns it into a `PayResult` with every figure and a full breakdown; the run stores the result. A draft is recalculated whenever HR asks and once more inside the approval transaction; after approval the stored breakdown is the only source for screens, payslips, reports and the bank sheet.
- **C. Spreadsheet-style editable lines:** HR types figures into a grid. Flexible but untestable and the opposite of "stop working it out by hand".

Chosen: **B**. The calculator is pure, so every money rule is pinned by unit tests with worked examples; HR's edits are stored as inputs (adjustments, tax override, loan skip, exclusion), so recalculation never loses them.

## 5. Decided behaviour

### 5.1 Salary profiles

- A profile row: employee, effective from (any date on or after the joining date), basic, tax mode and value, tax base, note. Components: allowances (name, amount, taxable yes / no) and fixed deductions (name, amount), up to 20 per profile. A change is a new row; history is kept.
- **Amounts are whole rupees** when entered (default, owner may change; the paisa columns allow paise later without a schema change).
- Tax (main design 9.5): `none`; `fixed` (rupees per month); `percentage` (0.00–100.00%) of gross taxable pay or of basic, chosen per profile, default gross taxable (main design open item 8). New profiles start with `settings('payroll.default_tax_mode')`.
- A profile can be edited or deleted only while no approved run covers any date on or after its effective date; otherwise HR adds a new row. A profile cannot start in or before the latest locked month.
- Mid-month change: each profile pays for the days it is in force (5.3).
- Who edits: `payroll.run`, never one's own profile unless Admin, and an Admin or HR person's profile only by Admin. Who sees: `payroll.view` only. Audited with every value old and new.

### 5.2 Who is in a run

- Everyone whose joining date is on or before the month's last day, except: a person whose last working day falls in the month (paid only through the Phase 6 final settlement; listed as **"Settled separately"**); a person whose last working day is before the month (not listed); a leaver without a last working day (`Leavers::withoutLastDay`; listed as **"Left, no last working day"** so HR fixes the record).
- A person in the run with no salary profile in force on any day of the month blocks approval until HR adds one or excludes the person from this run (with a reason, audited; an excluded line pays nothing and is not in the bank sheet).
- Attendance-exempt people are included and get no attendance deductions (main design 9.3); unpaid leave still deducts.

### 5.3 The money rules

All amounts are integer paisa. `R(n, d)` = n ÷ d rounded half up to the nearest **whole rupee** (100 paisa), for non-negative n and positive d. Every computed amount (prorated component, attendance deduction and its shares, overtime, percentage tax, prorated fixed amounts) goes through `R` once; entered amounts are whole rupees already. So every stored figure, and net pay, is a whole rupee (default, owner may change).

- **Days:** D = days in the calendar month. The pay window = max(joining date, 1st) .. last day (leavers in their final month are not in the run).
- **Earned pay per component** = Σ over profile segments of `R(amount × segment days, D)`. Segments split the pay window at each profile's effective date. Days in the window before the first profile earn nothing (warning). Proration is always by calendar days (main design 9.5), whatever the day-pay basis.
- **Gross** = earned basic + earned allowances (+ overtime + additions, below). **Taxable gross** = earned basic + earned taxable allowances.
- **Day-pay basis** (`settings('payroll.day_pay_basis')`, default `calendar_days`): basis days B = D; or 30 (`thirty`); or W (`working_days`) = days of the whole month that are neither the employee's weekly offs (their shift in force) nor holidays (W = 0 falls back to D). One day's pay = monthly gross of the profile in force ÷ B. Used only for deductions and overtime.
- **Deducted half days per date** (in halves, 0–2): attendance halves (status `absent` = 2, or 1 when the row has a half-day leave; `half_day` = 1; anything else 0) plus unpaid-leave halves from the leave ledger (full day 2, half 1), **capped at 2**. Exception: unpaid-leave halves on a date attendance records as `holiday` or `weekend` count 0 (warning "unpaid leave on a day off").
  - **Which source wins (decided):** attendance decides absences, half days and lates, but only on days attendance counts (on or after `attendance.start_date`, inside the employment window; `MonthGrid` already applies this); the **leave ledger alone decides unpaid leave**, on every day of the pay window, including days before `attendance.start_date` and manual days. Attendance shows an unpaid leave day as `on_leave` (no deduction there), so the two never double count except through a manual absent on a leave day, which the cap handles. Unpaid leave on a day HR corrected by hand as worked still deducts (warning "unpaid leave on a worked day": HR cancels the leave if the person worked, as in Phase 4).
- **Late rule:** late days in the month (attendance status `late`, which already excludes half days, absences and half-leave days) ÷ `settings('attendance.late_rule_count')`, rounded down, = days deducted (2 halves each), charged at the profile in force on the last day of the pay window. Count 0 = rule off. Lates do not carry into the next month (main design 9.3).
- **Attendance deduction** = Σ over segments of `R(segment profile's monthly gross × halves in that segment, 2 × B)`; its taxable share and basic share are computed the same way from taxable gross and basic. The deduction is capped at earned gross (shares at their earned amounts).
- **Overtime** (only when `settings('attendance.overtime_enabled')`, default off): minutes from attendance (always recorded). Mode `multiple`: `R(gross × minutes × multiplier%, B × hoursPerDay × 60 × 100)`; mode `fixed`: `R(fixedRate × minutes, 60)`. Settings in the Attendance group (main design 9.9): `overtime_rate_mode` (`multiple`), `overtime_multiplier_percent` (150), `overtime_fixed_rate` (0 rupees an hour), `overtime_hours_per_day` (8) (defaults, owner may change). Overtime is taxable.
- **One-off items** (HR, per line): additions (label, amount, taxable yes by default) and deductions (label, amount).
- **Fixed deductions and fixed tax** are prorated by calendar days like earnings (`R(amount × segment days, D)`), never reduced by attendance (default, owner may change).
- **Tax:** none; fixed (prorated); percentage `R(base × bp, 10000)` with base = taxable gross − taxable share of the attendance deduction + overtime + taxable additions (mode gross taxable) or earned basic − basic share (mode basic). The tax mode and rate are those of the profile in force on the last day of the pay window. HR may override one line's tax for that month (amount and reason; audited; kept across recalculation).
- **Loans** (5.4) are deducted last.
- **Net** = gross − attendance deduction − fixed deductions − one-off deductions − tax − loan deductions. Order when pay is short: attendance, fixed, one-off, tax are taken in full; loan installments are reduced (oldest loan first) so net is not below zero, the unpaid part stays on the loan (warning "installment reduced"). If net is still below zero before loans, the line blocks approval ("net pay below zero").

**Worked examples** (whole rupees; November 2026 has 30 days and starts on a Sunday; profile: basic 60,000, House rent 24,000 taxable, Medical 6,000 not taxable, fixed deduction Provident fund 3,000, tax 5.00% of gross taxable; gross 90,000, taxable gross 84,000; calendar-days basis; late rule 3):

1. Full month, 2 absent days, 1 half day, 5 late days, 1 unpaid leave day, loan installment 5,000: halves 4 + 1 + 2 (lates: 5 ÷ 3 = 1 day) + 2 = 9 → attendance deduction R(90,000 × 9, 60) = 13,500; taxable share 12,600; tax R(71,400 × 500, 10,000) = 3,570; net 90,000 − 13,500 − 3,000 − 3,570 − 5,000 = **64,930**.
2. Joined 16 Nov (15 days): basic 30,000, house rent 12,000, medical 3,000 = 45,000; provident fund 1,500; tax 5% of 42,000 = 2,100; net **41,400**.
3. Joined 10 Dec 2026 (22 of 31 days): basic R(1,320,000, 31) = 42,581; house rent 17,032; medical 4,258; gross 63,871; provident fund 2,129; tax R(59,613 × 500, 10,000) = 2,981; net **58,761**.
4. Raise from 16 Nov to basic 66,000 / house rent 26,400 / medical 6,000 (gross 98,400), absent on 20 Nov: earned basic 63,000, house rent 25,200, medical 6,000 = 94,200; attendance deduction R(98,400 × 2, 60) = 3,280.
5. Basis `thirty`, February 2027 (28 days), 1 absent day: deduction 3,000; basis `working_days`, November 2026 with Saturday/Sunday off and no holidays (W = 21), 1 absent day: R(90,000 × 2, 42) = 4,286.
6. Overtime on, mode multiple 150%, 8 hours a day, 600 minutes in November: R(90,000 × 600 × 150, 30 × 8 × 60 × 100) = 5,625.
7. Rounding half up: an allowance of 15,001 for 15 of 30 days = 7,500.5 → 7,501.

### 5.4 Loans and advances

- A loan: employee, type (loan / advance), amount, monthly installment (an advance defaults to its whole amount), first deduction month, note. **Remaining is derived, never stored** (Phase 4 lesson): amount − repaid before go-live (opening loans only) − sum of repayments. Status `active` or `cancelled`; "cleared" = active with nothing remaining.
- Each run month on or after the first deduction month deducts min(installment, remaining) unless that month is skipped (HR, with a reason; one month at a time per skip, main design 9.5). Approval writes one repayment per loan per item; a manual (cash) repayment can be recorded by HR with a note.
- Edits: installment and note any time (recalculation picks them up); amount and first month only before the first repayment; cancel (write off the remainder) by Admin with a reason. A loan with repayments is never deleted. Skips and repayments in locked months never change.
- Who: `payroll.run` with the same "own / privileged only by Admin" rule as profiles; seen with `payroll.view`. All audited.

### 5.5 Opening figures at go-live

- **Tax deducted to date** and **taxable income to date** per employee per tax year (form or Excel import). They feed the yearly tax report.
- **Loans in progress:** created as loans with "repaid before go-live" (form or Excel import: original amount, repaid, installment, first deduction month).
- **Leave used this year:** already built (Phase 4 Leave → Balances import); the opening figures page links to it.
- Tax year = Pakistan's July–June (`settings('payroll.tax_year_start_month')`, default 7; default, owner may change); a tax year is named by its start year and shown as "2026-27".

### 5.6 Run lifecycle

Statuses: `draft` → `approved` (= locked) → `paid`. (The main design's separate "locked" is the approval itself.)

- **Generate** (`payroll.run`): pick a month up to the current month and after the latest approved run's month (default, owner may change). Creates the draft (one per month) and a line per person in the run. A draft of the current month is allowed as a preview.
- **Review** (`payroll.run`): recalculate all or one line; per line add / remove one-off items, override tax, skip a loan month, exclude / include. HR cannot edit their own line or an Admin / HR person's line (Admin can). Delete a draft (audited).
- **Checks** shown on the run: blocking — month not ended yet (`attendanceToday()` still in the month); an earlier month's run still a draft; a person with no salary profile; unresolved missing clock-outs (`needs_attention`) or days still being calculated (`status` null or `needs_rebuild`) on counted days of the month for people in the run (main design 9.3 line 252); open leave requests (pending or waiting for HR) touching the month for people in the run; a line with net below zero. Warnings — no bank account (paid outside the sheet), settled separately, left without a last working day, excluded lines, tax overridden, installment reduced, unpaid leave on a day off or a worked day, profile changed mid-month, attendance start date inside or after the month (earlier days not checked).
- **Approve** (`payroll.approve`, Admin). Nobody approves a run containing their own line unless they are the only active Admin (audited "approved own payslip"), as in Phase 4. Inside one transaction: lock the run row; refuse unless draft and no blocking check; lock the loans of people in the run; **recalculate every line**; if any figure differs from what was reviewed, save the new figures, commit, and ask Admin to review again ("Figures changed since the last calculation for N people"); else snapshot each line's bank details and the company header and payslip footer onto the run, write loan repayments, set `approved`, audit with totals. After commit: payslips are generated and emailed (5.8).
- **Mark paid** (`payroll.run`): approved → paid with the payment date. Informational.
- **No re-opening.** An approved run is never edited, re-opened or deleted (main design anti-goal). A mistake is fixed by a one-off item in the next run (default, owner may change: an Admin re-open before payslips are emailed could be added later).
- Runs are approved in month order (a later month cannot be approved while an earlier draft exists). The first run ever can be any month (go-live).

### 5.7 What "locked" means

`PayrollLock::isLocked('Y-m')` is true when that month has an `approved` or `paid` run. Inside a transaction it reads the run row with a shared lock, so a write racing an approval waits for it and then sees the lock. Once a month is locked:

- **Leave:** applying for, approving, cancelling or refunding leave days in it is refused or skips those days (already wired in Phase 4).
- **Attendance:** manual corrections and correction requests for its days are refused; rebuild marks are never set on its days and the rebuilder leaves them untouched (late punches are stored but change nothing); holidays cannot be added, moved or removed on its dates.
- **Employees:** a joining date or last working day change whose old-to-new range touches a locked month is refused.
- **Salary:** profiles cannot start in it or before it; loan skips and repayments in it are fixed.
- Settings changes never alter a stored line (lines are frozen).

### 5.8 Payslips and email

- After approval each line in the run (not excluded) gets a PDF rendered from its stored breakdown and the run's snapshot (company name, address, logo, footer): company header, employee and month, earnings, deductions, tax, net pay, attendance summary (main design 9.5). Stored on the private disk; a missing file is re-rendered from the same stored data, so it is identical.
- Emailed (queued, after commit, through the scheduler-drained queue) to employees with an active login, **with the PDF attached** (default, owner may change: link only). HR can resend one payslip.
- **My Payslips** (everyone): own lines of approved / paid runs, download. **Payroll → Payslips** (`payroll.view`): every payslip, by month and person, download and resend.

### 5.9 Bank sheet and reports

- **Bank transfer sheet** (`payroll.run`, approved / paid runs): `.xlsx`, one row per paid line with a bank account (employee code, name, bank, account title, account number / IBAN as text, amount as a number), a second sheet "Paid outside the bank sheet" for lines without one. A generic format (default, owner may change: per-bank layouts later). Bank details come from the snapshot taken at approval.
- **Monthly payroll summary** (`payroll.view`): per run on screen and as `.xlsx` (every line's figures as numeric cells, totals).
- **Yearly tax report** (`payroll.view`): per tax year, per employee: opening tax and taxable income, then each month's taxable pay and tax, totals; screen and `.xlsx`. Phase 6 adds final settlements to it.
- Every export is audited and formula-safe (`App\Exports\TextCells`).

### 5.10 Screens

- **Payroll → Salary Profiles** (`payroll.view`): employees with current basic, gross, tax, effective date, "no profile" filter; per person history and the new-profile form.
- **Payroll → Payroll Runs:** runs list and generate; run page with totals, checks, lines, line drawer with the full breakdown and edits, approve, mark paid, downloads, settled-separately list.
- **Payroll → Payslips**, **Loans & Advances** (list, loan page with repayment history and projected schedule), **Opening Figures**, **Tax Report**.
- **My Payslips** (everyone).
- **Employee profile → Salary tab** (`payroll.view` only): current profile, history, loans, recent payslips.
- **Dashboard:** "Payroll This Month" tile (`payroll.view`): the run of the pay month = the month before the current one (paid in the first days of this month): status, people, net total; "Not generated" with a link otherwise. "My latest payslip" card for everyone with an employee record.
- **Settings:** Payroll group adds the tax year start month; Attendance group adds the overtime rate inputs.

### 5.11 Audit

Area `payroll`: profile created / updated / deleted; loan created / edited / skipped / repaid / cancelled; opening figures saved / imported; run generated / recalculated / deleted / approved (with totals, own-payslip flag) / marked paid; line adjusted / tax overridden / excluded; payslip resent; exports (bank sheet, summary, tax report). Old and new values.

## 6. Data (outline; the plan fixes columns)

All money columns `bigInteger` paisa.

- `salary_profiles`: employee, effective_from, basic, tax_mode, tax_value (paisa for fixed, basis points for percentage), tax_base, note, created_by; unique (employee, effective_from).
- `salary_components`: profile, name, type (allowance / deduction), amount, is_taxable, sort_order.
- `loans`: employee, type, amount, installment, start_month, opening_repaid, status, note, cancel fields, created_by.
- `loan_repayments`: loan, payroll_item (nullable: manual), amount, month, note, created_by. (Phase 6 adds the final settlement link.)
- `loan_skips`: loan, month, reason, created_by; unique (loan, month).
- `opening_balances`: employee, tax_year, tax_deducted, taxable_income, note; unique (employee, tax_year).
- `payroll_runs`: month (unique), status, headcount and totals, generated / approved / paid by and at, paid_on, summary (JSON: company snapshot, settled-separately and left lists, checks at approval).
- `payroll_items`: run, employee, excluded + reason, figures (basic, allowances, overtime, additions, gross, attendance_deduction, fixed_deductions, other_deductions, loan_deduction, taxable_pay, tax, net), tax override fields, breakdown (JSON), bank snapshot, payslip path / generated / emailed; unique (run, employee).
- `payroll_adjustments`: item, label, type, amount, is_taxable, created_by.
- Settings: `payroll.tax_year_start_month` (7); `attendance.overtime_rate_mode`, `overtime_multiplier_percent`, `overtime_fixed_rate`, `overtime_hours_per_day`.

## 7. Open decisions (defaults chosen; owner may change)

1. Rounding: every computed amount half up to the whole rupee; entered amounts whole rupees.
2. Day-pay basis default calendar days (already the settings default); proration always by calendar days.
3. Unpaid leave from the leave ledger on every pay-window day; absences, half days and lates from attendance from the attendance start date; per-date cap of one day; unpaid leave on a day off not deducted.
4. Fixed deductions and fixed tax prorated by calendar days for joiners and mid-month profile changes; not reduced by absences.
5. Tax mode and rate from the profile in force on the last day of the month.
6. Late-rule days charged at the last profile of the month.
7. Pay short: loan installments reduced first; still negative blocks approval.
8. Overtime settings in the Attendance group: multiple 150% of hourly pay, 8 hours a day (overtime stays off).
9. Generation: any month up to the current one, after the latest approved month; approval only after the month ends, in month order.
10. No re-opening of approved runs; corrections as next-month one-off items.
11. Approver's own line: only the sole active Admin may approve it (audited).
12. Payslip email carries the PDF attachment.
13. Bank sheet: one generic `.xlsx`.
14. Tax year July–June.
15. Leavers in their final month and leavers without a last working day are listed, never paid by the run (final settlement is Phase 6).
16. Loans are disbursed outside the system; payroll only recovers them.
17. HR sees every salary including Admin's (needed to review a run); editing an Admin / HR person's salary or line, or one's own, is Admin only.
18. Dashboard "Payroll This Month" shows the previous calendar month's run.
19. PDF library: `barryvdh/laravel-dompdf` (main design stack), installed in this phase.

## 8. Review focus

1. Approval racing a leave approval, an attendance correction, a profile save, a loan repayment or a line edit → one outcome; locked month refuses later writes; recalculation at approval catches anything earlier.
2. Arithmetic edges: joiner, mid-month profile change, 28–31-day months under each basis, half-day leave combinations, the per-date cap, rounding at exactly half a rupee, pay short of deductions.
3. Source conflicts: unpaid leave before the attendance start date, on a manual worked day, on a day off; exempt staff; leavers in and after their final month.
4. Privacy: Manager / Employee on every payroll route, id, PDF, prop and email; HR on own or an Admin's salary.
5. Immutability after approval: profile, settings and loan edits, PDF re-render, attendance / leave / holiday / employment-date changes in the month.

## 8a. Left to the builder

Page layouts within the design system; exact file names beyond the plan; email wording; PDF styling (one A4 page, DejaVu Sans for the currency text); test structure, provided the review-focus cases and every worked example are covered.
