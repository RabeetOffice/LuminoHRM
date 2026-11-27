# Lumino HRM Phase 4 (Leave): design

Date: 2026-11-25. Builds on the main design, `docs/superpowers/specs/2026-11-23-lumino-hrm-design.md` (sections 8, 9.1, 9.3, 9.4, 9.9, 9.10, 11), and on Phase 3 Attendance as built (HEAD `4fbe1ac`, branch `phase-3`). Where this document and the main design differ, this one wins.

## 1. Intent

Staff apply for leave in the app instead of on paper or chat; their manager and HR approve it; everyone can see who is off and how many days each person has left. Approved leave must land in attendance as "On Leave" so that the monthly figures, and from Phase 5 payroll, are right without HR fixing days by hand.

Success: an employee applies, the manager and HR approve by email link and screen, the balance drops by the right number of days, the attendance days show On Leave, and HR never has to reconcile a balance by hand.

## 2. Goals, non-goals, anti-goals

**Goals**

- Leave types, balances per employee per type per calendar year, requests with manager and HR approval, cancellation, opening balances at go-live, year-end allocation with carry-forward, a company calendar, emails, and the leave numbers on the dashboard and profile.
- Approved leave feeds the existing attendance calculator (`DayInput::onLeave`, already present and always false today).

**Non-goals (v1)**

- Hourly or short leave; leave without a type; comp-off earned from overtime; accrual month by month (quota is allocated once a year); leave encashment (Phase 6 exit, setting already exists and stays off); multi-level approval beyond manager then HR; delegation of approval while a manager is away.
- Holidays: already built in Phase 3 (`HolidayController`, `leave/holidays`). This phase only reads them, adds them to the calendar, and handles the holiday-on-leave-day case (section 5.8).

**Anti-goals (failure even if it works)**

- A balance that can drift from the requests behind it.
- Approving or cancelling leave silently rewriting a closed past attendance day that a person corrected by hand, or a day in a locked payroll month.
- A Manager or Employee seeing someone else's leave by changing an id in the URL.

## 3. Constraints

- All Phase 0–3 global constraints hold: localhost only, no deploys; PHP at `C:\php84\php.exe`; focused tests while working; commit by explicit path; naive date conventions (`onDate` / `betweenDates`, `Y-m-d` strings) and `AttendanceClock::attendanceToday()` as the only "today" for attendance-affecting decisions.
- Permissions already seeded: `leave.approve-all` (admin, hr), `leave.approve-team` (admin, hr, manager), `settings.attendance-leave` (admin, hr). Everyone may apply and view their own.
- Approval follows the reporting line (main design section 8): the manager step goes to the employee's reporting manager whatever that person's role; no manager, or the manager is the applicant, skips the step. A request by an HR user is finally decided by Admin. Same rulings as Phase 3 correction requests: HR/Admin may decide at the manager stage (audited as manager step skipped); a manager's approval stands if the reporting manager later changes.
- Payroll lock does not exist yet (Phase 5). This phase adds one check function, `PayrollLock::isLocked(Y-m)`, that returns false for now; every place that refuses because of a lock calls it.
- Emails go through the existing queue drained by the scheduler.

## 4. Approaches considered

How approved leave is stored, since attendance, balances and payroll all read it.

