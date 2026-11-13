# V2 #3388 — trusted rollback backup-root and JSON-list preflight (P112/G005)

**Source:** FOODEX V2 G005/P112 Update ZIP backup and recovery safety. Parent #3371 (original #3375 MERGED, duplicate #3380 CLOSED/NOT MERGED), #2746, #2846, master #2503. **Integration target: `Foodex_V2` ONLY.**

## Independently reproduced residual gap

The earlier merged `LaravelUpdateRuntime::rollbackFiles` preflights all referenced backup files, validates target paths and detects duplicate entries before deleting updated live files. However, a supplied `backupReference` could be any readable directory with a well-formed `files.json` outside the canonical `foodex.update_backup_path`. That foreign manifest could authorize deletion/overwrite of release targets. The earlier parser also accepted associative JSON object maps for `targets`/`existing` because `json_decode(..., true)` produces PHP arrays and `stringList` preserved their values.

## Bounded post-#3371 remediation

1. Resolve configured backup root and requested rollback directory with `realpath`. Require the reference to be a strict descendant of the configured trusted backup root both as a normalized reference and as a canonical resolved path.
2. Reject empty/dot/dotdot path segments or symlinks within the backup-root-relative reference even when they ultimately resolve inside the configured root. Configured storage ancestors are allowed to have a host-specific canonical path; descendants must still satisfy strict checks. Do not expose absolute paths or system secrets in errors.
3. Require `targets` and `existing` to be PHP `array_is_list` values decoded from true JSON arrays before the existing `stringList` and complete backup preflight. Existing protected-path, linked manifest/source-file, duplicate target, backup completeness and optimistic recovery checks remain unchanged.
4. Configure the original #3371 real-filesystem feature-test fixture's trusted root to its already-isolated testing parent. No code weakening or bypass to make the suite pass.
5. Do not change public API, routes, permission scopes, storage uploads, Laravel migrations or the update version contract.

## Dedicated negative/positive Laravel regression

`backend/tests/Feature/V2UpdaterBackupRootContainmentTest.php` verifies:

- A valid-looking foreign backup manifest must **not** remove a live release file.
- A `nested/../files` alias under a trusted root is rejected before unlink.
- A linked intermediate directory is rejected even if the final target resolves back inside the trusted root (skip only if the test runner does not support symlinks).
- JSON object-shaped `targets` and `existing` are rejected without changing a live file.
- A trusted complete backup restores original content (including a zero-byte file) and removes new release targets.

The previously integrated `V2UpdaterRollbackPreflightTest.php` must remain green: missing second backup, foreign existing key, symlink source, duplicate targets, malformed list and complete restoration.

## Impact classification

| Surface | Impact |
| --- | --- |
| Backend | Protected private file rollback source validation only; no HTTP route or migration change |
| Dashboard | Existing Super Admin System Updates / Backup & Recovery failure behavior, existing generic exception path; no UI or AR/EN text changed |
| Customer | Not affected: no customer API, Flutter or identity scope changes |
| Driver | Not affected: no dispatch, driver API or Flutter changes; B2C Driver-only remains |
| Van | Not affected: no field routes, settlement, Van API or Flutter changes; B2B Van-only remains |

## Mandatory delivery and remaining gates

One atomic branch `fix/3388-v2-rollback-trusted-root-manifest`, one PR base `Foodex_V2`. Run Repository Policy, FOODEX V2 Isolation and FOODEX Required CI on the exact final PR SHA, including PHP syntax/Pint/PHPStan/security/OpenAPI, Arabic/English localization, full Laravel suite and real MySQL/Redis clean-install, upgrade and recovery acceptance. **Do not merge or close until all applicable required checks are terminal SUCCESS**, no overlapping lease and PR is source-complete. Proof must link issue/PR, exact tested HEAD and merge SHA.

This change does NOT certify real signed Setup/Update ZIP package authenticity, all database migrations rollback, staging execution, the Customer/Driver/Van release APKs, or production readiness. Keep parent #3371/#2746/#2846/#2503 OPEN pending independent requirements. Manual UAT is deferred to the final V2 acceptance program.

No `main` change, release, tag, artifact publication, cPanel or production deployment.
