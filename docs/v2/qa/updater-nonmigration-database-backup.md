# V2 #3418 — mandatory verified DB backup for migration-free ZIP updates

- Parent contracts: #2746 (G005), #2846 (P112), #2503; integration **Foodex_V2 only**.
- Scope: `UpdateManager::execute()` backup gate and issue-owned Laravel regression tests. No installer, frontend, external API, mobile application, release/tag, or production changes.
- Observation on isolated source: `LaravelUpdateRuntime::rebuildCaches()` runs `reconcileBuiltInJobRoles()`, which may write the persisted role/permission matrix independently of whether the ZIP contains migrations. The previous conditional `backupDatabase(containsMigrations)` allowed an update with no migrations to proceed without a DB restore point.
- Control: every ZIP update now requests a required DB snapshot, checks its existence, and verifies it **before** entering maintenance mode or extracting files. No-migration packages still **skip migrations**; they do not skip backup/verification. Backup failure stops the update without a live release mutation.
- Recovery: after a cache-reconciliation failure the updater can restore files **and** its verified database backup. A failed database restore is explicitly `incomplete`, retains maintenance mode, records recovery evidence, and does **not** claim reversible migrations or silent success.
- Existing guards remain authoritative: signature/provenance/package version/ZIP safety validation, exclusive update lock, file backup verification, signed-release policy, history and audit; this change does not certify all G005/P112 packaging or production readiness.

## Reproducible verification

Focused:
`cd backend && php artisan test --filter=V2UpdaterNonMigrationDatabaseBackupTest`

Regression:
`cd backend && php artisan test --filter=UpdateManagerTest`
`cd backend && php artisan test --filter=V2UpdaterMutationAwareRollbackTest`

Required CI: exact PR HEAD Repository Policy + FOODEX V2 Isolation + FOODEX Required CI Gate (PHP lint/Pint/PHPStan, Composer audit, Laravel full tests, MySQL/Redis install/upgrade/recovery, AR/EN). Only recorded green Actions evidence permits Foodex_V2 merge and completed leaf closure; local commands listed here are **not** claimed as executed without results.

## Impact matrix

| Surface | Change / proof |
| --- | --- |
| Backend updater / admin update action | Backup required for all ZIP updates; same authorized route and lock |
| Dashboard | No new UI/translation key, same error/maintenance/recovery semantics |
| Customer | No Flutter or public API contract change |
| Driver | No Flutter, assignment or B2C dispatch change |
| Van | No Flutter, custody or B2B dispatch change |
| AR/EN + RTL/LTR | No strings rendered in the UI and no navigation/layout change; existing localization gate unchanged |
| Privacy/SoD | Preserve scoped role reconciliation, audit and existing permission checks; no live records accessed |

Owner manual UAT remains `MANUAL_ACCEPTANCE: DEFERRED_TO_V2_FINAL` per #2503; automatic functional/security acceptance is not waived.
