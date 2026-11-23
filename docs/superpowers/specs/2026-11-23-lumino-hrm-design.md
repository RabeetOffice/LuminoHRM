# Lumino HRM: design document

Date: 2026-11-23
Status: approved by the owner on 2026-11-23 (open items 13 and 14 still on their defaults)
Supersedes: "Lumino HRM: Project Documentation" (Word document, 2 Nov 2026). That document's module scope is kept; its frontend choice (Blade + Alpine) and hosting wording (cPanel) are replaced here.
Companion: [WorkDo reference analysis](../../research/2026-11-23-workdo-reference-analysis.md)

Items marked **(my call)** were decided by the author of this document, not by Lumino. Each can be reversed before build starts.

---

## 1. Intent

Lumino Digital has 50+ employees and needs one in-house system for the core HR work: who works here, who came in today, who is on leave, what each person is paid, how they are performing, what company equipment they hold, and how they leave.

Attendance must arrive by itself from the office ZKTeco MB20 fingerprint machine. Nobody should export files or keep an office PC running for it.

The system should look and feel like the WorkDo HRM demo, which the owner likes, but carry only the modules Lumino uses. WorkDo is a multi-tenant product with about 800 routes; Lumino needs roughly a third of that.

## 2. Goals, non-goals, anti-goals

**Goals**

- Nine modules working end to end: Dashboard, Employees, Attendance, Leave, Payroll, Performance, Assets, Exit, Settings.
- Punches from the MB20 appear in the HRM within a minute, with no office PC involved.
- Payroll for a month can be generated, reviewed, approved, locked, and paid from the system, with payslips as PDF and a bank transfer sheet as Excel.
- The interface matches the WorkDo look closely: same layout, component style, spacing and green accent, under Lumino's own name and logo.
- Every screen works on a phone browser.
- Runs on the existing Hostinger shared plan (Chiraag account).

**Non-goals for v1**

Recruitment and careers page, training, meetings, events, Zoom, Slack / Telegram / Twilio, landing page, multi-language, native mobile app, AI features, multiple companies or branches, subscription plans, payment gateways, asset depreciation, night shifts crossing midnight.

**Anti-goals** (would make this a failure even if it technically works)

- An employee or manager can see anyone's salary figures. Only Admin and HR ever can.
- A locked payroll run or an old payslip changes after the fact.
- Punches are lost or double-counted when the machine re-sends them.
- The app grows WorkDo's bulk: menu items or settings that nobody at Lumino uses.
- WorkDo's name, logo, or source code appears anywhere.

## 3. Constraints

| Constraint | Detail |
|---|---|
| Hosting | Hostinger shared hosting, plan `hostinger_unlimited_v6`, managed in hPanel. No long-running processes; cron every minute is available. |
| Server PHP | 8.4 active (8.2 to 8.5 selectable). Required extensions are enabled. `exec`, `shell_exec`, `system`, `passthru` are disabled. |
| Server database | MySQL / MariaDB provided by the plan. Version not yet checked. |
| No Node on the server | The React screens are compiled on the developer PC; only compiled files are uploaded. |
| Attendance machine | One ZKTeco MB20. It can push to one server only. It normally sends plain HTTP on port 80. |
| Developer PC | Windows 11, XAMPP with PHP 8.2.12 and MariaDB 10.4, Composer 2.9, Node 26, Git 2.54. PHP must be raised to 8.3+ for local work (see section 5). |
| Company context | Pakistan: currency PKR, CNIC field, timezone Asia/Karachi. |
| Users | About 55 accounts, a handful online at once. Performance is not a concern at this scale. |

## 4. Approaches

Three ways of building the screens were considered. The backend is Laravel in all three.

| | A. Laravel + Inertia + React + shadcn/ui | B. Laravel + Blade + Alpine | C. Buy and trim WorkDo |
|---|---|---|---|
| Match to the reference look | Near exact; it is the kit the reference uses | Close, with every component hand-made | Exact |
| Effort for this design | Lowest of A and B | Higher | Lowest at first, then high |
| Deploy | Build on PC, upload | Upload directly | Upload |
| Fit to Lumino's rules (direct MB20 push, tax modes, loans, late rule) | Built to fit | Built to fit | Must be forced into someone else's code and re-applied after each vendor update |
| Bulk | Only what is built | Only what is built | About 800 routes to hide or remove |

