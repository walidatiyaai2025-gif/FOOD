# FOODEX 1.0.38 Dashboard Recovery Notes

Status: cumulative dashboard recovery release for installations where FOODEX 1.0.36 files were installed while database migrations remained pending.

## Required sequence

1. Install the migration-free FOODEX 1.0.37 bootstrap package.
2. Reload System Update so the updater with automatic ZIP migration detection is active.
3. Install the cumulative FOODEX 1.0.38 Dashboard Update package.

The updater inspects the ZIP contents. When migration files are present under `backend/database/migrations/`, it automatically runs database preflight, creates the configured database backup, enters maintenance mode, extracts the package, and executes pending Laravel migrations with `php artisan migrate --force`.

Laravel migration history keeps the recovery idempotent: already-recorded migrations are skipped and only pending migrations execute.

No manual migration checkbox and no server-side shell command are required for this recovery sequence.

## Release identity

Dashboard `VERSION` is 1.0.38. Customer mobile identity is synchronized to 1.0.38+38 in this reconciliation slice. Driver synchronization is deliberately deferred until the active #522 Driver tracking branch merges, so #481 does not edit a file owned by another worker.
