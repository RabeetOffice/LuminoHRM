# Lumino HRM

An in-house HR system for Lumino Digital: organisation structure, users and roles, settings, an audit log and
(in later phases) employees, attendance from ZKTeco machines, leave, payroll, performance, assets and exit.
Laravel 13 with Inertia, React and TypeScript; Pest and Vitest for tests. It runs on shared hosting (Hostinger, no SSH).

## Local setup

Needs PHP 8.4 (with the sqlite and mysql PDO extensions), Composer, Node and MariaDB (XAMPP's is fine).

```
composer install
npm ci
cp .env.example .env          # then set DB_* for a local MariaDB database named lumino_hrm
php artisan key:generate
php artisan migrate
php artisan hrm:sync-access   # roles and permissions; creates the first Admin if HRM_ADMIN_EMAIL / HRM_ADMIN_PASSWORD are set
npm run build
php artisan serve
```

Run `npm run build` before `php artisan test`: the page tests read the Vite manifest. Tests use an in-memory SQLite
database, not MariaDB.

```
php artisan test              # Pest
npm test                      # Vitest
npm run types:check           # TypeScript
composer lint:check           # PHP style (Pint)
npm run check                 # front-end lint and format
```

`npm run dev` serves the front end with hot reload.

## Documentation

- `docs/deploy.md`: deploying to the host, the cron entry, scheduler, reading server state.
- `docs/backup-restore.md`: nightly database backups and how to restore one.
- `docs/audit-log.md`: what the audit log records and how to add entries.
- `docs/decisions/`: recorded decisions. `docs/superpowers/`: specs and plans.

Secrets never go in the repository: `.env` files are ignored and production settings live in `shared/.env` on the server.