- **A. Running "used" counter on each balance** (the main design's data model): simplest at first, but can drift from the requests after partial cancels, backdated approvals or concurrent actions, and attendance would have to unpack date ranges on every day it builds.
- **B. One row per counted leave day; balances derived (chosen by the owner).** Approval writes a `leave_days` row per counted day (1 or 0.5, half `am` / `pm`). Attendance asks "is there a leave day for this person on this date". Balance = allocated + carried + opening adjustment − sum of leave days for that type and year, so it cannot drift. Cancelling the future part deletes those rows. Payroll counts unpaid leave days per month from the same rows. Cost: one more table and a transactional approval write.
- **C. Derive leave days from requests every time:** no extra storage, but results change when a holiday or shift is edited after approval, rewriting history, which Phase 3 deliberately prevents.

Chosen: **B**, because it matches Phase 3 (one row per person per day, rules frozen when decided) and makes partial cancellation and payroll straightforward.

## 5. Decided behaviour

### 5.1 Leave types

- Fields: name (unique), yearly quota (days, 0–365, halves allowed), paid or unpaid, carry-forward on/off, carry-forward cap (days), active on/off, colour for the calendar (from a fixed palette).
- Unpaid type: no quota and no balance limit; it is the type staff are pointed to when a paid balance is short. Exactly one unpaid type is seeded and it cannot be deleted.
- Seeded defaults (open item 7): Annual 14 (carry forward, cap 5), Sick 8 (no carry forward), Casual 10 (no carry forward), Unpaid.
- A type with requests or balances cannot be deleted; it can be made inactive (no new requests; history stays).
- **Carry-forward cap lives on each type.** The Settings → Leave "carry-forward cap" input is removed (the main design had it in both places). Owner may reverse this.
- Edits are audited (area `leave`).

### 5.2 Balances

- One `leave_balances` row per employee, paid type and year: `allocated`, `adjustment` (signed days, HR corrections), `opening_used` (days already used this year before go-live, entered by HR).
- **Carried is never stored; it is worked out when read (builder-check call, avoids drift):** carried into year Y = min(remaining of year Y−1 excluding pending, the type's cap) for carry-forward types, else 0; never negative. A backdated December approval, an adjustment to last year or a holiday refund therefore flows into this year by itself.
- **Remaining = allocated + carried + adjustment − opening_used − sum(leave_days of that type in that year).** Pending requests are shown as "pending" next to the balance and also reserve days: applying checks remaining − pending (for next year, carried is the projection from this year's remaining minus this year's pending).
- **Allocation:** rows are created on 1 January by a scheduled job for every employee employed on that date, and also on demand the first time anything needs a row for a year (a request for next year, a new joiner, a new type). Creating a row that already exists changes nothing, so the job can run twice. Applying for next year is therefore allowed and checked against next year's quota plus the projected carry.
- **New joiners (setting `leave.prorate_new_joiners`, default on):** allocated = quota × remaining months including the joining month ÷ 12, rounded down to the nearest half day. Created when the employee is created (or when a type is created, for everyone current). With pro-rating off, the full quota.
- **Quota change mid-year:** does not touch existing rows; HR can adjust a row (below). New rows use the new quota.
- **HR adjustment:** HR/Admin can edit `allocated`, `adjustment` and `opening_used` on a balance row with a required reason; audited with old and new values.
- **Opening balances at go-live:** HR enters days already used this year per employee and type, by form (the balance edit above) or Excel import with a template, preview and per-row errors, following the employee import pattern.
- A balance for a year with leave days cannot go below zero by adjustment without a warning that the person is overdrawn; it is allowed and shown in red (HR may be fixing an old mistake).

### 5.3 Day count

- A request covers `from_date`..`to_date` inclusive, or a single date as a half day (`am` or `pm`).
- Counted days skip the employee's own weekly offs (from their shift as of each date; default weekly offs if no shift) and holidays. A request whose dates are all offs or holidays is refused.
- Days outside the employment window (before joining, after the last working day) are refused.
- Overlap: a request may not overlap another pending or approved request of the same employee on any counted day (a half `am` and a half `pm` on the same date may coexist).
- A request may span a year boundary; its days count against each year's balance separately, and each year must have enough.

### 5.4 Applying

- Anyone with an employee record applies for themselves (My Leaves). HR/Admin can also apply on behalf of an employee (it enters the same flow, recorded as applied by them).
- Reason required. Date window: from 30 days back (by `attendanceToday()`) up to the end of next calendar year. A past date in a locked payroll month is refused.
- Paid types: refused if days exceed remaining − pending for any year touched, with the message pointing to Unpaid leave. Unpaid: no limit.
- Optional attachment (one file, e.g. a medical note; PDF/JPG/PNG, private disk, same rules as employee documents). My call: useful for sick leave, cheap given the document store exists.
- Email to the first approver.

### 5.5 Approval

- Statuses: `pending` (waiting for manager), `manager_approved` (waiting for HR), `approved`, `rejected`, `cancelled`.
- Manager step: the reporting manager approves or rejects. Routing copies Phase 3 `CorrectionRouting` exactly (builder-check call, keeps one rule across the app): the step is skipped when there is no manager, the manager has no or a disabled login, the manager is the applicant, the manager lacks `leave.approve-team` (an Employee-role person with reports), or the manager holds `leave.approve-all` (an HR/Admin manager decides once, as the final approver). The step is worked out when needed, not stored.
- HR step: HR or Admin gives final approval. If setting `leave.hr_step_enabled` is off, the manager's approval is final; a request with no manager step then goes straight to HR/Admin as its only step.
- A request by an HR user is finally decided by Admin (not by another HR user); a request by an Admin user is decided by another Admin, or the same Admin if they are the only one (audited).
- Nobody approves their own request, and the person who approved as manager never also decides the HR step. The single exception is a request by the only active Admin, which that Admin may decide (audited).
- Reject needs a reason. Each decision is audited and emails the employee; manager approval with an HR step remaining emails HR (all users with the HR role).
- **Approval re-checks** the balance and overlaps at that moment (inside the transaction, with the employee's balance rows locked) and refuses if they no longer hold.
- **On final approval:** write the `leave_days` rows (date, leave type, amount 1 or 0.5, half) from the day count at that moment; then mark those attendance days for rebuild (section 5.7).

### 5.6 Cancelling

- While `pending` or `manager_approved`: the employee cancels their own request; HR/Admin may too.
- Once `approved`: only HR or Admin cancel, with a required reason. Leave days dated on or after `attendanceToday()` are deleted and go back to the balance; days before it stay (the request shows as "partly cancelled", status `approved` with `cancelled_from` set). If no day was taken yet, status becomes `cancelled`. Days in a locked payroll month are never removed.
- Cancellation emails the employee and is audited; the affected attendance days are rebuilt.

### 5.7 Attendance link

- `AttendanceRebuilder` sets `DayInput::onLeave` from `leave_days` for that employee and date (full day), and a new half-day input for half-day leave.
- **Half-day leave (owner decided 2026-11-25):** the other half is judged with the shift's half-day threshold as the required hours; lateness and early departure are not counted that day. The attendance row keeps `leave_half` (am / pm) and its status describes the worked half:
    - worked minutes >= half-day threshold: status `present`; counts as 0.5 leave + 0.5 present, no deduction;
    - fewer minutes, or no punches once the day is closed: status `absent`; counts as 0.5 leave + 0.5 absent, so payroll deducts half a day. The two short cases are treated the same;
    - on an open day (today) with no punches yet, it waits like any open day.
      Monthly totals, the grid symbol (a half-leave mark next to the worked-half symbol), the Excel export and dashboard counts all use these halves; payroll reads the same figures in Phase 5.
- **Punches on a full leave day:** the day stays `on_leave`; times and worked minutes are recorded (calculator already does this). HR cancels the leave if the person really worked.
- **Manual days win:** a day HR corrected by hand is not overwritten by approval or cancellation; it appears in a "needs attention" note on the request so HR can decide. Manual corrections cannot set `on_leave` (unchanged from Phase 3).
- **Backdated approval** marks closed days for rebuild; closed non-manual days are recalculated with their stored rule snapshot and become `on_leave`. Days before `attendance.start_date` get no attendance row change.
- Holiday and weekend keep precedence over leave in the calculator; since counted days skip them, they only meet when a holiday is added later (5.8).

### 5.8 Holiday added or removed after approval (my call)

- Adding a holiday on a date that has leave days: those leave days are deleted (balance refunded), the request records the change, the employee is emailed, audited. Removing a holiday does not add leave days back; HR sees the affected approved requests listed in the holiday delete confirmation and can ask the person to apply for that day.

### 5.9 Screens

- **Leave → Requests** (approvers): tabs Waiting for me / All with counts; filters by status, type, department, date range; row actions approve, reject, cancel; request detail with days list, balance at the time, attachment and history. Manager sees direct reports only; HR/Admin everyone.
- **Leave → Balances** (HR/Admin; Manager read-only for own team): employees × types for a chosen year, remaining / allocated, click to see the breakdown and adjust (HR/Admin). Excel export. Opening-balance import from here.
- **Leave → Leave Types** (HR/Admin, `settings.attendance-leave`).
- **Leave → Holidays**: existing.
- **My Leaves** (everyone): balance cards per type (remaining, used, pending), Apply dialog showing the counted days and remaining balance live, my requests with cancel.
- **Calendar** (Overview): month view with holidays, approved leave and birthdays. HR/Admin see everyone; Manager sees own team's leave; Employee sees holidays, birthdays and own leave only (my call; colleagues' leave is not shown to staff — owner may widen it).
- **Employee profile**: Leave balances tab (balances and recent requests), visible to whoever may see that profile.
- **Dashboard**: Pending Leaves tile (requests waiting for this user's decision; HR/Admin: all waiting), On Leave Today list, "on leave" series in the 30-day chart (already plotted from attendance), leave requests this month by status chart, Leave Balance tile for non-HR roles.
- Settings → Leave: HR step on/off, pro-rate on/off (cap input removed).

### 5.10 Emails

Apply → first approver; manager approved with HR step → HR users; approved / rejected / cancelled (by HR) / holiday refund → employee. Each email links to the request. Sent through the queue. No email to a user without an active login.

### 5.11 Audit

Area `leave`: type create/update/delete; request apply (incl. on behalf), each decision, cancel, partial cancel; balance adjustments and imports; year-end allocation run (one entry per run with counts); holiday refunds. Entries list old and new values.

## 6. Data (outline; the plan fixes columns)

- `leave_types`: name, yearly_quota, is_paid, carry_forward, carry_forward_cap, is_active, colour.
- `leave_balances`: employee_id, leave_type_id, year, allocated, adjustment, opening_used; unique (employee, type, year).
- `leave_requests`: employee_id, leave_type_id, from_date, to_date, is_half_day, half (am/pm), days, reason, attachment, status, applied_by, manager_decided_by/at, manager_skipped, hr_decided_by/at, rejected_reason, cancelled_by/at, cancel_reason, cancelled_from.
- `leave_days`: leave_request_id, employee_id, leave_type_id, date, amount (1 / 0.5), half; unique (employee, date, half).
- `attendances`: add `leave_half` (null / am / pm).
- Settings `leave.carry_forward_cap` removed.

## 7. Review focus

1. Two approvers act on the same request, or the employee cancels while the manager approves → one outcome, balance correct.
2. Balance edges: pro-rated joiner, year boundary request, carry-forward cap, opening used, overdrawn adjustment, half days.
3. Backdated approval over closed, manual and pre-start-date days; cancellation of an approved request part-way through.
4. URL tampering by Manager / Employee on requests, balances, attachments, calendar.
5. Half-day leave combined with punches on the same day.

## 8. Left to the builder

Page layouts and empty states within the design system; task split; exact columns, indexes and file names; email wording; calendar component choice (reuse existing UI kit, no new heavy dependency without need); test structure, provided the review-focus cases are covered.
