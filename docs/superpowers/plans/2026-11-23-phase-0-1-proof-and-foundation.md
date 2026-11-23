# Lumino HRM Phase 0–1 (Proof and Foundation) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Prove the MB20 can reach the HRM on Hostinger, then deliver a signed-in shell with the reference look, four roles, departments, designations, settings, users and an audit log.

**Architecture:** One Laravel app serving React pages through Inertia; no separate API. The machine-facing `/iclock` routes live in their own route file with no session, CSRF or HTTPS redirect. Permissions are enforced on the server with spatie/laravel-permission and mirrored to the browser only to hide menu entries.

**Tech Stack:** Laravel 13, PHP 8.4, Inertia + React + TypeScript, Tailwind 4, shadcn/ui, Lucide, spatie/laravel-permission, spatie/laravel-activitylog, Pest, MariaDB/MySQL.

**Spec:** `docs/superpowers/specs/2026-11-23-lumino-hrm-design.md` (sections 5, 6, 7, 8, 9.9, 9.10, 10.2, 12). Companion: `docs/research/2026-11-23-workdo-reference-analysis.md`.

**Later plans (not in this document):** Phase 2 Employees, Phase 3 Attendance, Phase 4 Leave, Phase 5 Payroll, Phase 6 Performance / Assets / Exit. Each is written after the previous phase passes its gate.

## Global Constraints

- PHP 8.3+ locally (target 8.4, same as the server). XAMPP's PHP 8.2.12 must not be used to run the app.
- No Node on the server: `npm run build` runs on the PC and `public/build` is uploaded.
- Server has `exec`, `shell_exec`, `system`, `passthru` disabled: no package or code path may shell out.
- No long-running processes: queue driver `database`, drained by the scheduler.
- App timezone `Asia/Karachi`; currency PKR; money columns `decimal(12,2)`.
- Accent colour `#10b77f`; font Inter; radius 10 px (`0.625rem`), buttons 8 px; sidebar 272 px.
- Product name shown in the UI: "Lumino HRM". The words "WorkDo" and its logo never appear in code, copy or assets.
- No language picker, no registration page: accounts are created by Admin only.
- Salary and payroll permissions are held by Admin and HR only; menu hiding is never the access control.
- The starter kit's file layout is the source of truth. Paths below marked "(kit)" follow the Laravel React starter kit as known at planning time; if the installed kit differs, use its equivalent file and keep the names in each task's Interfaces block.
- The existing `luminocrm.online` site, its database and its cron entry are never modified.
- Anything created on Hostinger (subdomain, database, cron, PHP settings) is confirmed with the owner first, naming exactly what will be created.
- Every commit message ends with: `Co-Authored-By: Claude Fable 5.2 <noreply@anthropic.com>`

## Review Focus

1. **A user deactivated while signed in keeps working** → their next request must sign them out. Test in Task 4.
2. **HR posts directly to a settings group they cannot see** (company, payroll, email) → 403, nothing saved. Test in Task 7.
3. **The machine re-sends, sends malformed lines, or an unknown serial number calls `/iclock`** → never a 500; unknown serials get a plain-text rejection and a log row. Test in Task 2.
4. **A setting has never been saved** → `settings()` returns the spec default, pages render. Test in Task 7.
5. **Admin removes or demotes the last Admin, or deletes a department that still has designations** → blocked with a clear message. Tests in Tasks 8 and 6.

---

## File map

| Path | Responsibility |
|---|---|
| `routes/iclock.php` | Machine routes only |
| `app/Http/Controllers/Iclock/IclockController.php` | Parse machine requests, reply in plain text |
| `app/Models/DeviceLog.php` + migration | Raw record of every machine request |
| `config/hrm.php` | Allowed device serials (phase 0), setting defaults, permission map |
| `scripts/deploy.ps1`, `docs/deploy.md` | Build and upload routine |
| `database/seeders/RolePermissionSeeder.php` | Four roles and their permissions |
| `app/Http/Middleware/EnsureUserIsActive.php` | Sign out deactivated users |
| `resources/js/config/navigation.ts` | Sidebar groups, entries, required permission |
| `resources/js/components/hrm/*` | `page-header`, `status-pill`, `status-tabs`, `data-table`, `confirm-dialog` |
| `app/Models/Department.php`, `Designation.php` + controllers, requests, pages | Organization lists |
| `app/Models/Setting.php`, `app/Support/Settings.php`, `app/helpers.php` | Settings store and `settings()` helper |
| `app/Http/Controllers/Settings/HrmSettingsController.php` | Settings screen |
| `app/Http/Controllers/UserController.php` | Users & roles screen |
| `app/Http/Controllers/AuditLogController.php` | Audit log viewer |

---

### Task 1: Toolchain and project skeleton

**Files:**
- Create: the Laravel app in `C:\xampp\htdocs\LuminoHRM` (existing `docs/` kept)
- Modify: `.env`, `.env.example`, `config/app.php` (timezone), `.gitignore`

**Interfaces:**
- Produces: a runnable app with the starter kit's auth, layout and Pest suite; local database `lumino_hrm`; git repository on branch `main`.

- [ ] **Step 1: Install PHP 8.4 locally.** Ask the owner before installing. Preferred: Laravel Herd for Windows (bundles PHP 8.4 and Composer). Alternative: a standalone PHP 8.4 folder placed ahead of XAMPP on `PATH`. Verify: `php -v` prints 8.4.x.
- [ ] **Step 2: Create the app.** `laravel new` refuses a non-empty folder, so create it as `C:\xampp\htdocs\LuminoHRM-new` with the React starter kit, Pest, and Laravel's built-in authentication, then move its contents into `LuminoHRM` beside `docs/` and delete the empty folder.
- [ ] **Step 3: Configure.** Database `lumino_hrm` on XAMPP's MariaDB (`DB_HOST=127.0.0.1`); `APP_NAME="Lumino HRM"`; `APP_TIMEZONE=Asia/Karachi`; `QUEUE_CONNECTION=database`; `SESSION_DRIVER=database`; `CACHE_STORE=database`; `MAIL_MAILER=log` locally.
- [ ] **Step 4: Remove public registration.** Delete the register route, page and link from the kit; delete or adjust the kit's registration tests.
- [ ] **Step 5: Verify.** Run `php artisan migrate`, `php artisan test`, `npm install`, `npm run build`. Expected: migrations run, all kit tests pass, build writes `public/build/manifest.json`.
- [ ] **Step 6: Commit.** `git init -b main`, confirm `.env`, `vendor/`, `node_modules/`, `public/build/` are ignored, commit `chore: scaffold Lumino HRM from Laravel React starter kit`.

