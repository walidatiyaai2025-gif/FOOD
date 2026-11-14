# V2 #3449 — bound ZIP members in package validator preflight

Source: FOODEX Unified RBAC/ERP P112, Admin Update ZIP G005 #2746, P112 #2846, V2 master #2503. All work isolated to `Foodex_V2`; no `main`, production, tags, release or cPanel action.

## Reproduced execution ordering / problem

`UpdateManager::execute()` calls `UpdatePackageValidator::validate()` **before** calling the `UpdateRuntime::preflight()` implementation. The validator must scan all entries in an opened ZIP when `containsMigrations=false` to prevent a manifest from hiding Laravel database migrations. The pre-existing scan iterated `ZipArchive::numFiles` without an entry count bound. An attacker-controlled archive with many zero-byte entries or empty directory records can consume unbounded validation CPU, independently of expanded-size limits.

Related #3441 / PR #3443 introduces a member count in `LaravelUpdateRuntime::archiveEntriesFrom()`, guarding runtime preflight, direct backup and extraction. That is **not sufficient by itself** for the validator's earlier migration scan. This issue owns the separate validator-entry boundary; it does not change #3441's source files, tests or deployment controls.

## Minimal correction

- In `UpdatePackageValidator::validate()`, after the verified file hash and after a ZIP opens successfully, compare `ZipArchive::numFiles` with `max(1, (int) config('foodex.update_max_zip_members', 50000))` **before** `getNameIndex()` iteration.
- Apply the count to all ZIPs regardless of the manifest `containsMigrations` flag, including empty directories. This prevents a true flag from opting out of the resource guard.
- Preserve signed-package integrity SHA, source version canonicalization, downgrade prevention and linked-path denial, plus all existing detection of undeclared migrations (including Windows separators and whitespace). Ensure `ZipArchive::close()` is in `finally`.
- Preserve non-ZIP mock payload compatibility in `UpdatePackageValidator` itself; actual ZIP format checks remain the runtime preflight responsibility.
- No write, backup or migration occurs within this validator; a denied package is rejected before any release mutation.

## Automated acceptance and evidence

`backend/tests/Feature/V2UpdateValidatorMemberCountPreflightTest.php` covers six cases:

1. Four zero-byte files with a three-member maximum: validator rejects.
2. Four empty directory members and `containsMigrations=true`: validator still rejects.
3. Zero configured maximum clamps to one; two files reject.
4. Exactly three members: legitimate ZIP passes and SHA/version results match.
5. Under-budget archive with whitespace-padded Windows migration path and `containsMigrations=false`: existing mandatory backup declaration still enforced.
6. Non-ZIP payload used by existing unit/service tests retains backward-compatible handling.

Synthetic fixtures are isolated temporary files. A sentinel is asserted unchanged after all member-limit rejections. CI must run required exact-final-head Repository Policy, FOODEX V2 Isolation, Laravel/Pint/PHPStan/security, AR/EN, MySQL/Redis install-upgrade-recovery and Required CI Gate. No claims are made from local tests alone.

## Impact matrix / placement

| Surface | Impact |
| --- | --- |
| Backend | Resource bound in the existing internal UpdatePackageValidator before migration inspection; no API/schema/permission change |
| Dashboard | Existing Super Admin System Updates validation error handling; no route, navigation, form or string addition |
| Customer | Not affected: no consumer API, identity, Flutter or order change |
| Driver | Not affected: no consumer API, Flutter or B2C dispatch change |
| Van | Not affected: no consumer API, Flutter or B2B route/visit/dispatch change |
| AR/EN, RTL/LTR, responsiveness/a11y | No UI/locale/navigation changes |
| cPanel/shared hosting/VPS | Works in production PHP without SSH/manual commands and prevents excessive ZIP member traversal |

Full signed Setup ZIP / Update ZIP, verifiable backups, recovery, artifact and staging acceptance remain independently owned under #2745/#2746/#2846 and master #2503. This atomic patch must not be reported as overall production release acceptance.
