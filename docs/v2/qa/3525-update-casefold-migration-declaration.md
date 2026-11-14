# V2 #3525 — Case-insensitive ZIP migration manifest declaration

## Requirement and source boundary

- Source P112 / G005. Parents #2746, #2846, #2791 and master #2503. **`Foodex_V2` ONLY.**
- Canonical authority: `UpdatePackageValidator::validate()` verifies the manifest and ZIP before the Admin Update Center may report Verify Package success.
- Existing trim + Windows backslash-to-forward slash canonicalization from #3402 is preserved. Previously, the undeclared-migration scan compared the ZIP member path with the lowercase literal `backend/database/migrations/` **case sensitively**, so `BACKEND/DATABASE/MIGRATIONS/file.PHP` could pass as a falsely migration-free manifest. This is an **early verification mismatch**, not proven database migration execution or bypass: the current updater also requires a verified DB snapshot before activation.

## Change / strict acceptance

Apply ASCII lowercasing to the existing normalized member pathname before comparing both the canonical migrations directory prefix and `.php` suffix. A false `containsMigrations` declaration fails early with the existing error. Preserve the SHA-256 mismatch priority, version compatibility, ZIP member caps, valid release templates and the current explicit true declaration. Do **not** add a second parser, bypass or trust the checksum as publisher signature.

`backend/tests/Unit/V2UpdaterCasefoldMigrationDeclarationTest.php` creates real synthetic ZIPs, checks upper/mixed-case prefix and uppercase PHP extension, padded/backslash mixed-case migration alias, true declaration positive, benign paths with similar names, and SHA-256 integrity rejection. The tests do not apply any ZIP or touch user data.

## Acceptance evidence required

Before issue closure, on exact PR final SHA:
- PHP syntax and Pint formatting for source and test, PHPStan, Composer security audit, OpenAPI.
- Focused `php artisan test --filter=V2UpdaterCasefoldMigrationDeclarationTest` plus full Laravel backend suite.
- Repository Policy, FOODEX V2 Isolation, FOODEX Required CI, AR/EN and disposable MySQL/Redis fresh-install/upgrade/recovery terminal success.
- SHA-guard merge **only** to `Foodex_V2`; note tested source SHA, merge SHA and URLs. Signed Setup/Update release artifacts, package authenticity, staged upgrade and business UAT remain separate open gate obligations.

## Product / security / three-app impact matrix

| Surface | Classification and impact |
| --- | --- |
| Backend | Update ZIP early manifest migration inventory now fails closed on case-aliased migration paths |
| Dashboard | Reuses existing Super Admin Settings → System → System Updates Verify/Install route and error handling; no new navigation or translations |
| API | No HTTP/API contract change or new permissions/entitlements |
| Customer | Not affected: no public API, Flutter, login, order or storefront change |
| Driver | Not affected: no Flutter/wallet/dispatch change; Retail/B2C → DRIVER ONLY preserved |
| Van | Not affected: no Flutter/Van route/assignment change; Wholesale/B2B → VAN ONLY preserved |

There is no new screen or business-facing field; Reuse existing updater path is the correct UI placement. AR/EN and RTL/LTR remain unchanged. RBAC/SoD and private `.env` protections are unchanged. No `main`, release, tag, APK publishing, cPanel or production deployment.