**Chosen: A.** The owner picked React + shadcn/ui on 23 Nov 2026 after hosting was confirmed. B was the earlier plan and loses on look and build time. C was not pursued because the anti-goal is WorkDo's bulk, and Lumino's attendance and payroll rules differ from WorkDo's.

## 5. Stack and resources

| Part | Choice | Why |
|---|---|---|
| Framework | Laravel 13 **(my call)** | Current version; needs PHP 8.3+, server has 8.4. Laravel 12 would run on the PC's PHP 8.2 as is, but its support window ends sooner. |
| Starting point | Official Laravel React starter kit | Ships login, password reset, profile, sidebar layout, dark mode, Inertia, React, TypeScript, Tailwind 4 and shadcn/ui already wired. Exact package versions are whatever the kit installs on the day the project is created. |
| Page delivery | Inertia.js | Laravel routes and controllers serve React pages directly; no separate API to build or secure. |
| UI components | shadcn/ui (Radix + Tailwind) | Same kit as the reference. Components are copied into the project, so they can be restyled freely. |
| Icons | Lucide | Default for shadcn/ui; matches the reference's outline icons. |
| Tables | TanStack Table (via shadcn data-table) | Sorting, column control, pagination. |
| Forms | Inertia `useForm` + Laravel Form Request validation | One set of validation rules, on the server. |
| Charts | Recharts (via shadcn chart) | Bars, lines and donuts on the dashboard. |
| Dates | date-fns + shadcn calendar / date picker | |
| Roles and permissions | spatie/laravel-permission | Four seeded roles, permission checks in policies and middleware. |
| Audit log | spatie/laravel-activitylog | Records who changed what, with old and new values. |
| PDFs | barryvdh/laravel-dompdf | Payslips and letters. Pure PHP, works with `exec` disabled. |
| Excel | maatwebsite/excel | Employee import, exports, bank sheet. |
| Email | Laravel Mail over SMTP (Hostinger mailbox) | Notifications and payslips. |
| Queue | Database queue, drained by the scheduler each minute | No worker process is possible on shared hosting. |
| Scheduler | Hostinger cron: `php artisan schedule:run` every minute | |
| File storage | Local private disk outside the web root; files served through an authorised route | Employee documents and photos are confidential. |
| Tests | Pest (ships with the starter kit) | Payroll and attendance rules get unit tests; key flows get feature tests. |
| Local PHP | PHP 8.4 via Laravel Herd for Windows, or a second PHP beside XAMPP **(my call)** | XAMPP's 8.2 is below Laravel 13's minimum. XAMPP's MariaDB can still be used. |
| MB20 link | Own `/iclock` endpoints inside the app **(my call)** | Five small routes. Community packages exist (see references) and are useful to read, but none is established enough to depend on for payroll-critical data. |

**Reference material**

- Laravel 13 release notes: https://laravel.com/docs/13.x/releases
- Laravel starter kits: https://laravel.com/docs/13.x/starter-kits
- Inertia.js: https://inertiajs.com
- shadcn/ui: https://ui.shadcn.com
- Lucide icons: https://lucide.dev
- spatie/laravel-permission: https://spatie.be/docs/laravel-permission
- spatie/laravel-activitylog: https://spatie.be/docs/laravel-activitylog
- Laravel Excel: https://docs.laravel-excel.com
- laravel-dompdf: https://github.com/barryvdh/laravel-dompdf
- ADMS push implementations to read, not to install: https://packagist.org/packages/tanemrahman/zkteco-adms , https://packagist.org/packages/athwari/laravel-zkteco-adms-server , https://packagist.org/packages/shadow046/zkteco-adms

## 6. Hosting and deployment

- **Where:** a new subdomain on the Chiraag Hostinger account, proposed `hrm.luminocrm.online` (name to be confirmed), with its own database. The existing `luminocrm.online` site is not touched.
- **Layout on the server:** Laravel app in the subdomain's folder with the web root pointing at `public/`. If hPanel cannot point the subdomain at `public/`, the builder uses a root `.htaccess` rewrite to `public/` and keeps `.env`, `storage/` and `vendor/` unreachable from the web.
- **Deploy routine:** on the PC run `npm run build` and `composer install --no-dev`, upload, then on the server run `php artisan migrate --force` and the cache commands. A one-command deploy script is part of phase 1. How it uploads (SSH, Git, or archive) is left to the builder after checking what the plan allows.
- **Cron:** one entry, every minute, `php artisan schedule:run`.
- **Staging:** a second subdomain or a password-protected folder for testing each phase before it goes live. Left to the builder.
- **Backups:** nightly database dump kept for 14 days, in addition to Hostinger's own backups.

