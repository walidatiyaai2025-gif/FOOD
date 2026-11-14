# V2 #3553 — Dotenv reserved scalar literal preservation

## Source and user impact

- Source: P112/G004 Web Installer. Parent #2745 signed Setup ZIP, #2846 Setup/Update P112 and #2503 isolated V2 master remain OPEN. Integration **Foodex_V2 ONLY**.
- `InstallerEnvironment::encodeValue()` previously serialized alphanumeric user input `null`, `true`, `false`, `empty` without quotes. `Laravel env()` interprets these (case-insensitively) as special non-string scalars, even though the installer's own `InstallerEnvironment::read()` returns the literal value. A valid literal database, Redis or SMTP password can therefore be altered when the application reads its configuration.
- **Important compatibility:** `InstallerWorkflow::saveCacheQueueConfiguration()` intentionally wrote unquoted `REDIS_PASSWORD=null` when **no** Redis password was entered. Quoting that absence sentinel would cause a Redis connection to attempt authentication with the string `null`. The fix must distinguish *typed null (no password)* from *string `'null'` (actual password)*.

## Implementation and contracts

1. Canonical env serializer quotes strings whose value is exactly one of Laravel's reserved `true/false/null/empty` tokens (case-insensitive), preserving original spelling, quoting, escaping and duplicate managed-key replacement.
2. A missing Redis password is represented internally as typed `null` from the existing cache/queue wizard; the serializer maps **only** the combination `REDIS_PASSWORD` + typed null to unquoted `REDIS_PASSWORD=null`. Actual user-entered string `'null'` or `'FALSE'` is quoted. Generic typed null on other config keys retains existing blank serialization; no DB/mail config policy changes.
3. No direct Redis, database or mail connection is made in this test; synthetic values are used and all paths are isolated under `storage/framework/testing`.

## Automated QA

`backend/tests/Feature/V2InstallerEnvReservedLiteralTest.php` uses temp `.env` plus a dedicated process-safe lock and verifies:
- Upper/lower/mixed reserved scalar strings written as literal **quoted** passwords/app names; installer `read()` roundtrip, only one managed assignment and private file mode `0600`.
- Passwordless Redis setup writes unquoted `null`; explicitly typed string `null` writes `"null"`; mixed-case string `FALSE` stays quoted, and switching back to no password recovers the unquoted sentinel without duplicate keys.
- Ordinary names, hostnames, plain password and blank values retain prior serialization; generic typed null retains blank behavior.

Required exact-final-head Policy, V2 Isolation and Required CI: PHP syntax/Pint/PHPStan/Composer security/OpenAPI; focused/full Laravel; AR/EN; disposable MySQL/Redis fresh install/upgrade/recovery; terminal `required-ci-gate`. Every check must be SUCCESS before guarded Foodex_V2 squash merge and atomic leaf closure. If required tests fail, repair this original issue branch/PR. No waiver or self-approved baseline.

## Backend / Dashboard / Customer / Driver / Van

| Surface | Impact |
| --- | --- |
| Backend installer | Safer `.env` scalar serialization for literal credentials; Redis absence sentinel preserved |
| Web setup/Dashboard | Reuses existing database/cache/queue/mail forms, step contract and error UX; no new screens, text, AR/EN strings or routes |
| API and permissions | No public API, auth, role or entitlement change |
| Customer | No Flutter/API/user/cart change |
| Driver | No Flutter/API/wallet/dispatch change; B2C=DRIVER ONLY |
| Van | No Flutter/API/territory/stock change; B2B=VAN ONLY |

No changes to protected live `.env`, uploads, users or production credentials. Actual signed Setup ZIP artifact and clean cPanel/shared-host install, backed-up Upgrade ZIP, runtime smoke and final manual/staging UAT remain on #2745/#2846/#2503. **No `main`, tag, release, cPanel, deployment or production.**
