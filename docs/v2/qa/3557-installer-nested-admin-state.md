# V2 #3557 — Fail closed on malformed saved installer admin state

Parents: #2745 (G004 Setup ZIP), #2846 (P112 Web Installer) and master #2503. Integration **Foodex_V2 ONLY**. No production ZIP, main merge, release, cPanel operation or deployment.

## Source discrepancy
`InstallState::progress()` previously treated a persisted `admin` non-null JSON value with missing or invalid required keys as absent admin (`null`). A subsequent `markStepComplete()` checkpoint could rewrite that corrupt object to `null`, losing forensic recovery evidence of a previously configured Super Admin; the existing token might be preserved, masking corruption. Only canonical `null` or a JSON object with four string fields (`name`, `email`, `password_hash`, `locale`) is a valid saved state. Existing #3551 already validates the **top-level** keys but does not validate this nested object. This is static source integrity analysis, **not** a demonstrated production incident or privilege bypass.

## Fix and safety
`backend/app/Domain/Installer/InstallState.php::progress()` now rejects any non-null `admin` that is not an object, lacks any canonical required field or has a non-string field. The existing generic error `Installer progress state is invalid.` is preserved and intentionally does not disclose credentials. Unknown extra object keys are allowed for forward compatibility. `admin:null` first-run flow and existing complete stored-admin values still work; no migration, new installer step, schema, permission, route or translation change.

Real isolated `backend/tests/Feature/V2InstallerIncompleteProgressObjectGuardTest.php` cases reuse the existing temp-state fixture and cover empty/missing-field/bad-type/list/scalar admin in otherwise valid saved JSON. Progress read, token creation, step checkpoint and Web Installer HTTP GET must reject without altering saved progress, setup `.env`, token lock or installed lock. Valid saved `admin:null` and existing complete admin positive tests remain. No live user data or secrets are placed in repository evidence.

## Surface matrix
- **Backend Web Installer:** affected state integrity guard and HTTP error handling, same safe exception path; no new UI controls.
- **Dashboard/Admin Update ZIP:** no Dashboard route/API/permission change; existing install/upgrade/recovery CI must remain green.
- **Customer:** no public API/Flutter/DTO change, B2B and B2C fulfillment unaffected.
- **Driver:** no API/Flutter change, B2C=DRIVER ONLY.
- **Van:** no API/Flutter change, B2B=VAN ONLY.
- **AR/EN, RTL/LTR:** no new user-facing message or screen; preserve existing installer localization and styling.

## Exact-head verification / release boundary
Require terminal SUCCESS of Repository Policy, FOODEX V2 Isolation Gate and FOODEX Required CI Gate including PHP lint/Pint/PHPStan/Composer security/OpenAPI, full Laravel regressions, AR/EN quality and real MySQL/Redis install/upgrade/recovery where applicable on latest PR SHA. Close only this atomic child after verified integration SHA, citing CI runs; parents stay open for complete signed Setup/Update ZIP and final staging/manual acceptance. No release/production success claim.
