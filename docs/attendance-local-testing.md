# Trying attendance on localhost (without the machine)

The real MB20 stays on BioTime until the switch-over. To see attendance working before then, the punch simulator makes
up a realistic day of punches and sends them through the same `/iclock/cdata` endpoint the machine uses, inside the
app (routing, device check, punch storage, `device_logs`). Nothing leaves your computer.

Use `C:\php84\php.exe artisan …` for every command below (the `php` on PATH is an older version). Where the commands
print a hint such as `php artisan hrm:attendance-rebuild`, read `php` as your PHP 8.4 binary (`C:\php84\php.exe` here).

## Safety

- `hrm:simulate-punches` refuses to run unless the configured `APP_ENV` (the `.env` file) is `local` or `testing`.
  Passing `--env=local` on the command line does not get round it: on a server with `APP_ENV=production` it stops with
  an error and sends nothing.
- It only touches the employees you choose with `--employees` (all working employees by default).
- It sends as its own device, "Local simulator (not a real machine)" (serial `LOCAL-SIMULATOR`), created on first use.
  It uses a real device only when you name it with `--serial`.
- It never changes the stamp a real machine resumes from (`devices.last_stamp`).
- Simulated punches are ordinary rows in `device_punches`. Use a local database you do not mind filling, not a copy you
  want to keep clean.

## 1. Set the attendance start date

Settings > Attendance > Attendance rules > **Attendance starts on**. Pick a date on or before the first day you will
simulate (for example the first of this month) and save.

While this is empty, attendance "has not started": days with punches are still worked out, but nobody is ever marked
Absent and no weekend or holiday rows are created. That is the right setting until the machine is switched over, and
the reason absences would not appear in a local test.

## 2. Give employees a machine user ID

Each punch carries the machine's user ID (PIN), and an employee is matched by the **Machine user ID** on their record.
Either fill it in on the employee form, or let the simulator hand out numbers to people who have none:

```
C:\php84\php.exe artisan hrm:simulate-punches --assign-ids --date=2026-11-23
```

Note that this also simulates that day: it hands out the IDs and then sends punches for 2026-11-23 straight away (step
3 below), so you do not need to run step 3 again for the same date.

## 3. Simulate a day

```
C:\php84\php.exe artisan hrm:simulate-punches --date=2026-11-23
```

Options:

| Option | Default | Meaning |
|---|---|---|
| `--date=` | the current attendance day | The day to simulate (`Y-m-d`). Never a future day. On today only punches up to now are sent. |
| `--employees=` | `all` | `all`, or a comma-separated list of employee ids or codes, e.g. `--employees=LD-0001,LD-0004` |
| `--late=` | `10` | Percent of people who arrive after the grace period (1 to 75 minutes past it) |
| `--absent=` | `5` | Percent of people who do not come |
| `--missing-out=` | `3` | Percent of people who forget to punch out |
| `--serial=` | `LOCAL-SIMULATOR` | Send as this active device instead. Without it the simulator always uses its own device "Local simulator (not a real machine)" (serial `LOCAL-SIMULATOR`), created on first use; it never borrows a real machine. |
| `--assign-ids` | off | Give the next free machine user ID to chosen employees who have none |
| `--seed=` | from the date | The same seed gives the same punches |

Times follow each person's shift: arrival from 20 minutes early to the end of the grace period (or late, as above),
departure from 10 minutes before the shift end to 45 minutes after it. Nobody punches on their weekly off day or on a
holiday. The command prints each person's outcome and the endpoint's reply (`OK: n`).

The punches are visible at once in Attendance > Raw Punches, and the request in Settings > ZKTeco Device > Sync log.

## 4. Work the punches out

Receiving a punch only marks the day for a rebuild. On a server the scheduler does the rest every minute; locally run:

```
C:\php84\php.exe artisan hrm:attendance-rebuild
```

Days with punches now show Present, Late or Half Day in Attendance > Daily and Monthly.

## 5. Close the day (absences)

