# V2 #3468 — Fail closed on unreadable ZIP at Admin Verify Package

- Source: P112 / #2846; gate #2746; V2 master #2503
- Integration: `Foodex_V2` only — no `main`, releases, tags or cPanel/production deployment
- Issue: https://github.com/walidatiyaai2025-gif/FOOD/issues/3468
- Scope: `UpdatePackageValidator.php`, existing updater unit and manager feature fixtures/tests; no route/API/UI/permission change

## Reproduced risk

Before this patch, a package file with matching manifest SHA-256 but **invalid ZIP bytes** passed `UpdatePackageValidator::validate` because a non-true `ZipArchive::open` result silently skipped ZIP inspection and continued to version success. The same logic skipped ZIP inspection if PHP ext-zip was unavailable. This is a false-success in **Verify Package** and misleading package-integrity evidence, rather than evidence of successful update activation. Later real-runtime preflight was a separate protection.

## Source change

- Require PHP ext-zip and a successful `ZipArchive::open` before the validator can return success.
- Reject bad archive input via generic `InvalidArgumentException`; retain SHA-256 comparison, canonical SemVer, symlink rejection, configurable ZIP member budget and migration declaration scan.
- Close every successfully opened archive in `finally`.
- Replace both pre-existing fake plain-text `valid package` test fixtures with real, harmless ZIP packages. This avoids masking the rejection in a simulated update runtime.
- Regression: real ZIP success; matching-hash non-ZIP denial; truncated ZIP denial; update-manager failed `validate_package` evidence with zero preflight/backup/maintenance/runtime calls, no version write and no rollback against untouched live files.

## Impact matrix

| Surface | Classification / evidence |
| --- | --- |
| Admin Dashboard | Existing Super Admin Verify Package action now gets a failure for malformed ZIP; no menu, UI, route or permission change |
| Backend | ZIP verification/validation only; no schema, finance, storage, scheduler or queue mutation introduced |
| API | No endpoint, request, response shape or version change |
| Customer | Not affected: no public API, store/identity, payment or Flutter source change |
| Driver | Not affected: no Driver API, state, B2C dispatch or Flutter source change |
| Van | Not affected: no Van API, B2B routing, custody or Flutter source change |
| Localization | No new user-facing AR/EN catalog keys or copy |
| Tenant/security | Existing RBAC, Super Admin update boundary, B2B=VAN ONLY, B2C=DRIVER ONLY unchanged |

## Full-suite regression follow-up

The first exact-head Required CI run [#43573188769](https://github.com/walidatiyaai2025-gif/FOOD/actions/runs/43573188769) passed Repository Policy, V2 Isolation, PHP lint/Pint/PHPStan/security/OpenAPI, AR/EN localization and real MySQL/Redis fresh-install/upgrade/recovery acceptance. Full Laravel reported **3,690 pass / 21 fail / 1 skip**, with every failure traced to a pre-existing `plain-text-as-valid-ZIP` test fixture incompatible with fail-closed validation (or an old assertion explicitly treating non-ZIP as valid). Its `required-ci-gate` is **FAILED** and is NOT acceptance evidence.

Same Issue/branch/PR CI rescue extends ONLY related existing tests: `V2LegacyInstalledVersionPrecisionTest`, `V2UpdateCanonicalManifestVersionTest`, `V2UpdaterPackageSymlinkGuardTest`, `V2SystemUpdateLocalizedFlashTest`, `V2UpdateValidatorMemberCountPreflightTest`, `V2UpdaterExecutionLockTest`, `V2UpdaterMutationAwareRollbackTest`, `V2UpdaterNonMigrationDatabaseBackupTest`, `V2UpdaterPostExitRollbackMaintenanceTest`. A single reusable `Tests\\Support\\UpdateZipFixture` writes an actual harmless ZIP or supplies ZIP bytes for HTTP uploads; the deliberately invalid non-ZIP test now **expects** rejection. Existing original negative, rollback, lock and tenant/RBAC expectations remain intact. No peer-owned production file was changed.

This follow-up is **unverified pending new exact-head Required CI**, and no earlier green partial job may substitute for the terminal latest run.

## Verification matrix

- [ ] `php -l` on changed PHP files
- [ ] Laravel `UpdatePackageValidatorTest` focused tests
- [ ] Laravel `UpdateManagerTest` focused tests
- [ ] Scoped Pint + PHPStan/security checks
- [ ] Full relevant Laravel/AR-EN/MySQL-Redis CI
- [ ] Exact-final-head Repository Policy workflow SUCCESS
- [ ] Exact-final-head FOODEX V2 Isolation Gate SUCCESS
- [ ] Exact-final-head FOODEX Required CI Gate (including terminal required-ci-gate) SUCCESS

**Do not mark verified until the corresponding exact-head tests are observed.** No signed production release artifact is claimed. Human/staging UAT and full G005/G006/P112 Setup/Update/Recovery acceptance remain with #2503/#2746/#2846.
