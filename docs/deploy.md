# Deploying Lumino HRM to Hostinger

Site: `https://hrm.luminocrm.online` (Hostinger shared hosting, LiteSpeed, PHP 8.4, account `u882878513`).

There is no SSH and no Node on the server. The routine is therefore:

1. build everything locally into a clean directory (`scripts/deploy.ps1`),
2. upload that directory into the site's document root (the uploader tool),
3. let one cron entry finish the job on the server (caches, scheduler; on a fresh install also migrate),
4. on an installed system, an Admin applies the database changes with **Update system** (see "Updates").

Nothing secret is ever in the repository or in the uploaded directory.

## GitHub auto-deploy (every push to main)

`.github/workflows/deploy.yml` does steps 1 and 2 on GitHub for every push to `main` (pushes that
only change `docs/` or `*.md` files are skipped). Step 3 is unchanged: cron.sh on the server.

1. Installs the dependencies, builds the front end, then runs `vp check`, `tsc`, Vitest, Pint,
   PHPStan and the Pest suite. Any failure stops the run; nothing is uploaded.
2. `scripts/deploy-stage.sh` (the Linux twin of `deploy.ps1`) builds the same release directory
   with `release.txt` and `release.manifest`. It never stages an env seed: secrets do not go
   through GitHub.
3. Installs the Hostinger CLI and runs `hostinger up --dry-run`. `scripts/check-upload-plan.py`
   fails the run if the plan would skip a file the release needs, or treat the site as Node.js.
4. `hostinger up --domain hrm.luminocrm.online --yes` replaces the document root.
5. Waits up to five minutes for the new build's script and `/login` to return 200.

**One-time setup (owner):**

1. hPanel → API (`https://hpanel.hostinger.com/api`) of the account that hosts the site
   (`u882878513`): create an API token.
2. GitHub → the LuminoHRM repository → Settings → Environments → New environment `production` →
   Add environment secret `HOSTINGER_API_TOKEN` = that token.
3. Optional: under the same environment, "Required reviewers" makes every deploy wait for your
   click. Optional repository variable `HOSTINGER_CLI_VERSION` pins the CLI version (default: latest).
4. Actions → deploy → Run workflow with "Only show what would be uploaded" ticked: a dry run that
   uploads nothing. Check that it is green before relying on pushes.

Progress and results: GitHub → Actions → deploy. After a run, `deploy/hostinger/status.txt` on the
server shows `state=ok` once cron.sh has finished the post-deploy step, and `pending_migrations=<n>` the database
changes waiting for an Admin on System > Updates. `scripts\deploy.ps1` plus a manual upload still
works, and is the only way to stage an env seed (`-SeedEnvFile`).

## Prerequisites (local machine)

- PHP 8.4 at `C:\php84\php.exe` (the `php` on PATH is a different version and is not used).
- Composer (`C:\ProgramData\ComposerSetup\bin\composer.phar`, or set `COMPOSER_PHAR`).
- Node + npm (for `npm run build`).
- A clean git working tree: commit first, so the release id matches a commit.

## Layout on the server

```
/home/u882878513/domains/hrm.luminocrm.online/
    public_html/     <- document root. Replaced on every deploy. Contents of build/deploy.
    shared/          <- survives deploys. Created by cron.sh. NOT uploaded.
        .lumino-hrm      empty marker file; cron.sh creates it on every run (see below)
        .env             production environment (secrets live only here)
        storage/         logs, cache, sessions, compiled views, app files
        release.done     id of the last release that finished its post-deploy step
```

The document root cannot point at `public/`, so the root `.htaccess`
serves the app from `public/`, denies everything else (dotfiles, `app/`, `vendor/`,
`storage/`, `deploy/`, ...) and handles HTTP to HTTPS (see below).
Because of the deny list, no application URL may begin with `/app`, `/config`,
`/database`, `/deploy`, `/docs`, `/resources`, `/routes`, `/scripts`, `/storage`,
`/tests`, `/vendor` or `/bootstrap`.

When `<app root>/../shared` exists AND contains the marker file `.lumino-hrm` (or, for the server that
was deployed before the marker existed, `release.done`), `bootstrap/app.php` and `public/index.php` load `.env`
from `shared/.env` and use `shared/storage` as the storage path. A bare `shared` folder without either file is
ignored, so another project's sibling folder of that name cannot hijack a developer PC. Without the marker
(local development, tests) nothing changes. `artisan key:generate`, `down`/`up`
and everything else follow the same paths.

