# V2 #3469 — Installer checkpoint read/modify/write concurrency

- **Source:** V2 P112 / #2846, Setup ZIP G004 / #2745, master #2503.
- **Target:** `Foodex_V2` only. No `main`, deployment, tags, release promotion or production changes.

## Verified source defect and threat model

Before this change, `InstallState::token()` protected only initial token creation with a persistent inode-checked `installer_token_lock_path`. `InstallState::markStepComplete()` read the progress JSON and wrote a new JSON document through atomic rename **without the same lock**. Atomic replacement prevents torn JSON but does not serialize two read/modify/write operations. Competing setup POSTs could overwrite a newer `completed_step`, replace a fresh setup token or drop the prepared Super Admin fields. The prerequisite is overlapping authorized web setup requests; production exploitation was not demonstrated.

## Chosen fix

Reuse the existing persistent token-lock inode for *all writes to the progress checkpoint* from initial token creation and `markStepComplete()`. Preserve its symlink and hardlink guards, inode/device/nlink verification, permissions and `flock(LOCK_EX)`. After acquiring the lock, re-read the persisted JSON before computing monotonic `completed_step` and optional `admin` update. Existing `token` is retained. Corrupt progress still fails closed **before creating a lock path**.

This changes neither the persisted JSON schema nor the installed marker / terminal step, and does not run database migrations, seeders or APP_KEY rotation on step replay. An already-installed site remains governed by the same installer HTTP 404 lock check. A persistent lock is necessary because the JSON file itself is replaced on each write.

## Test evidence and regressions

New `backend/tests/Feature/V2InstallerConcurrentProgressCheckpointTest.php`:

1. Independent PHP process announces a checkpoint attempt while the parent holds the persistent token lock. The progress file must remain unchanged until lock release. Parent substitutes a later checkpoint with prepared synthetic admin; child then commits the next step after re-reading under lock; final admin/token are preserved.
2. Older-step replay cannot regress completed step, remove admin or rotate token.
3. A hardlinked persistent lock fails closed for checkpoint mutation and preserves both the progress document and unrelated file.

Existing suites to retain: `V2InstallerConcurrentTokenInitializationTest`, `V2InstallerTokenLockHardlinkGuardTest`, `V2InstallerMalformedProgressFailClosedTest`, `V2InstallerProgressStepTypeGuardTest`, `V2InstallerStateSymlinkBoundaryTest`, and full backend CI. Verify source head and all required GitHub Actions before integration.

## Impact and acceptance boundaries

- **Backend:** setup progress concurrency only; same token and progress file paths, same web actions and errors.
- **Dashboard:** no new authenticated route, navigation or role/tenant permission changes. Existing installer wizard continues unmodified.
- **Customer:** no mobile/API or auth/channel change.
- **Driver:** no mobile/API or B2C dispatch change.
- **Van:** no mobile/API or B2B dispatch change.
- **Localization:** no user-facing messages changed; AR/EN and RTL/LTR behavior remains.
- **Data:** no real database, credentials, uploads or environment changes in the tests; fixture-only synthetic values.

Exact-head Policy, V2 Isolation, Required CI and applicable Laravel/security/localization/MySQL acceptance are mandatory. Manual browser/staging release verification is tracked at #2503; this leaf does **not** certify the full signed Setup ZIP, real clean MySQL package install or production readiness.
