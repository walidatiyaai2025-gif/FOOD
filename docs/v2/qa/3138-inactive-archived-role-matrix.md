# V2 #3138 — Fail-closed Sidebar matrix for disabled and archived roles

## Source and ownership
- Source: FOODEX Unified RBAC ERP Sidebar Discussion (2026-11-09), P022. Historical role-code/archive invariant P100; parent #2503.
- #2773 / PR #3130 already delivered the separate canonical HTTP role editor / post-authorization contract. This follow-up addresses only the remaining `AdminSidebarManager::roleMatrix` eligibility discrepancy for inactive or archived roles.
- Integration: isolated `Foodex_V2` only, no `main`, production, tags or cPanel release.
- Reuse (not a second authorization layer): `AdminSidebarManager::roleCanUseItem` and the existing `admin.sidebar-access.roles` view. No new screen, menu entry or API.

## Observed mismatch and minimal fix
Before this patch, `roleMatrix` used a role's historical permission pivot and scope but did not check whether the role was disabled (`is_active=false`) or archived (`archived_at!=null`). This could mark its `base_allowed` / `visible_for_role` flags true even though runtime `roleIdsForUser` excludes inactive assignments. The change returns `false` for either status before evaluating permission labels. All historical role rows, `permission_role` links, saved appearance preferences, and audit history remain untouched.

`backend/tests/Feature/V2InactiveArchivedSidebarRoleMatrixTest.php` verifies:
- an active B2B_ADMIN role retains its permitted customers-group projection;
- a disabled role's retained grant is NOT projected or used to write a cosmetic allow;
- an archived role's retained grant is NOT projected or used to write a cosmetic allow;
- an authenticated SUPER_ADMIN GET of the existing real Sidebar Role Access page receives the disabled role's true fail-closed matrix.

All fixtures are synthetic and use Laravel's disposable test DB, not production identities. No new database migration or authorization bypass exists. The `saveRoleVisibility` negative cases intentionally assert historical grants are preserved and inactive roles do not receive newly saved cosmetic overrides.

## Cross-app impact matrix
| Surface | Changed? | Rationale |
| --- | --- | --- |
| Backend / Dashboard | Yes, read-only role-matrix eligibility and dedicated HTTP/DB regression. | Existing canonical screen and route with `roles.manage`; no role mutation. |
| Customer Flutter | No | No API, auth token, user status or business data contract changed. |
| Driver Flutter | No | No dispatch/driver/notification or HTTP API change. |
| Van Flutter | No | No Van/visit/assignment/notification or HTTP API change. |

Arabic/English, RTL/LTR and accessibility use existing Dashboard UI; this scope adds no visible strings or markup. Existing localization, authorization and module/SoD tests must still pass. Run `php artisan test --filter=V2InactiveArchivedSidebarRoleMatrixTest` and required full CI, including MySQL/Redis fresh install, upgrade and recovery, against the latest exact PR SHA before merge. No local, manual staging/UAT or production acceptance is claimed. Manual acceptance is deferred per #2503.
