# V2 #3499 — Admin Update ZIP protects live dotenv variants

## Scope and authority

- V2 source: P112, atomic child of #2746 / #2846, master #2503; **`Foodex_V2` only**.
- Canonical code: `LaravelUpdateRuntime::safeRelativePath()`, already used by `archiveEntriesFrom()`, preflight, file backup, extraction and rollback. No new authorization or navigation surface.
- Original source guard blocked exact `.env` but accepted non-template variants such as `backend/.env.production` or `backend/.env.backup`; those could replace private live environment/credentials if present in an otherwise accepted archive. This is a source-level weakness, **not an observed production exploit**.

## Behavior

- Reject non-template `.env` and `.env.*` anywhere in an Update ZIP path, including compound suffixes (`.env.production.backup`), case aliases and directory targets.
- Preserve safe source templates ending in exact `.example` (e.g. `backend/.env.example`, `backend/.env.production.example`, `backend/.env.testing.example`). Templates are NOT active credentials and must never include real secrets.
- The `.env*.example` exemption applies **only to a terminal file member**, not a directory or its descendants; `backend/.env.production.example/private.php` is rejected.
- Deny case aliases for protected runtime directories `storage`, `vendor`, `.git`, including on case-insensitive shared-host filesystems.
- Fail closed at ZIP preflight before backup, maintenance, file replacement or migration. No database/schema/package protocol changes.

## Test evidence

`backend/tests/Feature/V2UpdateDotenvArchiveGuardTest.php` builds real temporary ZIP archives and invokes `LaravelUpdateRuntime::preflight()`: rejected live dotenv and protected runtime aliases, positive non-secret templates and normal PHP code. Neither test executes `extractRelease()` or writes the active `.env`.

Verification required on **exact final PR head**:
- `php -l` for PHP source and new test; Pint on both files; PHPStan/security.
- `php artisan test --filter=V2UpdateDotenvArchiveGuardTest` and full applicable Laravel suite.
- Repository Policy, FOODEX V2 Isolation, Required CI, AR/EN localization, MySQL/Redis acceptance as applicable. **No success claim before terminal verified runs.**

## Impact / placement / safety

| Surface | Impact |
| --- | --- |
| Backend | Update ZIP source-path preflight and protected-file safety hardened |
| Dashboard | Existing Settings → System → System Updates Verify/Install flow; same UI/API/error semantics; no new page |
| API | No route or public contract change |
| Customer | Not affected; no public API/Flutter shape change |
| Driver | Not affected; B2C=DRIVER ONLY unchanged |
| Van | Not affected; B2B=VAN ONLY unchanged |

Existing updater Super Admin authorization, backup/recovery steps, signing requirements, audit and SoD are unchanged. This leaf does not claim #2746 signed trusted publisher authenticity or final production readiness. No `main`, production, release, tag or deployment.
