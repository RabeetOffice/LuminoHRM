# Lumino HRM Phase 6a (Assets) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** HR keeps a register of company assets with automatic codes, hands them to employees and takes them back with dates and conditions, keeps the full history, and managers and employees see what they may; the go-live register comes in from a spreadsheet, and Exit (Phase 6c) gets a one-call hook to ask what an employee still holds.

**Architecture:** Three layers. (1) **Records:** `asset_categories`, `assets`, `asset_assignments`; cost in integer paisa; the open-assignment rule is held by a unique `open_asset_id` column besides the actions' checks. (2) **Actions** (`App\Actions\Assets`): one transaction each, `DB::transaction(fn, 3)`, locking the employee row first and the asset row second (assign, return) or the asset row alone (everything else), deciding after the locks, auditing in the same transaction. Every action exposes a `within(...)` method (the checks and writes, no transaction, caller holds the locks) so the import reuses the exact rules. (3) **Screens and hooks:** All Assets, asset page, Assignments, My Assets, the profile tab, the dashboard card, `AssetHoldings`, import and export.

**Tech Stack:** As Phases 0 to 5 (Laravel 13, PHP 8.4, Inertia + React + TypeScript, Tailwind 4, shadcn/ui, Wayfinder, spatie permission + activitylog, maatwebsite/excel, Pest, Vitest). No new packages.

**Spec:** `docs/superpowers/specs/2026-11-26-phase-6a-assets-design.md` (wins where it differs from the main design) and `docs/superpowers/specs/2026-11-23-lumino-hrm-design.md` sections 8 (roles), 9.2 (profile tabs), 9.7 (Assets), 9.8 (Exit: unreturned assets block clearance, Phase 6c), 11 (data model).

**Builds on:** Phases 3 to 5 as built on `main`. Code this phase reads: `App\Support\{EmployeeAccess, EmployeeCode, EmployeeImportStore, Settings}`, `App\Support\Attendance\AttendanceClock`, `App\Support\Audit\Audit`, `App\Support\Payroll\Money`, `App\Exports\TextCells`, `App\Imports\{PayrollOpeningWorkbook, OpeningLoansImport, ImportFileException}` and `App\Http\Controllers\{EmployeeImportController, Payroll\OpeningImportController}` (import pattern), `App\Http\Controllers\Settings\DeviceController` + `resources/js/components/settings/settings-nav.tsx` (a Settings sub-page), `App\Http\Resources\EmployeeProfileResource`, `App\Actions\Employees\DeleteEmployee`, `App\Http\Controllers\DashboardController`, `App\Models\{Employee, User}`, `tests/Feature/Payroll/SalaryProfileTest.php` (the statement-order test, "locks the employee row by id first").

## Owner instructions that bind every task

