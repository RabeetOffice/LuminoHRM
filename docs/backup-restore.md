# Database backups and restore

## What is backed up

Every night at 02:00 (Asia/Karachi) the scheduler runs `php artisan hrm:backup-database`. It writes the whole
database as one SQL file, gzip-compressed, using PHP/PDO only (the host disables `mysqldump`).

- Tables are dumped with `DROP TABLE IF EXISTS`, `CREATE TABLE` and batched `INSERT`s, with foreign key checks
  switched off for the restore. The dump is taken in one consistent snapshot, so the tables agree with each other.
- Structure only, no rows, for the transient tables `cache`, `cache_locks`, `sessions`, `jobs`, `job_batches` and
  `failed_jobs` (queued jobs can hold encrypted password-reset tokens). The list is `hrm.backup.exclude_data`
  in `config/hrm.php`.
- 14 days are kept; older `lumino-hrm-YYYY-MM-DD.sql.gz` files are deleted after each successful run. Nothing
  else in the folder is ever touched. A second run on the same day replaces that day's file.
- The file contains every employee's data and the (encrypted) SMTP password. Treat it like the database itself:
  it is stored outside the web root and is readable by the account owner only. Do not email it or leave copies
  on shared computers.

## Where the files are

On the server: `<account>/domains/hrm.luminocrm.online/shared/storage/app/private/backups/`
(not inside `public_html`, so it cannot be downloaded through the website).
Locally (development): `storage/app/private/backups/`.

The result of the last run is in `shared/storage/app/private/backup-status/last-backup.json` and is shown as
`last_backup` / `last_backup_ok` in `deploy/hostinger/status.txt`. To run a backup by hand: `php artisan hrm:backup-database`.

## Download one

hPanel > Files > File Manager, open `domains/hrm.luminocrm.online/shared/storage/app/private/backups/`, select the
dated file, Download. Keep a copy somewhere safe off the server from time to time: a backup on the same host does
not help if the hosting account is lost.

## Restore

Restoring replaces the tables in the target database with the backup's. Restore into an empty or scratch database
first if you only want to look at the data.

**With phpMyAdmin** (hPanel > Databases > phpMyAdmin): select the database, Import, choose the `.sql.gz` file
as it is (phpMyAdmin accepts gzip directly), leave the character set as utf8mb4, Go. For a large file the upload
limit may be too small: unzip it and import in parts, or use the command line.

**With the mysql client:**

```
gunzip -c lumino-hrm-2026-11-24.sql.gz | mysql -u <user> -p <database>
```

(On Windows without gunzip: unzip the `.gz` with 7-Zip, then `mysql -u <user> -p <database> < lumino-hrm-2026-11-24.sql`.)

After the restore, sessions and queued jobs are empty: everyone has to sign in again and any unsent queued email is
gone. That is expected.

## The `APP_KEY` must be the same

Encrypted values in the database (the SMTP password in Settings > Email, two-factor secrets, queued payloads) can only
be read with the `APP_KEY` of the installation that wrote them. When restoring onto a new server or a fresh install,
use the same `APP_KEY` as the original `shared/.env` (keep a safe copy of it, separately from the backups). With a
different key the site works, but the stored SMTP password and two-factor secrets cannot be decrypted and must be entered again.
