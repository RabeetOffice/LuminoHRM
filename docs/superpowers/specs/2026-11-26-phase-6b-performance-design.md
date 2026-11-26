# Lumino HRM Phase 6b (Performance): design

Date: 2026-11-26. Builds on the main design, `docs/superpowers/specs/2026-11-23-lumino-hrm-design.md` (sections 8, 9.2, 9.6, 11), on Phases 3 to 6a as built (`main`) and on the Phase 6a spec for conventions. Where this document and the main design differ, this one wins. Phase 6 is split: 6a Assets (built), 6b Performance (this document), 6c Exit.

## 1. Intent

HR stops running reviews in documents and mail. Each quarter (or month) HR opens a cycle; every person who should be reviewed gets a review with their own list of KPIs; the employee rates themselves, the manager rates each KPI, HR closes, and the person sees the result. Closed reviews stay as history on the profile.

Success (phase gate): HR runs one real cycle end to end in the system (KPIs entered, cycle opened, self-reviews, manager reviews, all closed, cycle closed), and every manager and employee found their own reviews without help.

## 2. Goals, non-goals, anti-goals

**Goals:** KPIs per designation or per person; review cycles with a due date; one review per person per cycle with a frozen copy of their KPIs; self-review (optional), manager review, HR close, send back, reopen, skip, change reviewer; weighted score and label; Cycles, Cycle page, KPIs, Team reviews, review page, My Reviews; a "Reviews" tab on the profile; a dashboard card; Settings > Performance.

**Non-goals (v1):** 360 feedback, goals or OKRs, links to salary increments, a PDF review form, email notices (in-app waiting list only), bulk close, comments threads, review templates other than KPIs.