**HTTP for the machine.** The MB20 sends plain HTTP. `luminocrm.online` currently answers HTTP with a redirect to HTTPS. Whether that redirect can be switched off for one path on this plan is unverified, so it is the first thing tested (phase 0, section 12). Outcomes:

1. `/iclock/*` can be served over HTTP: use the direct design in section 10.
2. The MB20 firmware supports HTTPS push: point it at HTTPS and keep the redirect.
3. Neither works: fall back to a small forwarder script on any always-on office device that reads the MB20 over the LAN and posts punches to the HRM over HTTPS.

## 7. Design system

Taken from measurements of the reference (see companion analysis). Lumino's name and logo replace the reference's.

| Token | Value |
|---|---|
| Accent | `#10b77f` until Lumino supplies a brand colour; one variable to change |
| Font | Inter; 14 px for controls and tables, 16 px base |
| Radius | 10 px cards and inputs, 8 px buttons |
| Borders | 1 px light grey; shadows barely visible |
| Surfaces | White cards on white page; muted grey for hover and table headers |
| Dark mode | Yes, shadcn token set, toggle in the top bar |
| Sidebar | 272 px, collapses to an icon rail; becomes a drawer on phones |

**Shell.** Left sidebar: logo, "Search menu" box, uppercase group labels, collapsible sub-menus. Top bar: sidebar toggle, breadcrumb, dark-mode toggle, avatar menu (profile, sign out). No language picker.

**Page pattern.** Title, one-line grey subtitle, actions on the right (Export, Import, green primary button). Content in bordered cards.

**List pattern.** Search, filter dropdowns, list / grid toggle where useful, status tabs with counts, table with avatar + name + email, code badge, status pill, icon actions, pagination with rows per page.

**Status pills.** Green Active / Approved / Present; grey Inactive / Draft; amber Probation / Pending / Late; red Terminated / Rejected / Absent; blue On Leave.

**Dashboard pattern.** Dark navy welcome banner ("Good afternoon, <name>", date, "N present today", last punch received), six pastel stat tiles, then two-column cards.

**Sketch of the shell**

```
+--------------------+-------------------------------------------------------+
| LUMINO HRM         | [=] Dashboard > Employees            (moon)  (avatar) |
| [ Search menu... ] +-------------------------------------------------------+
| OVERVIEW           | Employees                    [Export] [Import] [+ Add]|
|  Dashboard         | Manage employees and their information.               |
|  Calendar          | +---------------------------------------------------+ |
| WORKFORCE          | | [Search] [Department v] [Designation v]   [=][##] | |
|  Employees         | | All 54 | Active 50 | Probation 3 | Inactive 1 | ...| |
|  Organization Chart| +---------------------------------------------------+ |
|  Departments       | | #  Name            ID       Dept   Status  Joined  | |
|  Attendance      > | | 1  (o) Ali Khan   LD-0001  IT     Active  2024-.. | |
|  Leave           > | | 2  (o) Sara Ahmed LD-0002  HR     Active  2023-.. | |
| GROWTH             | +---------------------------------------------------+ |
|  Performance     > |                          Rows: 10 v   < 1 2 3 >       |
| FINANCE & ASSETS   |                                                       |
|  Payroll         > |                                                       |
|  Assets          > |                                                       |
| ...                |                                                       |
+--------------------+-------------------------------------------------------+
```

## 8. Roles and menu

Four roles, seeded. A user has exactly one role. Every employee has a login.

| Area | Admin | HR | Manager | Employee |
|---|---|---|---|---|
| Dashboard | Full company | Full company | Own team | Own summary |
| Employees | Add, edit, delete | Add, edit | View own team (no salary, bank, CNIC or documents) | Own profile |
| Attendance | All, edit, settings | All, edit | Own team, view; approve correction requests | Own, view; request correction |
| Leave | All, approve | All, approve | Approve own team | Apply, view own |
| Payroll | Run, approve, lock | Run, edit draft | No access | Own payslips only |
| Performance | All | All | Review own team | Self-review, view own |
| Assets | All | Assign, return | View own team | View own |
| Exit | All, final settlement | Process clearance | Approve own team | Submit resignation |
| Settings | Full | Attendance and leave rules only | None | None |
| Users & roles | Full | None | None | None |
| Audit log | View | None | None | None |

