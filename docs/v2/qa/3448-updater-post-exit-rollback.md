# V2 #3448 — Update ZIP: late metadata failure must not roll back while live

- Parent: #2746 G005 / #2846 P112 / #2503.
- Integration: **Foodex_V2 only**, no main/release/tag/cPanel/production.
- Actor/entry: authenticated Super Admin through existing protected Settings → System → System Updates; existing route and permission checks unchanged.
- Placement: **Reuse** existing update execution/recovery engine and existing Update History, no new page/API/Sidebar item. The updater's process-wide exclusive lock and protected backup references remain authoritative.
- Source defect: `UpdateManager::execute()` exited maintenance before writing `SystemVersion`, final history, and completion audit. An exception in any late operation could invoke `rollbackFiles` / `rollbackDatabase` while ordinary HTTP writes were permitted.

## Security contract and failure behavior

1. Before rollback of a release that has changed live files, if maintenance was successfully exited, reenter it under the existing exclusive execution lock. Only after successful reentry may file or database rollback begin.
2. If maintenance cannot be reentered, skip **both** rollback writes, preserve original backup references and record `rollback_status=incomplete`, machine evidence `maintenance_mode=reentry_failed` / `rollback_files=skipped_unsafe` / `rollback_database=skipped_unsafe` when applicable, and require operator recovery. Do not falsely mark a safe rollback or assume that the live site is in maintenance.
3. Existing paths for failures before release activation, for migrations, for cache rebuild, or for failure during rollback continue to follow their established mutation-aware rules.
4. Late metadata stages now distinguish `record_version`, `record_success` and `audit_completion` from `exit_maintenance_mode`. No secret/server path is exposed through the new machine evidence.
5. A database migration may be irreversible; this guard prevents live concurrent rollback but does not promise universal database rollback or operator-free recovery.

## Coverage

New `backend/tests/Feature/V2UpdaterPostExitRollbackMaintenanceTest.php` injects a failing `SystemVersion::creating` after maintenance exit and tests the protected Super Admin HTTP history view in both locales. It checks:

- successful maintenance **reentry precedes any rollback write**, then file/DB restoration, health check and safe exit;
- failed reentry causes **zero rollback writes**, incomplete recovery, explicit safe-to-display operator reason and preserved history/audit;
- the migration-free ZIP path also treats cache rebuild as a possible DB mutation, so it cannot bypass database recovery protection;
- authenticated Update History GET resolves `reentry_failed` through paired Arabic/English keys and hides the raw English technical diagnostic from that user-facing row.

Existing `UpdateManagerTest`, `V2UpdaterMutationAwareRollbackTest`, and manual Backup/Recovery lock tests must remain green.

## Cross-surface and localization

| Surface | Impact |
| --- | --- |
| Backend updater/DB | Affected: internal maintenance/recovery sequencing only; no new schema or package payload |
| Dashboard | Affected: existing authorized Update History row now displays a localized `reentry_failed` warning with `role=alert`; no new route or action |
| Customer | Not affected: no API, login, identity, order or Flutter source change |
| Driver | Not affected: B2C DRIVER ONLY and existing runtime APIs unchanged |
| Van | Not affected: B2B VAN ONLY and existing runtime APIs unchanged |

Security/AR-EN acceptance: the existing Super Admin update route remains authoritative. Added `admin.system_update_surface.rollback_reentry_failed` to both locale catalogs and reused the current paginated `.foodex-table` history with no custom CSS/shell/Sidebar. AR/RTL and EN/LTR HTTP assertions prove the corresponding localized failure warning and no raw diagnostic leakage on that row; persistent protected history/audit retains original diagnostics. Existing localization and 320px+ shared responsive layout remain in place. Customer/Driver/Van APIs and Flutter remain unchanged.

## Required exact-head evidence

Before integration: focused Laravel tests, full Laravel/Pint/PHPStan/security, MySQL/Redis deployment and rollback acceptance, Repository Policy, FOODEX V2 Isolation Gate, FOODEX Required CI with terminal `required-ci-gate` SUCCESS. Record PR HEAD SHA, actions run IDs, merge SHA and deferred browser/staging UAT in Issue #3448. **Do not merge on pending/red CI**.

Manual staging/recovery drill and final consolidated acceptance: deferred to #2503 and #2746 after complete V2 programming. No production execution authorized.