**Anti-goals (failure even if it works):**
- An employee seeing the manager's ratings, comments or score before the review is closed (by screen, prop, list, profile tab or dashboard).
- Anyone reading a draft that is not theirs (the employee's self-ratings before they submit are not shown to the manager).
- A review changed twice by two clicks, two tabs or two users (double submit, close against send back, skip against submit).
- A closed cycle changing, or a review's KPIs or weights changing after the cycle opened.
- A person rating, or closing, their own review.

## 3. Constraints

All Phase 0 to 6a constraints hold: localhost only, no deploys; PHP at `C:\php84\php.exe`; focused tests; work on `main`; commit by explicit path; naive dates (`Y-m-d`, `onDate` / `betweenDates`), `AttendanceClock::attendanceToday()` the only "today"; audit in the same transaction through `Audit::record`; 403 for ids out of scope; models never log by themselves.

- **Permissions** already seeded (no new names): `performance.manage` (admin, hr), `performance.review-team` (admin, hr, manager). Everyone has My Reviews and their own review.
- **Locking** (MariaDB REPEATABLE READ, `DB::transaction(fn, 3)` everywhere): see 5.9. No employee, payroll, leave or asset lock is taken. Kept simple on purpose: one cycle row, then one review row.
- Scores are integers in hundredths (412 = 4.12), never floats; formatted for people as "4.12".

## 4. Approaches considered

How a review keeps its KPIs after the KPI list changes.

- **A. Read the live KPI list.** Rejected: editing a weight rewrites history.
- **B. Snapshot per review (chosen):** `review_scores` rows hold name, description and weight copied at open (or add). `kpi_id` stays only as a link for "is this KPI in use".
- **C. Version the KPI.** More tables for the same result.

## 5. Decided behaviour

### 5.1 KPIs

- Fields: name (1 to 120), description (optional, 500), weight (whole number 1 to 100; a relative weight, not a percentage, nothing needs to total 100), active flag (new KPIs start active).
- Each KPI belongs to exactly one designation or exactly one employee. Who: `performance.manage`.
- Deactivating stops it from being copied into future reviews and future "add people"; reviews already open keep it. Editing never touches existing snapshots.
- Delete only while no review uses it (any `review_scores` row, open or closed): "N review(s) use this KPI, so it cannot be deleted. Deactivate it instead." A designation with KPIs cannot be deleted either ("N KPIs use this designation."); an employee with reviews or KPIs cannot be deleted (5.10).

### 5.2 Settings > Performance

Group `performance` (Admin, `settings.manage`, as the other company settings): `review_period` `quarterly` (default) or `monthly`; `self_review_enabled` default on. A due-date offset of 14 days is a config value (`config('hrm.performance.default_due_days')`), not a setting. Changing a setting never alters a cycle already opened: the cycle stores its own period type and self-review flag.

### 5.3 Cycles

- Periods are calendar periods: quarters Jan to Mar, Apr to Jun, Jul to Sep, Oct to Dec, or whole months. Name: "Q3 2026 (Jul–Sep)" or "November 2026".
- HR opens a cycle for a period that has started (start on or before `attendanceToday()`), whether or not it has ended; never a future one. The screen offers the current and the last eight periods of the set type that no cycle covers; the server accepts only a period key it would offer (`2026-Q3`, `2026-11`) and rebuilds the dates itself.
- One cycle per period, and cycles never overlap (a monthly cycle inside an opened quarter is refused; the reverse too).
- Due date: default `period_end + 14 days`, editable at open, never before the period end. It only drives an "overdue" badge; nothing is blocked by it.
- States: `open`, `closed`. Closing needs every review `closed` or `skipped`. A closed cycle is read-only for ever (no add people, no workflow action, no reopen).

### 5.4 Opening a cycle and adding people

- Eligible: employee status `active` or `probation`, `joining_date` on or before the period end, and at least one active KPI (their designation's or their own). People who meet the first two but have no KPIs are listed as "no KPIs" on the result and on the cycle page, so HR can fix the KPIs and use **Add people** while the cycle is open. Add people applies the same rules at that moment; a person already in the cycle is refused.
- Opening with nobody eligible is refused ("Nobody can be reviewed yet. Add KPIs first."); no empty cycle is created.
- Each review: `reviewer_id` = the employee's `manager_id` at that moment (never the person themselves), or none = "HR" (any `performance.manage` user). Start state `self_review` when the cycle's self-review flag is on, else `manager_review`. Snapshot rows (`review_scores`): designation KPIs first, then personal ones, each by name then id.
- A manager with no active login, or whose login lacks `performance.review-team`, is still stored as reviewer; the cycle page flags "Reviewer cannot sign in or lacks access" and HR changes the reviewer (5.5).

### 5.5 The flow of one review

| From | Action (who) | To |
|---|---|---|
| (open) | cycle opens or person added | `self_review`, or `manager_review` when self-review is off |
| `self_review` | submit self-review (the employee) | `manager_review` |
| `self_review` | waive self-review (HR) | `manager_review` |
| `manager_review` | submit manager review (the reviewer) | `ready_to_close` |
| `ready_to_close` | send back with a note (HR) | `manager_review` |
| `ready_to_close` | close (HR): the score freezes | `closed` |
| `closed` | reopen (HR, cycle still open): the frozen score is cleared | `ready_to_close` |
| `self_review`, `manager_review`, `ready_to_close` | skip with a reason (HR) | `skipped` |
| `skipped` | restore (HR, cycle still open) | the state it was skipped from |

Anything else is refused ("This review is {state} and cannot be changed that way."). A `closed` review cannot be skipped (reopen first).

- **Drafts:** the employee saves self-ratings, the reviewer saves manager ratings and the overall comment, any number of times while their stage is open; saving a draft is not audited and changes no state. Submitting needs every KPI rated 1 to 5 (comments optional, up to 1,000 each; overall comment up to 2,000). Once submitted, a side is read-only until HR sends the review back (manager side only) or reopens it.
- **Reviewer:** HR changes it while the review is not closed (a skipped review too): to an employee who has an active login holding `performance.review-team` and is not the reviewee, or to "HR". The previous reviewer loses access at once; saved drafts stay.
- **Own review:** nobody rates their own review as manager or closes it. For an HR or Admin person's own review the reviewer is their manager, or "HR", meaning another `performance.manage` user; closing is another `performance.manage` user. Only when the reviewee is the sole active `performance.manage` user may they do it themselves, and the entry is flagged `own_review: true` (the payroll approval principle).
- **HR waive** and **restore** are added to the audit list in 5.8.

### 5.6 Score

- Overall score = weighted average of the manager's ratings: `sum(rating * weight) / sum(weight)`, rounded half up to 2 decimals, in integer hundredths, stored as `final_score` at close and cleared on reopen. The self score is computed the same way from the self-ratings, stored at self submit, for display.
- Label: 4.50 and over Outstanding, 3.50 Exceeds, 2.50 Meets, 1.50 Below, else Unsatisfactory (thresholds in `config('hrm.performance.labels')`). Computed by `App\Support\Performance\ReviewScoring` only; the page shows a live preview for HR at `ready_to_close`.

### 5.7 Who sees what

| | Employee (reviewee) | Reviewer (manager) | HR / Admin | Other |
|---|---|---|---|---|
| Own self-ratings, draft | yes | no (not until submitted) | yes | no |
| Self-ratings, submitted | yes | yes | yes | no |
| Manager ratings, comments, overall comment | **only when `closed`** | yes (their own drafts too) | yes | no |
| Score and label | only when `closed` | yes | yes | no |
| HR notes (skip reason, send-back note) | send-back note no; skip reason no | send-back note yes | yes | no |
| Reviews listed | own, all states | reviews where they are reviewer (any state, open cycles) and the closed history of their current direct reports | everything | none |

- A person looking at their own review sees the reviewee view whatever their role: an HR or Admin user does not see manager ratings on their own review before close (except as the sole `performance.manage` user rating it, which is their own work).
- A `skipped` review shows the employee only "Skipped".
- Everything not allowed is left out of the props (key absent), not hidden by the page. Routes outside the viewer's rights are 403.
- **Profile "Reviews" tab:** closed history of that person (cycle, score, label, reviewer, link), for whoever may open that profile (own, `employees.view-all`, direct report). **My Reviews:** the account's own employee record only. **Team reviews** (`/performance/reviews`, `performance.review-team` without `performance.manage`): the manager's rows per the table.

### 5.8 Audit

Area `performance` (added to `Audit::AREAS`; section in `docs/audit-log.md`), always `Audit::record` in the transaction of the change: `KPI created`, `KPI updated` (old and new), `KPI deactivated` (its own entry when the active flag goes off), `KPI deleted`; `Review cycle opened`, `Review cycle people added`, `Review cycle closed`; `Review reviewer changed`; `Self-review submitted`, `Manager review submitted`, `Review sent back` (the note), `Review closed` (score, label), `Review reopened`, `Review skipped` (the reason), plus `Self-review waived` and `Review restored`. Subject the review (the cycle for cycle entries, the KPI for KPI entries). Properties carry the employee, cycle, scores in hundredths and `own_review` where it applies, never rating or comment text. Draft saves are not audited.

### 5.9 Locking

| Action | Order |
|---|---|
| Open cycle | every cycle row FOR UPDATE (overlap check), then insert cycle and reviews |
| Add people, close cycle | cycle row FOR UPDATE, then (close) the cycle's review rows |
| Draft save, submit, waive, send back, close, reopen, skip, restore, change reviewer | plain read of the review's cycle id, cycle row `sharedLock`, then review row FOR UPDATE; the state and the cycle's status are re-checked after both |
| KPI create, update, delete | KPI row FOR UPDATE (delete: then the usage check) |

One order everywhere: cycle row first, review row second. Closing a cycle takes the cycle exclusively, so no review change can slip in between "all done" and "closed". A unique index on `(review_cycle_id, employee_id)` and one on `(period_start, period_end)` are the database backstops. No employee, payroll or asset lock is involved.

### 5.10 Deleting an employee or a designation

Refused when the employee has any review or personal KPI ("This employee has performance reviews. Set the status to Inactive, Resigned or Terminated instead."), in `EmployeeAccess::deleteBlockers` and again under the lock in `DeleteEmployee`; the restrict foreign key is the backstop. A designation with KPIs is refused through `Designation::deleteBlocker`.

## 6. Data (outline; the plan fixes columns)

- `kpis`: name, description, weight (unsigned tiny), is_active, designation_id (nullable, restrict), employee_id (nullable, restrict); a saving hook and the action enforce exactly one of the two.
- `review_cycles`: name, period_type, period_key (unique), period_start, period_end (unique together), due_on, status, self_review_enabled, opened_by, closed_by, closed_at.
- `reviews`: review_cycle_id (restrict), employee_id (restrict), reviewer_id (nullable employee, restrict), status, self_score, final_score (hundredths, nullable), manager_comment, send_back_note, skip_reason, skipped_from, self_submitted_at, manager_submitted_at, closed_at, closed_by; unique (review_cycle_id, employee_id).
- `review_scores`: review_id (restrict; reviews are never deleted), kpi_id (nullable, restrict), name, description, weight, sort_order, self_rating, self_comment, manager_rating, manager_comment.
- Config: `hrm.performance` (periods, default due days, review states, label thresholds). Settings group `performance`. No new permissions.

## 7. Open items (not decided by the owner; the plan builds the stated default)

1. **Settings > Performance** is Admin only (`settings.manage`). HR can be added later by one config line.
2. **Reopen** returns a review to `ready_to_close` (not to the manager).
3. **Restore** of a skipped review exists, to the state it was skipped from (a mistaken skip would otherwise be permanent). Audited as `Review restored`.
4. **Waive** of a self-review is audited as `Self-review waived`.
5. **HR sees drafts** ("HR and Admin see everything"); the manager sees the employee's self-ratings only once submitted.
6. **Comments** are optional; ratings are required to submit.
7. **Skipped reason** is not shown to the employee.
8. **Opening with nobody eligible** is refused.
9. **Due date** is set at open only; not editable afterwards.
10. **Reviewer without a login or access** is kept as stored and flagged; HR changes it (no silent fall back to HR).
11. **HR cannot rate on a manager's behalf**; HR changes the reviewer instead.
12. **No bulk close** in v1: reviews are closed one at a time.

## 8. Review focus

1. Visibility: the employee never gets manager data before close, on any page or list; drafts are private; HR reviewing themselves sees the reviewee view (the walk-props test, Task 5, and the profile, My Reviews and dashboard tests).
2. State machine: every cell of the table in 5.5, refused edges, stale-model double actions, close against send back, skip against submit, cycle close against a late submit.
3. Locking: statement-order test (cycle row, then review row), no path locks a review then a cycle; unique indexes as backstop.
4. Own review: nobody rates or closes their own; sole-user exception audited.
5. Snapshots: KPI edits, deactivation and designation changes after open do not change a review; add people uses live KPIs at that moment.
6. Score: weighted average, half-up rounding, label edges (4.50, 3.50, 2.50, 1.50), cleared on reopen.
7. Dates: period rules, future refused, overlap, due default, naive dates on every screen.

## 8a. Left to the builder

Page layouts within the design system; file names beyond the plan; wording of empty states; test structure, provided every refusal and review-focus case is covered.