- **No live uploads or deploys** until the owner asks. Localhost only. No hosting tool, no `scripts/deploy.ps1`, nothing on `hrm.luminocrm.online`.
- **Focused tests.** While working, run only the test files covering the change. One full PHP + Vitest run at the end of a task only when the task changes shared code (`config/hrm.php`, `EmployeeAccess`, `DeleteEmployee`, `EmployeeProfileResource`, `DashboardController`); otherwise the controller runs the full suite once at the end of the phase.
- **Subagents:** Opus 5.5 at medium effort by default, or an agent fitted to the work. This plan names the model per task: Sonnet for CRUD and screens, Opus for the locking task and the import.
- **Branch:** work on `main`; never create a branch; commits go to GitHub `main`.
- **Processes and ports:** never stop a process the agent did not start; never use ports 8000 or 8173; never start, stop or restart a server or database.
- **Dev database `lumino_hrm`** holds demo data: never `migrate:fresh`, never truncate, never any destructive command. Agents do not run migrations against it; the controller runs `artisan migrate` (additive only) after Task 1 lands.
- Agents read and follow `.superpowers/sdd/2026-11-26-phase-6a-assets/agent-rules.md` (the controller creates it from the Phase 5 rules: read "phase-6a" for "phase-5", this plan's path, reports to `.superpowers/sdd/2026-11-26-phase-6a-assets/task-N-report.md`).

## Global Constraints

- All Phase 0 to 5 Global Constraints still apply (PHP at `C:\php84\php.exe`, never the `php` on PATH; product name "Lumino HRM"; commit by explicit path, never `git add -A`; design: accent `#10b77f`, Inter, radius 10px, existing shadcn components and page patterns (`PageHeader`, `DataTable`, `StatusPill`, `StatusTabs`, `ConfirmDialog`, `useCan`), phone width, light and dark; naive dates as `Y-m-d` strings with `onDate` / `betweenDates`; `AttendanceClock::attendanceToday()` is the only "today"; props carry formatted strings, never a `YYYY-MM-DD` through `new Date()`).
- **Permissions (seeded; no new names):** `assets.assign` (admin, hr) for create / edit / assign / return / available and in-repair / categories / import / export / Assignments and All Assets; `assets.manage` (admin) for retire, bring back from retired and delete; `assets.view-team` (admin, hr, manager) for the read-only team list; My Assets for everyone. Every route checks on the server; anything about someone out of scope is 403; **no cost in any prop, export or preview of a user without `assets.assign`** (the key is absent, not null).
- **Money:** cost is nullable integer paisa (`unsignedBigInteger`), entered as whole rupees (`cost_rupees`, integer 0 to 99,999,999, converted with `Money::rupees`), shown with `Money::format` / `formatMoney` from `resources/js/lib/payroll.ts`. No float, no `round()`, no `decimal`.
- **Enums** (Task 1 adds `config('hrm.assets')`): `conditions` `new|good|fair|poor|damaged`, `statuses` `available|assigned|in_repair|retired`, `code_prefix` `AST-`. Labels for people: New, Good, Fair, Poor, Damaged; Available, Assigned, In repair, Retired.
- **Transactions and locks:** `DB::transaction(fn, 3)`. Assign and Return: first statement `Employee::query()->whereKey($id)->lockForUpdate()->first()`, second `Asset::query()->whereKey($id)->lockForUpdate()->first()`; every decision after both. Edit, status change and delete: the asset row only (first statement). Category delete: the category row. A write that changes an asset's category takes the new category row `sharedLock()` first. The import run: employees by id ascending, categories (shared) by id, assets by id ascending, then writes. **Never take an asset row and then an employee row.** Return reads the assignment's employee id with a plain read, locks that employee, locks the asset, then re-reads the open assignment and refuses if it changed.
- **Audit:** area `assets` (Task 1 adds `'assets' => 'Assets'` to `Audit::AREAS` and a section to `docs/audit-log.md`). `Audit::record('assets', <description>, $subject, [...], $by)` inside the same transaction. Models never use `LogsActivity` here. Descriptions per task; later tasks append their lines to the section.
- **Mail:** none in this phase.
- **Excel:** `TextCells` for every text cell; cost as a numeric rupee cell; dates as text `Y-m-d`.
- **Shared files** (edit last, in one small step, `git diff -- <file>` first, commit straight away): `routes/web.php`, `resources/js/config/navigation.ts` (+ its test), `docs/audit-log.md`, `tests/Pest.php`, `resources/js/components/settings/settings-nav.tsx`. Only Task 1 edits `config/hrm.php` and `App\Support\Audit\Audit`; only Task 6 edits `EmployeeAccess`, `DeleteEmployee`, `EmployeeProfileResource`, `DashboardController`, `employee-profile.ts` and `employee-profile-view.tsx`; only Task 5 edits `navigation.ts`.
- Regenerate typed routes with `C:\php84\php.exe artisan wayfinder:generate --with-form` (put `C:\php84` first on PATH). Frontend checks: `npx tsc --noEmit`, `npx vitest run <files>`, `npx vp check <files>` (`--fix` to format). Never `npm run build` / `npm run dev`, never `npx prettier` / `npx eslint`.
- Every commit message ends with: `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`

## Open items (not decided by the owner; the stated default is built, a change is small)

1. Dashboard "assets held" card is new (Task 6): `myAssets` prop and card for anyone with an employee record.
2. Import has an optional leading **Asset Code** column so a re-import can match by code (Task 7).
3. An open assignment cannot be edited or undone; fix = return the same day and assign again (Task 3).
4. An unknown category in an import row is an error (Task 7).
5. Import audit is counts only, so imported hand-overs have no `Asset assigned` entry (Task 7).
6. Both notes of an assignment stay editable after the return (Task 3).
7. Purchase date cannot be in the future; an assignment before the purchase date is not checked (Tasks 2, 3).

## Review Focus

1. **Races:** double assign (two users, two tabs, a stale model, an import against a manual assign), double return, assign against a status change, delete against assign. Tests in Tasks 1, 3, 7.
2. **Privacy:** cost absent from props, export and preview for anyone without `assets.assign`; Manager team-only and read-only; Employee own-only; another person's asset or history 403. Tests in Tasks 2, 4, 5, 6, 7.
3. **History:** returned assignments cannot be deleted or edited (except notes), by model guard as well as action. Tasks 1, 3.
4. **Permission edges:** retire and bring-back need `assets.manage` through status change, return and import. Tasks 2, 3, 7.
5. **Dates:** assign and return bounds, joining date, `attendanceToday()`, naive dates on every screen and export. Tasks 3, 4, 5, 6, 7.
6. **Employee delete** with asset history, **category delete** in use. Tasks 1, 6.

---

## Shared interfaces

- **`App\Support\Assets\AssetCode`** (Task 1): `PREFIX = 'AST-'`, `PATTERN = '/^AST-\d{4,}$/'`, `next(): string` (reads existing codes FOR UPDATE inside the caller's transaction, like `EmployeeCode`; `AST-0001` when none; grows past `AST-9999`), `isCodeConflict(UniqueConstraintViolationException): bool`.
- **`App\Support\Assets\AssetRules`** (Task 2): `statusRefusal(User $by, string $from, string $to, bool $hasOpenAssignment, ?Asset $asset = null): ?string` returns the plain-English refusal for a hand-made status change, or null. Used by `ChangeAssetStatus`, `ReturnAsset` (the next status) and the import. Matrix in Task 2.
- **`App\Support\Assets\AssetScope`** (Task 4): `assignments(User $viewer): Builder<AssetAssignment>` (everything for `assets.assign`; for `assets.view-team` assignments of `EmployeeAccess::scope` employees; `whereRaw('1 = 0')` otherwise); `assets(User $viewer): Builder<Asset>` the same for the register (a team viewer gets assets with an open assignment of a scoped employee).
- **`App\Support\Assets\AssetHoldings`** (Task 6): `openFor(Employee): Collection<AssetAssignment>` (asset and category loaded, oldest first), `holdsAny(Employee): bool` (one query). For Phase 6c Exit.
- **Models** (Task 1): `AssetCategory` (`assets()`), `Asset` (`category()`, `assignments()`, `openAssignment()` hasOne where `returned_on` null; `purchase_date` `NaiveDate` + `HasNaiveDateScopes` on `purchase_date`), `AssetAssignment` (`asset()`, `employee()`, `scopeOpen`, `isOpen()`; `assigned_on`, `returned_on` `NaiveDate`); `Employee::assetAssignments()`.
- **Messages** are class constants on the action that raises them (`AssignAsset::NOT_AVAILABLE` etc.) so tests assert the exact text.

---

## File map

| Path | Responsibility |
|---|---|
| `database/migrations/2026_11_26_200000_create_asset_tables.php`, `2026_11_26_200100_seed_default_asset_categories.php` | Tables; the five seeded categories |
| `app/Models/{AssetCategory,Asset,AssetAssignment}.php` + factories | Records and guards |
| `app/Support/Assets/{AssetCode,AssetRules,AssetScope,AssetHoldings,DashboardAssets}.php` | Rules and queries |
| `app/Actions/Assets/{SaveAssetCategory,SaveAsset,DeleteAsset,ChangeAssetStatus,AssignAsset,ReturnAsset,EditAssignmentNote}.php` | Writes |
| `app/Http/Controllers/Assets/{AssetController,AssignmentController,MyAssetController,AssetImportController,AssetExportController}.php`, `app/Http/Controllers/Settings/AssetCategoryController.php` | Screens |
| `app/Http/Requests/Assets/*.php` | Validation |
| `app/Imports/AssetRegisterImport.php`, `app/Exports/{AssetsExport,AssetTemplateExport}.php` | Excel |
| `resources/js/pages/assets/{index,show,assignments,import}.tsx`, `pages/my/assets.tsx`, `pages/hrm-settings/asset-categories.tsx`, `components/assets/*`, `lib/assets.ts` (+ test) | Pages |

---

### Task 1: Schema, models, categories, settings page and seed

**Model:** Sonnet.

**Files:** Create the two migrations; `app/Models/{AssetCategory,Asset,AssetAssignment}.php` and factories; `app/Support/Assets/AssetCode.php`; `app/Actions/Assets/SaveAssetCategory.php`; `app/Http/Controllers/Settings/AssetCategoryController.php`; `app/Http/Requests/Assets/AssetCategoryRequest.php`; `resources/js/pages/hrm-settings/asset-categories.tsx`; `resources/js/lib/assets.ts` (+ `assets.test.ts`: labels for status and condition, status pill tones). Modify `app/Models/Employee.php` (relation `assetAssignments()`), `config/hrm.php` (an `assets` block), `app/Support/Audit/Audit.php` (`assets` area), `tests/Pest.php` (helpers `assetCategory(string $name = 'Laptop')`, `asset(array $attrs = [])`, `assignedAsset(Employee, array $attrs = [])` writing rows directly), `docs/audit-log.md` (an `assets` row in the Areas table and its conventions line), `routes/web.php` (the four category routes, registered inside the `hrm-settings` prefix before the `{group}` route, behind `can:assets.assign`), `resources/js/components/settings/settings-nav.tsx` (an "Asset categories" entry, shown with `assets.assign`; `active` type gains `'asset-categories'`; follow the Devices precedent). Shared: `routes/web.php`, `settings-nav.tsx`, `tests/Pest.php`, `docs/audit-log.md`.
**Tests:** `tests/Feature/Assets/SchemaTest.php`, `tests/Feature/Assets/AssetCodeTest.php`, `tests/Feature/Assets/AssetCategoryTest.php`, `tests/Feature/Assets/ModelGuardsTest.php`, Vitest `resources/js/lib/assets.test.ts`.

**Interfaces - Produces:**
- Tables (FKs `restrict` unless noted; timestamps):
  - `asset_categories`: `name` (string 60, unique), `sort_order` (unsigned smallint, default 0).
  - `assets`: `asset_code` (string 20, unique), `name` (120), `asset_category_id` (FK), `serial_number` (nullable string 100, unique), `purchase_date` (nullable DATE), `cost` (nullable unsignedBigInteger, paisa), `condition` (string 10), `status` (string 12, default `available`), `notes` (nullable text), `created_by` (nullable FK users, null on delete). Indexes on `status` and `asset_category_id`.
  - `asset_assignments`: `asset_id` (FK), `employee_id` (FK), `assigned_on` (DATE), `returned_on` (nullable DATE), `condition_out` (string 10), `return_condition` (nullable string 10), `note` (nullable string 500), `return_note` (nullable string 500), `open_asset_id` (nullable unsignedBigInteger, **unique**), `assigned_by` and `returned_by` (nullable FK users, null on delete). Indexes (`employee_id`, `returned_on`) and (`asset_id`, `assigned_on`).
- Seed migration inserts Laptop, Phone, Monitor, Licence, Other (`sort_order` 1 to 5) only when the table is empty; no audit entry.
- `AssetAssignment` hooks (the guard behind the unique index and the history rule):
  - `saving`: `open_asset_id = returned_on === null ? asset_id : null`, always, so it cannot drift.
  - `updating`: when the stored row (read from the database) already has `returned_on`, any dirty attribute other than `note` and `return_note` throws `LogicException`; an open row may not change `asset_id`, `employee_id` or `assigned_on` either.
  - `deleting`: always throws `LogicException` (assignment rows are never deleted).
- `Asset` hook `deleting`: throws `LogicException` when any assignment row exists (read from the database). `AssetCategory` deleting is left to `SaveAssetCategory::delete`.
- `AssetCode::next()` as in Shared interfaces. `SaveAssetCategory`: `create(User $by, array $data): AssetCategory` (`name`; `sort_order` = highest + 1), `update(User $by, AssetCategory $c, array $data): AssetCategory`, `delete(User $by, AssetCategory $c): void`. Transaction; update and delete lock the category row first. Refusals (`ValidationException`, key `name`): duplicate ignoring case (compared in PHP, lower-cased, because SQLite is case-sensitive) "A category called {name} already exists."; delete in use (`DeleteBlocked` message on key `category`) "{n} asset(s) use this category. Move them to another category first." (the count read under the lock, retired assets included; the foreign key is the backstop and its violation maps to the same message). Audit: `Asset category created` (`attributes`: name, sort_order), `Asset category updated` (`old` / `attributes`; nothing when nothing changes), `Asset category deleted` (`old`).
- Routes (all `can:assets.assign`): `hrm-settings.asset-categories.index` `GET /hrm-settings/asset-categories` (rows: id, name, assets_count, `can_delete`), `.store` `POST`, `.update` `PUT /{category}`, `.destroy` `DELETE /{category}` (ids `whereNumber`). Page: list with add, rename (dialog) and delete (confirm; disabled with a hint when in use), inside `SettingsNav`. Request: `name` required, trimmed, 1 to 60.

**Key tests:** schema columns, uniques and the `open_asset_id` unique (a second open row for one asset throws `UniqueConstraintViolationException`; closed rows for the same asset coexist because the column is NULL); the model hook fixes `open_asset_id` when a caller sets it wrongly; a returned assignment refuses any dirty attribute but the notes (also a raw `save()`), and refuses `delete()`; an `Asset` with an assignment row refuses `delete()`; `AssetCode::next()` is `AST-0001` on an empty table, continues from the highest, grows past 9999, and a duplicate-code insert throws and `isCodeConflict` recognises it; the five categories are seeded; role matrix on the four routes (Admin and HR 200; Manager and Employee 403); create, rename (including changing only the letter case), duplicate ignoring case refused, delete unused works, delete in use refused with the exact message and nothing deleted; audit entries hold old and new; Settings left list shows "Asset categories" for HR and Admin only; Vitest labels.

- [ ] Tests, implement, screenshots by controller, commit `feat: asset tables, models and asset categories`.

---

### Task 2: Register actions and status rules

**Model:** Sonnet.

**Files:** Create `app/Actions/Assets/{SaveAsset,DeleteAsset,ChangeAssetStatus}.php`, `app/Support/Assets/AssetRules.php`, `app/Http/Controllers/Assets/AssetController.php` (write methods only; Task 4 adds the pages), `app/Http/Requests/Assets/{AssetRequest,AssetStatusRequest}.php`. Shared: `routes/web.php` (POST `assets`, PUT and DELETE `assets/{asset}`, POST `assets/{asset}/status`; all `whereNumber`).
**Tests:** `tests/Feature/Assets/SaveAssetTest.php`, `tests/Feature/Assets/AssetStatusTest.php`, `tests/Feature/Assets/DeleteAssetTest.php`, `tests/Unit/Assets/AssetRulesTest.php`.

**Interfaces - Consumes:** Task 1 models, `AssetCode`, `Money`, `Audit`.

**Interfaces - Produces:**
- `SaveAsset::create(User $by, array $data): Asset`, `update(User $by, Asset $a, array $data): Asset`, and `within(User $by, ?Asset $locked, array $data): Asset` (no transaction). `$data`: `name`, `asset_category_id`, `serial_number` (blank becomes null), `purchase_date` (`Y-m-d` or null), `cost` (paisa or null), `condition`, `notes`. Create: transaction, category row `sharedLock()`, `AssetCode::next()`, status `available`, `created_by`; a code conflict is retried once. Update: transaction, asset row first (then the category row shared when it changes); status is never touched. Refusals (`ValidationException`): serial taken (compared ignoring case, in PHP) [serial_number] "Another asset ({code}) already has this serial number."; purchase date in the future [purchase_date] "The purchase date cannot be in the future."; category missing [asset_category_id] "Choose a category." Audit `Asset created` (`attributes`: code, name, category, serial_number, purchase_date, cost + `cost_formatted`, condition, notes), `Asset updated` (`old` / `attributes` of the changed fields only; nothing when nothing changes; the audit property is `cost` paisa plus `cost_formatted`).
- `AssetRules::statusRefusal(...)`. Matrix (`from` to `to`, by hand, never `assigned`):
  - `available` to `in_repair`: `assets.assign`; `in_repair` to `available`: `assets.assign`;
  - `available` or `in_repair` to `retired`: `assets.manage`, "Only an Admin can retire an asset.";
  - `retired` to `available`: `assets.manage`, "Only an Admin can bring a retired asset back.";
  - `retired` to `in_repair`: "Bring a retired asset back to available first.";
  - to `assigned`: "Assign the asset to an employee instead.";
  - from `assigned` to anything: "{code} is held by {name}. Return it first." (needs the asset and its open assignment; with `$hasOpenAssignment` true);
  - same status: "The asset is already {status label}."
- `ChangeAssetStatus::handle(User $by, Asset $a, string $to): Asset` and `within(...)`: transaction, asset row first, then the open assignment read (so the "held" refusal sees a hand-over that committed first), `AssetRules` refusal as `ValidationException` on [status]. Audit `Asset status changed` (subject the asset; `old` / `attributes` of status).
- `DeleteAsset::handle(User $by, Asset $a): void`: needs `assets.manage` (403 otherwise from the controller; the action re-checks), asset row first, refuses when any assignment exists "This asset has been assigned before, so it cannot be deleted. Retire it instead." (the model hook and the foreign key are the backstop). Audit `Asset deleted` (keeps code, name, category).
- Routes: `assets.store` and `assets.update` and `assets.status` `can:assets.assign`; `assets.destroy` `can:assets.manage`. Controllers answer with a redirect and a plain flash message ("Asset AST-0004 added.", "Asset updated.", "Asset deleted."). Request rules: `name` required 1 to 120; `asset_category_id` exists; `serial_number` nullable 100; `purchase_date` nullable date; `cost_rupees` nullable integer 0 to 99,999,999 (a form field only: the request converts to paisa); `condition` in the five; `notes` nullable 1,000.

**Key tests:** create assigns `AST-0001` then `AST-0002`; status starts `available`; serial duplicate refused ignoring case, blank serials do not collide, two assets may both have none; future purchase date refused; cost stored as paisa and absent from props of other roles (asserted in Task 4); update changes only given fields and never status; the audit holds old and new and writes nothing for a no-op; full status matrix table-driven through `AssetRules` (every cell, with Admin and HR); a change on a held asset refused with the holder's name; `ChangeAssetStatus` re-reads under the lock (a stale model that still says `available` after an assign committed is refused); delete: Admin deletes a never-assigned asset, HR 403, an asset with a returned assignment refused with the message and still there; Manager and Employee 403 on every write route; HR may not retire (status route returns the validation error), Admin may.

- [ ] Tests, implement, commit `feat: asset register actions and status rules`.

---

### Task 3: Assign and return, with locking

**Model:** Opus.

**Files:** Create `app/Actions/Assets/{AssignAsset,ReturnAsset,EditAssignmentNote}.php`, `app/Http/Controllers/Assets/AssignmentController.php` (write methods only; Task 5 adds the list), `app/Http/Requests/Assets/{AssignAssetRequest,ReturnAssetRequest,AssignmentNoteRequest}.php`. Shared: `routes/web.php` (POST `assets/{asset}/assign`, POST `assets/assignments/{assignment}/return`, PATCH `assets/assignments/{assignment}/note`; all `can:assets.assign`, `whereNumber`).
**Tests:** `tests/Feature/Assets/AssignAssetTest.php`, `tests/Feature/Assets/ReturnAssetTest.php`, `tests/Feature/Assets/AssignmentRaceTest.php`, `tests/Feature/Assets/AssignmentLockOrderTest.php`, `tests/Feature/Assets/AssignmentHistoryTest.php`.

**Interfaces - Consumes:** Task 1 models and guards, Task 2 `AssetRules`, `AttendanceClock::attendanceToday()`, `Audit`.

**Interfaces - Produces:**
- `AssignAsset::handle(User $by, Asset $asset, Employee $employee, string $assignedOn, ?string $note): AssetAssignment` and `within(User $by, Asset $lockedAsset, Employee $lockedEmployee, string $assignedOn, ?string $note): AssetAssignment`. The transaction:
  1. `Employee` row `lockForUpdate` (first statement); 2. `Asset` row `lockForUpdate` (second); 3. decide on the locked rows: status must be `available`; employee status in `active`, `probation`; `assignedOn` valid `Y-m-d`, not after `attendanceToday()`, not before the joining date; 4. insert the assignment (`condition_out` = the asset's condition, `assigned_by`), set the asset to `assigned`; 5. audit. A `UniqueConstraintViolationException` on `open_asset_id` (the database guard) becomes the "just assigned" refusal.
  - Refusals (`ValidationException`): [asset] `NOT_AVAILABLE` "This asset is not available. It is {status label}."; held: "{code} is already assigned to {name}. Return it first."; lost race: `JUST_ASSIGNED` "This asset was just assigned to someone else."; [employee_id] `NOT_ACTIVE` "{name} is not an active employee, so assets cannot be given to them."; [assigned_on] `FUTURE` "The assigned date cannot be in the future."; `BEFORE_JOINING` "The assigned date cannot be before {name} joined ({date})."; note over 500 characters.
  - Audit `Asset assigned` (subject the employee, causer = who assigned; `asset_id`, `code`, `asset_name`, `assigned_on`, `condition`, `note`).
- `ReturnAsset::handle(User $by, AssetAssignment $assignment, string $returnedOn, string $returnCondition, string $nextStatus, ?string $note): AssetAssignment` and `within(...)`. Transaction: plain read of the assignment's `employee_id` and `asset_id`; lock that employee row first, then the asset row; re-read the assignment under the lock and refuse when it is no longer open or the holder changed (`ALREADY_RETURNED` "This asset was already returned."); decide: `returnedOn` not before `assigned_on` (`BEFORE_ASSIGNED` "The return date cannot be before it was handed over ({date})."), not in the future (`FUTURE` "The return date cannot be in the future."); `returnCondition` in the five; `nextStatus` in `available`, `in_repair`, `retired` ("Choose what happens to the asset next."), `retired` needs `assets.manage` ("Only an Admin can retire an asset."); writes: `returned_on`, `return_condition`, `return_note`, `returned_by`, the asset's `condition` = the return condition, `status` = `nextStatus`; audit. Return is allowed for any holder status.
  - Audit `Asset returned` (subject the employee; `asset_id`, `code`, `asset_name`, `assigned_on`, `returned_on`, `condition_out`, `return_condition`, `next_status`, `note`).
- `EditAssignmentNote::handle(User $by, AssetAssignment $a, ?string $note, ?string $returnNote): AssetAssignment`: asset row not needed; locks the assignment row; changes only the two notes (open or returned); audit `Asset assignment note changed` (subject the employee, `old` / `attributes`; nothing when unchanged).
- Routes answer with a redirect back and a flash ("AST-0004 assigned to Sara Ahmed.", "AST-0004 returned.").
- An assignment "mistake" has no undo (open item 3): there is no route that edits dates or holder, and no delete.

**Key tests:**
- Assign happy path: assignment row, `open_asset_id` = asset id, status `assigned`, `condition_out`, audit with the employee as subject. Each refusal above (table-driven): unavailable (in repair, retired, held), probation allowed, inactive / resigned / terminated refused, future date, before joining, same day as joining allowed, exactly today allowed.
- **Race test (double assign):** asset A available; load `$stale = Asset::find(A)` and the second employee; assign to employee 1 (commits); then call `AssignAsset::handle` with `$stale` for employee 2: refused ("already assigned to ... Return it first."), exactly one open assignment, one `Asset assigned` entry, status still `assigned` with employee 1. Repeat with the unique-index path: insert a second open row for the asset with the query builder and expect `UniqueConstraintViolationException`; and a test where the action's own insert is made to collide (a listener inserts the competing row just before the action's insert) and the action reports `JUST_ASSIGNED`, with the transaction rolled back (asset unchanged, no audit entry).
- **Race test (double return):** two `ReturnAsset::handle` calls on the same assignment (second with a stale instance): the second is refused `ALREADY_RETURNED`, one `Asset returned` entry, status and condition from the first call.
- Assign against status change and delete: after `ChangeAssetStatus` to `in_repair`, an assign with a stale model is refused; a delete after an assign is refused.
- **Statement-order test** (`AssignmentLockOrderTest`, same technique as the "locks the employee row by id first" test in `SalaryProfileTest`: record SQL with `DB::listen`, and compile the lock clause with `MySqlGrammar` because SQLite drops it): for assign, the first statement of the transaction is the employee select by id with `for update`, the second the asset select by id with `for update`, and no statement reads `assets` or `asset_assignments` before them; for return, the assignment is read plainly, then the employee `for update`, then the asset `for update`, then the assignment is re-read; an assertion that no path selects `assets ... for update` before `employees ... for update` (guards the "never asset then employee" rule); the edit, status and delete paths lock only the asset.
- Return happy path: asset condition and status updated for each of `available`, `in_repair`, `retired` (Admin); HR choosing `retired` refused and nothing written; return date before assigned refused, same day allowed, future refused; return for a holder now Inactive / Resigned works; return condition required.
- History (`AssignmentHistoryTest`): after a return, changing `returned_on`, `assigned_on`, `employee_id` or either condition through the model throws; only the notes can change; there is no delete route and `delete()` throws; the same asset can be assigned again after a return (a new row, the old row intact, `open_asset_id` NULL on the old one); after every flow `assets.status = assigned` if and only if one open assignment exists (an invariant helper asserted in each test).
- Manager and Employee 403 on all three routes; the controller 404s an assignment whose asset does not match the URL where relevant.

- [ ] Tests, implement, commit `feat: assign and return assets with locking`.

---

### Task 4: All Assets list and the asset page

**Model:** Sonnet.

**Files:** Create `app/Support/Assets/AssetScope.php`; extend `app/Http/Controllers/Assets/AssetController.php` with `index` and `show`; `resources/js/pages/assets/{index,show}.tsx`; `resources/js/components/assets/{asset-form-dialog,assign-dialog,return-dialog,status-dialog,asset-status-pill,history-table}.tsx`. Shared: `routes/web.php` (GET `assets`, GET `assets/{asset}` after the static `assets/assignments`, `assets/import` and `assets/export` routes, `whereNumber`).
**Tests:** `tests/Feature/Assets/AssetListTest.php`, `tests/Feature/Assets/AssetPageTest.php`, `tests/Feature/Assets/AssetScopeTest.php`, Vitest additions in `assets.test.ts`.

**Interfaces - Consumes:** Tasks 1 to 3, `EmployeeAccess::scope`, `Money`.

**Interfaces - Produces:**
- `assets.index` `GET /assets?search=&category=&status=&condition=&page=` (`assets.assign` or `assets.view-team`; 25 per page; a fixed number of queries whatever the page size: eager-load category and open assignment with employee). Props: `assets` (paginated: `id`, `asset_code`, `name`, `category`, `serial_number`, `condition`, `status`, `holder` {id, code, name} or null, `held_since` formatted, `cost` paisa **only with `assets.assign`**), `filters`, `options` (categories, statuses, conditions), `scope` `'all'|'team'`, `can` {create, import, export} (all false for a team viewer). A team viewer (no `assets.assign`): `AssetScope::assets` limits the rows to assets held by `EmployeeAccess::scope` people, the status and condition filters are ignored, no `cost`, titled "Team assets". Search matches code, name, serial, holder name or code.
- `assets.show` `GET /assets/{asset}` (`assets.assign` only; a Manager 403): `asset` (all fields, `cost` paisa and formatted, `purchase_date` as the formatted string), `holder`, `history` (every assignment, newest first: id, employee {id, code, name}, assigned_on, returned_on or null, condition_out, return_condition, note, return_note, assigned_by and returned_by names), `can` {edit, assign, return, status, delete (`assets.manage` and never assigned), retire (`assets.manage`)}, `status_options` (the target statuses the viewer may choose, from `AssetRules`), `employees` for the assign dialog (active and probation people: id, code, name; one query, not paged beyond 500, with a search-as-you-type fallback if more).
- Screens: list with filters and Add asset (dialog, the form of Task 2), buttons Import and Export (links to Task 7 routes, shown with `assets.assign`); asset page with Edit, Assign, Return, Change status, Delete (each a dialog or `ConfirmDialog` posting to the Task 2 and 3 routes), history table, status pill colours from `lib/assets.ts`. Plain error text from the server under the field it belongs to.

**Key tests:** HR and Admin 200, Manager gets the team list (only assets held by their own reports and themself, no `cost` key anywhere in the serialised props, `can` all false), a Manager with no reports sees an empty list, Employee 403; the Manager's `assets.show` is 403; HR and Admin props include `cost`; the serialised props of a Manager contain no `cost` and no `purchase_date` of anything outside the team; filters (category, status, condition, search by holder code) narrow correctly; query count equal for 5 and 25 rows; the asset page lists the full history in order including returned rows and a never-assigned asset has an empty history and `can.delete` true only for Admin; `can.retire` false for HR; `status_options` for HR on an available asset are `in_repair` only, for Admin `in_repair` and `retired`; Vitest label and tone helpers.

- [ ] Tests, implement, screenshots by controller, commit `feat: all assets list and asset page`.

---

### Task 5: Assignments list, My Assets and navigation

**Model:** Sonnet.

**Files:** Extend `app/Http/Controllers/Assets/AssignmentController.php` with `index`; create `app/Http/Controllers/Assets/MyAssetController.php`; `resources/js/pages/assets/assignments.tsx`, `resources/js/pages/my/assets.tsx`. Modify `resources/js/config/navigation.ts` and its test (All Assets, Assignments and My Assets lose `ready: false`). Shared: `routes/web.php` (GET `assets/assignments` before `assets/{asset}`; GET `my/assets`, name `my.assets`), `navigation.ts`.
**Tests:** `tests/Feature/Assets/AssignmentListTest.php`, `tests/Feature/Assets/MyAssetsTest.php`, the navigation Vitest.

**Interfaces - Consumes:** Task 4 `AssetScope`, Task 1 models, `AssetHoldings` is not used yet (Task 6).

**Interfaces - Produces:**
- `assets.assignments.index` `GET /assets/assignments?status=open|returned|all&search=&category=&department=&page=` (`assets.assign` all, `assets.view-team` team via `AssetScope::assignments`; default `open`; 25 per page; fixed query count). Rows: id, asset {id, code, name, category}, employee {id, code, name, department}, assigned_on, returned_on, days held (open: to `attendanceToday()`), conditions out and back. Open rows ordered longest held first; others newest first. A team viewer: read-only (no `can.return`), no link to the asset page; no cost anywhere. `can.return` for `assets.assign` with a link to the return dialog on the asset page.
- `my.assets` `GET /my/assets` (every signed-in user): the account's own employee record only; props `holding` and `history` (asset code, name, category, assigned and returned dates, condition out and back, note) and never `cost`, serial number kept (it identifies the device). An account with no employee record gets empty lists and the existing "no employee record" empty-state wording used by My Leaves. Nobody can pass an employee id.
- Navigation: `ready` flags removed; the Assets group keeps permission `['assets.assign', 'assets.view-team']`.

**Key tests:** Admin and HR see everyone's open assignments, `returned` and `all` switches work, search by asset code, serial, employee name and code, category and department filters; a Manager sees only their team's rows, read-only, an Employee 403; query count equal across page sizes; My Assets: an employee sees only their own current and past assets, not another person's even when the other holds the same model, `cost` absent from the serialised props for Employee, Manager, HR and Admin alike, an account without an employee record gets empty lists; the navigation test shows the three entries ready for the right roles and "Assignments" beating "All Assets" in `activeHref`.

- [ ] Tests, implement, screenshots by controller, commit `feat: assignments list and my assets`.

---

### Task 6: Profile tab, dashboard, AssetHoldings and the delete refusal

**Model:** Sonnet.

**Files:** Create `app/Support/Assets/{AssetHoldings,DashboardAssets}.php`, `resources/js/components/assets/assets-panel.tsx`. Modify `app/Http/Resources/EmployeeProfileResource.php` (an `assets` tab), `resources/js/lib/employee-profile.ts` (`PROFILE_TABS` gains `{ key: 'assets', label: 'Assets', title: 'Assets' }`) and its test, `resources/js/components/employees/employee-profile-view.tsx`, `app/Http/Controllers/DashboardController.php` (the `myAssets` prop and its doc comment), `resources/js/pages/dashboard.tsx` (the card), `app/Support/EmployeeAccess.php` (`ASSETS_MESSAGE`, a `deleteBlockers` check), `app/Actions/Employees/DeleteEmployee.php` (the same check under the lock), `app/Http/Controllers/Assets/MyAssetController.php` (use `AssetHoldings` for "holding"). Shared: none beyond the files above.
**Tests:** `tests/Feature/Assets/AssetHoldingsTest.php`, `tests/Feature/Assets/ProfileAssetsTabTest.php`, `tests/Feature/Assets/DashboardAssetsTest.php`, `tests/Feature/Assets/EmployeeDeleteTest.php`; existing `EmployeeProfileTest`, `MyProfileTest`, `DashboardTest` and the employee delete tests keep passing (shared code: one full PHP + Vitest run at the end of the task).

**Interfaces - Consumes:** Task 1 to 5, `EmployeeAccess::canView`.

**Interfaces - Produces:**
- `AssetHoldings::openFor(Employee $e): Collection<AssetAssignment>` (asset and category loaded, `assigned_on` then id ascending) and `holdsAny(Employee $e): bool` (a single `exists` query on `returned_on IS NULL`). Documented as the Phase 6c Exit hook; works for any employee status.
- Profile tab: `EmployeeProfileResource::TABS` gains `'assets' => 'assets'`; the group is allowed for whoever may open the profile (own, `employees.view-all`, or a manager's direct report). Data `assets`: `{holding: [{id, code, name, category, assigned_on, condition_out, asset_id}], history: [last 20 returned, newest first: same plus returned_on, return_condition], can_assign (assets.assign), cost?}` where `cost` appears on each row only with `assets.assign`; link to the asset page only with `assets.assign`; a link "Assign an asset" only with `assets.assign`. Tab label "Assets"; an empty state "Nothing assigned yet."
- Dashboard: `myAssets` `{count, names: first 5 asset names, more: count beyond 5}` for every viewer with an employee record (count 0 and an empty state otherwise); no cost, no codes of others. `DashboardAssets::mine(int $employeeId): array` (two queries at most).
- Delete refusal: `EmployeeAccess::ASSETS_MESSAGE = 'This employee has asset history. Set the status to Inactive, Resigned or Terminated instead.'`; `deleteBlockers` appends it when `$employee->assetAssignments()->exists()`; `DeleteEmployee` re-checks inside its transaction after the employee row lock and before any write (`AccountRuleViolation`); the restrict foreign key is the backstop.

**Key tests:** `openFor` returns only open rows, oldest first, with assets loaded and in a fixed number of queries; `holdsAny` true / false and ignores returned rows and other people; works for a Resigned employee; profile: HR and Admin see the tab with cost, a Manager viewing a direct report sees the tab without any `cost` key, an Employee on My Profile sees their own tab without cost, a Manager cannot open a non-report (existing 403 unchanged), the tab lists held and history; dashboard: Employee with two assets gets `myAssets.count` 2 and names, with none gets count 0, an account without an employee record gets no key, no props leak another person's assets; delete: an employee with one open assignment, one with only a returned assignment and one with neither (deletes), refusal text exact, `deleteBlockers` lists it, the action refuses when the assignment appears after the controller's check (call the action directly), no employee row or audit entry removed on refusal; Vitest `PROFILE_TABS` order.

- [ ] Tests, implement, one full PHP + Vitest run (shared code), commit `feat: assets on the profile and dashboard, AssetHoldings and the delete refusal`.

---

### Task 7: Import and export

**Model:** Opus.

**Files:** Create `app/Imports/AssetRegisterImport.php` (extends `PayrollOpeningWorkbook` for the file guards and the `text()`, `wholeRupees()` readers; adds a `date()` reader for `Y-m-d` text or an Excel date; does not edit any Phase 5 file), `app/Exports/{AssetsExport,AssetTemplateExport}.php`, `app/Http/Controllers/Assets/{AssetImportController,AssetExportController}.php`, `resources/js/pages/assets/import.tsx`. Shared: `routes/web.php` (static `assets/import/...` and `assets/export` registered before `assets/{asset}`; all `can:assets.assign`), `docs/audit-log.md` (final `assets` lines).
**Tests:** `tests/Feature/Assets/AssetImportTest.php`, `tests/Feature/Assets/AssetImportLockOrderTest.php`, `tests/Feature/Assets/AssetExportTest.php`.

**Interfaces - Consumes:** `SaveAsset::within`, `AssignAsset::within`, `ChangeAssetStatus::within` (Tasks 2 and 3), `AssetRules`, `EmployeeImportStore` (slot string `assets-register`; no change to the store), `EmployeeImportController::{REFUSED, BUSY, CHANGED, FAILED, LOCK_SECONDS, extendRunTimeLimit}`, `TextCells`, `Money`.

**Interfaces - Produces:**
- Columns (template order): `Asset Code` (optional, blank on new rows), `Name`, `Category`, `Serial`, `Purchase Date`, `Cost`, `Condition`, `Status`, `Holder Employee Code`, `Assigned Date`. `columns()` header to key map, `optional()` = `['code']`; unknown columns ignored with a notice (as in Phase 5).
- `AssetRegisterImport::check(array $rows, User $user): list<array{line, display, errors, warnings, unchanged, action: 'create'|'update'|'none', data}>` validating every row with a fixed number of queries whatever the row count (categories by name, assets by code and by serial, employees by code, open assignments of the assets named). Row rules, each a plain-English error naming the column:
  - Name required up to 120; Category must exist (ignoring case): "Category {name} does not exist. Add it under Settings > Asset categories first."; Serial up to 100, not in the file twice, not belonging to a different asset than the code names; Purchase Date a valid date not in the future; Cost whole rupees 0 to 99,999,999 or blank; Condition one of the five, required for a new asset, blank on an update keeps the stored one; Status one of the four or blank.
  - Match: Asset Code present means that asset must exist ("No asset has the code AST-0099."); else Serial matching an asset is an update; else new. The same asset twice in the file: an error on each line.
  - Holder: employee code must exist and be `active` or `probation`; Assigned Date required with a holder and refused without one; not in the future, not before the joining date; the holder needs status `assigned` (or blank); `assigned` without a holder is an error. An asset already held: same holder is `none` (a different date is a warning "Assigned date ignored: held since {date}."); another holder or a status other than assigned: "Return it on the asset page first."
  - Status changes in a row are judged by `AssetRules::statusRefusal` for the importing user (so HR cannot import `retired` or bring one back); an asset with an open assignment cannot be set to repair or retired.
  - A row equal to the stored asset (all fields and holder) is `unchanged`.
- Run (`assets.import.run`): the file is re-read and re-checked; any error saves nothing. A cache lock `assets-import` allows one run at a time. One `DB::transaction(fn, 3)`: lock employees named by the rows by id ascending, then categories shared by id, then the existing assets by id ascending (all `FOR UPDATE` / shared), then apply rows in file order with `SaveAsset::within`, `ChangeAssetStatus::within`, `AssignAsset::within` (new assets get their codes in file order), then one audit entry `Assets imported` (no subject; counts `rows`, `created`, `updated`, `unchanged`, `assigned`). Unexpected failure shows "The import failed" and keeps the upload. Preview, upload, template and run follow `OpeningImportController` (token bound to the uploader, 60 minutes, single use).
- Preview props never contain a cost for a user without `assets.assign` (the route already needs it; the test asserts the middleware, not only the props).
- `assets.export` `GET /assets/export?search=&category=&status=&condition=`: the same filters as the list, every matching row, columns as the template plus `Holder Name`, in the template's order so it re-imports. Audit `Assets exported` (rows, filters, columns) written before the file is built. Cost as a numeric rupee cell, dates as `Y-m-d` text, all text through `TextCells`. File name `assets-{today}.xlsx`.

**Key tests:**
- Import happy path: new assets get codes in file order, a holder creates an open assignment and status `assigned`, a row with a code updates, a row with only a serial updates, re-importing the same file changes nothing (`unchanged` all, zero writes, counts in the audit entry show `unchanged`), the export round-trips through the importer unchanged.
- All or nothing: a file with one bad row among ten saves none (row count and audit unchanged) and lists the line and reason; each row rule above (table-driven); HR importing a `retired` row gets the row error, Admin does not; held-by-another and status-conflict rows refused; never returns an asset.
- **Race and lock tests:** an import row assigning asset X to employee E while a manual `AssignAsset` for X committed after the preview: the run re-checks and refuses ("Return it on the asset page first." or not available) and saves nothing; two overlapping runs are refused by the cache lock; the statement-order test (`AssetImportLockOrderTest`, same technique as Task 3): employees `for update` by id ascending, then categories, then assets by id ascending, and no `assets ... for update` before an `employees ... for update`.
- Permissions: Manager and Employee 403 on import, preview, template, run and export; export carries cost only here (the sheet has the Cost column) and that is `assets.assign` only; a formula-looking name ("=1+1") is stored as text in the export; the audit entries hold counts and filters and no row data.

- [ ] Tests, implement, commit `feat: asset register import and export`.

---

### Task 8: Phase check on localhost (controller)

- [ ] After Task 1: `C:\php84\php.exe artisan migrate:status` (only the two asset migrations pending), then `artisan migrate` on the dev DB (additive only).
- [ ] One full PHP + Vitest run, `npx tsc --noEmit`, `npm run build`, PHPStan.
- [ ] Walk the flow on the controller's preview server (existing `.claude/launch.json` entry; subagents never start servers): Settings > Asset categories (add, rename, a category in use refuses delete); Add asset; edit; assign to a demo employee; try to assign the same asset again in a second tab (refused plainly); return with a condition and "in repair"; bring it back to available; Admin retires and brings back; import the template filled with eight demo assets (two with holders, one with a bad row to see the error list, then fixed), re-import to see "no changes"; export and open the file; Assignments filters; the employee's profile Assets tab; the employee's dashboard card and My Assets; try to delete an employee with asset history (refused with the message).
- [ ] Browser checks with manager and employee logins (two local test logins on the dev DB, created through the Users screen; passwords never written in chat or committed): the manager sees only their team's list and no buttons, every write URL and the asset page 403; the employee sees only My Assets and no cost anywhere; phone width, light and dark.
- [ ] Whole-phase review (Review Focus 1 to 6 explicitly, plus a lock-order table for the new transactions); fix wave; update `docs/audit-log.md`, the README and the ledger.
- [ ] **Phase gate (owner):** the real register imported at go-live with holders, then one week of hand-overs and returns in the system only; register and people agree with what is physically out.
- [ ] **On hold until the owner asks:** any upload or deploy.

---

## Self-review notes

- Design coverage: 5.1 register and codes, 5.2 categories -> Tasks 1 and 2; 5.3 permissions -> every task (matrix tests) and `AssetRules`; 5.4 status rules -> Task 2 (and Tasks 3, 7 for return and import); 5.5 assigning and the one-open-assignment rule -> Tasks 1 (unique index), 3 (action, race, lock order); 5.6 returning and history guard -> Tasks 1 (model guard) and 3; 5.7 screens -> Tasks 4 and 5 (profile tab and dashboard in 6); 5.8 `AssetHoldings` -> Task 6; 5.9 employee delete -> Task 6; 5.10 import -> Task 7; 5.11 export -> Task 7; 5.12 audit -> every task, section in Task 1, final lines in Task 7; 5.13 lock order -> Tasks 3 and 7 statement-order tests.
- Main design: 9.7 register fields, assign and return with date and condition, history, profile shows held assets -> Tasks 1 to 6; "unreturned assets block exit clearance" -> Phase 6c through `AssetHoldings`; main design section 11 columns differ on purpose (cost as paisa, `assigned_on` / `returned_on` dates, `open_asset_id`, notes) and the Phase 6a spec wins.
- Names checked consistent across tasks: `AssetCode::{next, isCodeConflict}`, `AssetRules::statusRefusal`, `AssetScope::{assignments, assets}`, `AssetHoldings::{openFor, holdsAny}`, `DashboardAssets::mine`, `SaveAsset::{create, update, within}`, `ChangeAssetStatus::{handle, within}`, `DeleteAsset::handle`, `AssignAsset::{handle, within}`, `ReturnAsset::{handle, within}`, `EditAssignmentNote::handle`, `SaveAssetCategory::{create, update, delete}`, route names `assets.{index,show,store,update,destroy,status,assign,export}`, `assets.assignments.{index,return,note}`, `assets.import.*`, `my.assets`, `hrm-settings.asset-categories.*`.
- Parallel waves: after Task 1 -> **Tasks 2 and 3 in parallel** (disjoint files; Task 3 needs only `AssetRules` constants from Task 2, so land Task 2's `AssetRules` first or let Task 3 stub the call until it merges); after Tasks 2 and 3 -> **Tasks 4, 5, 6, 7 in parallel** (Task 6 touches only shared profile, dashboard and employee files; Tasks 4, 5, 7 add routes to `routes/web.php` through the shared-file rule, static `assets/...` routes before `assets/{asset}`); then Task 8.
- Proportion: code bodies appear nowhere; the plan fixes signatures, values, messages and tests.
