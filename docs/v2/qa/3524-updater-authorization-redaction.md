# V2 #3524 — Scheme-independent Authorization secret scrubbing

Parent #2746 / #2846 / #2503; target branch `Foodex_V2` only.

## Verified source defect

The previous `UpdateManager::redactSecrets` Authorization expression masked only the first non-whitespace token for non-Bearer/non-Basic schemes. In a synthetic diagnostic `SQLSTATE[HY000] Authorization: Token token-value-3524 rejected`, the original sanitizer retained `token-value-3524` even after writing `[REDACTED]` over `Token`. The same structure applies to `Digest username="...",response="..."`, custom credentials and challenge fields. `exceptionSummary` is persisted in administrative updater failure history, execution evidence and audit trails, creating a possible diagnostic secret leak. No actual live secret disclosure is claimed.

## Minimal remediation

`backend/app/Domain/Updater/UpdateManager.php::redactSecrets` now masks the **whole remainder** of a string once an `Authorization:` or `Authorization=` label is encountered, regardless of scheme, because upstream `exceptionSummary` already flattens whitespace/newlines into a single diagnostic line and cannot safely identify where unstructured credential fields end. This intentionally sacrifices some same-line diagnostic detail **after** the Authorization label. Diagnostic stage / SQLSTATE **before** the label, nested exception messages, original 1200-character bound, existing URL/env redaction and fail-closed regex behavior remain intact.

This fixes a narrow supplementary failure mode to #3472; it does not alter package validation, signature trust, migrations, maintenance or rollback behavior and must not be used to certify the whole Update ZIP program.

## Negative and compatibility regression

`backend/tests/Unit/V2UpdaterAuthorizationSchemeRedactionTest.php` invokes the actual private sanitizer through the real `exceptionSummary` using reflection. Data providers check Token, Digest with comma-separated fields, Custom, ApiKey, Basic/Bearer and mixed case. Nested causes preserve preceding stage/SQLSTATE and redact both outer authorization credentials and inner DB password. The negative control asserts ordinary SQLSTATE/authorization-denied prose without a credential label is unchanged. No secret files, database, actual audits, external services or real user identities are used.

Local preflight: isolated PHP reproduction of the previous regex exhibited the residual token. Isolated PHP reproduction of the new regex masked the full Authorization tail. Full PHP syntax, Pint, PHPStan, security, AR/EN, Laravel and required MySQL/Redis/Flutter gates are **not to be claimed successful** until exact-final-HEAD GitHub Actions conclude.

## Cross-surface classification

| Surface | Impact |
| --- | --- |
| Laravel Admin Updater | Sanitized error evidence/history/audit text; no update runtime stage transition or new user-visible copy |
| Dashboard | Existing System Updates failure path unchanged, with less secret-bearing detail |
| Customer | No changed API, Flutter source, B2B/B2C customer navigation or money contract |
| Driver | No changed API, Flutter source, B2C dispatch/collection or tokens; DRIVER ONLY |
| Van | No changed API, Flutter source, B2B routes/custody/assignment or tokens; VAN ONLY |

All parents remain OPEN until trusted signed Setup and Update ZIP packages, migration/data-preserving upgrades and recovery, exact artifact provenance and final acceptance are independently certified. No main merge, release, tag, cPanel or production deployment.
