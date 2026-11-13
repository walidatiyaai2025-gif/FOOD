# V2 #3389 — Signed update manifest Semantic Version exactness

Parent #2746 (Admin ZIP Update/Recovery), #2846 (P112 delivery), master #2503. Integration `Foodex_V2` only. No production, main, release or tag.

## Source / cause

`UpdateVersion::isCanonical()` previously applied `trim($version)` before its strict `MAJOR.MINOR.PATCH` expression. A signed manifest declaring `targetVersion='1.0.85 '` or `minimumCurrentVersion=' 1.0.84'` was therefore accepted even though those strings are not canonical. `UpdatePackageValidator` then carried the raw target value forward in the result. Canonical signed manifest metadata must be accepted byte-for-byte, not silently normalized by the recipient.

Further boundary: PHP's PCRE `# V2 #3389 — Signed update manifest Semantic Version exactness

Parent #2746 (Admin ZIP Update/Recovery), #2846 (P112 delivery), master #2503. Integration `Foodex_V2` only. No production, main, release or tag.

## Source / cause

`UpdateVersion::isCanonical()` previously applied `trim($version)` before its strict `MAJOR.MINOR.PATCH` expression. A signed manifest declaring `targetVersion='1.0.85 '` or `minimumCurrentVersion=' 1.0.84'` was therefore accepted even though those strings are not canonical. `UpdatePackageValidator` then carried the raw target value forward in the result. Canonical signed manifest metadata must be accepted byte-for-byte, not silently normalized by the recipient.

 anchor accepts a newline immediately before the end of the subject. Use absolute `\\A` and `\\z` anchors as well as removing trim, so the validator rejects a trailing LF/CR and does not silently accept padded signed metadata.

Scope: remove manifest-version trimming and require absolute version-string matching in `isCanonical`; leave `normalizeInstalled()` untouched for historical stored `1.084` compatibility. Keep checksum comparison, minimum-version policy, upgrade/downgrade checks and all rollback code unchanged. No changes to sibling #3386's owned `UpdatePackageValidator.php`.

## Executable regression

`backend/tests/Unit/V2UpdateCanonicalManifestVersionTest.php`:

- Strict well-formed versions PASS; space/tab/CR/LF padded and leading-zero/prefixed/suffixed forms FAIL.
- Real `UpdatePackageValidator::validate` against SHA-256 validated synthetic test payload rejects whitespace in **either** target or minimum version.
- Legacy persisted `' 1.084 '` still normalizes to `1.0.84` and safely upgrades with a canonical `1.0.85` manifest.
- Same-version/downgrade and unsupported-minimum checks still deny updates.

Run `cd backend && php artisan test --filter=V2UpdateCanonicalManifestVersionTest` with the repository's CI setup; production users should never be asked to run this command. CI also requires Laravel/PHP syntax/Pint/PHPStan/security, AR/EN and real MySQL/Redis install/upgrade/recovery acceptance.

## Application impact and recovery

- Backend: narrow version input validation only; no API route/permission, migration or database change.
- Dashboard: rejects noncanonical metadata during System Updates package validation; existing generic error handling and labels remain.
- Customer: no Flutter/public API change.
- Driver: no Flutter/public API change; B2C driver routing unaffected.
- Van: no Flutter/public API change; B2B van routing unaffected.
- Arabic/English and RTL/LTR: no user-visible string, screen or accessibility contract change.
- Shared-host cPanel/VPS: no composer/terminal action required on production; legacy persisted version normalization preserved.

The validator fails before any update-side mutation when version metadata is invalid. This narrow issue does not certify signed ZIP provenance or the full release system; parent gates #2746/#2846/#2503 remain OPEN pending artifact/hosted acceptance.
