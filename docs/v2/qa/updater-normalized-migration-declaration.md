# FOODEX V2 #3402 — ZIP migration-path normalization before backup decision

**Source:** signed Admin Update ZIP G005 #2746; Setup/Update P112 #2846; release gate #2791; V2 master #2503. **Target:** `Foodex_V2` only. No `main`, production, tags or public release.

## Original source discrepancy

`LaravelUpdateRuntime::safeRelativePath()` canonicalizes a ZIP member using `str_replace('\\', '/', trim($path))`. In contrast, `UpdatePackageValidator::validate()` checked whether an uploaded ZIP contains a `backend/database/migrations/*.php` entry without trimming the raw ZIP name. A synthetic archive member named ` backend/database/migrations/2026_11_14_000000_test.php` or `backend/database/migrations/2026_11_14_000000_test.php ` was not detected as a migration. The manifest could therefore pass validation with `containsMigrations=false`.

This is a **manifest/preflight classification bug**, not proof that a production SQL migration was applied without a backup. The current extraction layer reopens ZIP entries by normalized name and is expected to reject malformed noncanonical aliases rather than necessarily execute them. Early false validation is nonetheless inappropriate: a checksum match does not excuse a contradictory migration declaration.

## Bounded implementation

The validator now uses the same outer-whitespace trim and slash normalization for **migration presence detection only**. Signed-version comparison, SHA-256, ZIP symlink rejection, protected file paths, extraction and rollback, database backup runtime, and Super Admin route authorization remain unchanged. The change fails closed before maintenance mode for a false `containsMigrations` declaration with padded migration members.

New isolated PHPUnit `backend/tests/Unit/V2UpdaterNormalizedMigrationDeclarationTest.php` creates actual synthetic ZIPs using `ZipArchive` and checks:

- Leading space, trailing whitespace/tab, and newline-padded ZIP migration names.
- Backslash migration separators combined with padding.
- Exact `InvalidArgumentException` for undeclared migrations and intact checksum guard.
- Valid explicit `containsMigrations=true` and benign non-migration package validation.

No actual database is touched in the new unit tests, and no live package, credentials or user data are used.

## Surface scope / localization

| Surface | Effect |
| --- | --- |
| Backend updater | More conservative, service-level ZIP manifest validation for migration backup declaration |
| Dashboard / System Updates | Existing Super Admin upload flow; an invalid/misdeclared package is rejected; no new UI/route/action/locale strings |
| Customer | No runtime API, commerce or Flutter change |
| Driver | No dispatch, finance, API or Flutter change |
| Van | No inventory/route, API or Flutter change |
| AR/EN, RTL/LTR | Existing error text and layouts reused, no translation or responsive change |

B2B VAN ONLY and B2C DRIVER ONLY invariants unchanged. The full signed Setup ZIP/Admin Update ZIP acceptance, real supported version upgrades, and recovery attestations remain the responsibility of open parent issues #2746/#2846/#2791/#2503, and are **not** certified by this unit fix.

## Required evidence

Before integrating the issue-scoped PR, verify exact-final-head `Repository Policy`, `FOODEX V2 Isolation Gate`, `FOODEX Required CI Gate` including PHP syntax/Pint/PHPStan/security/OpenAPI, localization, Laravel full tests and MySQL/Redis install/upgrade/recovery all terminal **SUCCESS**. Then verify only issue-owned paths changed, PR targets `Foodex_V2` and no active worker collision. Close the atomic leaf only after GitHub confirms merge and source presence on `Foodex_V2`; never merge `main` or deploy.
