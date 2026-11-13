# V2 #3372 — Installer managed `.env` duplicate assignment safety (G004 / P112)

**Source:** #2745 (G004), #2846 (P112), #2503. **Integration:** `Foodex_V2` only; no production actions or main promotion.

## Verified defect

Before this change, `InstallerEnvironment::write` replaced only the **first** existing assignment of each installer-managed key, but `InstallerEnvironment::read` deliberately iterated all assignments and returned the **last** value. Thus a preexisting duplicate `DB_PASSWORD`, `APP_URL` or `APP_KEY` could survive a successful setup write and appear as an inconsistent/stale value on subsequent reads. A second unsafe detail was using the multiline-sensitive `\\s*` in the key-matching expression.

## Bounded fix and verification

- For each **explicitly written** allowlisted key, locate assignments only on the same line using horizontal whitespace matching; replace the first with the existing literal-safe encoded new value and remove duplicate assignments. Unrelated key names, duplicates outside the requested set, comments and lines remain untouched.
- Keep the existing allowlist, literal replacement rather than regex replacement-string interpolation, symlink-ancestor checks, atomic temp-file/rename, 0600 mode and read-only behavior unchanged.
- Three new synthetic Laravel feature cases under `backend/tests/Feature/V2InstallerEnvDuplicateKeyCanonicalizationTest.php`: stale APP_KEY/DB_PASSWORD/APP_URL elimination (including literal `$1`, `$0`, `$$` credential content), repeat/idempotent writes with unrelated duplicates preserved, and no cross-line match when a key occurs without equals.
- No real deployment secrets, live `.env` files, installer stage transitions or migrations are changed. The installer retains its current error/exception behavior if the target filesystem is not writable; retries preserve the canonical written value. Full Setup ZIP fresh-install and upgrade certification remain the responsibility of open G004/P112 acceptance gates, not this child.

## Impact / acceptance matrix

| Surface | Impact | Rationale |
| --- | --- | --- |
| Backend installer | **Affected** | Existing `.env` write service now canonicalizes duplicate managed assignments. No public API contract or route change. |
| Dashboard | **Affected indirectly** | Existing `/install` setup flow writes the same keys; no new screen, label, permission or UI state. AR/EN and RTL/LTR unchanged. |
| Customer Flutter | **Not affected** | No mobile route, response, authentication, store/channel or binary/asset changes. |
| Driver Flutter | **Not affected** | No Driver API, role, assignment or dispatch change; B2C Driver-only preserved. |
| Van Flutter | **Not affected** | No Van API, territory, assignment or dispatch change; B2B Van-only preserved. |

Security regression must fail before this change and pass after it. Pending exact-head Repository Policy, FOODEX V2 Isolation, Required CI (PHP syntax/Pint/PHPStan, Laravel, localization, real MySQL/Redis install/upgrade/recovery) and SHA-guarded isolated PR merge. This document alone is **not** a CI or release acceptance claim.
