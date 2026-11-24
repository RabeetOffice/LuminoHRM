# Lumino HRM Phase 2 (Employees) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Employee records end to end: list, add/edit in tabs, profile page, documents, Excel import/export, organization chart and My Profile, with a login account per employee.

**Architecture:** One `employees` table with three satellite tables (contact, bank account, documents), linked one-to-one to `users`. Who may see which employee and which fields is decided in one place on the server (`EmployeeAccess`), and every controller, page prop and export goes through it. Files live on the private disk and are served by authorised routes.

**Tech Stack:** As Phase 0–1 (Laravel 13, PHP 8.4, Inertia + React + TypeScript, Tailwind 4, shadcn/ui, spatie permission + activitylog, Pest, Vitest), plus `maatwebsite/excel` for import/export.

**Spec:** `docs/superpowers/specs/2026-11-23-lumino-hrm-design.md` — sections 7 (list and page patterns), 8 (roles), 9.1, 9.2, 11 (Employees group). Reference look: `docs/research/2026-11-23-workdo-reference-analysis.md` and the two owner screenshots described in Task 2 and Task 4.

**Builds on (read their "interfaces produced" sections):** the Phase 0–1 reports in `.superpowers/sdd/2026-11-23-phase-0-1-proof-and-foundation/` — task-4 (roles, permissions, `userWithRole`), task-5 (shell, `navigation.ts`, `useCan`, `PageHeader`, `StatusPill`, tile map), task-6 (`DataTable`, `StatusTabs`, `ConfirmDialog`, flash, list conventions), task-7 (`settings()`, branding file serving pattern), task-8 (`CreateUserAccount` + `linkSent()`, `SetUserActive`, `LastAdminGuard`), task-9 (`LogsActivity` allow-list convention, `Audit::record`, `docs/audit-log.md`), task-10 (backup exclusions), final-fix-report (shared `auth.user` is `id, name, email, email_verified_at` only).

## Global Constraints

- Everything in the Phase 0–1 plan's Global Constraints still applies (PHP at `C:\php84\php.exe`, no shell-outs, no Node on the server, product name "Lumino HRM", the reference product's name never in code or copy, reserved URL prefixes, never port 8000, never stop a process you did not start).
- Permissions (exact names, already seeded): `employees.view-all` (admin, hr), `employees.view-team` (admin, hr, manager), `employees.manage` (admin, hr), `employees.delete` (admin). No new permission names in this phase.
- **Field visibility is enforced on the server.** A Manager sees direct reports only, and never their salary, bank details, CNIC, date of birth, home address, emergency contact or documents. An Employee sees only their own record. Fields a viewer may not see are absent from page props, exports and validation errors, not merely hidden.
- "Own team" = employees whose `manager_id` is the viewer's employee record; direct reports only.
- Employees are never hard-deleted once they have history. In this phase "delete" (Admin only) is allowed only for a record with no login history and no linked data beyond its own satellites; otherwise the action is refused with a message telling the Admin to set the status to Inactive, Resigned or Terminated.
- `users.employee_id` is nullable and stays nullable (the Phase 1 data migration creates a user with no employee). The first Admin may have no employee record.
- One role per user; role is chosen on the employee form (default `employee`) and written through the Phase 1 code path (`syncRoles`), never `fill()`.
- Employee code format `LD-0001` (prefix `LD-`, zero-padded to 4, grows past 9999); unique; auto-assigned in a transaction; editable by Admin only.
- `device_user_id` (the ID the attendance machine knows the person by) is a nullable string, unique when set.
- CNIC stored as 13 digits (formatted `12345-1234567-1` for display); unique when set. Phone numbers stored trimmed, as entered.
- Dates are calendar dates (no time zone conversion). Display format from `settings('company.date_format')`.
- Money, salary: none in this phase. Do not add salary columns or a Salary tab.
- Files: private disk only (`employees/{id}/photo/…`, `employees/{id}/documents/…`), random stored names, served only through routes that run `EmployeeAccess`. Photos: png, jpg, webp, ≤ 2 MB, ≤ 2000×2000. Documents: pdf, png, jpg, webp, doc, docx, ≤ 10 MB, real MIME checked.
- Audit (area `employees`): create, each changed field with old/new (allow-list; CNIC, bank account number and date of birth are recorded as `"[changed]"`), status changes, document added/removed (title and type, not contents), import runs (count created/updated/failed, not row data), export runs (who, how many rows, which columns). Follow `docs/audit-log.md`.
- List conventions from Phase 1: `per_page` 10/25/50, clamped pages with redirect, literal `%`/`_` in search, array-valued query params never 500. The duplicated helpers in `DepartmentController` and `UserController` are extracted in Task 1 and used by every list from now on.
- Every commit message ends with: `Co-Authored-By: Claude Fable 5.2 <noreply@anthropic.com>`