---

### Task 2: `/iclock` logging endpoints (phase 0 version)

Phase 0 only records what the machine sends. Punch storage and day rebuilds come in Phase 3 and will extend these same routes.

**Files:**
- Create: `routes/iclock.php`, `app/Http/Controllers/Iclock/IclockController.php`, `app/Models/DeviceLog.php`, migration `create_device_logs_table`, `config/hrm.php`
- Modify: `bootstrap/app.php` (register the route file with no middleware group)
- Test: `tests/Feature/Iclock/IclockEndpointsTest.php`

**Interfaces:**
- Produces:
  - Table `device_logs`: `id`, `serial_no` (string, indexed), `method`, `path`, `query` (text), `body` (longText, nullable), `ip`, `scheme` (`http`/`https`), `accepted` (bool), `summary` (string), timestamps.
  - `config('hrm.devices.allowed_serials')`: array from env `HRM_DEVICE_SERIALS` (comma-separated). Replaced by the `devices` table in Phase 3.
  - Route names: `iclock.handshake` (GET `/iclock/cdata`), `iclock.upload` (POST `/iclock/cdata`), `iclock.getrequest` (GET `/iclock/getrequest`), `iclock.devicecmd` (POST `/iclock/devicecmd`).
  - `IclockController::parseAttlog(string $body): array` returning a list of `['pin' => string, 'punched_at' => string, 'status' => string, 'verify' => string]`; lines that do not parse are skipped.

- [ ] **Step 1: Write the failing tests** (set `hrm.devices.allowed_serials` to `['TESTSN001']` in `beforeEach`):

```php
it('answers the handshake in plain text with device options', function () {
    $r = $this->get('/iclock/cdata?SN=TESTSN001&options=all');
    $r->assertOk()->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
    expect($r->getContent())->toStartWith("GET OPTION FROM: TESTSN001\n")
        ->toContain('Realtime=1')->toContain('TransFlag=TransData AttLog OpLog');
    expect(DeviceLog::where('serial_no', 'TESTSN001')->where('accepted', true)->count())->toBe(1);
});

it('accepts ATTLOG lines without a CSRF token and reports the count', function () {
    $body = "101\t2026-11-23 09:00:17\t0\t1\t0\t0\n102\t2026-11-23 09:02:00\t0\t1\t0\t0\n";
    $r = $this->call('POST', '/iclock/cdata?SN=TESTSN001&table=ATTLOG&Stamp=9999', [], [], [], ['CONTENT_TYPE' => 'text/plain'], $body);
    $r->assertOk();
    expect($r->getContent())->toBe("OK: 2\n");
    expect(DeviceLog::latest('id')->first()->body)->toBe($body);
});

it('skips malformed lines instead of failing', function () {
    $r = $this->call('POST', '/iclock/cdata?SN=TESTSN001&table=ATTLOG', [], [], [], [], "garbage\n\n103\t2026-11-23 18:30:00\t1\t1\n");
    expect($r->getContent())->toBe("OK: 1\n");
});

it('rejects an unknown serial number and logs it', function () {
    $r = $this->get('/iclock/cdata?SN=EVIL999');
    $r->assertStatus(403);
    expect($r->getContent())->toBe("ERROR: unknown device\n");
    expect(DeviceLog::where('serial_no', 'EVIL999')->where('accepted', false)->exists())->toBeTrue();
});

it('rejects a request with no serial number', function () {
    $this->get('/iclock/getrequest')->assertStatus(400);
});

it('answers command polling and command results with OK', function () {
    expect($this->get('/iclock/getrequest?SN=TESTSN001')->getContent())->toBe("OK\n");
    expect($this->call('POST', '/iclock/devicecmd?SN=TESTSN001', [], [], [], [], "ID=1&Return=0&CMD=DATA")->getContent())->toBe("OK\n");
});

it('accepts OPERLOG uploads', function () {
    expect($this->call('POST', '/iclock/cdata?SN=TESTSN001&table=OPERLOG', [], [], [], [], "OPLOG 4\t0\t2026-11-23 09:00:00\t0\t0\t0\t0\n")->getContent())->toBe("OK\n");
});

it('does not start a session or set cookies', function () {
    expect($this->get('/iclock/getrequest?SN=TESTSN001')->headers->getCookies())->toBeEmpty();
});
```

- [ ] **Step 2: Run** `php artisan test tests/Feature/Iclock` — expected: all fail (routes missing).
- [ ] **Step 3: Implement.** Register `routes/iclock.php` in `bootstrap/app.php` via the `then:` callback of `withRouting`, with `Route::middleware([])` so no `web` group applies. Handshake body, one option per line, `\n` endings:

```
GET OPTION FROM: {SN}
ATTLOGStamp=None
OPERLOGStamp=9999
ATTPHOTOStamp=None
ErrorDelay=30
Delay=10
TransTimes=00:00;14:05
TransInterval=1
TransFlag=TransData AttLog OpLog
TimeZone=5
Realtime=1
Encrypt=None
```

ATTLOG lines are tab-separated: PIN, `Y-m-d H:i:s`, status, verify, then extra fields to ignore. A line counts when PIN is non-empty and the second field parses as a date-time. Truncate stored `body` at 1 MB. Every request writes one `device_logs` row; a logging failure must not change the reply.
- [ ] **Step 4: Run** the tests — expected: all pass.
- [ ] **Step 5: Commit** `feat: iclock endpoints that log machine requests`.

---

### Task 3: Hostinger deploy and the phase 0 gate

**Files:**
- Create: `scripts/deploy.ps1`, `docs/deploy.md`, `docs/decisions/2026-11-phase-0-machine-link.md`
- Modify: `public/.htaccess` (only if the HTTP exception needs it)

**Interfaces:**
- Produces: a live URL for the HRM; a repeatable deploy routine; a written outcome (1, 2 or 3 from spec section 6) that Phase 3 depends on.

