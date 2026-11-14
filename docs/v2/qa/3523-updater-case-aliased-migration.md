# V2 #3523 — Case-aliased ZIP migration classification before backup decision

## Source and isolation
Source: FOODEX Unified RBAC/ERP/Sidebar P112; parents #2746 (G005), #2846 (P112), #2791, master #2503. Target only Foodex_V2. Never main, release, tags, cPanel or production.

## Verified source gap
The ZIP migration verifier and Dashboard migration auto-detection normalized whitespace, backslashes and PHP extension casing, but checked the Laravel migration directory prefix case-sensitively. On case-insensitive hosts a single ZIP member named Backend/Database/Migrations/2026_11_14_000001_probe.PHP may reach the real backend/database/migrations directory while manifest containsMigrations=false was accepted. This is a migration declaration trust-boundary discrepancy, NOT evidence of a real exploit. UpdateManager already requires verified database backup for every update. Fix source classification and the required migration execution decision before update execution.

## Scope and implementation
- UpdatePackageValidator::validate(): normalize and ASCII-case-fold entry name before comparing directory prefix and PHP extension; retain SHA-256, canonical SemVer, member budgets and signature boundary.
- SystemUpdateController::packageContainsMigrations(): identical canonical case-insensitive scan in the existing Super Admin ZIP flow. Retain route, authorization, redirect, failure response, ZIP cleanup and API/UI/locale contracts.
- No modifications to LaravelUpdateRuntime.php or other workers' #3506-#3509 case-collision file fences, UpdateManager, release publishing, schema, finance or mobile code. Prior merged #3402 fixed whitespace/backslash aliases only.

## Test matrix
- Unit V2UpdaterCaseAliasedMigrationDeclarationTest uses real temporary ZIPs and verifies case-alias, mixed-case PHP extension and whitespace/backslash variations reject an undeclared migration; declared migration and benign files pass; wrong checksum fails first.
- Feature V2UpdaterCaseAliasedMigrationUploadTest checks Dashboard detection for mixed-case, backslash and benign paths, and performs an authorized authenticated HTTP upload with an intentionally incorrect SHA. This must fail at validate_package before any backup/maintenance/extraction/migrations, but the persisted prevalidation execution evidence must show migrations=pending (not not_required); upload is cleaned and no new system version installed. Unauthenticated request denied.
- Required exact final HEAD CI: Repository Policy, V2 Isolation, FOODEX Required CI with full Laravel/PHP lint/Pint/PHPStan/security, Arabic/English and MySQL/Redis fresh-install/upgrade/recovery. No green claim until actual runs succeed.

## Impact matrix
| Surface | Classification | Contract |
| --- | --- | --- |
| Backend / Updater | AFFECTED | Fail-closed ZIP migration recognition; version/signature/integrity decisions unchanged |
| Dashboard | AFFECTED | Existing Super Admin Settings → System Updates upload auto-detection; same UI, AR/EN, RTL/LTR |
| Customer API / Flutter | NOT AFFECTED | No public route, schema or application source changed |
| Driver API / Flutter | NOT AFFECTED | No public route or application source; B2C DRIVER ONLY unchanged |
| Van API / Flutter | NOT AFFECTED | No public route or application source; B2B VAN ONLY unchanged |

No tenant, store, permission, SoD or maker/checker grants expanded. Fixtures are synthetic and never ship production/user data or perform a live upgrade.

## Closure and deferred acceptance
Close only atomic #3523 with CODE_COMPLETE / FINAL_ACCEPTANCE_DEFERRED when its issue-scoped PR is merged exclusively into Foodex_V2 after all required exact final-head CI checks are successful and links to tested SHA, integration SHA, runs and rollback/no-write evidence are recorded. Parents #2746, #2846, #2791 and #2503 remain OPEN for signed packages, genuine Setup/Update ZIP fresh MySQL install, supported upgrade/rollback, all-application staging acceptance and explicit future owner release order.