## Review Focus

1. **A Manager or Employee requests another person's record, photo, document or export by changing the ID in the URL** → 403/404, nothing leaked in the response body, props or error bag. Tests in Tasks 1, 4, 5.
2. **A Manager's list, profile, org chart or export contains a restricted field** (CNIC, DOB, bank, address, emergency contact, documents) → absent. Tests in Tasks 2, 4, 7.
3. **A reporting line loop** (A reports to B, B reports to A, or someone reports to themselves) → refused on save and on import; the org chart never recurses forever. Tests in Tasks 3, 6, 7.
4. **An import file with bad rows, duplicates (email, CNIC, employee code, machine ID), a manager that does not exist yet, 5,000 rows, or formulas in cells** → per-row errors, nothing half-saved, no formula injection on export (`=`, `+`, `-`, `@` prefixed cells are escaped). Tests in Task 6.
5. **Creating an employee whose email already has a user, or whose login link cannot be sent** → the employee is linked or created consistently, never a 500 or an orphan; the HR user is told what happened. Tests in Task 3.

---

## File map

| Path | Responsibility |
|---|---|
| `app/Support/ListQuery.php` | Shared list helpers: search escaping, per-page, page clamp redirect |
| `database/migrations/*_create_employees_tables.php` | `employees`, `employee_contacts`, `employee_bank_accounts`, `employee_documents`, `users.employee_id`, `departments.head_employee_id` |
| `app/Models/Employee.php` (+ `EmployeeContact`, `EmployeeBankAccount`, `EmployeeDocument`) | Records, relations, casts, audit allow-list |
| `app/Support/EmployeeCode.php` | Next `LD-0001` code, race-safe |
| `app/Support/EmployeeAccess.php` | Who may see/edit which employee and which field groups |
| `app/Http/Resources/EmployeeListResource.php`, `EmployeeProfileResource.php` | The only way an employee reaches page props |
| `app/Actions/Employees/CreateEmployee.php`, `UpdateEmployee.php`, `ChangeEmployeeStatus.php` | Writes, each in one transaction with its audit entry |
| `app/Http/Controllers/EmployeeController.php` | List, create, edit, show, status, delete |
| `app/Http/Controllers/EmployeeFileController.php` | Photo and document upload, download, removal |
| `app/Http/Controllers/EmployeeImportController.php`, `EmployeeExportController.php` | Excel in and out |
| `app/Imports/EmployeesImport.php`, `app/Exports/EmployeesExport.php`, `EmployeeTemplateExport.php` | Sheet definitions |
| `app/Http/Controllers/OrganizationChartController.php` | Chart data |
| `app/Http/Controllers/MyProfileController.php` | The signed-in person's own record |
| `resources/js/pages/employees/{index,form,show,import}.tsx`, `organization-chart/index.tsx`, `my/profile.tsx` | Pages |
| `resources/js/components/hrm/{avatar,field-list,form-tabs,org-chart-node,view-toggle}.tsx` | Reusable pieces |

---

### Task 1: Schema, model, access rules and shared list helper

**Files:** create the migration, the four models and factories, `EmployeeCode`, `EmployeeAccess`, `ListQuery`; modify `User` (relation `employee()`), `Department` (relation `head()`), `DepartmentController` and `UserController` (use `ListQuery`), `config/hrm.php` (enums), `tests/Pest.php` (helper).
**Tests:** `tests/Unit/EmployeeCodeTest.php`, `tests/Unit/EmployeeAccessTest.php`, `tests/Feature/Employees/SchemaTest.php`, `tests/Unit/ListQueryTest.php`.

