# V2 #3389 — Signed update manifest Semantic Version exactness

Parents #2746 (Admin ZIP Update/Recovery), #2846 (P112 delivery), master #2503. Integration `Foodex_V2` only. No production, main, release or tag.

## Source / cause

`UpdateVersion::isCanonical()` previously applied `trim($version)` before checking `MAJOR.MINOR.PATCH`. A signed manifest could declare `targetVersion='1.0.85 '` or `minimumCurrentVersion=' 1.0.84'`, which would pass canonical validation despite containing noncanonical whitespace. The validator carries the accepted target metadata onward.

PCRE's `$` end anchor can also match just before a final newline. The fix checks the raw manifest string without trimming and uses absolute `\A` and `\z` anchors, rejecting spaces, tabs, CR and LF. Signed manifest values must match canonical SemVer exactly.

`normalizeInstalled()` remains unchanged for legacy persisted `1.084` compatibility. Checksum, minimum-version, downgrade/upgrade and rollback policies are unchanged. No edits to active #3386's `UpdatePackageValidator.php`.
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
