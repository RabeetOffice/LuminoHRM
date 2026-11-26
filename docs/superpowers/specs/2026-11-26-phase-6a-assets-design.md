# Lumino HRM Phase 6a (Assets): design

Date: 2026-11-26. Builds on the main design, `docs/superpowers/specs/2026-11-23-lumino-hrm-design.md` (sections 8, 9.2, 9.7, 9.8, 11), on Phases 3 to 5 as built (`main`), and on the Phase 5 spec for money, import and audit conventions. Where this document and the main design differ, this one wins. Phase 6 is split: 6a Assets (this document), 6b Performance, 6c Exit.

## 1. Intent

HR stops tracking laptops, phones and licences in a spreadsheet. Every item gets a register entry with a fixed code; HR hands it to an employee with a date, takes it back with a date and a condition, and the full history of who held what stays on the asset and on the person. A manager can see what their team holds; every employee can see what they hold. Phase 6c (Exit) later refuses clearance while anything is still held, through a small hook built here.

Success (phase gate): the real register is imported at go-live (holders included) and HR runs one week of hand-overs and returns in the system only; the register and the people agree with what is physically out.

## 2. Goals, non-goals, anti-goals

**Goals**

- Asset register with automatic codes, editable categories, status and condition; assign, return, status changes; full assignment history; All Assets, Assignments and My Assets screens; an "Assets" tab on the employee profile; assets held on the employee dashboard; `.xlsx` register import (go-live) with a template; `.xlsx` export; the `AssetHoldings` hook for Phase 6c.

**Non-goals (v1)**

- Depreciation (main design anti-goal), asset requests by employees, QR labels or barcodes, photos or documents on an asset, warranty and maintenance schedules, email notices (none in v1), editing or undoing an assignment (5.6).
- Exit clearance itself (Phase 6c). This phase only provides `AssetHoldings`. An employee's status change (a leaver) does not touch assets.

**Anti-goals (failure even if it works)**

- One asset held by two people at the same time, or an asset showing "available" while someone holds it (through a double click, two tabs, two users or an import).
- Cost seen by anyone without `assets.assign`: by screen, URL, prop, export or import preview.
- History rewritten after the fact: an assignment deleted, or its dates or condition changed after the return.
- An employee deleted while assets were ever assigned to them.

## 3. Constraints

