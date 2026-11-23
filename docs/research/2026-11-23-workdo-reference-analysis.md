# WorkDo HRM reference analysis

Date: 2026-11-23
Source: live public demo at `https://demo.workdo.io/hrm` (Company demo account), inspected in a browser: rendered pages, computed styles, and the route list the app ships to the browser.
Purpose: record what the reference product is and how it looks, so Lumino HRM can borrow the design without the bulk. Lumino HRM carries its own name, logo and code; nothing is copied from WorkDo's source.

Legend: **Observed** = seen directly in the demo. **Inferred** = concluded from what was seen. **Unknown** = not checked.

## 1. How the reference is built

| Item | Finding | Basis |
|---|---|---|
| Backend | Laravel | Observed: Ziggy route list, CSRF token, Debugbar routes |
| Frontend | Inertia.js + React, built with Vite | Observed: `data-page` Inertia payload, `build/assets/app-*.js` |
| UI kit | shadcn/ui on Tailwind CSS v4 | Observed: shadcn token names (`--sidebar`, `--card`, `--ring`, `--chart-1..5`) in oklch, `data-slot` / `data-sidebar` attributes |
| Icons | Outline icon set, Lucide style | Inferred from appearance and shadcn defaults |
| Size | 797 named routes, 24 top-level menu entries in 6 groups | Observed |
| Product type | Multi-tenant SaaS (plans, coupons, companies, 20+ payment gateways) | Observed in route list |

This matters because the existing Lumino doc chooses Blade + Alpine. The reference look comes from shadcn/ui, which is a React kit.

## 2. Design tokens (observed)

| Token | Value |
|---|---|
| Accent / primary | `#10b77f` (rgb 16, 183, 127), user-selectable theme colour |
| Font | Inter; base 16 px, controls and table text 14 px, weight 500 on buttons |
| Radius | `0.625rem` (10 px) base; buttons 8 px |
| Border | `oklch(92.2% 0 0)` light grey, 1 px |
| Background / card | white; muted surfaces `oklch(97% 0 0)` |
| Text | `oklch(14.5% 0 0)`; muted text `oklch(55.6% 0 0)` |
| Destructive | `oklch(57.7% .245 27.325)` |
| Shadow | very light, `0 1px 2px rgba(0,0,0,.05)` |
| Sidebar | 272 px expanded, collapses to an icon rail; white with grey border |
| Dark mode | full token set under `.dark` (background `oklch(14.5% 0 0)`) |

## 3. Layout patterns (observed)

- **Shell:** fixed left sidebar with logo, "Search menu" box, uppercase group labels, collapsible sub-menus. Top bar with sidebar toggle, breadcrumb, dark-mode toggle, language picker, avatar menu.
- **Page header:** title, one-line grey subtitle, actions on the right (Export, Import, primary green Add button).
- **Dashboard:** dark navy welcome banner ("Good afternoon, <name>", "N present today", shortcut icons); a row of six pastel stat tiles (icon in a rounded square, label, big number, small footnote); then two-column cards: Today's Birthdays, Today's Leave, Attendance last 7 days (stacked bars), Leave Overview (by status), recent lists, yearly trend charts, Asset Status.
- **List pages:** search box, filter dropdowns, "Filters" button, list/grid toggle; status tabs with counts (All / Active / Inactive / Probation / Terminated); table with avatar + name + email, code badge, status pill, icon actions; pagination with rows-per-page.
- **Attendance Records:** month grid, employees × days, symbol legend (Present, Absent, Half Day, On Leave, Holiday, Day Off, Future, plus Late / Early Departure / Overtime markers), totals column such as `17.5/21`.
- **Biometric Attendance:** week strip date picker, per-employee first clock-in, last clock-out and punch count ("2x", "4x").
- **Payroll Runs:** month strip selector, status tabs (Draft / Processing / Completed / Cancelled), table with frequency, pay period, pay date, employees, gross, net, status.
- **Settings:** left list of setting groups, right panel of cards, each with its own Save Changes button.

## 4. Module inventory vs Lumino v1

| WorkDo area | Sub-pages seen in routes | Lumino v1 |
|---|---|---|
| Dashboard, Calendar | dashboard, calendar | Keep |
| Employees, Org chart | employees (create, import, export), organization-chart | Keep |
| Organization structure | branches, departments, designations, document-types | Keep departments and designations; branches not needed for one office |
| Attendance | shifts, attendance-policies, attendance-records, attendance-regularizations, time-entries, biometric-attendance | Keep; see gaps below |
| Leave | leave-types, leave-policies, leave-applications, leave-balances, holidays | Keep |
| Payroll | salary-components, employee-salaries, payroll-runs, payslips | Keep, extended (tax modes, loans, late rule) |
| Performance | indicator-categories, indicators, goal-types, employee-goals, review-cycles, employee-reviews | Keep, simplified to KPIs + reviews |
| Assets | asset-types, assets, dashboard, depreciation report | Keep without depreciation |
| Employee lifecycle | awards, promotions, resignations, terminations, warnings, trips, complaints, transfers | Only resignation / exit in v1 |
| Users & roles | users, roles, permissions, login-history | Keep (four fixed roles) |
| Settings | system, brand, email, working days, IP restriction, ZKTeco, NOC / experience certificate / joining letter templates, storage | Keep a trimmed set |
| Recruitment | ~20 pages (jobs, candidates, interviews, offers, onboarding) | Out |
| Training | programs, sessions, assessments | Out |
| Meetings, Events, Zoom | 9 pages | Out |
| Documents & contracts | contract templates, HR documents, acknowledgments | Out (employee document uploads only) |
| Announcements | announcements + dashboard | Out for now; candidate for v1 (see below) |
| Media library, landing page, custom pages, newsletters | | Out |
| SaaS: plans, coupons, companies, referral, payment gateways | | Out (single company) |
| Slack, Telegram, Twilio, ChatGPT, ReCaptcha, cookie, SEO settings; 16 languages | | Out |

## 5. Gaps worth discussing

Present in WorkDo, missing from the Lumino doc, cheap and useful:

1. **Attendance regularization** – employee requests a correction (forgot to punch), manager / HR approves. The Lumino doc only has HR fixing it manually.
2. **Announcements** – one small table, shown on the dashboard.
3. **Letter templates** – joining letter and NOC alongside the experience letter already planned.
4. **Login history** – who signed in, when, from where.
5. **Early departure** as a tracked marker next to Late.

Present in the Lumino doc, absent from WorkDo (Lumino is already ahead here):

- Direct ADMS push from the MB20 with raw punch log, unknown-ID list and re-sync
- Per-employee tax modes with effective-date history
- Loans and advances with installments
- Late-arrival deduction rule
- Audit log
- Exit clearance blocked by unreturned assets, final settlement

## 6. Limits of this analysis

- Only the Company role was explored; HR and Employee role views are **unknown**.
- Create/edit forms, payslip PDF layout and the employee profile tabs were not opened in this pass.
- How WorkDo's ZKTeco integration talks to the device is **unknown** (settings fields not read).
- The REA toolkit's web tools need a Chrome DevTools endpoint, which was not set up; inspection used the app's browser pane instead. No WorkDo source code was downloaded or decompiled.