**Interfaces — Produces:**
- `employees`: `id`, `employee_code` (unique), `device_user_id` (nullable, unique), `first_name`, `last_name`, `photo_path` (nullable), `email` (unique), `phone` (nullable), `dob` (nullable date), `gender` (nullable: `male|female|other`), `cnic` (nullable, unique, 13 digits), `department_id` (FK restrict), `designation_id` (FK restrict), `manager_id` (nullable, self FK, null on delete), `joining_date` (date), `probation_end_date` (nullable date), `employment_type` (`full_time|part_time|contract|intern`), `status` (`active|probation|inactive|resigned|terminated`), `last_working_day` (nullable date), timestamps. Indexes on `status`, `department_id`, `designation_id`, `manager_id`. No `shift_id` yet (Phase 3 adds it).
- `employee_contacts`: `employee_id` (unique FK cascade), `address` (text, nullable), `emergency_name`, `emergency_phone` (nullable).
- `employee_bank_accounts`: `employee_id` (unique FK cascade), `bank_name`, `account_title`, `account_number` (nullable strings).
- `employee_documents`: `employee_id` (FK cascade), `type` (`cnic|contract|offer_letter|certificate|other`), `title`, `file_path`, `original_name`, `mime`, `size_bytes`, `expiry_date` (nullable), `uploaded_by` (nullable user FK, null on delete), timestamps.
- `users.employee_id` nullable unique FK (null on delete); `departments.head_employee_id` nullable FK (null on delete). Deleting a department or designation that employees reference is blocked with the Phase 1 message style ("Move this department's employees first." / "Move this designation's employees first."), added to the existing delete blockers.
- `Employee`: `full_name` accessor; relations `department`, `designation`, `manager`, `reports`, `contact`, `bankAccount`, `documents`, `user`; scopes `status(string)`, `search(string)` (name, code, email).
- `EmployeeCode::next(): string` — inside a transaction with a lock; `LD-0001` on an empty table; next after the highest numeric suffix.
- `EmployeeAccess` (constructed for a `User`):
  - `scope(Builder $q): Builder` — all for `employees.view-all`; direct reports plus self for `employees.view-team` without view-all; self only otherwise; nothing for a user with no employee record and no view permission.
  - `canView(Employee $e): bool`, `canManage(): bool`, `canDelete(Employee $e): bool`.
  - `fieldGroups(Employee $e): array` — subset of `['basic','employment','contact','banking','documents','identity']` where `identity` = CNIC + DOB. view-all: all six. Manager viewing a report: `basic`, `employment` only. Self: all six (own data), read-only.
- `ListQuery`: `escapeLike(string): string`, `perPage(Request): int`, `page(Request, string $key): int`, `clampRedirect(Request, array $paginators): ?RedirectResponse`, `stringParam(Request, string $key): string`.
- Test helper `employeeFor(User $user, array $attrs = []): Employee` and `userWithRole()` unchanged.
- `config('hrm.employees.statuses')`, `.employment_types`, `.genders`, `.document_types`.

**Key tests (write first):** code sequence (`LD-0001`, after `LD-0009` → `LD-0010`, after `LD-9999` → `LD-10000`, ignores malformed codes, two concurrent calls differ); `scope` and `fieldGroups` for each role against self / report / stranger / indirect report; FK restrict on department and designation; unique `cnic`, `device_user_id`, `email` (null allowed many times); department delete blocked with the exact message; `ListQuery` behaviours equal to the existing controller tests, which must still pass unchanged.

- [ ] Write failing tests → run → implement → run → full suite → commit `feat: employee schema, access rules and shared list helper`.

---

### Task 2: Employees list

**Files:** `EmployeeController@index`, `EmployeeListResource`, `resources/js/pages/employees/index.tsx`, `components/hrm/{avatar,view-toggle}.tsx`, `routes/web.php`, `navigation.ts` (Employees `ready: true`).
**Tests:** `tests/Feature/Employees/EmployeeListTest.php`, Vitest for `initials()`.