## HTTPS

The hosting-level HTTPS redirect is OFF on purpose: the attendance machine posts to
`/iclock/...` over plain HTTP. The root `.htaccess` redirects every other path to
HTTPS (301) and skips `/iclock`, and also skips `localhost`, `127.0.0.1`, `[::1]`
and `*.test`, so local development is unaffected. Laravel does not force the
scheme (no `URL::forceScheme`), so `/iclock` keeps working over HTTP. Session
cookies are marked secure (`SESSION_SECURE_COOKIE=true` in the template); `/iclock`
uses no cookies.

The root `.htaccess` also sets (through `mod_headers`, guarded by `<IfModule>`) `X-Content-Type-Options: nosniff`,
`X-Frame-Options: DENY`, `Content-Security-Policy: frame-ancestors 'none'` and
`Referrer-Policy: strict-origin-when-cross-origin`, plus `Strict-Transport-Security: max-age=31536000` on HTTPS
responses only (never on plain-HTTP `/iclock`). There is deliberately no script/style CSP yet (inline styles carry the
accent colour). A direct request for `/public/...` returns 404; the app is served from `/`.

`/iclock` also protects itself against junk: a request without a registered serial number is logged with at most
the first 1 KB of its body, at most 60 such rows per address per hour (then one marker row, then nothing until the
hour is over). The reply to the caller never changes. Registered machines are never limited.

## Routine deploy