"Own team" means employees whose reporting manager is that user, direct reports only **(my call)**.

Team approvals follow the reporting line, not the role **(my call)**: anyone with direct reports, including an HR or Admin user, gets the manager step for those people. Where an employee has no reporting manager, or the manager is the person applying, the manager step is skipped and HR decides. A request by an HR user is decided by Admin.

Salary rule: salary profiles, payroll runs, other people's payslips, loans and the "Payroll This Month" tile are checked on the server for Admin or HR on every request. Hiding menu items is not the control.

**Sidebar**

- **Overview:** Dashboard; Calendar (holidays, approved leaves, birthdays)
- **Workforce:** Employees; Organization Chart; Departments & Designations; Attendance (Daily, Monthly, Correction Requests, Shifts, Raw Punches); Leave (Requests, Balances, Leave Types, Holidays)
- **Growth:** Performance (KPIs, Reviews)
- **Finance & Assets:** Payroll (Salary Profiles, Payroll Runs, Payslips, Loans & Advances); Assets (All Assets, Assignments)
- **Exit:** Resignations & Clearance
- **System:** Settings; Users & Roles; Audit Log
- **My Space** (everyone): My Profile, My Attendance, My Leaves, My Payslips, My Assets, My Reviews

Each role sees only the entries it can open.

## 9. Module specifications

Anything not listed is not in v1.

### 9.1 Dashboard

- Welcome banner: greeting by time of day, date, "N present today", time of the last punch received.
- Stat tiles: Total Employees, Present Today, Absent Today, Late Today, Pending Leaves, Payroll This Month (Admin / HR only; others see a Leave Balance tile instead).
- Lists: Today's Birthdays, On Leave Today, Late Arrivals Today.
- Charts: attendance for the last 30 days (present / absent / on leave); leave requests this month by status.
- Manager view: same widgets limited to own team.
- Employee view: own attendance this month, leave balances, latest payslip, assets held.

### 9.2 Employees

- List with search, department and designation filters, status tabs with counts (All, Active, Probation, Inactive, Resigned, Terminated), list / grid toggle, Excel export.
- Add / edit form in tabs:
  - **Basic:** name, photo, employee code (auto, `LD-0001`, editable by Admin), email, phone, date of birth, gender, CNIC.
  - **Employment:** department, designation, reporting manager, joining date, employment type (full-time, part-time, contract, intern), status, shift, machine user ID, probation end date.
  - **Contact:** address, emergency contact name and phone.
  - **Banking:** bank name, account title, account number / IBAN.
  - **Documents:** uploads with type (CNIC, contract, offer letter, certificate, other) and optional expiry date.
- Profile page: left card with photo, designation and key facts; right side tabs as above plus Attendance summary, Leave balances, Assets held, Review history. Salary tab for Admin / HR only.
- A login is created with each employee (email as username); the employee sets a password from an emailed link. Admin can disable, enable or send a reset.
- Excel import with a downloadable template, a preview of rows and per-row errors before saving.
- Organization chart drawn from the reporting-manager field: cards with photo, name and designation, expandable per manager.
- Departments and Designations: two simple list screens.

### 9.3 Attendance

- Punches come from the MB20 (section 10). First punch of the day is clock-in, last is clock-out, anything between is kept in the raw log but ignored.
- Shifts: name, start, end, grace minutes, half-day threshold (hours), weekly off days. One shift per employee.
- Daily status per employee: Present, Late, Half Day, Absent, On Leave, Holiday, Weekend. Late, Early Departure and Overtime are shown as markers on the day.
- Late = clock-in after shift start + grace. Early departure = clock-out before shift end. Half day = worked hours below the threshold. Overtime = minutes after shift end, counted only above a minimum set in Settings.
- One punch only = missing clock-out, flagged in a "Needs attention" list for HR.
- Manual correction by HR or Admin with a required reason; logged in the audit log and shown with a "manual" marker.
- **Correction requests (my call, added from the reference):** an employee asks for a missing or wrong punch to be fixed, giving date, times and reason; the manager approves, then HR applies it. If Lumino prefers HR-only fixes, this screen is dropped.
- Screens: Daily Attendance (date picker, all employees), Monthly grid (employees × days with symbols, legend and totals column, as in the reference), Correction Requests, Shifts, Raw Punches (every punch, unknown machine IDs highlighted), My Attendance.
- Monthly report to Excel; payroll reads the same monthly figures.
- Days are computed in Asia/Karachi time. The machine's clock is treated as local time.
- **Attendance-exempt employees (my call):** a tick-box on the employee (for the owner, remote or field staff). Exempt people are never marked Absent or Late and get no attendance deductions; they appear as Present on working days.

