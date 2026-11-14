# V2 #3396 — database backup recovery provenance (P112 / G005)

**Source:** FOODEX V2 P112 / G005 backup and recovery. Parents #2746 (Admin Update ZIP), #2846 (Setup/Update delivery), master #2503. Target `Foodex_V2` only. This is an atomic, private database-recovery protection, not a new screen or production release.

## Confirmed source defect and prior ownership

The existing runtime `LaravelUpdateRuntime::rollbackFiles` already rejects non-trusted backup roots, linked/aliased paths and malformed file manifests after #3371/#3388 (PRs #3375/#3392, both merged). However, independent `verifyDatabaseBackup` and `rollbackDatabase` previously opened `$backupReference/database.json` from any readable directory, accepted arbitrary snapshot paths from JSON metadata and, in the rollback path, could reach `DB::purge`, SQLite sidecar deletion or an external database client without proving backup provenance. No existing open PR owned the now issue-scoped runtime path at preflight. No live accounts or production data were inspected or touched.

## Implementation and fail-closed contract

- A private shared read-only metadata preflight now checks the configured `foodex.update_backup_path` and the backup directory lexically and via canonical `realpath`. Reject foreign/outside references, dot/dotdot, extra separators and linked relative directory components.
- Preserve real backup references created by `newBackupDirectory()` when the configured root ends with a separator: exactly the generated double boundary separator may be accepted, not arbitrary repeat/dot aliases.
- Metadata `database.json` must be an actual readable, nonlinked file, with a known `driver` that matches the active DB connection. The snapshot is only the canonical expected leaf in the same backup directory: `database.sqlite`, `database.sql` or `database.dump`, with exact declared reference and no linked file, missing/empty file, or external alias. Error messages never expose backup filesystem paths.
- `verifyDatabaseBackup` preserves the existing SQLite `PRAGMA integrity_check`, MySQL/MariaDB dump-header validation and PostgreSQL `pg_restore --list`.
- `rollbackDatabase` rechecks provenance and content immediately before any `DB::purge`, sidecar unlink, external restore program or write; no claim is made that all database migrations are reversible.

## Feature regression

`backend/tests/Feature/V2UpdaterDatabaseBackupProvenanceTest.php` builds private temporary SQLite snapshots and checks both verification and rollback rejection **before connection purge** for foreign backup root, `nested/../` alias, intermediate directory symlink, metadata symlink, snapshot symlink, external snapshot, renamed-in-directory snapshot, active-driver mismatch and malformed metadata. It proves valid trusted SQLite verification, generated trailing-boundary compatibility with excessive-separator rejection, and successful restoration of a dedicated temporary SQLite database fixture with original row content. The test never opens or alters production database credentials.

No shared file-rollback source logic was altered. Existing #3371/#3388 regression tests continue to own their separate safety scope.

## Application impact / navigation placement

| Surface | Scope |
| --- | --- |
| Backend | Private database backup verification and rollback only; no route, migration or schema change |
| Dashboard | Existing Super Admin Settings → System → System Updates → Backup & Recovery uses the current failure handling; no screen/control/new locale string |
| Customer | Not affected: public API, login, payments, notification and Flutter code unchanged |
| Driver | Not affected: dispatch/finance API or Flutter unchanged; Retail B2C remains DRIVER ONLY |
| Van | Not affected: Van/route/inventory API or Flutter unchanged; Wholesale B2B remains VAN ONLY |

**Navigation:** Reuse existing Admin Settings → System Updates; no new link or direct URL. AR/EN strings unchanged. No customer/driver/van linked child issue required because no exposed contract changes.

## Exact-head acceptance and release boundary

One Issue #3396, one branch `fix/3396-v2-database-backup-provenance`, one PR **base=Foodex_V2**. Require latest exact PR HEAD success for Repository Policy, FOODEX V2 Isolation Gate and FOODEX Required CI, including PHP syntax/Pint/PHPStan/security/OpenAPI, full Laravel feature suite, AR/EN localization, real MySQL/Redis install/upgrade/recovery and terminal `required-ci-gate`. No acceptance, merge or closure from file creation alone.

Keep #2746/#2846/#2503 open for trusted digital signatures, real signed Setup/Update ZIP artifacts, full environment/staging recovery and complete application parity. No main, version tags, release promotion, cPanel operations or production deployment.