- [ ] **Step 1: Confirm with the owner** the subdomain name (default `hrm.luminocrm.online`), that a new database may be created, and the answer to open item 14 (is BioTime in use now). Do not proceed without a yes.
- [ ] **Step 2: Create on Hostinger** (Chiraag account, user `u882878513`, hosting MCP tools or hPanel): the subdomain, a database with a generated password (store only in the server `.env`), PHP 8.4 for the subdomain. Check and record the MySQL/MariaDB version.
- [ ] **Step 3: Decide the upload method.** Check whether SSH is available on the plan. Write `scripts/deploy.ps1` to: run tests, `npm run build`, `composer install --no-dev --optimize-autoloader` into a staging copy, upload, then run `migrate --force`, `config:cache`, `route:cache`, `view:cache` on the server. If hPanel cannot point the subdomain at `public/`, add a root `.htaccess` that rewrites to `public/` and denies `.env`, `storage/`, `vendor/`. Document the routine in `docs/deploy.md`.
- [ ] **Step 4: Deploy and verify HTTPS.** Expected: the login page loads at `https://<subdomain>/login`; `https://<subdomain>/.env` returns 403 or 404.
- [ ] **Step 5: Test plain HTTP.** Set `HRM_DEVICE_SERIALS` to a test value on the server and run:

```bash
curl -i "http://<subdomain>/iclock/cdata?SN=<test-serial>&options=all"
```

Expected for outcome 1: `HTTP/1.1 200` with the option list, no redirect. If it returns 301 to HTTPS, find the source: the app (`URL::forceScheme`, `.htaccess`) or Hostinger's "Force HTTPS" setting for the subdomain; turn off the hosting-level setting for this subdomain and instead redirect to HTTPS in `public/.htaccess` for every path except `^iclock/`. Re-run the curl.
- [ ] **Step 6: Add the cron entry** for this app only: every minute, `/opt/alt/php84/usr/bin/php <app path>/artisan schedule:run`.
- [ ] **Step 7: Machine test (owner does this at the office, under an hour).** Owner photographs MB20 → COMM → Cloud Server Setting, enters the server address and port 80 (or 443 if testing HTTPS push), and reads the serial number from the machine's device info so it can be put in `HRM_DEVICE_SERIALS`. Someone punches. Expected: `device_logs` shows a handshake row and an ATTLOG row with that punch. Then point the machine back at BioTime if item 14 was "yes".
- [ ] **Step 8: Record the outcome** in `docs/decisions/2026-11-phase-0-machine-link.md`: which of outcomes 1, 2, 3 applies, the exact handshake query string and ATTLOG line the real machine sent, firmware version, and any reply-format changes needed. If outcome 3 (forwarder), stop and bring it to the owner before Phase 3 is planned.
- [ ] **Step 9: Commit** `chore: deploy routine and phase 0 machine-link result`.

**Gate:** real MB20 punches are in `device_logs` on the server. Phase 1 tasks may proceed in parallel with waiting for the office test; Phase 3 may not be planned until this gate is recorded.

---

### Task 4: Roles, permissions and inactive users

**Files:**
- Create: `database/seeders/RolePermissionSeeder.php`, `app/Http/Middleware/EnsureUserIsActive.php`, migration `add_is_active_and_last_login_to_users`
- Modify: `app/Models/User.php`, `app/Http/Middleware/HandleInertiaRequests.php` (kit), `bootstrap/app.php`, `database/seeders/DatabaseSeeder.php`, `config/hrm.php`, `resources/js/types/index.d.ts` (kit)
- Test: `tests/Feature/Auth/RolePermissionTest.php`, `tests/Feature/Auth/InactiveUserTest.php`

**Interfaces:**
- Produces:
  - Roles: `admin`, `hr`, `manager`, `employee`.
  - Permissions (exact names), from `config('hrm.permissions')`:

    | Permission | admin | hr | manager | employee |
    |---|---|---|---|---|
    | `org.manage` | ✓ | ✓ | | |
    | `employees.view-all` | ✓ | ✓ | | |
    | `employees.view-team` | ✓ | ✓ | ✓ | |
    | `employees.manage` | ✓ | ✓ | | |
    | `employees.delete` | ✓ | | | |
    | `attendance.view-all` | ✓ | ✓ | | |
    | `attendance.view-team` | ✓ | ✓ | ✓ | |
    | `attendance.edit` | ✓ | ✓ | | |
    | `leave.approve-all` | ✓ | ✓ | | |
    | `leave.approve-team` | ✓ | ✓ | ✓ | |
    | `payroll.view` | ✓ | ✓ | | |
    | `payroll.run` | ✓ | ✓ | | |
    | `payroll.approve` | ✓ | | | |
    | `performance.manage` | ✓ | ✓ | | |
    | `performance.review-team` | ✓ | ✓ | ✓ | |
    | `assets.manage` | ✓ | | | |
    | `assets.assign` | ✓ | ✓ | | |
    | `assets.view-team` | ✓ | ✓ | ✓ | |
    | `exit.manage` | ✓ | | | |
    | `exit.clearance` | ✓ | ✓ | | |
    | `exit.approve-team` | ✓ | ✓ | ✓ | |
    | `settings.manage` | ✓ | | | |
    | `settings.attendance-leave` | ✓ | ✓ | | |
    | `users.manage` | ✓ | | | |
    | `audit.view` | ✓ | | | |

    "My Space" pages need no permission: every signed-in user has them.
  - `users.is_active` (bool, default true), `users.last_login_at` (nullable datetime).
  - Inertia shared props: `auth.user`, `auth.role: string`, `auth.permissions: string[]`.
  - Test helper in `tests/Pest.php`: `userWithRole(string $role): User` (seeds roles once, returns an active user with that role).
  - Seeder creates one Admin from env `HRM_ADMIN_EMAIL` / `HRM_ADMIN_PASSWORD` when no admin exists; it never prints or logs the password.

- [ ] **Step 1: Install** `spatie/laravel-permission`, publish its migration, add `HasRoles` to `User`.
- [ ] **Step 2: Write the failing tests:**

