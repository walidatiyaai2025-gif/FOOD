# V2 #3391 — Isolate APP_KEY replay HTTP test's persistent installer token lock

**Source:** FOODEX V2 unified RBAC/ERP/Sidebar G004/P112; parent installer gate #2745 and release gate #2846; master #2503. Related runtime PR: #3378 / #3382, existing merged APP_KEY replay #3369 / #3370. **Target:** `Foodex_V2` ONLY.

## Reproduced exact-head CI failure

- Existing #3382 original tested head `dd5fe4d4a944a50321a2304ccc146b01bc0a321c`: [FOODEX Required CI #43496136416](https://github.com/walidatiyaai2025-gif/FOOD/actions/runs/43496136416), Backend tests job `132132041042`.
- 3563 tests passed (60063 assertions), one failed in `V2InstallerAppKeyReplayTest::test_attempt_to_skip_to_key_generation_before_previous_steps_is_rejected`.
- The failure occurs in fixture **teardown** at old line 47: `rmdir(.../v2-install-key-replay-...): Directory not empty`. It is not an observed failed APP_KEY rotation/invalid-token assertion. The exact source-state of every leftover path was not captured before the CI sandbox was discarded.
- The persistent first-run lock added on the #3378 branch deliberately remains on disk after `flock` release: unlinking it in production can cause workers to lock different inodes. This PR does **not** change that runtime invariant.

## Test-isolation correction

The isolated APP_KEY replay fixture now sets `foodex.installer_token_lock_path` explicitly to `$directory/installer-token.lock`, inside its own randomly named test directory; teardown removes this test-owned lock together with the existing `.env`, `installed.lock` and `install-progress.json` before `rmdir`. The fixture is wholly synthetic and scoped to a private test directory. It never modifies the deployment's live lock and never changes runtime lock creation/unlink policy.

This makes the fixture predictable when the active #3378 branch is integrated, rather than relying on the default token-lock location or another test's config. Existing HTTP assertions continue to exercise first key generation, replay preserving ciphertext, invalid-token 419, out-of-sequence step 409 and unchanged progress.

## Security and app impact matrix

| Surface | Effect |
|---|---|
| Backend | **Test-only:** APP_KEY replay fixture config/teardown. No runtime, route, permission, DB or API modification. |
| Dashboard | Installer runtime and visual UI unchanged. No new strings, AR/EN, RTL or accessibility behavior. |
| Customer | **Not affected:** no Flutter, HTTP API, roles or checkout changes. |
| Driver | **Not affected:** B2C Driver-only and dispatch/proof contracts unchanged. |
| Van | **Not affected:** B2B Van-only and route/visit contracts unchanged. |

## Acceptance and integration

- [ ] Verify exact issue-scoped diff consists solely of `backend/tests/Feature/V2InstallerAppKeyReplayTest.php` plus this QA document.
- [ ] Repository Policy, FOODEX V2 Isolation Gate and FOODEX Required CI all terminal **SUCCESS** on exact PR HEAD, including complete Laravel, PHP syntax/Pint/PHPStan/security, localization AR/EN and MySQL/Redis clean-install/upgrade/recovery where required by the CI plan.
- [ ] SHA-guarded merge into **`Foodex_V2` only**, verify PR merged and branch closed/cleaned, close only #3391 with evidence.
- [ ] Rebase/reconcile #3382's original branch only by its current authorized worker; require **new exact-head CI** on #3382 after this correction has entered Foodex_V2. Do not claim #3378 passed merely because #3391 is green against a different HEAD.

**Non-goals:** No installer key or token rotation, no change to `InstallState`, `InstallerWorkflow`, `InstallerController`, `InstallerEnvironment`, user `.env`, V2 feature tree, production artifacts or `main`. This atomic test fix is not a signed Setup/Update ZIP release or staging certificate.
