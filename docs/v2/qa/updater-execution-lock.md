# V2 #3291 — exclusive execution lock for Admin Update ZIP (P112 / G005)

## Source, ownership, and verified defect

Parent #2746 (signed Admin Update ZIP / Backup & Recovery), source umbrella #2846 P112, master #2503. Integration **Foodex_V2 ONLY**. This is one atomic concurrency safety improvement, not full signed ZIP certification.

Previously `UpdateManager::execute` created `UpdateHistory` and proceeded to validate/backup/apply/migrate/rollback with no mutually exclusive updater lock. A second Super Admin POST could interleave backup, maintenance, patch activation, migration, health-check and rollback, producing ambiguous recovery status and potentially corrupting a hosted installation.

## Behavior and trust boundary

- **Reuse** the existing Settings → System → System Updates screen and `UpdateManager` pipeline; no new route, Sidebar node, view, form, translation key or permission grant. Existing server-side `system.update` authorization is unchanged.
- **Exclusive lock** is acquired before writing update history or calling validator/runtime. The process holds a non-blocking `flock(LOCK_EX|LOCK_NB)` through the complete success path and all failure/rollback stages. A concurrent attempt receives a generic `Another system update is already running.` failure before any database, backup or application change.
- **Path**: private runtime storage file `storage/app/private/updates/execution.lock`, with test-only configurable `foodex.updater_execution_lock`. Final/ancestor symbolic links are rejected before opening; linked external targets are not intentionally read or written. File is restricted to `0600`; directory created with `0750`. Locks are always released by `finally`, including failures in validation, backup, health-check or rollback.
- **Scope**: local filesystem advisory lock works across concurrent PHP workers sharing this filesystem (cPanel, single-node shared hosting, one VPS). **A node-local file lock alone does not certify multi-node installations using separate storage volumes.** Such deployments require shared locking storage or a separately approved distributed lock.
- **Recovery**: the lock file remains after execution, but its OS advisory lock is released; it is not a persistent "blocked" flag requiring manual deletion. No automatic rollback is assumed safe for irreversible migrations; preexisting recovery handling remains unchanged.

## Test and app impact matrix

Dedicated `V2UpdaterExecutionLockTest` verifies (1) two distinct descriptors contend and the blocked request creates no `UpdateHistory`/version or runtime action, then a safe sequential call succeeds; (2) a failing preflight path releases its lock, permitting retry; (3) linked lock-file target remains unchanged and no history is persisted; (4) linked parent directory cannot redirect lock creation. All tests use synthetic ZIP bytes and a mocked runtime, not live credentials or a hosted update.

- Laravel Backend: **affected** — `UpdateManager` execution serialization and isolated Feature tests.
- Dashboard: existing authorized System Updates page only; no AR/EN, RTL/LTR, layout or navigation changes.
- Customer: **not affected** — no customer API/auth/product/order or Flutter source changes.
- Driver: **not affected** — no B2C delivery, wallet, assignment or Flutter source changes.
- Van: **not affected** — no B2B Van routing/visits/collection or Flutter source changes.

Run `cd backend && php artisan test --filter=V2UpdaterExecutionLockTest`, followed by syntax/Pint/PHPStan/Composer security and full Laravel tests. Exact-final-head `Repository Policy`, `FOODEX V2 Isolation Gate` and terminal `FOODEX Required CI Gate` must pass; real MySQL/Redis installation/upgrade/recovery gates remain required.

## Residual parent gate

This issue does not create a signed `PROJECT-Update-vX.Y.Z.zip`, a trusted signature root, full rollback certification or a production release. The parent #2746, source #2846, and master #2503 remain open. No `main` integration, tag, live cPanel operation, staging promotion or production deployment is authorized.
