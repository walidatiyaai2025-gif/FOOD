# FOODEX V2 #3367 — installer public storage symlink destination

## Source and delivery boundary

- Scope: G004 / P112 Laravel one-ZIP web installer in #2745, release umbrella #2846 and V2 master #2503.
- **Target:** `Foodex_V2` only. No `main`, cPanel deployment, release/tag or production changes.
- The step-8 `InstallerWorkflow::saveStorageConfiguration` accepted any preexisting `public/storage` symbolic link because it checked `is_link` without checking where the link resolved. A dangling link also passed the same condition. This could make the installer report public uploads ready while `public/storage` pointed somewhere else or nowhere. It is a local filesystem trust/setup correctness gap, not an assertion of a remotely exploitable vulnerability.

## Minimal safety correction

- Reuse the existing step-8 `FILESYSTEM_DISK` allowlist and Laravel `storage:link` mechanism; no new route, screen, translation, host shell command or storage backend.
- If the path is a symbolic link, compare its resolved canonical destination with `realpath(storage_path('app/public'))`; reject foreign/dangling destinations with a generic, path-free installer error before the step can advance.
- A correct absolute or relative symbolic link is kept. A pre-existing **real directory** is still accepted for a restricted shared host where symlinks are disallowed. The existing path is never unlinked, moved, overwritten or followed to delete external data.
- Check the path **after** the optional Laravel `storage:link` call, so newly created links also meet the same destination invariant. Existing runtime file paths, `.env` symlink guards and installer lock are unchanged.

## Test and acceptance matrix

`backend/tests/Feature/V2InstallerPublicStorageLinkBoundaryTest.php` uses a random isolated `storage/framework/testing` public-path fixture and restores the actual app public path in teardown. Every test creates only synthetic, temporary filesystem entries.

| Case | Expected |
| --- | --- |
| Existing symlink to a foreign directory with a sentinel file | Generic RuntimeException; no trusted-target claim; link and sentinel unchanged |
| Dangling preexisting symlink | Generic RuntimeException; no unlink or replacement of the symlink |
| Existing **relative** symlink to canonical `storage/app/public` | Succeeds; `FILESYSTEM_DISK=public`; link destination unchanged |
| Existing real directory `public/storage` (shared-host compatibility) | Succeeds without attempting to replace it |

There is no Dashboard source change beyond the existing Installer Step 8 validation response. No route, translation key, visual component or permission change. The existing 422 form-error mechanism renders the safe message in both selected locale shells; no new English-only label is added to production pages.

**Mobile impact: Customer / Driver / Van:** each **not affected**; no public API shape or Flutter code touched. Customer orders and invoices unchanged; Driver B2C-only dispatch unchanged; Van B2B-only stock/route/dispatch unchanged. No mobile issue needed for this server-local setup validation.

## Required CI and incomplete parent gates

On the original #3367 branch/PR, verify exact-head `Repository Policy`, `FOODEX V2 Isolation Gate` and `FOODEX Required CI Gate`, including this Laravel test, PHP syntax/Pint/PHPStan/security, AR/EN and real MySQL/Redis acceptance. Record actual run IDs and merge SHA in the issue after green — never infer success from an old head.

The atomic #3367 correction does **not** produce a signed Setup ZIP, validate Composer production dependencies/assets, complete the 15-step installer, prove a clean installation from the packaged ZIP, or certify Update ZIP signature/backup/recovery. Therefore **#2745, #2846 and #2503 remain OPEN**. Final browser/device/manual visual UAT is deferred to V2 final; automated security/packaged install gates are not waived.
