# FOODEX V2 #3475 — controller ZIP migration scan member budget

**Parents:** #2746 (G005), #2846 (P112), #2503. **Integration:** Foodex_V2 only; no main, production, release or tags.

## Independent source finding
The Super Admin POST system-update handler calls `packageContainsMigrations()` on the uploaded file **before** the manager invokes validator and runtime preflight. Its previous `ZipArchive::numFiles` loop had no member cap. Guards from merged #3441 and #3449 execute later and cannot bound this early upload-time scan. This is a bounded authenticated upload resource risk, not verified remote code execution or unauthenticated access.

## Minimal safe change
- Apply the same positive `foodex.update_max_zip_members` budget (50,000 default) immediately after ZIP open and before the first name enumeration, counting directories and zero-length entries. Zero/negative configured limits clamp to one.
- Reject unreadable ZIP / absent ZIP support; always close a successfully opened ZIP.
- Preserve automatic migration detection with trimmed names, Windows path separators and case-insensitive PHP extension.
- Move manifest construction into the existing controller `try` so early errors return the preexisting translated `admin.system_update_surface.not_applied` message and unlink the private uploaded file in `finally`. No error text, route, privilege or layout added.

## Tests and evidence
`backend/tests/Feature/V2SystemUpdateControllerZipBudgetTest.php` uses real synthetic ZIPs for four boundary regressions: budget rejection before `UpdateHistory`/backup/maintenance, invalid ZIP no-mutation and upload cleanup, exact-limit + migration alias, and nonpositive config fail-closed. The test uses a synthetic Super Admin fixture and no production data. Run focused PHPUnit and full required CI before claiming success; this document alone is not CI proof.

| Surface | Result |
| --- | --- |
| Backend | Admin upload-time ZIP auto-detection bounded before validator |
| Dashboard | Existing Super Admin Update Center and translated failure path, no UI/route changes |
| Customer | No public API, Flutter, store or identity delta |
| Driver | No B2C dispatch/API/Flutter delta |
| Van | No B2B route/custody/API/Flutter delta |
| AR/EN, RTL/LTR | Existing translated generic rejection and layout; no new key or copy |

Full signed package, Setup ZIP/Upgrade, backup recovery and staging/UAT remain parent gates. B2B = VAN ONLY, B2C = DRIVER ONLY remain unchanged.

**Merge gate:** exact-final-head Repository Policy, FOODEX V2 Isolation, FOODEX Required CI (Laravel full + PHP/Pint/PHPStan/security + AR/EN + MySQL/Redis relevant jobs) must all be terminal SUCCESS. No main merge, production publishing, or release.
