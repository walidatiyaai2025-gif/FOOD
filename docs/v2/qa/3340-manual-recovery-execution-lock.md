# V2 #3340 — Manual database recovery shares ZIP updater execution lock

**Source:** P112 / G005, parent #2746 and #2846, master #2503. **Isolated target:** `Foodex_V2` only. The previous updater-exclusive lock was implemented and merged in #3291 / PR #3293. This fix reuses that lock rather than introducing another coordination mechanism.

## Reproduced failure boundary

Before this issue, `UpdateManager::execute` acquired a private, symlink-guarded, nonblocking `flock` through its update and rollback; the manual `SystemAdministrationRuntime::createDatabaseBackup` and `restoreDatabaseBackup` paths called the same `UpdateRuntime` database primitives without holding it. Concurrent Super Admin requests could therefore interleave database snapshots, maintenance mode, rollback, migrations and ZIP deployment. Authorization checks alone do not serialize independent HTTP requests.

## Implementation contract

- `UpdateManager::withExclusiveExecutionLock` exposes the **existing canonical** file-lock lifecycle (same configured path, nonblocking acquisition, symlink/ancestor containment, finally-based release) to other approved recovery consumers. Its original `execute` pipeline and history semantics remain intact.
- Manual DB backup takes that same lock before taking the snapshot, writing metadata or auditing completion. Manual restore takes it before reading the recovery candidate, audit-start, verification, maintenance, rollback, migration, cache rebuild, health check, success or failure audit. Lock contention fails closed with the same generic existing error; `SystemUpdateController` maps manual recovery failures to localized, non-secret Dashboard messages. Never leak the lock filesystem path.
- Existing restore confirmation, production double confirmation, available-backup validation and maintenance-mode-on-unsafe-failure behavior remain unchanged.
- The lock is effective for PHP worker processes sharing the configured filesystem. **Separate application nodes with non-shared storage require a truly shared/distributed lock**; this change does not certify multi-host atomic recovery.
- Background migration/other out-of-band database tools not using this lock are outside the scope; do not claim they are serialized.
- No modifications to installer, signing, release artifact, runtime seed, `.env`, uploads, API schema, Dashboard HTML or mobile clients.

## Regression and acceptance

New `backend/tests/Feature/V2ManualRecoveryExecutionLockTest.php` verifies:
1. An externally held updater lock rejects both manual backup and manual restore **before** snapshot, maintenance, recovery or audit side effects.
2. After releasing it, normal sequential manual backup and verified restore execute the existing recovery stages and audit success.
3. A failed restore records failure without revealing the underlying exception to the UI-visible error, preserves maintenance mode when safe restoration cannot be verified, and releases the execution lock for controlled remediation.
4. Linked lock-file target is rejected before any backend operation or write to the external target.
5. Existing updater-exclusive-lock tests (#3291) and SystemAdministrationRuntimeTest remain applicable; CI must run the full Laravel backend suite, security/Pint/PHPStan, AR/EN, real MySQL/Redis install-upgrade-recovery and final Required CI on the exact head.

**Customer:** no app API, login, checkout, notification or Flutter change. **Driver:** no delivery/collection, API or app change; B2C Driver-only retained. **Van:** no route, visit, sale, order, API or app change; B2B Van-only retained. Mobile Flutter jobs may correctly be skipped for these backend-only paths; do not claim mobile E2E certification.

## Non-overlap / remaining parent acceptance

Changes are confined to the existing canonical UpdateManager and SystemAdministrationRuntime classes, one new issue test and this QA note. No open PR at preflight modified these paths. This atomic issue can close only on exact-head green CI and verified merge to `Foodex_V2`. Parent #2746/#2846/#2503 remain OPEN until signed ZIP trusted provenance, exact built artifacts, MySQL clean install/upgrade, safe backup/recovery and three-app acceptance are complete. **No `main` integration, tags, release, installation on live cPanel, production, or staging deployment.**
