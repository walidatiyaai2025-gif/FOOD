# Update Center

FOODEX system updates are a privileged, backend-owned workflow. Only the dedicated `system.update` ability may start an update; the built-in `SUPER_ADMIN` role receives it through the canonical permission matrix.

The execution coordinator uses this mandatory sequence:

`Validate package -> Preflight -> Backup files -> Backup database when needed -> Maintenance mode -> Extract release -> Run migrations when declared -> Rebuild caches -> Health check -> Exit maintenance -> Record version/history`

The package SHA-256 is checked before any backup or mutation. The target version must be newer than the installed version and the current version must satisfy the package minimum.

If a stage fails after backups exist, the coordinator attempts file rollback, database rollback when a database backup exists, and maintenance-mode exit. The failure stored in `update_history` identifies the stage but does not persist raw exception details or credentials. Both successful and failed executions emit audit events.

The runtime adapter that performs production filesystem/database operations is intentionally separated from the coordinator so preflight, backup and rollback behavior can be tested independently and platform-specific backup mechanics can fail closed rather than bypassing safety.