How outcomes combine **(my calls; each is a setting where noted)**:

- A day has one status. A day that is Half Day or Absent does not also count as a late.
- A missing clock-out counts as Present until HR fixes it. A payroll run cannot be approved while its month still has unresolved missing clock-outs.
- With an approved half-day leave, the other half is judged as a normal day with the half-day threshold as the required hours; lateness is not counted that day.
- Late rule is applied per calendar month and rounded down: with "3 lates = 1 day", 5 lates deduct 1 day and 6 deduct 2. Lates do not carry into the next month.

### 9.4 Leave

- Leave types: name, yearly quota, paid or unpaid, carry-forward on or off with a cap.
- Flow: employee applies → reporting manager approves → HR gives final approval. The HR step can be switched off in Settings. Either approver can reject with a reason. The employee can cancel while pending.
- Half-day leave supported. Day count skips weekly offs and holidays.
- Balances per employee, per type, per calendar year; allocated on 1 January, with carry-forward applied. New joiners get a pro-rated quota by joining month **(my call)**.
- Applying beyond the balance is blocked for paid types; the employee is pointed to Unpaid leave.
- Holiday calendar maintained by HR.
- Approved leave marks those attendance days "On Leave".
- Email on apply (to approver), approve and reject (to employee).

### 9.5 Payroll

- **Salary profile** per employee: basic salary, allowances (each taxable or not), fixed deductions, effective-from date. A new profile row is added for each change, so history is kept.
- **Tax** per employee on the profile:

  | Mode | Calculation |
  |---|---|
  | None | Nothing deducted |
  | Fixed | Same amount each month |
  | Percentage | % of gross taxable salary or of basic, selectable per employee |

- **Attendance deductions:** absent days and unpaid leave days at one day's pay each; late rule (N lates = 1 day, N in Settings); half days at half a day's pay.
- One day's pay = monthly gross ÷ days in that calendar month **(my call; could instead be ÷ 30 or ÷ working days, set in Settings)**.
- **Overtime pay:** on or off; hourly rate in Settings or a multiple of the employee's hourly pay.
- **Loans and advances:** amount, monthly installment, start month; deducted automatically until cleared; HR can skip one month.
- **One-off items** per run: bonus or extra deduction with a label.
- **Run flow:** pick a month → Generate draft (calculates every active employee) → HR reviews and edits lines, may override one employee's tax for that month → Admin approves → run is locked → payslip PDFs generated and emailed → bank transfer sheet exported to Excel.
- A locked run cannot be edited or deleted. Fixing a mistake means an adjustment item in the next month's run. Each payroll line stores its full breakdown, so later changes to profiles or settings never alter it.
- **Pay period (default, open item 13):** the calendar month. The run for a month is generated after the month ends, using that month's complete attendance, and paid in the first days of the next month.
- Employees joining mid-month are paid pro rata by calendar days.
- **Leavers (my call):** a person's final month is paid only through the final settlement (9.8), never through the normal run, so it cannot be paid twice. The run skips anyone whose last working day falls in that month and shows them in a "settled separately" list. Settlement pay and tax are included in the yearly tax report.
- **Opening figures at go-live (my call):** HR can enter, per employee, leave already used this year, loans in progress (remaining amount and installment), and tax deducted so far this year, by form or Excel import. These feed balances, loan schedules and the yearly tax report from the first month.
- Payslip PDF: company header, employee and month, earnings, deductions, tax, net pay, attendance summary.
- Reports: monthly payroll summary; yearly tax deducted per employee.
- Statutory schemes (EOBI, provident fund, income tax slabs) are not automated in v1. They can be entered as fixed deductions or through the tax modes above.

### 9.6 Performance

