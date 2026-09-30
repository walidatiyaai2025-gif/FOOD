# FOODEX 1.0.38 Dashboard Recovery Notes

Status: dashboard-only recovery release for installations where FOODEX 1.0.36 was installed without running its database migrations.

## Required sequence

1. Install the migration-free FOODEX 1.0.37 bootstrap package.
2. Reload System Update. The updated screen no longer requires a migration checkbox.
3. Install the FOODEX 1.0.38 cumulative recovery package.

The 1.0.37 updater inspects the 1.0.38 ZIP itself. Because the cumulative package contains files under `backend/database/migrations/`, the updater automatically performs database preflight, creates a database backup, enters maintenance mode, extracts the release and runs `php artisan migrate --force`.

Laravel migration history makes the recovery idempotent: migrations already recorded as executed are skipped; only pending migrations run.

No server-side shell command is required for this recovery sequence.
