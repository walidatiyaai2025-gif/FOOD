# V2 #3472 — Secret-safe Updater failure and audit diagnostics

Parent G005 #2746, P112 #2846 and master #2503. Integration exclusively `Foodex_V2`; no source publication, tags, production updates or server access.

## Source finding and threat model

`UpdateManager::execute` catches failures during package validation, backup, maintenance, file activation, migrations, database rollback and audit. It persists `exceptionSummary` into `UpdateHistory.failure_reason`, `execution_evidence.failure_cause` and `updater.failed` audit. Before this change, `exceptionSummary` masked Unix absolute paths and enforced 1200 Unicode-character diagnostic limits but kept sensitive values from an upstream exception verbatim. This was a **source-level possible credential exposure**, not proof of a real deployed secret leak, and not a public API privilege bypass.

### Remediation boundary

- Redact credential-containing key/value diagnostic fragments such as `DB_PASSWORD`, `MAIL_PASSWORD`, `REDIS_PASSWORD`, `APP_KEY`, `API_KEY`, `TOKEN`, `SECRET`, `ACCESS_KEY` and `PRIVATE_KEY`, with case-insensitive equals/colon syntax. Mask Bearer and Authorization Basic token values and URL user-info (e.g. `mysql://user:password@host`). Preserve nonsecret SQLSTATE codes, stage, exception type, cause chain and useful operational error.
- Execute redaction **before** existing absolute-path masking and diagnostic truncation. A regex failure fails closed with a generic scrubbed message, never raw credentials.
- No `UpdateManager` stage transitions, lock behavior, rollback semantics, `LaravelUpdateRuntime`, ZIP verification, signatures or database schema are changed.
- This is defense in depth against common credential patterns; arbitrary free-form secrets without identifiable markers may still need upstream sanitized exceptions, and does not constitute HMAC/cryptographic source provenance or full updater security certification.

## Automated acceptance

`backend/tests/Unit/V2UpdaterExceptionSecretRedactionTest.php` is a dependency-free unit test using the real private summary method via reflection, with no database/filesystem operations. It covers 14 representative cases (environment passwords, quoted secrets, bearer/basic authorization, URI user-info, query tokens), nested causes, negative nonsecret SQLSTATE and 1200-character truncation fallback. Required exact-final-head CI must run PHP syntax/Pint/PHPStan, Composer security, Laravel backend tests, AR/EN and MySQL/Redis fresh-install/upgrade/recovery under the existing FOODEX Required CI gate. Record the PR SHA and CI IDs before merging.

## Cross-surface impact

| Surface | Impact |
| --- | --- |
| Backend updater | Sanitizes exception evidence and audit diagnostic text; no mutation semantics |
| Dashboard | Existing restricted Admin System Updates history shows safer failure text; no UI/route/copy changes |
| Customer | No API/Flutter/login/payload/dispatch changes |
| Driver | No API/Flutter/auth/wallet/dispatch changes; B2C DRIVER ONLY retained |
| Van | No API/Flutter/assignment/stock changes; B2B VAN ONLY retained |

Parent #2746 / #2846 / #2503 remain open for signed package trusted source, full MySQL upgrade/data integrity and final human acceptance. This atomic leaf may close only after reviewed PR merges into `Foodex_V2` with all exact-head required checks green.
