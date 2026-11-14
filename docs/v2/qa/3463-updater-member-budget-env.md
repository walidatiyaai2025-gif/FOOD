# V2 #3463 — cPanel-configurable ZIP member budget (P112 / G005)

Integration: `Foodex_V2` only. Parent #2746 / #2846 / master #2503. No main branch, production deploy, tag, release or cPanel action authorized.

## Canonical defect and solution

The previously merged #3441 / #3449 protections reject archives containing more than `config('foodex.update_max_zip_members', 50000)` entries, including empty files and directories, before enumerating member names. However the Laravel `backend/config/foodex.php` config did not declare the key. On cPanel, setting `FOODEX_UPDATE_MAX_ZIP_MEMBERS` in the environment therefore had no effect, and the services silently used the constant fallback.

Define `foodex.update_max_zip_members = max(1, (int) env('FOODEX_UPDATE_MAX_ZIP_MEMBERS', 50000))` alongside existing expanded ZIP budgets. The default permits regular large Laravel/vendor packages; zero/negative overrides cannot disable the protection. Both existing validator and runtime read the same effective Laravel config key. Use Laravel's normal cached-config rebuild when changing an existing site's .env; no shell commands are required from an installer user.

## Automated acceptance

- `backend/tests/Feature/V2UpdaterMemberBudgetConfigTest.php`: canonical config key exists, is a positive integer, agrees with environment mapping, and overrides to 7, 0 and negative values resolve to 7, 1 and 1.
- Existing `V2UpdaterZipMemberCountBudgetTest` (#3441) and `V2UpdateValidatorMemberCountPreflightTest` (#3449) cover actual ZIP rejection/acceptance for preflight, backup, extraction and earlier validation. These tests are not modified.
- CI evidence: GitHub Repository Policy, FOODEX V2 Isolation and exact-HEAD Required CI including backend syntax/Pint/PHPStan, Laravel tests, security audit and MySQL/Redis checks must be recorded from the final PR. This document does **not** claim an unobserved test pass.

## Impact and security

- Backend/Setup/Admin: only the signed Update ZIP administrative resource budget becomes configurable in the normal Laravel `.env`/cached-config path; no signature, provenance, migration, backup or permission guard is weakened.
- Dashboard: existing Super Admin Update Center and localized error handling unchanged, no new screen/route/permission/navigation. AR/EN and RTL/LTR remain as implemented.
- Customer: no public API, Flutter source or endpoint contract change.
- Driver: no public API, Flutter source, B2C dispatch or collection contract change.
- Van: no public API, Flutter source, B2B dispatch or inventory contract change.
- No user data, tenant status, uploaded files or live settings are mutated by this change.

Issue #3463 can close upon exact-head CI green and verified PR integration into `Foodex_V2`. Parent G005 #2746 and P112 #2846 remain OPEN until signed package, actual upgrade, backup/recovery and final source acceptance have independent evidence. 
