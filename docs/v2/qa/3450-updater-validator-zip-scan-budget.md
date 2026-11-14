# V2 #3450 — Bound update validator migration ZIP scan (P112/G005)

## Source and finding

- Parent #2746 / #2846; master #2503; integration `Foodex_V2` only.
- `UpdateManager::execute()` validates a package before calling `LaravelUpdateRuntime::preflight()`.
- When `UpdatePackageManifest::containsMigrations=false`, `UpdatePackageValidator::validate()` opens the ZIP and checks every central-directory member name for undeclared Laravel migrations. Before #3450 there was no member-count limit in this **earlier** iteration.
- #3441 independently limits `LaravelUpdateRuntime::archiveEntriesFrom()` for preflight, file backup and extraction. That parser does not protect the validator before it runs. We reuse the same `foodex.update_max_zip_members` budget and fallback of 50,000, counting file and directory records.

## Implementation and security behavior

- Reject `ZipArchive::numFiles` greater than the configured positive budget **before** the first `getNameIndex()` call; a nonpositive configuration is clamped to one. This is a resource-exhaustion safeguard, not a package authenticity substitute.
- Keep SHA-256 integrity comparison, canonical version compatibility, symlink-parent rejection, migration declaration, signature/updater orchestration, backup, and recovery behavior unchanged.
- Remain compatible with the #3441 runtime bound without modifying its source, tests or configuration. An archive at the limit remains eligible for subsequent normal checks, and a migration in that archive with a false manifest flag still fails.
- No external ZIP unpacking, new route, permission, UI, secrets or production deployment.

## Regression proof

`backend/tests/Feature/V2UpdaterValidatorZipScanBudgetTest.php` covers:

1. Four central-directory records (including an empty directory and undeclared migration) with a three-member limit: fail closed at member-count budget **before** any migration scan.
2. Three records at the exact limit with empty content: pass the validator.
3. Three records including a migration with an undeclared migration flag: preserve the migration rejection.
4. A zero-configured limit with two records: fail closed after positive clamping.

Also retain `backend/tests/Unit/UpdatePackageValidatorTest.php` for existing hash, version, symlink and migration declaration behavior. Required CI includes PHP syntax, Pint, static analysis, security, localization AR/EN, Laravel tests and real MySQL/Redis setup/upgrade/recovery. Final run IDs and head/merge SHAs belong in the PR and closure comment only after verified success.

## Platform and authorization impact matrix

| Surface | Impact |
| --- | --- |
| Backend | Early internal Update ZIP package validation; no state mutation before rejection |
| Dashboard | Existing Super Admin System Updates page and permissions unchanged; no new UI strings/route |
| Customer | No API, authentication, pricing, Flutter or dispatch changes |
| Driver | No B2C assignment, wallet, API or Flutter changes |
| Van | No B2B assignment, custody, API or Flutter changes |

AR/EN, RTL/LTR and accessibility surfaces are unchanged; this patch adds no user-facing UI. The existing update rejection diagnostic is consistent with the runtime's existing member-count error.

## Acceptance boundary

Only this atomic V2 #3450 coding leaf can be marked complete after its one issue-scoped PR merges to `Foodex_V2` with all required exact-head checks green. Full signed Setup/Update ZIP, end-to-end backup/recovery, staging and owner acceptance remain tracked by #2746/#2846/#2503. No `main`, production, release, tag, or cPanel action is authorized.