Absences are recorded only when a past day is closed. On a server this happens every night. Locally:

```
C:\php84\php.exe artisan hrm:attendance-close 2026-11-23
```

Without a date it closes the previous 7 days. People without punches on a working day are now Absent, weekly offs
show as Weekend, holidays as Holiday, and single punches are flagged for attention.

## Example: a week

```
C:\php84\php.exe artisan hrm:simulate-punches --date=2026-11-16 --assign-ids
C:\php84\php.exe artisan hrm:simulate-punches --date=2026-11-17 --late=25
C:\php84\php.exe artisan hrm:simulate-punches --date=2026-11-18 --absent=15
C:\php84\php.exe artisan hrm:simulate-punches --date=2026-11-19 --missing-out=20
C:\php84\php.exe artisan hrm:simulate-punches --date=2026-11-20
C:\php84\php.exe artisan hrm:attendance-rebuild
C:\php84\php.exe artisan hrm:attendance-close 2026-11-16
C:\php84\php.exe artisan hrm:attendance-close 2026-11-17
C:\php84\php.exe artisan hrm:attendance-close 2026-11-18
C:\php84\php.exe artisan hrm:attendance-close 2026-11-19
C:\php84\php.exe artisan hrm:attendance-close 2026-11-20
```

Close each simulated day by date: without a date the command closes only the 7 days before today, which may not be
the week you simulated. Shifts cannot span midnight in this version, so every punch falls on the simulated day.

Running the same day twice with the same seed sends the same punches again; they are stored once (duplicates are
acknowledged, not added).

## Trying the device screens

- Settings > ZKTeco Device lists the simulator device. It shows **Online** for 5 minutes after a simulation, then
  **Offline**.
- **Re-send punches** queues a command; it stays "Waiting for the machine" because the simulator does not poll for
  commands. Only a real machine (or a test server, below) picks it up. The same range cannot be queued twice while it
  is waiting, and a command with no reply after 7 days is marked Failed by the nightly `hrm:prune-device-logs`.
- The **Sync log** hides the machine's routine command checks (about every 10 seconds); tick **Show polls** to see
  them. It is paged, 25 entries at a time, over the last 7 days, and can be filtered by type.
- A device whose serial is listed in `HRM_DEVICE_SERIALS` cannot be removed (the next check-in would create it again);
  deactivate it, or take it out of the environment first.
- Unknown user IDs: simulate with an ID no employee has, for example by clearing an employee's machine user ID after a
  simulation, then assign it again from Attendance > Raw Punches. Only working employees are offered; the dialog says
  how many punches will be linked before you confirm, and assigning never creates or changes a login.

## Later: pointing the real MB20 at a test server

Do this only on a test copy of the app that the owner has agreed to, never on the live site while BioTime is still in
use. It changes where the machine sends punches; BioTime stops receiving them until the settings are put back.

1. Write down the machine's current Cloud Server settings (Menu > COMM. > Cloud Server Setting): server address, port,
   "Enable Domain Name", proxy. You need them to put BioTime back.
2. Read the serial number (Menu > System Info > Device Info) and add the device on the test server in Settings >
   ZKTeco Device (or list it in `HRM_DEVICE_SERIALS` so it is created on first contact).
3. On the machine set the server address to the test server's host name, Enable Domain Name on, port 80, proxy off.
   The machine speaks plain HTTP: the test server must answer `/iclock/...` over HTTP without redirecting to HTTPS
   (see `docs/decisions/2026-11-phase-0-machine-link.md`).
4. Within a minute Settings > ZKTeco Device should show the machine Online, with a Handshake row in the sync log (tick
   Show polls to see its Command poll rows). Punch once: an Upload row appears and the punch is in Raw Punches.
5. To pull older punches, use **Re-send punches** for a date range (at most 31 days at a time). The machine runs it at
   its next check-in; punches already stored are not duplicated.
6. When finished, put the settings from step 1 back so BioTime receives punches again.