```php
it('seeds four roles with the mapped permissions', function () {
    $this->seed(RolePermissionSeeder::class);
    expect(Role::pluck('name')->sort()->values()->all())->toBe(['admin', 'employee', 'hr', 'manager']);
    expect(Role::findByName('hr')->hasPermissionTo('payroll.run'))->toBeTrue();
    expect(Role::findByName('hr')->hasPermissionTo('payroll.approve'))->toBeFalse();
    expect(Role::findByName('manager')->permissions->pluck('name')->filter(fn ($p) => str_starts_with($p, 'payroll.')))->toBeEmpty();
    expect(Role::findByName('employee')->permissions)->toBeEmpty();
});

it('is safe to run the seeder twice', function () {
    $this->seed(RolePermissionSeeder::class); $this->seed(RolePermissionSeeder::class);
    expect(Role::count())->toBe(4);
});

it('shares role and permissions with the page', function () {
    $this->actingAs(userWithRole('hr'))->get('/dashboard')
        ->assertInertia(fn ($p) => $p->where('auth.role', 'hr')->where('auth.permissions', fn ($list) => collect($list)->contains('org.manage')));
});

it('refuses sign-in for an inactive user', function () {
    $u = userWithRole('employee'); $u->update(['is_active' => false]);
    $this->post('/login', ['email' => $u->email, 'password' => 'password']);
    $this->assertGuest();
});

it('signs out a user deactivated mid-session on their next request', function () {
    $u = userWithRole('employee');
    $this->actingAs($u)->get('/dashboard')->assertOk();
    $u->update(['is_active' => false]);
    $this->get('/dashboard')->assertRedirect('/login');
    $this->assertGuest();
});

it('records the last sign-in time', function () {
    $u = userWithRole('employee');
    $this->post('/login', ['email' => $u->email, 'password' => 'password']);
    expect($u->fresh()->last_login_at)->not->toBeNull();
});
```

- [ ] **Step 3: Run** — expected: fail.
- [ ] **Step 4: Implement.** Seeder is idempotent (`firstOrCreate`, `syncPermissions`). `EnsureUserIsActive` is appended to the `web` group after authentication. Inactive sign-in shows the message "This account has been disabled. Contact HR."
- [ ] **Step 5: Run** the full suite — expected: pass.
- [ ] **Step 6: Commit** `feat: roles, permissions and inactive-user handling`.

---

### Task 5: Design system and application shell

**Files:**
- Modify (kit): `resources/css/app.css`, `resources/js/components/app-sidebar.tsx`, `resources/js/components/nav-main.tsx`, `resources/js/components/app-sidebar-header.tsx`, `resources/js/pages/dashboard.tsx`, `resources/views/app.blade.php`
- Create: `resources/js/config/navigation.ts`, `resources/js/hooks/use-can.ts`, `resources/js/components/hrm/page-header.tsx`, `resources/js/components/hrm/status-pill.tsx`, `resources/js/components/hrm/sidebar-search.tsx`, `resources/js/lib/navigation.test.ts`
- Test: `resources/js/lib/navigation.test.ts` (add Vitest as a dev dependency if the kit has no JS test runner), `tests/Feature/DashboardShellTest.php`

**Interfaces:**
- Consumes: `auth.permissions`, `auth.role` from Task 4.
- Produces:
  - `navigation.ts` exports `type NavItem = { title: string; href?: string; icon?: LucideIcon; permission?: string | string[]; children?: NavItem[] }`, `type NavGroup = { label: string; items: NavItem[] }`, `const navigation: NavGroup[]`, and `filterNavigation(groups: NavGroup[], permissions: string[], query?: string): NavGroup[]`. An item with several permissions shows when the user has any one. A parent with no visible children is dropped; a group with no items is dropped. `query` matches titles case-insensitively, keeping parents of matches.
  - `useCan(): (permission: string) => boolean`.
  - `<PageHeader title subtitle actions? />`, `<StatusPill tone="green|grey|amber|red|blue">label</StatusPill>`.
  - CSS variables in `app.css`: `--primary: #10b77f` with a white foreground in light and dark; `--radius: 0.625rem`; `--sidebar-width: 272px`; Inter loaded from a local or Bunny Fonts stylesheet.
- Sidebar content (spec section 8). Entries whose pages do not exist yet are included with their final `href` and hidden behind a `ready: false` flag, so later phases only flip the flag:

  | Group | Entries → permission |
  |---|---|
  | Overview | Dashboard → none; Calendar → none |
  | Workforce | Employees → `employees.view-all` or `employees.view-team`; Organization Chart → same; Departments & Designations → `org.manage`; Attendance (Daily, Monthly, Correction Requests, Shifts, Raw Punches) → `attendance.view-all` or `attendance.view-team`; Leave (Requests, Balances, Leave Types, Holidays) → `leave.approve-all` or `leave.approve-team` |
  | Growth | Performance (KPIs, Reviews) → `performance.manage` or `performance.review-team` |
  | Finance & Assets | Payroll (Salary Profiles, Payroll Runs, Payslips, Loans & Advances) → `payroll.view`; Assets (All Assets, Assignments) → `assets.assign` or `assets.view-team` |
  | Exit | Resignations & Clearance → `exit.clearance` or `exit.approve-team` |
  | System | Settings → `settings.manage` or `settings.attendance-leave`; Users & Roles → `users.manage`; Audit Log → `audit.view` |
  | My Space | My Profile, My Attendance, My Leaves, My Payslips, My Assets, My Reviews → none |

- [ ] **Step 1: Write the failing tests.**

```ts
// navigation.test.ts
test('employee sees only Overview and My Space', () => {
  const labels = filterNavigation(navigation, []).map(g => g.label)
  expect(labels).toEqual(['Overview', 'My Space'])
})
test('manager never sees Payroll, Settings, Users or Audit Log', () => {
  const perms = ['employees.view-team','attendance.view-team','leave.approve-team','performance.review-team','assets.view-team','exit.approve-team']
  const titles = JSON.stringify(filterNavigation(navigation, perms))
  for (const t of ['Payroll','Settings','Users & Roles','Audit Log']) expect(titles).not.toContain(t)
})
test('search keeps the parent of a matching child and drops other groups', () => {
  const r = filterNavigation(navigation, allPermissions, 'payslip')
  expect(r.map(g => g.label)).toEqual(['Finance & Assets', 'My Space'])
})
test('search with no match returns an empty list', () => {
  expect(filterNavigation(navigation, allPermissions, 'zzzz')).toEqual([])
})
```

```php
// DashboardShellTest.php
it('renders the dashboard for every role', function (string $role) {
    $this->actingAs(userWithRole($role))->get('/dashboard')->assertOk()
        ->assertInertia(fn ($p) => $p->component('dashboard'));
})->with(['admin', 'hr', 'manager', 'employee']);

it('never mentions the reference product', function () {
    $files = collect(File::allFiles(resource_path()))->merge(File::allFiles(app_path()));
    expect($files->filter(fn ($f) => str_contains(strtolower($f->getContents()), 'workdo')))->toBeEmpty();
});
```

