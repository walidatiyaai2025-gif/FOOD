# V2 #3423 — Lossless legacy update version normalization and comparison

Source: P112; parents #2746, #2846 and V2 master #2503. **Integration: `Foodex_V2` only.** No main, production release, package publication, cPanel, tag or deployment.

## Reproduced defect and correction

Legacy installed `MAJOR.0PATCH` values such as `1.084` are normalized to `1.0.84`. Before this patch both MAJOR and PATCH were cast to PHP integers, which silently saturated above `PHP_INT_MAX`. On PHP 8.4, a compact patch of 28 nines became `9223372036854775807`, potentially rewriting `system_versions` or `update_histories` with a fabricated number when the Update Center's existing legacy repair runs.

A second independent PHP CLI reproduction demonstrated that `version_compare` returns zero for distinct canonical dotted numbers beyond PHP's machine integer range. Merely removing the casts would not fix upgrade/downgrade safety; the UpdatePackageValidator still used `version_compare` for supported-minimum and newer-target checks.

The patch instead:
- Strips leading zeroes from the compact legacy patch by decimal-string operations; preserves the exact major and patch magnitude; maintains historical normalizations (`1.084 → 1.0.84`, `1.00124 → 1.0.124`).
- Compares the exact three already-canonical ASCII decimal components by digit length, then ordinal digit text; no integer or floating point conversion. A canonical version with an extremely large component remains distinct and correctly ordered.
- Uses the comparator only for `UpdatePackageValidator`'s minimum-compatible-current and strictly-newer-target decisions. Existing canonical version and SHA-256 checks, ZIP security, signed origin, backups/migrations/rollback, update locks and Admin permissions are unaffected.

## Test matrix and app impact

`V2LegacyInstalledVersionPrecisionTest` asserts historical normalization, lossless huge major/patch, no-leading-zero canonical compare, huge-valued comparison ordering, malformed version denial, compatible forward update, incompatible source floor rejection, and downgrade rejection through the real validator. Existing `UpdatePackageValidatorTest` remains applicable.

Dashboard: `SystemUpdateController::currentVersion` continues calling the same normalizer with correct precision; no route, form, translation, button, state machine or UI structure changes. Backend: two domain classes only; no public API or schema changes. Customer: no new payload/Flutter changes. Driver: no new payload/Flutter changes. Van: no new payload/Flutter changes. B2B=VAN ONLY and B2C=DRIVER ONLY preserved. AR/EN labels and accessibility unchanged.

Acceptance requires latest exact-head GitHub Policy, V2 Isolation, full Required CI (Laravel/Pint/PHPStan/security/localization/MySQL) success, source review and a checked merge into `Foodex_V2`. Parent P112 / G005 must remain OPEN until full signed package + fresh-install and real upgrade/recovery acceptance. Not a production installation or release claim.