1. Commit your work on the branch to be released.
2. Run, from the repository root:

    ```powershell
    powershell -ExecutionPolicy Bypass -File scripts\deploy.ps1
    ```

    It refuses to run with uncommitted changes (`-AllowDirty` overrides, for
    experiments only). It runs the test suite (stops on failure), `npm run build`,
    copies the runtime files into `build\deploy\`, runs
    `composer install --no-dev --optimize-autoloader` there, removes local-only
    files, writes `deploy\hostinger\release.txt` (`<git sha>-<UTC timestamp>`), and
    prints the staging path and file count. It does not upload.

3. Upload the **contents** of `build\deploy\` into
   `/home/u882878513/domains/hrm.luminocrm.online/public_html` with the uploader
   (it replaces the document root; `shared/` is outside it and untouched).
   What is in there: `app bootstrap config database public resources/views routes deploy
   storage(skeleton) vendor artisan composer.json composer.lock .htaccess`.
   Not in there: `.env*`, `node_modules`, `package.json`, `tests`, `docs`,
   `scripts`, `resources/js`, `resources/css`, dev dependencies.
4. Wait a couple of minutes. The cron job notices the new `release.txt`, but only acts once the upload is
   complete: `deploy/hostinger/release.manifest` (written by `deploy.ps1`) must name the same release id and
   every file listed in it (all staged files) must exist, on two consecutive cron runs (state in
   `shared/release.pending`; file timestamps are not used). Until then `cron.log` says
   `upload still in progress: <reason>` (for example `3 of 8331 files missing, first: ...`) and nothing runs,
   including the scheduler. Then it runs `config:clear` and `hrm:install-state`, and
    - on an **installed** system (the normal case): `config:cache`, `route:cache`, `view:cache`. It does **not**
      run `migrate` or `hrm:sync-access`: the new code is live, its database changes wait for an Admin
      (see "Updates" below);
    - on a **fresh** install only (no migration has run yet, or no account exists yet): `migrate --force`,
      `hrm:sync-access`, then the three cache commands (see "First deploy").

    Only then it records the release in `shared/release.done`. On failure it logs the command output and retries
    every minute.

5. If `status.txt` shows `pending_migrations` above 0, sign in as an Admin, open **System > Updates** and click
   **Update system**. The scheduler keeps running while changes wait.

### The cron entry

One entry, every minute (set once, in hPanel > Advanced > Cron Jobs):

```
* * * * * sh /home/u882878513/domains/hrm.luminocrm.online/public_html/deploy/hostinger/cron.sh
```

`cron.sh` is idempotent and takes a lock, so runs never overlap. It uses
`/opt/alt/php84/usr/bin/php` (override with `PHP_BIN`). Each run: creates the
`shared/` layout (and the empty `shared/.lumino-hrm` marker) if missing; does nothing but log "waiting for shared/.env" until
`shared/.env` exists; generates `APP_KEY` if it is empty; runs the post-deploy
step when `release.txt` differs from `shared/release.done` (or when
`shared/.env` is newer than `release.done`, because config is cached); then runs
`artisan schedule:run`, then `artisan hrm:pending-migrations` (the count goes to `status.txt`). The scheduler is
skipped while the upload is incomplete, while `hrm:install-state` fails (database unreachable), or on a fresh
install while `migrate` or `hrm:sync-access` is failing; waiting database changes on an installed system never stop
it; if only a cache command (`config:cache`, `route:cache`, `view:cache`) fails, it still
runs (so the queue drain and backups continue), the release stays pending, the cache command is retried every
minute and `status.txt` reports `post_deploy_failed` with that command. Output goes
to `shared/storage/logs/cron.log`, which is rotated at about 1 MB (one `.1` backup).

### What the scheduler runs (`routes/console.php`)

`schedule:run` is the only thing cron calls; the application decides what is due:

| What                                                   | When                                | Notes                                                                                                                                                         |
| ------------------------------------------------------ | ----------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `queue:work --stop-when-empty --max-time=50 --tries=3` | every minute                        | Sends queued mail (set-password emails). `withoutOverlapping` with a 10 minute expiry. A fresh process each minute, so current Settings > Email values apply. |
| `hrm:backup-database`                                  | 02:00 `APP_TIMEZONE` (Asia/Karachi) | PDO-only SQL dump to `shared/storage/app/private/backups/lumino-hrm-YYYY-MM-DD.sql.gz`, 14 days kept. See [backup-restore.md](backup-restore.md).             |
| `hrm:backup-database --if-missing`                     | hourly at :20                       | Catch-up, see below: does nothing unless it is 02:00 or later and today has no good backup yet.                                                               |
| `queue:prune-failed --hours=168`                       | 03:30 daily                         | Drops failed jobs (encrypted payloads can hold reset tokens) after a week.                                                                                    |
| `queue:prune-batches`                                  | 03:35 daily                         | Only when the `job_batches` table exists.                                                                                                                     |

`activitylog:clean` is deliberately not scheduled: the audit log is permanent.
`schedule:run` runs these in the foreground, so a long queue run (up to about 50 s) keeps `cron.sh`'s lock for that
time; the next minute's cron then exits at once and picks up again after it.

**Lock interaction and the catch-up.** A schedule entry at 02:00 only fires if `schedule:run` is actually invoked
in the 02:00 minute. If a 01:59 run is still draining the queue, the 02:00 cron exits on the lock and that minute is
lost (there is no catch-up in Laravel itself). So `hrm:backup-database --if-missing` also runs hourly at :20: it
exits at once, writing nothing, unless it is 02:00 or later (`APP_TIMEZONE`) and there is no good backup file for
today's date (a file that does not read back to its completion marker counts as missing). Worst case a night's
backup is made at 02:20 instead of 02:00. A backup is only given its final name after it was read back and checked.
**Check that `last_backup` in `deploy/hostinger/status.txt` is from last night** (it is the time of the last
SUCCESSFUL backup; `last_backup_ok=no` means the latest attempt failed, with the last good one kept). The owner or
whoever deploys should look at it after each deploy and now and then; nothing else will tell you a backup stopped.

### Real client address behind the CDN

The host sits behind a CDN (`Server: hcdn`), so the connecting address Laravel sees may be the CDN's, and the
audit log and `device_logs` would record that instead of the visitor or machine. Set `HRM_TRUSTED_PROXIES` in
`shared/.env` (via the seed) to a comma-separated list of the CDN's addresses or CIDR ranges. Only requests
whose connecting address is on that list have `X-Forwarded-For` and `X-Forwarded-Proto` believed. The default is
empty (trust nobody; `request()->ip()` is the connecting address). A `*` (or `**`, `REMOTE_ADDR`, `0.0.0.0/0`, `::/0`) is ignored: trusting everyone would let any visitor forge
the address recorded in the audit log. Find the right addresses by comparing the audit log IP of your own
sign-in with your real address; if they differ, the logged address is the CDN's. `/iclock` goes through the same setting: with a proxy configured it also sees the forwarded address, with none
it sees the direct peer; the route works either way. Note: behind the CDN with no trusted proxy, all visitors share
one address, so per-address limits (the failed sign-in cap and the one lockout entry per address per 10 minutes)
act globally until `HRM_TRUSTED_PROXIES` is set.

## First deploy (differs from routine ones)

1. Create the cron entry above (it can exist before the first upload; it does
   nothing until the files and `.env` are there).
2. Run `scripts\deploy.ps1` and upload `build\deploy\` as above.
3. Within a minute `cron.sh` creates `shared/storage/...` and logs
   "waiting for shared/.env".
4. Create the production env file (there is no way to write `shared/` directly, so it travels as a seed):
   copy `deploy/hostinger/env.production.example` to a file OUTSIDE the repository (for example
   `C:\Users\<you>\lumino-hrm-prod.env`), replace `__DB_PASSWORD__`, leave `APP_KEY=` empty, set
   `HRM_DEVICE_SERIALS` when a machine serial is known, then deploy with
   `scripts\deploy.ps1 -SeedEnvFile C:\Users\<you>\lumino-hrm-prod.env` and upload. The script copies it to
   `deploy/hostinger/env.seed` in the staging directory (never printed, not in `release.manifest`, refused if
   it sits inside the git tree without being git-ignored). On its next run `cron.sh` installs it as
   `shared/.env` (mode 600, atomically), deletes the seed and logs `installed shared/.env from seed`. The
   password is never in git. `deploy.ps1` appends a last line `# seed-end <release id>` to the staged copy only;
   `cron.sh` installs a seed only if that marker (same id as `release.txt`) is its last line and it has a
   non-empty `DB_PASSWORD` (not the `__DB_PASSWORD__` placeholder). A truncated or foreign seed is left in place,
   logged as `seed incomplete or invalid, waiting` (also in `status.txt` `detail`), and never replaces a good
   `shared/.env`. **The staging directory then contains a secret: delete it after uploading with
   `scripts\deploy.ps1 -Clean`** (it only removes `build\`; the script prints a reminder whenever a seed is
   staged). The seed is under `deploy/`, which `.htaccess` denies over HTTP; it is also
   gone from the server within a minute of arriving.
5. Within a minute `cron.sh` generates `APP_KEY` into `shared/.env`, runs the
   post-deploy step and records the release. `hrm:install-state` answers `fresh` (no migration has run, or no
   account exists yet), so this one time `cron.sh` runs `migrate --force` itself (the tables are created) and
   `hrm:sync-access` (roles, permissions and the first Admin). From then on the system is `installed` and
   deploys never change the database by themselves.
6. Check the deploy (below).

## Changing a production setting

`shared/.env` can only be changed through the seed. Edit your local env file (outside the repo), run
`scripts\deploy.ps1 -SeedEnvFile <that file>` and upload. `cron.sh` replaces `shared/.env`; if the seed's
`APP_KEY` is empty the existing key is kept (a non-empty `APP_KEY` replaces it, which logs everyone out
and invalidates encrypted data, so normally leave it empty). The config cache is rebuilt in the same run; on an
installed system that rebuild never migrates (database changes still wait for Update system).
A seed is only staged when `-SeedEnvFile` is passed, so routine deploys never touch `shared/.env`
(note that a deploy replaces the document root, and with it any seed from an earlier upload).

## Creating the first Admin

Production runs no seeder and has no shell, so the first Admin is created by the post-deploy step of a fresh
install (`artisan hrm:sync-access`, which runs right after `migrate --force` and before `config:cache`; a fresh
install is one with no migrations or no account yet, so it also covers a first seed that arrives after the
tables were made):

1. Put `HRM_ADMIN_EMAIL` and `HRM_ADMIN_PASSWORD` in your local env file (outside the repo) and deploy it with
   `scripts\deploy.ps1 -SeedEnvFile <that file>`; upload.
2. Within a minute read `deploy/hostinger/status.txt`: `access=access_sync roles=4 permissions=25 admin=created`
   means the Admin exists. Other values: `admin=exists` (an admin already exists), `skipped_no_env`,
   `skipped_invalid_email`, `skipped_email_in_use` (the email belongs to a non-admin account, which is never
   taken over; use another email). The line never contains the email or password.
3. Sign in with that email and password. The account is marked "must change password": every page leads to
   **Welcome — choose your own password** until a new password is set (the change is audited, other sessions
   are signed out). Then deploy again with a seed that no longer has `HRM_ADMIN_PASSWORD`.

The same command also repairs roles and permissions if they ever drift from `config/hrm.php`; on an installed
system "Update system" runs it after the database changes.

## Updates (installed system)

New code goes live on every push, but database changes wait for an Admin (`settings.manage`):

1. When code with waiting database changes (or a new version at the top of `resources/changelog.php`) is on the
   server, Admins see a **New** badge on **System > Updates** in the menu.
2. The screen shows the version that is ready, the list of waiting database changes, "What's new" for every
   version (New / Improved / Fixed / Removed, with "Waiting to be applied" and "Installed" marks) and the update
   history (last 8, each with its step-by-step details).
3. **Update system** (asks for the Admin's password when the database will change) runs, inside the request:
   a backup with `hrm:backup-database` (if it fails nothing is changed, and "Update without a backup" is offered,
   again behind the password); `migrate --force`; `hrm:sync-access`; clearing the config, route, view and event
   caches and rebuilding the config and route caches when they were built; and records the version. Every step is
   logged in the `deployments` table and the result is in the audit log (area "System").
4. `status.txt` shows `pending_migrations=0` within a minute.

Every change people can see adds a line to `resources/changelog.php` (raise the last number for fixes, the middle
one for features). `artisan hrm:pending-migrations` prints the waiting count; `artisan hrm:install-state` prints
`fresh` or `installed`.

## Reading the server state

`shared/` is outside the document root, so `cron.sh` publishes two plain-text files for the hosting
file API (denied over HTTP, rewritten every minute, removed by the next deploy until cron runs again):

- `deploy/hostinger/status.txt`: `time`, `release`, `release_done`, `state`
  (`waiting_for_env`, `upload_in_progress`, `post_deploy_failed`, `ok`), `detail`, `env_present`,
  `app_key_set`, `php`, `proc_open`, `last_migrate` (the last time `cron.sh` itself migrated: fresh installs only),
  `access` (result line of the last `hrm:sync-access` run by `cron.sh`), `pending_migrations` (database changes
  waiting for Update system; empty when they could not be counted),
  `last_backup` (time of the last SUCCESSFUL backup, empty if none yet) and `last_backup_ok` (result of the latest attempt: `yes`, `no` or `unknown`). No secrets and no command output.
- `deploy/hostinger/status.log`: the last 40 lines `cron.sh` wrote itself (raw artisan output is not copied;
  for that, `shared/storage/logs/cron.log` is the full log).

## Check that a deploy worked

- `https://hrm.luminocrm.online/login` shows the login page.
- `https://hrm.luminocrm.online/.env`, `/vendor/autoload.php`, `/storage/logs/laravel.log`
  and `/deploy/hostinger/cron.sh` return 403 (or 404).
- `http://hrm.luminocrm.online/login` answers `301` to `https://`.
- `curl -i "http://hrm.luminocrm.online/iclock/cdata?SN=<serial>&options=all"`
  answers `200` with the option list and **no redirect** (the serial must be listed in
  `HRM_DEVICE_SERIALS`).
- `shared/release.done` holds the same id as `public_html/deploy/hostinger/release.txt`.
- Last lines of `shared/storage/logs/cron.log` read `release <id> done`. A failing step
  is logged as `FAILED: artisan <command>` followed by its output.

## Roll back

There are no server-side releases to switch between. To roll back, check out the
previous good commit, run `scripts\deploy.ps1` again and upload. The new
`release.txt` triggers the post-deploy step again (which never migrates an installed system). Migrations are not
reversed automatically: if Update system ran a bad migration, restore the database from the backup it took first
(`shared/storage/app/private/backups/`, see `docs/backup-restore.md`) or write a new forward migration.

## Troubleshooting

- 500 error right after upload: usually `shared/.env` is missing or `APP_KEY` is empty
  (check `cron.log`), or `bootstrap/cache` is not writable.
- "waiting for shared/.env" never goes away: the file must be at
  `domains/hrm.luminocrm.online/shared/.env`, next to (not inside) `public_html`.
- A command keeps failing in `cron.log`: fix the cause (for example a wrong DB
  password in `shared/.env`); the next minute retries from the start.
- The uploader may skip or flag `.htaccess`/dotfiles: confirm `public_html/.htaccess`
  exists after upload, otherwise the app, the deny rules and the HTTPS redirect are
  not active.
- `upload still in progress: ...` that never clears: the log names the missing files (first three) or the
  manifest/release mismatch. Re-upload them. If the uploader refuses a file for good, exclude it in the manifest
  section (7b) of `scripts/deploy.ps1` and redeploy; a release is never forced past the check. The check
  applies only to a new release: an already completed release keeps running the scheduler.