- [ ] **Step 2: Run** — expected: fail.
- [ ] **Step 3: Implement the shell.** Sidebar: text logo "Lumino HRM" (image logo comes from Settings in Task 7), "Search menu" box, uppercase group labels, collapsible sub-menus, icon-rail collapse, drawer under 768 px. Top bar: sidebar toggle, breadcrumb, dark-mode toggle, avatar menu (Profile, Sign out); no language picker. An empty search result shows "No menu items match".
- [ ] **Step 4: Implement the dashboard frame** with placeholder data only: dark navy welcome banner ("Good morning / afternoon / evening, {first name}", today's date), six pastel stat tiles showing "—", and two empty cards. Real numbers arrive in Phases 2–5.
- [ ] **Step 5: Run** PHP and JS tests, then `npm run build` — expected: pass.
- [ ] **Step 6: Look at it.** Run the app, sign in as the seeded Admin, and compare against the screenshots described in the companion analysis at 1440 px and 375 px, light and dark. Capture four screenshots into `docs/screens/phase-1/`.
- [ ] **Step 7: Commit** `feat: design tokens, sidebar shell and dashboard frame`.

**Gate (spec section 12):** the owner signs off the look from the Task 5 screenshots or the staging site before Tasks 6–9 are polished further.

---

### Task 6: Departments and designations

**Files:**
- Create: migrations `create_departments_table`, `create_designations_table`; `app/Models/Department.php`, `Designation.php`; `app/Http/Controllers/DepartmentController.php`, `DesignationController.php`; `app/Http/Requests/DepartmentRequest.php`, `DesignationRequest.php`; `resources/js/pages/organization/index.tsx`; `resources/js/components/hrm/data-table.tsx`, `status-tabs.tsx`, `confirm-dialog.tsx`
- Modify: `routes/web.php`, `resources/js/config/navigation.ts` (set `ready: true`)
- Test: `tests/Feature/Organization/DepartmentTest.php`, `DesignationTest.php`

**Interfaces:**
- Consumes: `org.manage`; `PageHeader`; `userWithRole()`.
- Produces:
  - `departments`: `id`, `name` (unique), timestamps. `head_employee_id` is added in Phase 2 with the employees table.
  - `designations`: `id`, `name`, `department_id` (FK, restrict on delete), unique (`department_id`, `name`), timestamps.
  - Routes under `/organization` (middleware `can:org.manage`): `organization.index` (one page, two tabs), `departments.store|update|destroy`, `designations.store|update|destroy`.
  - `<DataTable columns rows pagination search? toolbar? emptyText />` (server-side pagination, 10 / 25 / 50 rows, search as a query-string parameter), `<StatusTabs tabs={[{key,label,count}]} value onChange />`, `<ConfirmDialog title description onConfirm />`. Later phases reuse these three.

- [ ] **Step 1: Write the failing tests:**

```php
it('lets admin and hr manage departments', function (string $role) {
    $this->actingAs(userWithRole($role))->post('/organization/departments', ['name' => 'Engineering'])->assertRedirect();
    expect(Department::where('name', 'Engineering')->exists())->toBeTrue();
})->with(['admin', 'hr']);

it('forbids managers and employees', function (string $role) {
    $this->actingAs(userWithRole($role))->get('/organization')->assertForbidden();
    $this->actingAs(userWithRole($role))->post('/organization/departments', ['name' => 'X'])->assertForbidden();
})->with(['manager', 'employee']);

it('rejects a duplicate department name, ignoring case and outer spaces', function () {
    Department::create(['name' => 'Engineering']);
    $this->actingAs(userWithRole('admin'))->post('/organization/departments', ['name' => '  engineering '])->assertSessionHasErrors('name');
});

it('will not delete a department that still has designations', function () {
    $d = Department::create(['name' => 'Engineering']); $d->designations()->create(['name' => 'Developer']);
    $this->actingAs(userWithRole('admin'))->delete("/organization/departments/{$d->id}")
        ->assertSessionHas('error', 'Move or delete this department\'s designations first.');
    expect($d->fresh())->not->toBeNull();
});

it('allows the same designation name in two departments but not twice in one', function () {
    $a = Department::create(['name' => 'A']); $b = Department::create(['name' => 'B']);
    $admin = userWithRole('admin');
    $this->actingAs($admin)->post('/organization/designations', ['name' => 'Manager', 'department_id' => $a->id])->assertSessionHasNoErrors();
    $this->actingAs($admin)->post('/organization/designations', ['name' => 'Manager', 'department_id' => $b->id])->assertSessionHasNoErrors();
    $this->actingAs($admin)->post('/organization/designations', ['name' => 'Manager', 'department_id' => $a->id])->assertSessionHasErrors('name');
});

it('searches and paginates the list', function () {
    Department::factory()->count(30)->create(); Department::create(['name' => 'Zebra Team']);
    $this->actingAs(userWithRole('hr'))->get('/organization?search=zebra')
        ->assertInertia(fn ($p) => $p->has('departments.data', 1));
});
```

- [ ] **Step 2: Run** — expected: fail.
- [ ] **Step 3: Implement** models, requests (trim names; max 100 characters), controllers, and the page: `PageHeader` with an "Add" button, tabs "Departments" and "Designations" with counts, tables with edit and delete icon actions, add/edit in a dialog, delete through `ConfirmDialog`, success and error toasts from flash messages.
- [ ] **Step 4: Run** — expected: pass.
- [ ] **Step 5: Commit** `feat: departments and designations with shared list components`.

---

### Task 7: Settings

**Files:**
- Create: migration `create_settings_table`; `app/Models/Setting.php`; `app/Support/Settings.php`; `app/helpers.php` (autoloaded in `composer.json`); `app/Http/Controllers/Settings/HrmSettingsController.php`; `app/Http/Requests/Settings/*Request.php` (one per group); `resources/js/pages/hrm-settings/index.tsx` and one panel component per group; `app/Mail/TestMail.php`
- Modify: `config/hrm.php`, `routes/web.php`, `HandleInertiaRequests.php`, `app-sidebar.tsx` (logo), `app.css` usage of the accent, `navigation.ts`
- Test: `tests/Feature/Settings/SettingsTest.php`, `tests/Unit/SettingsTest.php`

**Interfaces:**
- Consumes: `settings.manage`, `settings.attendance-leave`.
- Produces:
  - `settings` table: `id`, `group`, `key`, `value` (text, JSON-encoded), unique (`group`, `key`), timestamps.
  - `Settings::get(string $dotted, mixed $default = null): mixed`, `Settings::set(string $group, array $values): void`, `Settings::group(string $group): array`; global helper `settings(string $dotted, mixed $default = null)`. Reads are cached per request and the cache is cleared on `set`. Missing values fall back to `config('hrm.defaults')`.
  - Defaults (`config/hrm.php`, values from spec section 14):

    | Key | Default |
    |---|---|
    | `company.name` | `Lumino Digital` |
    | `company.currency` | `PKR` |
    | `company.timezone` | `Asia/Karachi` |
    | `company.date_format` | `d M Y` |
    | `branding.accent` | `#10b77f` |
    | `attendance.shift_start` / `shift_end` | `09:00` / `18:00` |
    | `attendance.grace_minutes` | `15` |
    | `attendance.weekly_offs` | `["saturday","sunday"]` |
    | `attendance.late_rule_count` | `3` |
    | `attendance.half_day_hours` | `4` |
    | `attendance.overtime_enabled` | `false` |
    | `attendance.overtime_min_minutes` | `30` |
    | `leave.hr_step_enabled` | `true` |
    | `leave.carry_forward_cap` | `0` |
    | `leave.prorate_new_joiners` | `true` |
    | `payroll.day_pay_basis` | `calendar_days` (also `thirty`, `working_days`) |
    | `payroll.default_tax_mode` | `none` |
    | `payroll.leave_encashment` | `false` |
    | `payroll.notice_period_days` | `30` |
    | `payroll.payslip_footer` | empty string |

  - Groups and who may open and save them: `company`, `branding`, `payroll`, `email`, `letters` → `settings.manage`; `attendance`, `leave` → `settings.manage` or `settings.attendance-leave`. The "ZKTeco Device" group is added in Phase 3.
  - Routes: `GET /hrm-settings` (`hrm-settings.index`), `PUT /hrm-settings/{group}` (`hrm-settings.update`), `POST /hrm-settings/email/test` (`hrm-settings.email.test`). The kit's own `/settings/*` profile pages stay as they are and are reached from the avatar menu.
  - Inertia shared prop `app: { name: string, accent: string, logoUrl: string | null, logoDarkUrl: string | null }`.
  - Email settings are stored in the `email` group with the SMTP password encrypted (`Crypt`), never sent back to the browser, and applied to the mailer config at boot. An empty password field on save keeps the stored one.

- [ ] **Step 1: Write the failing tests:**

```php
// Unit
it('returns the spec default when nothing is saved', function () {
    expect(settings('attendance.grace_minutes'))->toBe(15);
    expect(settings('attendance.weekly_offs'))->toBe(['saturday', 'sunday']);
    expect(settings('nope.missing', 'x'))->toBe('x');
});
it('returns a saved value over the default and keeps its type', function () {
    Settings::set('attendance', ['grace_minutes' => 10, 'overtime_enabled' => true]);
    expect(settings('attendance.grace_minutes'))->toBe(10);
    expect(settings('attendance.overtime_enabled'))->toBeTrue();
});

// Feature
it('lets hr save attendance rules but not company, payroll or email', function () {
    $hr = userWithRole('hr');
    $this->actingAs($hr)->put('/hrm-settings/attendance', validAttendance(['grace_minutes' => 20]))->assertSessionHasNoErrors();
    expect(settings('attendance.grace_minutes'))->toBe(20);
    foreach (['company', 'branding', 'payroll', 'email', 'letters'] as $g) {
        $this->actingAs($hr)->put("/hrm-settings/$g", ['name' => 'Hacked'])->assertForbidden();
    }
    expect(settings('company.name'))->toBe('Lumino Digital');
});
it('shows hr only the groups they may edit', function () {
    $this->actingAs(userWithRole('hr'))->get('/hrm-settings')
        ->assertInertia(fn ($p) => $p->where('groups', ['attendance', 'leave'])->missing('values.payroll')->missing('values.email'));
});
it('forbids managers and employees entirely', function (string $role) {
    $this->actingAs(userWithRole($role))->get('/hrm-settings')->assertForbidden();
})->with(['manager', 'employee']);
it('returns 404 for an unknown group', function () {
    $this->actingAs(userWithRole('admin'))->put('/hrm-settings/bogus', [])->assertNotFound();
});
it('validates attendance rules', function () {
    $this->actingAs(userWithRole('admin'))->put('/hrm-settings/attendance', validAttendance(['grace_minutes' => -5, 'shift_end' => '08:00', 'weekly_offs' => ['funday']]))
        ->assertSessionHasErrors(['grace_minutes', 'shift_end', 'weekly_offs.0']);
});
it('accepts only a 6-digit hex accent colour', function () {
    $this->actingAs(userWithRole('admin'))->put('/hrm-settings/branding', ['accent' => 'red;}'])->assertSessionHasErrors('accent');
});
it('rejects a logo that is not an image or is over 1 MB', function () {
    $admin = userWithRole('admin');
    $this->actingAs($admin)->put('/hrm-settings/branding', ['accent' => '#10b77f', 'logo' => UploadedFile::fake()->create('x.php', 10)])->assertSessionHasErrors('logo');
    $this->actingAs($admin)->put('/hrm-settings/branding', ['accent' => '#10b77f', 'logo' => UploadedFile::fake()->image('l.png')->size(2000)])->assertSessionHasErrors('logo');
});
it('never sends the SMTP password to the browser and keeps it when left blank', function () {
    $admin = userWithRole('admin');
    $this->actingAs($admin)->put('/hrm-settings/email', validEmail(['password' => 'secret-1']));
    $this->actingAs($admin)->get('/hrm-settings')->assertInertia(fn ($p) => $p->missing('values.email.password')->where('values.email.has_password', true));
    $this->actingAs($admin)->put('/hrm-settings/email', validEmail(['password' => '']));
    expect(Crypt::decryptString(Settings::group('email')['password']))->toBe('secret-1');
});
it('sends a test email to the signed-in admin', function () {
    Mail::fake(); $admin = userWithRole('admin');
    $this->actingAs($admin)->post('/hrm-settings/email/test')->assertSessionHas('success');
    Mail::assertSent(TestMail::class, fn ($m) => $m->hasTo($admin->email));
});
it('reports a failed test email instead of crashing', function () {
    Mail::shouldReceive('to->send')->andThrow(new \Exception('Connection refused'));
    $this->actingAs(userWithRole('admin'))->post('/hrm-settings/email/test')->assertSessionHas('error');
});
```

`validAttendance(array $overrides)` and `validEmail(array $overrides)` are helpers in the test file that return a full valid payload merged with the overrides.

- [ ] **Step 2: Run** — expected: fail.
- [ ] **Step 3: Implement** store, helper, controller and requests. Validation: `shift_end` after `shift_start`; `grace_minutes` 0–120; `late_rule_count` 1–31; `half_day_hours` 1–12; `weekly_offs` a subset of the seven lowercase day names; accent `^#[0-9a-fA-F]{6}$`; logos `png`, `jpg`, `webp`, `svg` up to 1 MB, stored on the public disk under `branding/`.
- [ ] **Step 4: Implement the page**: left list of groups, right panel of cards each with its own "Save Changes" button, as in the reference. The sidebar logo and the `--primary` variable read from the `app` shared prop.
- [ ] **Step 5: Run** — expected: pass.
- [ ] **Step 6: Commit** `feat: settings store, helper and settings screen`.

---

### Task 8: Users and roles screen

**Files:**
- Create: `app/Http/Controllers/UserController.php`, `app/Http/Requests/UserRequest.php`, `resources/js/pages/users/index.tsx`, `app/Notifications/SetPasswordNotification.php`
- Modify: `routes/web.php`, `navigation.ts`
- Test: `tests/Feature/Users/UserManagementTest.php`

**Interfaces:**
- Consumes: `users.manage`, `DataTable`, `StatusTabs`, `StatusPill`, `ConfirmDialog`.
- Produces: routes under `/users` (middleware `can:users.manage`): `users.index`, `users.store`, `users.update` (name, email, role), `users.toggle` (`PATCH /users/{user}/active`), `users.send-reset` (`POST /users/{user}/send-reset`). No delete route: accounts are disabled, never removed. `users.employee_id` is added in Phase 2, which will create accounts from the employee form through `UserController`'s underlying action `App\Actions\CreateUserAccount::handle(string $name, string $email, string $role): User`.

- [ ] **Step 1: Write the failing tests:**

```php
it('lets only admin open the users screen', function (string $role) {
    $this->actingAs(userWithRole($role))->get('/users')->assertForbidden();
})->with(['hr', 'manager', 'employee']);

it('creates a user with a role and emails a set-password link, never a password', function () {
    Notification::fake();
    $this->actingAs(userWithRole('admin'))->post('/users', ['name' => 'Sara Ahmed', 'email' => 'sara@example.com', 'role' => 'hr'])->assertSessionHasNoErrors();
    $u = User::whereEmail('sara@example.com')->first();
    expect($u->hasRole('hr'))->toBeTrue();
    Notification::assertSentTo($u, SetPasswordNotification::class);
});

it('rejects an unknown role and a duplicate email', function () {
    $admin = userWithRole('admin');
    $this->actingAs($admin)->post('/users', ['name' => 'X', 'email' => 'x@example.com', 'role' => 'superuser'])->assertSessionHasErrors('role');
    $this->actingAs($admin)->post('/users', ['name' => 'X', 'email' => $admin->email, 'role' => 'hr'])->assertSessionHasErrors('email');
});

it('will not demote or disable the last active admin', function () {
    $admin = userWithRole('admin');
    $this->actingAs($admin)->put("/users/{$admin->id}", ['name' => $admin->name, 'email' => $admin->email, 'role' => 'hr'])
        ->assertSessionHas('error', 'There must be at least one active Admin.');
    $this->actingAs($admin)->patch("/users/{$admin->id}/active")->assertSessionHas('error');
    expect($admin->fresh()->hasRole('admin'))->toBeTrue()->and($admin->fresh()->is_active)->toBeTrue();
});

it('allows demoting an admin when another active admin exists', function () {
    $a = userWithRole('admin'); $b = userWithRole('admin');
    $this->actingAs($a)->put("/users/{$b->id}", ['name' => $b->name, 'email' => $b->email, 'role' => 'manager'])->assertSessionHasNoErrors();
    expect($b->fresh()->hasRole('manager'))->toBeTrue()->and($b->fresh()->roles)->toHaveCount(1);
});

it('disables and re-enables a user', function () {
    $u = userWithRole('employee');
    $this->actingAs(userWithRole('admin'))->patch("/users/{$u->id}/active");
    expect($u->fresh()->is_active)->toBeFalse();
});
```

- [ ] **Step 2: Run** — expected: fail.
- [ ] **Step 3: Implement.** New accounts get a random unusable password and a set-password link built on Laravel's password-reset broker. The page: tabs All / Active / Disabled with counts, role filter, columns name + email, role pill, status pill, last sign-in, actions (edit, disable or enable, send reset link).
- [ ] **Step 4: Run** — expected: pass.
- [ ] **Step 5: Commit** `feat: users and roles screen`.

---

### Task 9: Audit log

**Files:**
- Create: `app/Http/Controllers/AuditLogController.php`, `resources/js/pages/audit-log/index.tsx`, `app/Listeners/LogAuthEvents.php`
- Modify: `app/Models/Department.php`, `Designation.php`, `User.php` (add `LogsActivity`), `app/Support/Settings.php` (log on `set`), `routes/web.php`, `navigation.ts`
- Test: `tests/Feature/Audit/AuditLogTest.php`

**Interfaces:**
- Consumes: `audit.view`.
- Produces: routes `audit-log.index` (`GET /audit-log`, middleware `can:audit.view`; query filters `user_id`, `area`, `from`, `to`). No update or delete routes exist. Convention for later phases: every model whose changes matter adds `LogsActivity` with `logOnlyDirty()` and a `useLogName('<area>')`; areas used so far are `organization`, `users`, `settings`, `auth`.

- [ ] **Step 1: Install** `spatie/laravel-activitylog` and publish its migration.
- [ ] **Step 2: Write the failing tests:**

```php
it('records who changed a department, with old and new values', function () {
    $admin = userWithRole('admin'); $d = Department::create(['name' => 'Ops']);
    $this->actingAs($admin)->put("/organization/departments/{$d->id}", ['name' => 'Operations']);
    $log = Activity::where('log_name', 'organization')->latest('id')->first();
    expect($log->causer_id)->toBe($admin->id);
    expect($log->properties['old']['name'])->toBe('Ops')->and($log->properties['attributes']['name'])->toBe('Operations');
});

it('records settings changes without storing the SMTP password', function () {
    $this->actingAs(userWithRole('admin'))->put('/hrm-settings/email', validEmail(['password' => 'secret-1']));
    $log = Activity::where('log_name', 'settings')->latest('id')->first();
    expect(json_encode($log->properties))->not->toContain('secret-1');
});

it('records role changes and never records password hashes', function () {
    $admin = userWithRole('admin'); $u = userWithRole('employee');
    $this->actingAs($admin)->put("/users/{$u->id}", ['name' => $u->name, 'email' => $u->email, 'role' => 'manager']);
    $log = Activity::where('log_name', 'users')->latest('id')->first();
    expect($log->description)->toContain('manager');
    expect(json_encode(Activity::all()->pluck('properties')))->not->toContain($u->password);
});

it('records sign-in, failed sign-in and sign-out', function () {
    $u = userWithRole('employee');
    $this->post('/login', ['email' => $u->email, 'password' => 'wrong']);
    $this->post('/login', ['email' => $u->email, 'password' => 'password']);
    expect(Activity::where('log_name', 'auth')->pluck('description')->all())->toContain('Failed sign-in', 'Signed in');
});

it('shows the log to admin only', function (string $role) {
    $this->actingAs(userWithRole($role))->get('/audit-log')->assertForbidden();
})->with(['hr', 'manager', 'employee']);

it('filters by area and date', function () {
    $admin = userWithRole('admin'); Department::create(['name' => 'A']);
    $this->actingAs($admin)->get('/audit-log?area=organization&from='.now()->toDateString())
        ->assertInertia(fn ($p) => $p->has('entries.data', 1));
});

it('exposes no route to change or delete entries', function () {
    $routes = collect(Route::getRoutes())->filter(fn ($r) => str_starts_with($r->uri(), 'audit-log'));
    expect($routes->flatMap->methods()->unique()->values()->all())->toEqualCanonicalizing(['GET', 'HEAD']);
});
```

- [ ] **Step 3: Run** — expected: fail.
- [ ] **Step 4: Implement.** Exclude `password` and `remember_token` from user logging. The page: filters (user, area, date range), table of time, user, area, description, and an expandable row showing old and new values side by side.
- [ ] **Step 5: Run** the full suite — expected: pass.
- [ ] **Step 6: Commit** `feat: audit log recording and viewer`.

---

### Task 10: Scheduler, queue, backups and the phase 1 release

**Files:**
- Create: `app/Console/Commands/BackupDatabase.php`
- Modify: `routes/console.php`, `scripts/deploy.ps1`, `docs/deploy.md`
- Test: `tests/Feature/Console/BackupDatabaseTest.php`, `tests/Feature/Console/ScheduleTest.php`

**Interfaces:**
- Produces: schedule entries `queue:work --stop-when-empty --max-time=50` every minute (`withoutOverlapping`), `hrm:backup-database` daily at 02:00, `activitylog:clean` is **not** scheduled (audit entries are kept). Command `hrm:backup-database` writes `storage/app/backups/lumino-hrm-YYYY-MM-DD.sql.gz` using PDO only (no `mysqldump`, because `exec` is disabled) and deletes backups older than 14 days. Later phases add their jobs to `routes/console.php`.

- [ ] **Step 1: Write the failing tests:**

```php
it('writes a gzip backup containing table structure and rows', function () {
    Storage::fake('local'); Department::create(['name' => 'Backup Check']);
    $this->artisan('hrm:backup-database')->assertSuccessful();
    $file = collect(Storage::disk('local')->files('backups'))->first();
    expect($file)->toEndWith('.sql.gz');
    expect(gzdecode(Storage::disk('local')->get($file)))->toContain('Backup Check')->toContain('CREATE TABLE');
});

it('keeps 14 days of backups and removes older ones', function () {
    Storage::fake('local');
    Storage::disk('local')->put('backups/lumino-hrm-'.now()->subDays(20)->toDateString().'.sql.gz', 'old');
    Storage::disk('local')->put('backups/lumino-hrm-'.now()->subDays(5)->toDateString().'.sql.gz', 'recent');
    $this->artisan('hrm:backup-database');
    expect(Storage::disk('local')->files('backups'))->toHaveCount(2);
});

it('schedules the queue drain every minute and the backup nightly', function () {
    $events = collect(app(Schedule::class)->events());
    expect($events->first(fn ($e) => str_contains($e->command, 'queue:work'))->expression)->toBe('* * * * *');
    expect($events->first(fn ($e) => str_contains($e->command, 'hrm:backup-database'))->expression)->toBe('0 2 * * *');
});
```

- [ ] **Step 2: Run** — expected: fail.
- [ ] **Step 3: Implement.** The backup streams table by table to keep memory flat. `storage/app/backups` must not be web-reachable.
- [ ] **Step 4: Run** the full suite and `npm run build` — expected: pass.
- [ ] **Step 5: Deploy** with `scripts/deploy.ps1`; run the role seeder on the server with `HRM_ADMIN_EMAIL` and `HRM_ADMIN_PASSWORD` set in the server `.env` (the owner chooses and enters the password; it is not written in chat or committed), then remove `HRM_ADMIN_PASSWORD` from `.env`.
- [ ] **Step 6: Verify on the live site:** sign in as Admin; create a department; change a setting; see both in the audit log; confirm `device_logs` still receives a test `curl`; wait a day and confirm one backup file exists.
- [ ] **Step 7: Commit** `feat: scheduler, queue drain and nightly backup`, tag `phase-1`.

**Phase 1 done when:** all tests pass, the owner has signed off the look (Task 5 gate), and the Task 3 outcome is recorded.

---

## Self-review notes

- Spec coverage for phases 0–1: section 6 hosting → Tasks 3, 10; section 7 design → Task 5; section 8 roles and menu → Tasks 4, 5; section 9.9 settings → Task 7 (ZKTeco device group deferred to Phase 3 by design); section 9.10 users and audit → Tasks 8, 9; section 10.2 endpoints → Task 2 (logging only; storage in Phase 3); section 12 phase 0 gate → Task 3.
- Deferred on purpose: `employees` table and everything that references it (`users.employee_id`, `departments.head_employee_id`, "own team" checks) → Phase 2 plan. Calendar page and My Space pages are menu entries flagged `ready: false`.
- Open items 13 (pay cycle) and 14 (BioTime) are unanswered; 14 is asked in Task 3 Step 1, 13 before the Phase 5 plan.