**Look (owner's screenshot, reference list page):** title "Employees", subtitle "Manage employees and their information.", actions on the right: Export, Import, green "Add Employee". Filter bar: search box, "All Departments" and "All Designations" selects, list/grid toggle. Status tabs with counts: All, Active, Probation, Inactive, Resigned, Terminated. Table columns: #, Name (round photo or initials, name, email under it), Employee ID (code as a soft blue badge), Department, Designation, Status (pill), Joined (calendar icon + date), Login (pill Active/Disabled/No account), Actions (view, edit, delete icons; shown per permission). Grid view: cards with photo, name, designation, department, status, code. No "Branches" filter and no language picker.

**Interfaces — Produces:** route `employees.index` (`GET /employees`, permission: `employees.view-all` or `employees.view-team`); query params `search`, `department_id`, `designation_id`, `status`, `view=list|grid`, `per_page`, `page`. Props: `employees` (paginated `EmployeeListResource`: `id, code, name, email, photo_url, department, designation, status, joining_date, login` — email omitted for a Manager's view only if the spec's manager rule requires; it does not: name, code, department, designation, status, joined and work email are `basic`/`employment`), `counts` per status (within the viewer's scope, ignoring the status filter but honouring the others), `filters`, `departments`, `designations` (id + name only), `can: { manage, delete, import, export }`. `<Avatar src name size />`, `<ViewToggle value onChange />`.

**Key tests:** admin and hr see all; manager sees only direct reports and self; employee gets 403; counts match scope; each filter and search (name, code, email; literal `%`); status tab; `per_page` and clamp; array-valued params; props never contain `cnic`, `dob`, bank or contact keys for any role; Login pill states; no N+1 (query count stays flat from 10 to 50 rows).

- [ ] Tests → implement → look at it (screenshots `docs/screens/phase-2/employees-list-1440-light.png`, `employees-grid-1440-light.png`, `employees-list-375-light.png`, with seeded sample people removed afterwards) → commit `feat: employees list with filters, tabs and grid view`.

---

### Task 3: Add and edit employee

**Files:** `CreateEmployee`, `UpdateEmployee`, `ChangeEmployeeStatus`, `EmployeeRequest`, `EmployeeController@create|store|edit|update|status|destroy`, `resources/js/pages/employees/form.tsx`, `components/hrm/form-tabs.tsx`.
**Tests:** `tests/Feature/Employees/EmployeeWriteTest.php`, `tests/Unit/ManagerLoopTest.php`.

**Form (one page, five tabs, one Save; a tab with an error shows a red dot and the first one opens):**
- **Basic:** first name, last name, photo, employee code (auto; editable by Admin), work email, phone, date of birth, gender, CNIC.
- **Employment:** department, designation (filtered by department), reporting manager (searchable, excludes self and own subordinates), joining date, probation end date, employment type, status, machine user ID, role (default Employee; Admin may pick any, HR may pick Employee or Manager only).
- **Contact:** address, emergency contact name and phone.
- **Banking:** bank name, account title, account number / IBAN.
- **Documents:** handled in Task 5; the tab is present and says documents can be added after the employee is saved (create) or shows the Task 5 panel (edit).

**Interfaces — Produces:** routes `employees.create`, `employees.store`, `employees.edit`, `employees.update`, `employees.status` (`PATCH /employees/{employee}/status`, body `status`, `last_working_day` required for resigned/terminated), `employees.destroy`; all need `employees.manage` except destroy (`employees.delete`). `CreateEmployee::handle(array $data, User $by): Employee` — one transaction: code, employee, satellites, then the login: if a user with that email exists and has no employee, link it and set the chosen role under the Phase 1 last-admin rules; otherwise `CreateUserAccount`; `->linkSent(): bool` and `->linkedExistingUser(): bool` for the flash. `UpdateEmployee::handle(Employee, array, User)`: syncs the user's name and email when they change (through the Phase 1 update action), never changes the role of the acting user's own account. `ChangeEmployeeStatus::handle(Employee, string $status, ?string $lastWorkingDay, User)`: `inactive|resigned|terminated` disables the login via `SetUserActive` (respecting the last-admin guard); `active|probation` re-enables it. `ManagerLoop::wouldCreateCycle(int $employeeId, ?int $managerId): bool`.

**Validation:** names required, max 100, normalised; email required, unique across employees case-insensitively, and not owned by a user linked to another employee; CNIC optional, exactly 13 digits after stripping dashes, unique; phone max 30; `dob` in the past and at least 16 years before `joining_date`; `joining_date` required; `probation_end_date` after `joining_date`; department and designation exist and the designation belongs to the department; manager exists, is not self, creates no cycle, is not resigned/terminated; `device_user_id` max 20, unique; enums from config; HR cannot assign `admin` or `hr` roles (403 for that field, not silent).

**Key tests:** each rule above; code auto-assigned and stable on update; HR cannot edit the code; create links an existing unlinked user and does not duplicate it; link-not-sent path flashes the Phase 1 message and keeps the employee; update syncs user name/email and writes one audit entry listing changed fields with CNIC/DOB/account masked; status change disables and re-enables login; the last active Admin cannot be made inactive; manager cycle (self, two-step, three-step) refused; destroy refused for an employee with a signed-in user or reports, allowed for a fresh mistake; manager and employee roles get 403 on every write route.

- [ ] Tests → implement → screenshots (`employees-form-basic-1440-light.png`, `employees-form-employment-1440-light.png`, `employees-form-375-light.png`) → commit `feat: add and edit employees with login account`.

---

### Task 4: Employee profile page

**Files:** `EmployeeController@show`, `EmployeeProfileResource`, `resources/js/pages/employees/show.tsx`, `components/hrm/field-list.tsx`.
**Tests:** `tests/Feature/Employees/EmployeeProfileTest.php`.

**Look (owner's screenshot, reference profile):** breadcrumb Dashboard › Employees › name; title = name, subtitle "View detailed information and activity for employee."; "Back" button on the right, plus "Edit" for those who can. Left card: large round photo, name, designation, status pill, then icon rows: Employee ID, email, phone, joined date, department, employment type (and DOB only if `identity` is allowed). Right: a tab strip — Basic Info, Employment, Contact, Banking, Documents — over a card titled for the tab with a two-column label/value grid. Tabs the viewer may not see are not rendered and their data is not sent.

**Interfaces — Produces:** route `employees.show` (`GET /employees/{employee}`); props `employee` built only from `EmployeeAccess::fieldGroups`; `tabs: string[]`; `can: { edit, changeStatus, delete }`; `reports` (id, name, code, designation) for the Employment tab; `manager` (id, name) linked only if the viewer may view that manager. `<FieldList items={[{label, value}]} columns={2} />` renders "—" for empty values.

**Key tests:** per role × relationship matrix (admin, hr, manager→report, manager→stranger 403, manager→indirect report 403, employee→self via Task 8 only, employee→other 403): exact set of prop keys and tabs; a Manager's props contain none of `cnic, dob, address, emergency_*, bank_*, documents`; unknown id 404; the status-change control enforces Task 3's rules.

- [ ] Tests → implement → screenshots (`employee-profile-1440-light.png`, `employee-profile-manager-view-1440-light.png`, `employee-profile-375-light.png`) → commit `feat: employee profile page with role-based tabs`.

---

### Task 5: Photos and documents

**Files:** `EmployeeFileController`, `EmployeeDocumentRequest`, document panel in `form.tsx` and `show.tsx`, photo control in `form.tsx`.
**Tests:** `tests/Feature/Employees/EmployeeFilesTest.php`.

**Interfaces — Produces:** routes `employees.photo.show` (`GET /employees/{employee}/photo` — allowed for anyone who may view the employee; cache privately), `employees.photo.update|destroy` (manage), `employees.documents.store` (manage), `employees.documents.download` (`GET /employees/{employee}/documents/{document}` — `documents` field group required; forced download with the original name sanitised; `nosniff`), `employees.documents.destroy` (manage). `photo_url` in resources points at the photo route with a version query from `updated_at`. A document of another employee requested through this employee's URL is 404.

**Key tests:** type, size and dimension limits; real MIME checked (a `.pdf` that is HTML is refused); stored name is random and under `employees/{id}/…` on the private disk; nothing is reachable under `/storage` or `public_path()`; manager can load a report's photo but gets 403 for the report's documents; employee cannot fetch another person's photo; cross-employee document id → 404; replacing a photo deletes the old file; deleting a document removes the file and writes an audit entry without contents; download headers.

- [ ] Tests → implement → commit `feat: employee photos and documents on the private disk`.

---

### Task 6: Excel import and export

**Files:** `composer require maatwebsite/excel`; `EmployeesImport`, `EmployeesExport`, `EmployeeTemplateExport`, the two controllers, `resources/js/pages/employees/import.tsx`.
**Tests:** `tests/Feature/Employees/EmployeeImportTest.php`, `EmployeeExportTest.php`.

**Flow:** Import page (permission `employees.manage`): download template → upload `.xlsx` (≤ 5 MB, ≤ 2,000 data rows) → **preview** table of every row with a status (Ready / Error and the reasons) and totals → "Import N employees" saves only if the file still matches the previewed upload (stored temporarily on the private disk under a random token tied to the user, deleted after import or after 1 hour by the scheduler). All-or-nothing: if any row has an error the import button is disabled and nothing is saved.

**Template columns (header names exact):** `First Name*`, `Last Name*`, `Work Email*`, `Phone`, `Date of Birth` (YYYY-MM-DD), `Gender`, `CNIC`, `Department*`, `Designation*`, `Manager Email`, `Joining Date*` (YYYY-MM-DD), `Employment Type*`, `Status*`, `Machine User ID`, `Employee Code` (blank = auto), `Address`, `Emergency Contact Name`, `Emergency Contact Phone`, `Bank Name`, `Account Title`, `Account Number`. Second sheet "Help" lists allowed values. Departments and designations are matched by name (case-insensitive, normalised) and must already exist — the error names the missing one. `Manager Email` may refer to an existing employee or to another row in the same file; rows are saved in dependency order; cycles are an error.

**Import behaviour:** rows are created through `CreateEmployee` with role `employee`; login links are NOT emailed during import (accounts are created without sending; HR sends links later from the Users screen or a "Send set-password links" bulk action on the list — include that bulk action here, limited to 50 per click and queued). One audit entry per import run.

**Export:** `employees.export` (`GET /employees/export`, permission `employees.view-all`; a Manager has no export). Honours the list's current filters. Columns: the template's columns for Admin/HR. Cells beginning with `=`, `+`, `-`, `@`, tab or CR are prefixed with `'`. File name `employees-YYYY-MM-DD.xlsx`. One audit entry per export.

**Key tests:** template headers exact; each validation error surfaces on the right row with a readable message; duplicates inside the file (email, CNIC, code, machine ID) and against the database; manager in the same file, forward reference, cycle; date as Excel serial and as text; trailing spaces and mixed case in enums; an all-valid 300-row file imports in one transaction and assigns sequential codes; any invalid row means zero rows saved; the preview token cannot be used by another user or twice; non-xlsx and oversized files refused; no mail sent; export scope and filter honouring; formula escaping; manager and employee get 403 for both.

- [ ] Tests → implement → screenshots (`employees-import-preview-1440-light.png`) → commit `feat: employee Excel import with preview and export`.

---

### Task 7: Organization chart

**Files:** `OrganizationChartController`, `resources/js/pages/organization-chart/index.tsx`, `components/hrm/org-chart-node.tsx`, `resources/js/lib/org-tree.ts`.
**Tests:** `tests/Feature/OrganizationChartTest.php`, Vitest `org-tree.test.ts`.

**Look:** tree of cards from the top (people with no manager) downwards; each card: photo, name, designation badge, department, a "N reports" toggle that expands or collapses the branch; first two levels open by default; a search box highlights and reveals a person; horizontal scroll inside the card on small screens. Only active and probation employees are shown; a person whose manager is hidden attaches to the nearest shown ancestor.

**Interfaces — Produces:** route `organization-chart.index` (`GET /organization-chart`, permission `employees.view-all` or `employees.view-team`). Props: a flat list `nodes: { id, name, designation, department, photo_url, manager_id }[]` limited by `EmployeeAccess::scope` (a Manager gets themself and their direct reports). `buildTree(nodes)` in `org-tree.ts`: tolerates missing parents and cycles in data (a cycle is broken deterministically at the lowest id and never recurses forever), stable sibling order by name.

**Key tests:** scope per role; no restricted fields in nodes; inactive people excluded and their reports re-attached; `buildTree` on empty, single, deep (200 levels), wide, missing parent and cyclic input.

- [ ] Tests → implement → screenshots (`org-chart-1440-light.png`, `org-chart-375-light.png`) → commit `feat: organization chart`.

---

### Task 8: My Profile, dashboard number and navigation

**Files:** `MyProfileController`, `resources/js/pages/my/profile.tsx`, `DashboardController` (or the existing dashboard route) + `dashboard.tsx`, `navigation.ts`, the Users screen (show linked employee).
**Tests:** `tests/Feature/MyProfileTest.php`, `tests/Feature/DashboardNumbersTest.php`.

**Interfaces — Produces:** route `my.profile` (`GET /my/profile`, any signed-in user): the viewer's own employee record, read-only, same layout as Task 4 with all six field groups; a user with no employee record sees a clear empty state ("Your account is not linked to an employee record yet."). Dashboard "Total Employees" tile shows the count of active + probation employees within the viewer's scope (company for Admin/HR, own team for Manager), with footnote "N on probation"; the tile is hidden for a plain Employee and replaced by nothing else in this phase; other tiles keep "—". Today's Birthdays card (Admin/HR only, since DOB is restricted): name, designation, photo for people whose birthday is today in the display timezone; empty state otherwise. Navigation: Employees, Organization Chart and My Profile set `ready: true`. Users & Roles list gains an "Employee" column linking to the profile.

**Key tests:** my profile shows own data for every role and never another person's; empty state for an unlinked user; dashboard number per role and scope; birthdays use the display timezone at a day boundary; a Manager and an Employee receive no birthday data in props.

- [ ] Tests → implement → screenshots (`my-profile-1440-light.png`, `dashboard-phase-2-1440-light.png`) → commit `feat: my profile, employee count on dashboard and navigation`.

---

### Task 9: Release and the phase gate (controller + owner)

- [ ] Deploy with `scripts/deploy.ps1 -SeedEnvFile …` and `hosting_deploy`; confirm `deploy/hostinger/status.txt` shows the new release `ok`; run the live checks from `docs/deploy.md` plus: `/employees` redirects to sign-in when signed out; a photo URL is 302/403 when signed out.
- [ ] Owner creates the first Admin (email + password through the env seed, then re-seed without the password), signs in, enters departments and designations, downloads the import template.
- [ ] **Gate (spec section 12): the real staff list is imported** and the owner confirms names, departments and reporting lines look right on the list and the org chart.
- [ ] Tag `phase-2`.

---

## Self-review notes

- Spec 9.2 coverage: list, filters, tabs with counts, list/grid, export → Tasks 2, 6; form in five tabs → Task 3; profile with left card and tabs → Task 4; documents with type and expiry → Task 5; login created with the employee, Admin can disable/enable/reset → Tasks 3 and existing Users screen; Excel import with template, preview and per-row errors → Task 6; org chart → Task 7; departments and designations → Phase 1. "Salary tab for Admin/HR only" → Phase 5 with salary data. Profile sub-tabs "Attendance summary, Leave balances, Assets held, Review history" → added by the phases that own that data.
- The reference's "Certifications" tab is covered by documents of type `certificate`. "Branches" is out (one office).
- `shift_id` and the Shift field arrive with Phase 3; `device_user_id` is captured now so the real staff import can carry machine IDs.
- Spec status tabs list "All, Active, Probation, Inactive, Resigned, Terminated"; the design-system pills already define tones for these.
