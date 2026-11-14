# V2 #3569 — Reject malformed persisted Setup token

## Source and integration boundary
- Parents: #2745 (G004 signed Setup ZIP), #2846 (P112 installer/update/recovery), #2503 (full V2 acceptance). **Only Foodex_V2**, not main, tags, release, production, cPanel or APK publication.
- Existing file: `backend/app/Domain/Installer/InstallState.php::progress()`.
- Previous protections #3435 (invalid JSON/root), #3551 (incomplete root keys), #3557 (malformed admin) are already integrated; this is a separate persisted **token** integrity condition.

## Verified source discrepancy
Canonical installer token writes save either `null` before initialization or a 64-character lowercase hexadecimal random token. Previously, any present non-null malformed token (invalid string/list/object/boolean) was silently converted to null; `token()` then generated and saved a new token, erasing the malformed persisted snapshot. This is a source-level damaged local-state/recovery integrity gap, not a claim of a live remote exploit or unauthorized filesystem modification.

## Bounded implementation
- A non-null saved token must be a string matching `^[a-f0-9]{64}$`; otherwise use the existing generic `RuntimeException('Installer progress state is invalid.')` before any lock or progress write. Do not echo source bytes, token or environment secrets.
- Explicit `token:null` still permits one securely generated initial token. Existing valid token, nested admin, completed step, locks, symlink checks, .env and installer frontend unchanged.
- No new route, migration, database schema, PHP extension requirement or translation, and no changes to Web Installer steps.

## Real isolated regression
The existing `backend/tests/Feature/V2InstallerIncompleteProgressObjectGuardTest.php` tests now include:
- Malformed non-null saved token cases: blank, too short, nonhex, uppercase, boolean, numeric, JSON list/object. Both `progress()`, `token()`, `markStepComplete()` and Web `GET /install` must refuse invalid state.
- Byte-for-byte saved progress and isolated `.env` remain unchanged, and no installer token/installed lock appears after each invalid case. Real app files are never touched.
- A valid explicitly null token with saved complete admin + step 4 becomes one random secure 64hex token, preserving admin fields and progress. Existing valid token tests remain.

## Surface impact
| Surface | Impact |
| --- | --- |
| Backend Web Installer | Affected: fail closed on corrupt saved token, same generic existing error |
| Dashboard Admin Update | Not affected: no update route/package mutation; installation validation suite covers compatibility |
| Customer Flutter | Not affected: no public API, app route, session model or DTO change |
| Driver Flutter | Not affected: no public API, app route or wallet/dispatch change; B2C DRIVER ONLY |
| Van Flutter | Not affected: no public API, app route or custody change; B2B VAN ONLY |
| Localization/RTL | Existing error route and AR/EN/UI unchanged; no new copy or elements |

## Required evidence to close
Exact-final-head Repository Policy, FOODEX V2 Isolation Gate and terminal FOODEX Required CI Gate must be **all SUCCESS**; full Laravel/PHP lint/Pint/PHPStan/security/AR-EN and MySQL/Redis fresh install/upgrade/recovery where applicable. Then verify reviewed original PR base, guarded merge into Foodex_V2, re-read integration source, document tested/integrated SHA and CI links before closing only #3569 as CODE_COMPLETE / FINAL_ACCEPTANCE_DEFERRED. Parent gates remain open for signed Setup/Update ZIP and manual/staging UAT. No production sign-off.