- KPIs with a weight, attached to a designation or to one employee.
- Review cycles: monthly or quarterly, chosen in Settings. HR opens a cycle; reviews are created for every active employee.
- Flow: optional self-rating → manager rates each KPI 1 to 5 with comments → HR closes the review.
- Overall score = weighted average. History on the employee profile; the employee sees their own closed reviews.

### 9.7 Assets

- Register: name, category (laptop, phone, monitor, licence, other; editable list), serial number, purchase date, cost, condition, status (available, assigned, in repair, retired).
- Assign to an employee with a date; return with date and condition note. Full assignment history per asset.
- Employee profile shows assets currently held.
- Unreturned assets block exit clearance.

### 9.8 Exit

- Employee submits a resignation with last working day and reason, or HR records one (including termination).
- Manager, then HR, approve. Notice period from Settings shown against the requested last day.
- Clearance checklist: assets returned (ticked automatically when none are held), system access removed, dues cleared, plus custom items from Settings.
- Final settlement: salary due to the last working day, leave encashment if enabled, minus outstanding loans and advances. Approved by Admin; produces a PDF statement.
- Experience letter as PDF from a template in Settings.
- On the last working day the status becomes Resigned or Terminated and the login is disabled by the nightly job.

### 9.9 Settings

Left list of groups, right panel of cards each with Save Changes, as in the reference.

- **Company:** name, logo, address, currency (PKR), timezone, date format.
- **Branding:** accent colour, light / dark logo.
- **Attendance:** grace minutes default, late rule, half-day threshold, overtime minimum and rate.
- **Leave:** approval steps, carry-forward cap, pro-rating on or off.
- **Payroll:** day-pay basis, default tax mode, payslip footer text, leave encashment on or off, notice period.
- **ZKTeco device:** see 10.5.
- **Email:** SMTP details and a "send test email" button.
- **Letter templates:** experience letter text with placeholders.

### 9.10 Users, roles, audit

- Users list: employee link, role, active or not, last sign-in. Admin changes roles and resets passwords.
- Audit log: who, when, what record, old and new values. Covers salary profiles, payroll runs, attendance corrections, leave decisions, role changes, settings and sign-ins. Filter by user, area and date. Entries cannot be edited or deleted from the app.

## 10. ZKTeco MB20 integration

### 10.1 How it works

The MB20 is set to push to the HRM using ZKTeco's ADMS (iclock) protocol. The machine opens the connection outward, so the office needs no static IP or port forwarding. The machine can push to one server only: once it points at the HRM, BioTime stops receiving punches.

Machine menu: COMM → Cloud Server Setting → server address = HRM domain, Enable Domain Name on, port 80, proxy off.

### 10.2 Endpoints

| Request from the machine | When | HRM reply |
|---|---|---|
| `GET /iclock/cdata?SN=…&options=all` | Start-up | Plain-text config: last stamp, push interval, realtime on |
| `POST /iclock/cdata?SN=…&table=ATTLOG` | Each new punch or batch | `OK: <count>` |
| `POST /iclock/cdata?SN=…&table=OPERLOG` | User or fingerprint changes on the machine | `OK` |
| `GET /iclock/getrequest?SN=…` | Every few seconds | `OK`, or one queued command |
| `POST /iclock/devicecmd?SN=…` | Result of a command | `OK` |

Replies are plain text, not JSON or HTML. These routes skip sessions, CSRF and the HTTPS redirect.

### 10.3 Rules

- Only serial numbers registered in Settings are accepted. Others get a rejection and a log entry. Optional extra: accept only from the office's public IP.
- Every punch line is stored once in `device_punches`, unique on device + machine user ID + punch time. A re-sent punch is ignored, and the reply still says OK.
- A punch is matched to an employee by machine user ID. Unmatched punches are kept and listed for HR; when HR later sets the ID on an employee, those punches attach and the affected days are rebuilt.
- Receiving a punch only stores it and marks that employee-day as needing a rebuild. A scheduled job every minute rebuilds marked days **(my call; the earlier document said every 10 minutes)**. A nightly job at 00:30 closes the previous day: marks Absent, Weekend, Holiday or On Leave for anyone without punches, and flags missing clock-outs.
- Rebuilding a day never overwrites a manual correction.

### 10.4 Machine users

Fingerprints are enrolled on the machine. The machine user ID is typed onto the employee's profile. Pushing new users from the HRM to the machine is a later option, not v1.

### 10.5 Settings → ZKTeco Device

