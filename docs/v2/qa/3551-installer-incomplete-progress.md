# V2 #3551 — Incomplete persisted Installer progress objects

## Source and recovery trust boundary

- **Source:** FOODEX P112 / G004, parents #2745 (Setup ZIP), #2846 (P112) and master #2503. Integration **`Foodex_V2` ONLY**.
- Canonical `InstallState::progress()` reads persisted `install-progress.json`. Prior merged #3435 rejected invalid JSON, scalar and list roots; #3398 normalized unsafe step types. Existing **JSON objects missing a required persisted key** were still silently interpreted as default first-run/partial state.
- Installer-generated documents always contain `completed_step` (integer), `admin` (nullable array) and `token` (nullable hex). When a saved file exists, the absence of any one field is an incomplete snapshot and must not be overwritten by a new token or later step. This is a local persistent integrity guard, not an assertion of remote exploitation.
- The correction rejects only missing required **keys** with the existing generic exception. It does not reject legitimate `admin: null`, normalize malformed existing step types differently, add a separate state format, leak raw JSON or weaken the persistent token/write locks.

## Failure and recovery invariants

The real temporary-state Feature test `V2InstallerIncompleteProgressObjectGuardTest.php` asserts:
1. Existing `{}`, missing token, missing admin and missing completed step are rejected by `progress()`, `token()`, and `markStepComplete()`; original file bytes, `.env`, installer lock and token lock remain unchanged.
2. GET `/install` fails closed rather than regenerating a first-run token from incomplete existing state; no live installation, migration or user data change.
3. Valid three-key progress with an existing admin/token remains readable, and a later normal checkpoint keeps the admin and token; forward-compatible additional keys are ignored as previously.
4. Missing progress file still yields first-run defaults and token creation persists all three required keys.

Recovery of a genuinely corrupted progress file remains a controlled host/operator action. No automatic reset or overwrite is claimed to be safe. No production server installation performed.

## Backend / Dashboard / three-app impact matrix

| Surface | Classification and authority |
| --- | --- |
| Backend setup state | Persisted JSON canonical format strengthened before any progress/token mutations |
| Dashboard/web installer | Existing `/install` failure behavior retained for malformed saved state; no new UI, routes or AR/EN strings |
| API | No public API schema or new permissions; existing setup access and lock handling preserved |
| Customer | No Flutter/API/identity/order change |
| Driver | No Flutter/API/dispatch change; B2C → DRIVER ONLY retained |
| Van | No Flutter/API/routes/territories change; B2B → VAN ONLY retained |

Reuses existing install-state service and web wizard (no new Screen/Sidebar). Existing AR/EN, RTL/LTR and accessible setup UX unchanged. No new RBAC grants.

## Required CI/closure

At the final PR SHA: Policy, V2 Isolation, FOODEX Required CI (PHP syntax/Pint/PHPStan/Composer security/OpenAPI, fresh migration/seed, full Laravel tests including this new Feature suite, AR/EN, real disposable MySQL/Redis install-upgrade-recovery and terminal `required-ci-gate`) all SUCCESS. Only then guarded merge to Foodex_V2 and source-leaf closure with CI/commit evidence. Signed Setup ZIP, final web installation/staging, Update ZIP release and comprehensive acceptance remain on parent gates #2745/#2846/#2503. Do not claim production readiness.

NO `main` integration, release/tag, APK distribution, cPanel, production or deployment.