- All Phase 0 to 5 constraints hold: localhost only, no deploys; PHP at `C:\php84\php.exe`; focused tests while working; work on `main`, no branches; commit by explicit path; naive dates (`Y-m-d` strings, `onDate` / `betweenDates`), `AttendanceClock::attendanceToday()` as the only "today"; audit inside the same transaction; 403 for out-of-scope ids; money as integer paisa through `App\Support\Payroll\Money`.
- **Permissions** already seeded (no new names, no `hrm:sync-access` change): `assets.manage` (admin), `assets.assign` (admin, hr), `assets.view-team` (admin, hr, manager). Everyone has My Assets.
- **Locking** (MariaDB REPEATABLE READ): `DB::transaction(fn, 3)`; assign and return lock the **employee row first, then the asset row FOR UPDATE**, and decide after the locks (the project's "employee row first" rule). Edits, status changes and deletes of an asset lock only the asset row. Categories lock the category row. SQLite (tests) ignores row locks, so the order is proven by a statement-order test and the guarantee by a unique index (5.5).
- Shared hosting: no binaries; import and export with `maatwebsite/excel` as in Phase 5.

## 4. Approaches considered

How "at most one open assignment per asset" is kept true.

- **A. Check in the action only.** Correct under the row lock on MariaDB, but one missed path (an import, a later feature) corrupts the register silently.
- **B. Action check under lock, plus a database guard (chosen).** `asset_assignments.open_asset_id` holds the asset id while the assignment is open and NULL once returned, with a unique index. Both MariaDB and SQLite allow many NULLs in a unique index, so closed rows never collide and a second open row for the same asset is refused by the database itself. A model hook keeps `open_asset_id` equal to `returned_on IS NULL ? asset_id : NULL`, so it cannot drift. A generated column or a partial index would do the same job but is less portable across the two engines.
- **C. Derive "held" from `assets.status` alone, no open-row rule.** Rejected: status and holder could disagree.

Chosen: **B**. `assets.status = assigned` if and only if one open assignment exists; the actions keep the two together, and a test asserts it after every flow.

## 5. Decided behaviour

### 5.1 The register

- **Asset code:** automatic, `AST-0001`, `AST-0002` ... (zero-padded to 4, growing past 9999), unique, never reused or edited, shown on every screen and export. Handed out like `EmployeeCode` (read under lock inside the saving transaction; the unique index is the backstop).
- Fields: name (required, up to 120); category (required, from the list in 5.2); serial number (optional, up to 100, unique when present, compared ignoring letter case, blank saved as none); purchase date (optional, not in the future); **cost** (optional, whole rupees entered, stored as integer paisa; shown and returned only to `assets.assign` holders); condition (`new`, `good`, `fair`, `poor`, `damaged`); status (`available`, `assigned`, `in_repair`, `retired`); notes (optional, up to 1,000).
- A new asset starts `available` (the form has no status field). Status changes only through 5.4, 5.5 and 5.6.
- Who: `assets.assign` creates and edits (including condition and notes, also on a retired or held asset). `assets.manage` deletes (5.4).

### 5.2 Categories

- Settings > Asset categories (`assets.assign`): add, rename, delete. Seeded by a data migration: Laptop, Phone, Monitor, Licence, Other (no audit entry; a system write).
- Names are 1 to 60 characters, unique ignoring letter case. A category that any asset uses (retired assets included) cannot be deleted: "N assets use this category. Move them to another category first." Order is the stored order, then name; new ones go last (no reordering in v1).

### 5.3 Who may do what

| | Admin | HR | Manager | Employee |
|---|---|---|---|---|
| Create, edit assets, categories, import, export, assign, return, available / in repair | yes | yes | no | no |
| Retire, bring back from retired, delete a never-assigned asset (`assets.manage`) | yes | no | no | no |
| See cost | yes | yes | no | no |
| All Assets, asset page, Assignments | all | all | no asset page; read-only list of what their team holds | no |
| My Assets, own Assets tab | yes | yes | yes | yes |

- A Manager's team is whatever `EmployeeAccess::scope` returns for them (as in Leave and Attendance): their own record plus their direct reports. Out of scope is simply not listed; an asset page of another person is 403.
- The Assets tab on a profile is visible to whoever may open that profile; cost shows only with `assets.assign`.

### 5.4 Status rules

- `assigned` is never set by hand: only by assigning, and it ends only by returning.
- `available` to `in_repair`, and `in_repair` to `available`: `assets.assign`. To `retired` (from `available` or `in_repair`): `assets.manage`. `retired` to `available`: `assets.manage` (Admin) only; retired to in repair is not offered (go through available).
- `in_repair` and `retired` can be set only when there is no open assignment: "{code} is held by {name}. Return it first."
- Deleting an asset needs `assets.manage` and no assignment row ever (open or returned): otherwise "This asset has been assigned before, so it cannot be deleted. Retire it instead."

### 5.5 Assigning

- Only an `available` asset, to an employee whose status is `active` or `probation`.
- Assigned date: required, not in the future (`attendanceToday()`), not before the employee's joining date.
- At most one open assignment per asset: enforced in the action under the lock order of section 3 (employee row, then asset row, then re-read status and open assignment), and by the unique index of section 4. A lost race is shown as "This asset was just assigned to someone else." (not an error page).
- Writes: the assignment (assigned date, who, the asset's current condition as `condition_out`, optional note up to 500), asset status `assigned`. Audit `Asset assigned`.

### 5.6 Returning

- Return date: on or after the assigned date, not in the future. Return condition (required, one of the five), optional return note (up to 500), and the asset's next status chosen by HR: `available`, `in_repair` or `retired`; `retired` needs `assets.manage` ("Only an Admin can retire an asset.").
- The asset's condition becomes the return condition. Returning is allowed whatever the holder's status now is (a leaver's assets must be returnable).
- Locks as in 5.5: the holder's employee row first, then the asset row; the open assignment is re-read under the lock ("This asset was already returned.").
- **History is permanent.** Assignment rows are never deleted. After the return no field changes except the two notes; before it, the assigned date and holder are fixed too (a wrong hand-over is fixed by returning it the same day and assigning again). Notes are edited by `assets.assign` and audited.

### 5.7 Screens

- **Assets > All Assets** (`assets.assign`, or `assets.view-team` read-only): table (code, name, category, serial, condition, status pill, holder, held since, cost for `assets.assign`), filters (search code / name / serial / holder, category, status, condition), 25 per page, fixed query count. Buttons: Add asset, Import, Export. For a Manager: the same table limited to assets their team holds, no cost, no buttons, no status or condition filter, titled "Team assets".
- **Asset page** (`assets.assign`): details, actions (Edit, Assign / Return, Change status, Delete), full history newest first (holder, assigned, returned, condition out and back, notes).
- **Assets > Assignments** (`assets.assign` all, `assets.view-team` team, read-only): open assignments by default with a Returned / All switch, filters (search asset or person, category, department), longest-held first.
- **My Assets** (everyone): what I hold now and what I held before (code, name, category, dates, condition); never cost. Navigation flips All Assets, Assignments and My Assets to ready.
- **Employee profile > Assets tab**: held now and history. **Dashboard**: "Assets held" card for anyone with an employee record (count and up to five names; an empty state otherwise).

### 5.8 Hook for Phase 6c

`App\Support\Assets\AssetHoldings`: `openFor(Employee): Collection` (open assignments with their asset, oldest first) and `holdsAny(Employee): bool` (one query). This phase uses them on the profile tab, My Assets and the dashboard only; Exit calls them later.

### 5.9 Deleting an employee

Refused when the employee has any assignment, open or returned: "This employee has asset history. Set the status to Inactive, Resigned or Terminated instead." Checked in `EmployeeAccess::deleteBlockers` and again under the lock in `DeleteEmployee`; the foreign key restricts it as a backstop.

### 5.10 Import (go-live)

- `.xlsx`, template download; the same file guards as the Phase 5 opening imports (real `.xlsx`, up to 2,000 rows and 5 MB, data only, first sheet, header matched by name). Columns: Name, Category, Serial, Purchase Date, Cost, Condition, Status, Holder Employee Code, Assigned Date, plus an optional leading Asset Code (see section 7).
- **Validate, then save, all or nothing:** upload, preview of every row with its errors, run. The run re-reads and re-checks the file and saves every row in one transaction, or none; any row error lists the line and the reason and saves nothing.
- **Re-import updates, never duplicates:** a row matches an existing asset by Asset Code when given, else by Serial; no match is a new asset; a code that exists next to a serial that belongs to another asset is an error; the same asset twice in the file is an error. Re-importing an unchanged file changes nothing.
- A holder makes an open assignment under the same rules as 5.5 (active or probation employee; assigned date required, not in the future, not before joining; the asset must be available). A holder with a status other than `assigned` is an error; `assigned` without a holder is an error; blank status means `assigned` with a holder, else `available`. An asset already held: the same holder is unchanged (a different assigned date is only a warning); another holder, or a status other than assigned, is an error ("Return it on the asset page first"); the import never returns anything.
- Status changes in a row follow 5.4, so `retired` rows need `assets.manage` and HR gets a row error for them.
- Category must already exist (unknown is a row error). Cost is whole rupees. Condition is required on a new asset and kept when blank on an update.
- Audit: one entry with counts only (5.12).

### 5.11 Export

`.xlsx` of the register with the list's filters, `assets.assign` only, every matching row: Asset Code, Name, Category, Serial, Purchase Date, Cost (rupees as a number), Condition, Status, Holder Employee Code, Holder Name, Assigned Date. The columns of the template come first and in the same order, so an export can be edited and imported back. Text cells formula-safe (`TextCells`). Cost is included because the export already needs `assets.assign`.

### 5.12 Audit

Area `assets` (added to `Audit::AREAS`; section in `docs/audit-log.md`). Written with `Audit::record` in the transaction of the change, never through model auto-logging (assign and return would otherwise log a second, partial entry):

- `Asset created`, `Asset updated` (old and new values), `Asset deleted` (keeps code and name), `Asset status changed` (old and new, by hand only).
- `Asset assigned` and `Asset returned` (subject the employee; asset id, code and name, dates, conditions, notes; a return also holds the next status), `Asset assignment note changed`.
- `Asset category created`, `updated`, `deleted`.
- `Assets imported` (counts only: rows, created, updated, unchanged, assigned) and `Assets exported` (rows and filters, written before the file is built). Import rows write no per-row entries.

### 5.13 Locking summary

| Action | Order |
|---|---|
| Assign, Return | employee row FOR UPDATE, then asset row FOR UPDATE |
| Edit, status change, delete | asset row FOR UPDATE (category row shared when the category changes) |
| Category delete | category row FOR UPDATE, then the usage check |
| Import run | one run at a time (cache lock); employees by id ascending, categories shared, assets by id ascending, then writes |

No path takes an asset row and then an employee row, so no cycle exists.

## 6. Data (outline; the plan fixes columns)

- `asset_categories`: name (unique), sort_order.
- `assets`: asset_code (unique), name, asset_category_id (restrict), serial_number (nullable, unique), purchase_date, cost (nullable, paisa), condition, status, notes, created_by.
- `asset_assignments`: asset_id and employee_id (both restrict), assigned_on, returned_on, condition_out, return_condition, note, return_note, open_asset_id (nullable, unique), assigned_by, returned_by.
- No new settings, permissions or roles. `config/hrm.php` gains an `assets` block (conditions, statuses).

## 7. Open items (not decided; the plan uses the stated default)

1. **Dashboard slot.** `DashboardController` has no "assets held" card yet (it has attendance, leave and payslip cards). Default: add one `myAssets` prop and card for anyone with an employee record.
2. **Asset Code import column.** The decided column list has no code, but "re-import by asset code" needs one. Default: an optional leading Asset Code column.
3. **No edit or undo of an open assignment.** A wrong hand-over is returned the same day and assigned again. Default: as stated; allowing edits while open is a small change.
4. **Unknown category in an import row** is an error (not created on the fly).
5. **Import audit** is counts only per the decision, so assignments made by an import have no `Asset assigned` entry.
6. **Two notes** per assignment (hand-over, return), both editable after the return.
7. **Purchase date** may not be in the future, and an assignment may predate the purchase date (not checked).

## 8. Review focus

1. Races: double assign (two users, two tabs, a double click, an import against a manual assign), double return, assign against a status change, delete against assign: one outcome, the other refused with a plain message; open-assignment unique index as backstop; status and holder never disagree.
2. Privacy: cost absent from every prop, export and preview for non-`assets.assign` users; a Manager sees only their team and nothing writable; an Employee sees only their own and never cost; 403 for another person's asset or history.
3. History: no delete and no edit of a returned assignment except notes (model guard, not just the action).
4. Permissions at the edges: retire and bring-back only with `assets.manage`, including through return and import.
5. Dates: assign and return bounds, joining date, `attendanceToday()`; naive dates through the profile, My Assets and exports.
6. Deleting an employee with asset history, and categories in use.

## 8a. Left to the builder

Page layouts within the design system; exact file names beyond the plan; empty-state and email-free wording; test structure, provided every refusal and review-focus case is covered.