- Add a device: name and serial number.
- Status: online if heard from in the last 5 minutes; last contact; last punch; punches today.
- Unknown user IDs list with a link to assign each to an employee.
- Sync log: recent requests with time, type and result (last 7 days kept).
- "Re-send punches" button: queues a command asking the machine to upload its log for a date range.

### 10.6 History

BioTime history can be exported once to Excel and imported through a one-time import screen for raw punches (machine user ID, date-time). Whether to import it, and from what date, is Lumino's choice.

## 11. Data model

Every table has `id`, `created_at`, `updated_at`. Tables supplied by packages (roles, permissions, activity log, jobs, sessions) are not listed.

| Group | Table | Key fields |
|---|---|---|
| Access | users | name, email, password, employee_id, is_active, last_login_at |
| Organization | departments | name, head_employee_id |
| | designations | name, department_id |
| | settings | group, key, value |
| Employees | employees | employee_code, device_user_id, first_name, last_name, photo, email, phone, dob, gender, cnic, department_id, designation_id, manager_id, shift_id, attendance_exempt, joining_date, probation_end_date, employment_type, status, last_working_day |
| | employee_contacts | employee_id, address, emergency_name, emergency_phone |
| | employee_bank_accounts | employee_id, bank_name, account_title, account_number |
| | employee_documents | employee_id, type, title, file_path, expiry_date |
| Attendance | shifts | name, start_time, end_time, grace_minutes, half_day_hours, weekly_offs |
| | devices | name, serial_no, last_seen_at, last_stamp, is_active |
| | device_punches | device_id, device_user_id, employee_id (nullable), punch_time, verify_type; unique (device_id, device_user_id, punch_time) |
| | device_commands | device_id, command, status, sent_at, result |
| | device_logs | device_id, type, summary, created_at |
| | attendances | employee_id, date, clock_in, clock_out, worked_minutes, late_minutes, early_minutes, overtime_minutes, status, is_manual, needs_rebuild, note; unique (employee_id, date) |
| | attendance_corrections | employee_id, date, requested_in, requested_out, reason, status, manager_id, hr_id |
| | holidays | name, date |
| Leave | leave_types | name, yearly_quota, is_paid, carry_forward, carry_forward_cap |
| | leave_balances | employee_id, leave_type_id, year, allocated, carried, used |
| | leave_requests | employee_id, leave_type_id, from_date, to_date, days, is_half_day, reason, status, manager_approved_by, hr_approved_by, rejected_reason |
| Payroll | salary_profiles | employee_id, basic, tax_mode, tax_value, tax_base, effective_from |
| | salary_components | salary_profile_id, name, type (allowance / deduction), amount, is_taxable |
| | loans | employee_id, type (loan / advance), amount, installment, remaining, start_month, status |
| | loan_repayments | loan_id, payroll_item_id (nullable), final_settlement_id (nullable), amount, month |
| | opening_balances | employee_id, year, tax_deducted_to_date (leave used and loans are entered on their own tables) |
| | payroll_runs | month, status (draft / approved / locked), totals, generated_by, approved_by, locked_at |
| | payroll_items | payroll_run_id, employee_id, basic, allowances, overtime, additions, deductions, tax, net, tax_overridden, breakdown (json), payslip_path |
| | payroll_adjustments | payroll_item_id, label, type (addition / deduction), amount |
| Performance | kpis | name, designation_id (nullable), employee_id (nullable), weight |
| | review_cycles | name, period_start, period_end, status |
| | reviews | review_cycle_id, employee_id, reviewer_id, self_score, manager_score, final_score, status |
| | review_scores | review_id, kpi_id, self_rating, manager_rating, comment |
| Assets | asset_categories | name |
| | assets | name, asset_category_id, serial_no, purchase_date, cost, condition, status |
| | asset_assignments | asset_id, employee_id, assigned_at, returned_at, return_condition |
| Exit | resignations | employee_id, type (resignation / termination), submitted_on, last_working_day, reason, status, manager_approved_by, hr_approved_by |
| | clearances | resignation_id, item, cleared, cleared_by, cleared_at |
| | final_settlements | resignation_id, salary_due, leave_encashment, loan_recovery, other, net_amount, approved_by |

Money is stored as decimal(12,2). Records tied to payroll or attendance history are never hard-deleted; employees are deactivated, not removed.

## 12. Build phases

Each phase ends with a working release on the staging site.

