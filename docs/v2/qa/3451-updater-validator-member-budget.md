# V2 #3451 — bound migration-scan ZIP member count in package validation

**Source/parents:** P112 / G005 (#2746), #2846, master #2503. Target `Foodex_V2` ONLY. No `main`, release, tag, cPanel, SSH or production deployment.

## Distinct source gap

The earlier `UpdatePackageValidator::validate()` performs a checksum and, when a caller declares `containsMigrations=false`, opens the archive and iterates **every** `ZipArchive::numFiles` member looking for undeclared migrations. A checksum-match does not cap the number of central-directory entries; thousands of zero-length files/directory names can force unbounded verification CPU/memory before the runtime stage begins. The separate #3441 runtime protection bounds `LaravelUpdateRuntime::archiveEntriesFrom()` at preflight/backup/extract and remains owned by its original worker. This issue covers **only** the distinct validation-time scan.

## Change

- Enforce the matching configurable `foodex.update_max_zip_members` default of **50,000**, with `max(1, ...)` clamping nonpositive settings, immediately after successfully opening a ZIP and before the first `getNameIndex` call.
- The guard counts directory records, including zero-byte members, and uses a generic exception with no server or package secrets.
- Preserve SHA-256 comparison, symlink validation, canonical target/minimum versions, existing migration detection and mandatory database backup. No backup, maintenance or extraction is invoked from this validator.
- No runtime/Dashboard view, API, route, permissions, AR/EN translation, Customer, Driver or Van code/behavior was changed. Existing setup/update UI reports a rejected upload using its established error path; no new visual/RTL/LTR/a11y surface is introduced.

## Hermetic regression

`backend/tests/Feature/V2UpdaterValidatorMemberBudgetTest.php` creates local synthetic ZIP files and verifies: (1) two-member budget rejects three directory/zero-byte entries without mutating the ZIP; (2) an exactly three-member archive remains valid; (3) invalid zero/negative configured limits clamp to one; (4) a real migration file under the boundary continues to fail when `containsMigrations=false`. Existing runtime budget and path-security checks are unchanged.

Fresh Laravel/PHP syntax, Pint/static/security checks, AR/EN localization and MySQL/Redis install/upgrade/recovery must be proven by exact-head CI before this leaf is closed. Existing final **signed** ZIP, Web Installer, Admin Update Center, full MySQL migration upgrade and restore acceptance remain the responsibility of parents #2746/#2846/#2503; this bounded guard alone does not certify production readiness.
