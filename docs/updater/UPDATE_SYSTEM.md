# Update Center

FOODEX system updates are a privileged, backend-owned workflow. Only the dedicated `system.update` ability may start an update; the built-in `SUPER_ADMIN` role receives it through the canonical permission matrix.

The execution coordinator uses this mandatory sequence:

`Validate package -> Preflight -> Backup files -> Backup database when needed -> Maintenance mode -> Extract release -> Run migrations when declared -> Rebuild caches -> Health check -> Exit maintenance -> Record version/history`

The package SHA-256 is checked before any backup or mutation. The target version must be newer than the installed version and the current version must satisfy the package minimum.

If a stage fails after backups exist, the coordinator attempts file rollback, database rollback when a database backup exists, and maintenance-mode exit. The failure stored in `update_history` identifies the stage but does not persist raw exception details or credentials. Both successful and failed executions emit audit events.

The runtime adapter that performs production filesystem/database operations is intentionally separated from the coordinator so preflight, backup and rollback behavior can be tested independently and platform-specific backup mechanics can fail closed rather than bypassing safety.

## Incomplete rollback recovery (#128)

If either file or database restoration fails after maintenance begins, the updater
attempts the other restoration but keeps maintenance enabled. Failure history uses
a sanitized manual-recovery message; no successful target version is recorded.
Clients continue receiving maintenance responses until an operator restores a
consistent release and database. A successful rollback still exits maintenance.

Operators must restore and verify both backups, check database/storage health and
the deployed version, and inspect server logs before explicitly running
`php artisan up`. Do not disable maintenance just to clear the failed update screen.

Regression coverage in `UpdateManagerTest` injects file and database restore failures.
These unit-level runtime substitutes verify orchestration; #124 still requires real
PostgreSQL/Redis backup/restore and production-like deployment evidence.