| Phase | Contents | Gate before moving on |
|---|---|---|
| 0. Proof | Bare Laravel app on the HRM subdomain; `/iclock` endpoints that only log requests; point the MB20 at it for a short test (under an hour), then point it back at BioTime | Real punches from the MB20 are logged on the server. Decides which HTTP outcome in section 6 applies. |
| 1. Foundation | Starter kit, design system and shell, roles and permissions, users, departments, designations, settings, audit log, deploy script | Owner signs off the look against the reference |
| 2. Employees | Employee CRUD with all tabs, documents, import / export, org chart, My Profile | The real staff list is imported |
| 3. Attendance | Device settings, punch storage, day rebuild, shifts, daily and monthly screens, corrections, dashboard tiles | One full week of HRM attendance matches the machine's own log |
| 4. Leave | Types, requests, approvals, balances, holidays, calendar, emails | |
| 5. Payroll | Salary profiles, tax modes, loans, runs, payslips, bank sheet, reports | One month run in parallel and matched to the current manual payroll |
| 6. Performance, Assets, Exit | KPIs and reviews; asset register; resignation, clearance, settlement, letter | |
| Go live | HR training, BioTime retired, machine log kept as backup record | |

**Machine cutover (default, open item 14).** Only one server can receive punches, so the machine stays on BioTime until phase 3 is ready. It is then switched to the live HRM for good, and the phase 3 gate week runs on live data. Punches made during the switch are recovered with "Re-send punches". Staging never receives live punches; it is tested with copied or sample punches.

## 13. Risks

| Risk | Handling |
|---|---|
| Hostinger forces HTTPS and the MB20 only speaks HTTP | Phase 0 tests it before anything else is built; three outcomes in section 6 |
| MB20 firmware has no Cloud Server (ADMS) option | Phase 0 finds out; firmware update from the dealer, or the LAN forwarder fallback |
| Payroll mistakes | Rules covered by automated tests; parallel month in phase 5; locked runs are immutable |
| Punches arrive while the site is down | The machine keeps its own log and re-sends; duplicates are ignored; "Re-send punches" covers gaps |
| Salary data exposure | Server-side permission checks on every payroll route, tested per role; documents stored outside the web root |
| No queue worker on shared hosting | Queue drained by the scheduler each minute; bulk payslip emails sent in batches |

## 14. Open items for Lumino

None of these block phases 0 to 2. Each has a default the build will use until told otherwise; all are settings, not code.

| # | Question | Default used |
|---|---|---|
| 1 | Subdomain name for the HRM | `hrm.luminocrm.online` |
| 2 | Logo and brand colour | Text logo "Lumino HRM", accent `#10b77f` |
| 3 | Does the MB20 show COMM → Cloud Server Setting? | Assumed yes; checked in phase 0 |
| 4 | Office hours and grace | 09:00 to 18:00, 15 minutes |
| 5 | Weekly offs | Saturday and Sunday |
| 6 | Late rule | 3 lates = 1 day's deduction |
| 7 | Leave types and yearly quotas | Annual 14, Sick 8, Casual 10, Unpaid |
| 8 | Percentage tax on gross or basic | Gross taxable, selectable per employee |
| 9 | Overtime paid? | Off |
| 10 | Leave encashment at exit | Off |
| 11 | Import BioTime history? | No |
| 12 | Who are the first Admin and HR users? | One Admin account created at install |
| 13 | Pay cycle: is salary for a calendar month, paid after the month ends? Or a cutoff cycle (such as 26th to 25th), or paid before month end? | Calendar month, paid in the first days of the next month. **Must be confirmed before phase 5.** |
| 14 | Is BioTime used today for attendance or payroll, so the machine must stay on it until the HRM's attendance is ready? | Yes; switch for good at phase 3. **Must be confirmed before phase 0.** |

## 15. Left to the builder

- Folder structure, naming, and how React pages and components are organised.
- Exact package versions, pinned at project creation.
- Upload method for deploys and the staging arrangement, within section 6.
- Form layouts and empty states, following the patterns in section 7.
- Test structure, provided payroll and attendance rules are covered.
- The ADMS reply details (exact header lines and option names), verified against the real machine in phase 0.

## 16. Later versions (not v1)

Announcements, joining letter and NOC templates, sign-in history screen, pushing new users from the HRM to the machine, night shifts, asset depreciation, recruitment, training, statutory payroll automation, a mobile app.
