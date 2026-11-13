# FOODEX V2 #3346 — Web installer environment ancestor symlink boundary

**Source:** P112 / #2846, G004 #2745, master #2503. Original issue-scoped branch `fix/3346-v2-installer-env-ancestor-symlink`. Integration target **`Foodex_V2` only**. No `main`, release/tag, live cPanel or production action.

## Reproduced source weakness and fix

Before this change `InstallerEnvironment::rejectSymbolicLink()` checked only the `.env` leaf: `is_link($path)`. A regular `.env` inside a symlinked directory would pass and lead `read()`, `write()` or `ensureEnvironmentFile()` through the directory link. That violates P112's safe installer-file boundary and could expose or replace unrelated environment configuration when an attacker can control the configured path's ancestor.

The fix checks each existing or dangling path segment **from the configured `.env` leaf up to (but not including) the trusted configured Laravel application root**, failing closed on any symlinked child ancestor. If a custom installer path is outside the application root, its ancestors are checked to filesystem root. Stop at the application root itself to preserve an operator-managed symlinked *release/application root* used in some VPS deployments; a symlink **inside** that root remains forbidden. No content, password or filesystem path is revealed in the exception.

The existing allowlist for environment keys, literal credential replacement, 0600 atomic replacement, and leaf-symlink rejection remain unchanged. This is a path safety check, not a claim of eliminating TOCTOU attacks from a hostile process that concurrently swaps directories; host filesystem ownership/permissions remain an essential control.

## Executable acceptance

`backend/tests/Feature/V2InstallerEnvAncestorSymlinkBoundaryTest.php` covers:
- A symlinked containing directory with a regular `.env` is denied for both read and write, leaving the outside file byte-for-byte unchanged.
- A dangling symlinked containing directory is denied before `.env` initialization, without creating the symlink target.
- A normal nested directory accepts the existing installer environment read/write flow.

Predecessor tests `InstallerEnvironmentSymlinkBoundaryTest` (#3209) and `V2InstallerEnvLiteralReplacementTest` (#3284) must remain green. Require PHP/Pint/PHPStan, Laravel tests, AR/EN and real MySQL/Redis install/upgrade/recovery in exact-head FOODEX Required CI; Repository Policy and V2 Isolation Gate must also pass. No claim of a tested live cPanel install or production release.

## Scope and cross-surface contract

| Surface | Impact |
| --- | --- |
| Backend Web Installer | **Affected:** fail-closed server filesystem safety check during env read/init/write |
| Dashboard/Admin Updates | Unchanged: no routes, UI, updater execution, backup or migrations touched |
| Customer App | Not affected: no app/API/auth/order/credit contract modified |
| Driver App | Not affected: no Flutter/API/wallet/dispatch change; B2C Driver-only intact |
| Van App | Not affected: no Flutter/API/Van assignment/stock/offline change; B2B Van-only intact |

No new user-facing page, translation key, API route or mobile Issue. Real Browser/hosting manual V2 acceptance remains at #2503/#2745 and is **not** claimed complete from this security regression.
