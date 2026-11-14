# V2 #3369 — APP_KEY idempotency for completed web installer step 13

## Source and scope
- Parent gates: #2745 (G004 Setup ZIP), #2846 (P112), #2503.
- Preflight: `AGENTS.md` and `docs/v2/V2_ISOLATION_GOVERNANCE.md` on `Foodex_V2`; existing controller, installer state, workflow and tests; open PR file ownership. #3367 owns InstallerWorkflow.php and this issue **does not modify it**.
- Real route: POST `/install/step/13`, protected by the existing installer token and sequential-progress gate. The controller accepts already completed steps, while the workflow generates a fresh random `APP_KEY` on each call. Resubmitting completed step 13 therefore rotates the key before finishing setup.

## Fix
The controller calls key generation **only if** `InstallState::progress()['completed_step'] < 13`. A repeated, authenticated POST after step 13 is marked complete continues to step 14 without rotating the existing key. First-time generation is unchanged, as are installer token checks and ordered step enforcement. No change to `.env` parsing, installer storage links, migrations, seeders, scheduler or update packages.

## Acceptance and regression
`backend/tests/Feature/V2InstallerAppKeyReplayTest.php` exercises:
- Real first POST generates a proper base64 32-byte APP_KEY and persists completed step 13.
- Replaying step 13 with the same valid setup token preserves the identical key and allows ciphertext encrypted after the first request to be decrypted.
- Invalid setup token returns 419, preserves key and progress.
- Requesting step 13 before prerequisite steps returns 409 with no key generated.

The tests use a disposable installer `.env` and progress file under Laravel's testing storage, leave production `.env` and real accounts untouched, and remove only test-owned files.

## Impact matrix
| Surface | Impact |
| --- | --- |
| Web Installer / Dashboard | Affected: completed setup key generation is idempotent; same UI/route/step order and English labels |
| Backend / Admin | Affected only through first-run installer; no operational API/permission contract changes |
| Customer Flutter | No effect: neither app source nor public Customer API changed |
| Driver Flutter | No effect: Driver API and dispatch status unchanged; B2C Driver only |
| Van Flutter | No effect: Van API/assignments unchanged; B2B Van only |

## Required integration
One PR targeting **`Foodex_V2` ONLY**, exact-head Repository Policy, FOODEX V2 Isolation, Required CI (Laravel, security/Pint/PHPStan, AR/EN, MySQL/Redis installation/upgrade/recovery) all success prior to SHA-guarded merge. Close this atomic issue only on evidence of integrated source; keep parent release readiness gates open until full tested Setup/Update ZIP delivery. **No main/release/tag/production.**
