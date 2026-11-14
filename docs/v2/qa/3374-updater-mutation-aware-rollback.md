# V2 #3374 — Mutation-aware ZIP updater rollback gate

- **Source / parent:** P112 G005, #2746 / #2846 / master #2503
- **Integration target:** `Foodex_V2` only; no main, production, release or tag
- **Code owner:** `backend/app/Domain/Updater/UpdateManager.php`
- **Regression:** `backend/tests/Feature/V2UpdaterMutationAwareRollbackTest.php`

## Proven failure mode

Before #3374, `UpdateManager::execute()` attempted `rollbackDatabase` as soon as `backupDatabase` returned a path, even when `verifyDatabaseBackup` threw and the application had not entered maintenance or changed database records. That is unsafe because the snapshot that just failed verification may be incomplete/unrestorable; an unwarranted restore can damage an untouched live database.

## Corrected execution boundary

The manager records **two independent conservative mutation markers**:

1. **Release file mutation starts immediately before `extractRelease`**; partial extraction may already change files before failure. Only then can file rollback run.
2. **Database mutation starts immediately before `runMigrations` or `rebuildCaches`**; migrations may partially commit, and cache rebuild reconciles persisted role grants. Only then may database restore run **if** a verified backup reference exists.

Failed package validation, preflight, file/database backup and backup verification do **not** trigger restoration of untouched live files or database. Failure still records the exact stage and retains any backup reference for diagnosis. A partial extraction performs file rollback only; a partial migration performs both file/database rollback, health validation and maintenance exit when safe. On incomplete rollback, the existing maintenance-mode safety remains enforced. Failed-before-mutation history states `rollback_status=not_required` and omits the false claim that rollback was attempted.

## Focused acceptance matrix

| Injected failure | Release rollback | DB rollback | Maintenance |
| --- | --- | --- | --- |
| Preflight | No | No | Never entered |
| Database backup verification | No | No | Never entered |
| Partial release extraction | Yes | No | Exit only after healthy rollback |
| Partial migrations | Yes | Yes | Exit only after healthy rollback |

The tests run against an isolated recording `UpdateRuntime`, real `UpdateManager`, Laravel `UpdateHistory` persistence and existing `UpdatePackageValidator` inputs. Existing full updater success and failed rollback tests remain in `UpdateManagerTest.php` and must continue passing.

## Data and product boundaries

- **Dashboard:** existing System Updates error/history UI; no new screens, routes, strings or authorization grants.
- **Customer:** no public API, Customer Flutter, pricing or fulfillment change.
- **Driver:** no public API, Flutter, B2C-only dispatch or finance collection change.
- **Van:** no public API, Flutter, B2B-only Van dispatch or custody change.
- **AR/EN:** existing failure messages only; no new localization keys or UI flows.
- **Recovery:** no assumption that any DB migration is reversible. This patch does not validate ZIP signatures or guarantee safe rollback under every storage/DB failure; those remain parent gate acceptance.

## Release gate

Require exact-head Repository Policy, V2 Isolation, and Required CI (PHP syntax, Pint, PHPStan, Laravel, security, AR/EN, MySQL/Redis install/upgrade/recovery) **success** on this branch. Merge only to `Foodex_V2` after confirmation. Parent G005/P112 and full signed ZIP acceptance remain open.
