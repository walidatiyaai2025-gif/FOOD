# V2 — Sidebar Migration T356R12: Page Tree & Permissions

**Source:** `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx`, table `T356R12`. **Issue:** #2729; master #2503. **Integration:** `Foodex_V2` ONLY; no `main` change, tag, release, or production deployment.

## Resolution: Reuse the existing authorized governance screen

- **Domain/entity:** Administration / role-based Sidebar page tree and visibility rules.
- **User/goal:** Authorized security administrator reviews the page/role permission matrix and controls *presentation only*, never underlying RBAC grants.
- **Workflow/pattern:** Occasional administrator read/manage; existing master/detail selection, no new page.
- **Existing parent/placement:** Administration group → `sidebar_role_access` key, navigation type **SIDEBAR**, canonical `admin.sidebar-access.roles`, existing `SidebarManagementController::roleAccess()`.
- **Navigation owner/authority:** `AdminNavigation::groupsFor()` projects `roles.manage`; existing `SidebarManagementController` read and update operations call `Gate::authorize('roles.manage')`; role visibility cannot override missing server permission.
- **Label migration:** Existing `sidebar_admin.role_access_nav` and `sidebar_admin.role_access_title` now display **شجرة الصفحات والصلاحيات / Page Tree & Permissions**. Shared `admin.nav_groups.administration` group remains **الإدارة / Administration**. Retain `sidebar_role_access`, route names, request formats, RBAC, protected items, audit records, controllers and layout behavior unchanged.
- **RTL/LTR and responsive:** Inherit the existing FOODEX Dashboard shell, Sidebar, labels, form and responsive layout. No new DOM, CSS or JS. The real Laravel HTML tests assert AR RTL and EN LTR; viewport/touch/focus/screen-reader acceptance requires separate isolated staging browser evidence.
- **Fallback/back:** Existing Administration navigation and Dashboard shell; direct URL cannot bypass the Gate.

## Server authorization / scope test matrix

| Actor | Projected entry | HTTP read / update |
| --- | --- | --- |
| SUPER_ADMIN with `roles.manage` | Exactly one `sidebar_role_access` under Administration, canonical named route, translated human name | GET 200 in AR/EN; standard existing update flow unchanged |
| B2B_ADMIN without `roles.manage` | Hidden | GET 403; forged PUT 403, no visibility row or audit change |
| RETAIL_INVENTORY assigned Retail store | Hidden despite valid B2C context | GET 403; forged PUT 403; no cross-channel privilege escalation |
| Authenticated user with no role | Hidden | GET 403; forged PUT 403 |

Regression test `backend/tests/Feature/V2PageTreePermissionsSidebarMigrationTest.php` exercises the same existing route/permission in real Laravel HTTP in both locales, with seeded roles and store assignments; `AdminSidebarManagementTest` updates the existing Arabic rendered heading assertion. Existing #2793 and #2896 authorization tests remain the canonical deeper tests of role denies, SoD and navigation projection.

## Impacts

| Surface | Classification / justification |
| --- | --- |
| Backend | **Affected — display translations and automated tests only**; no API, route, middleware, migration, security policy, audit event or mutation logic changed |
| Dashboard | **Affected — Sidebar item and governance page title**. One existing screen reused, no duplicate, no responsive layout edit |
| Customer Flutter | **Not affected:** no customer API/schema/entitlement/route/payload/Flutter files changed |
| Driver Flutter | **Not affected:** no delivery, collection, dispatch, API or Flutter files changed |
| Van Flutter | **Not affected:** no van assignment, route, inventory, API or Flutter files changed |

No app-specific V2 mobile issue is required for this Dashboard-only naming change. Fulfillment rules **B2B=Van only / B2C=Driver only**, data isolation, supplier/credit and finance maker/checker remain unchanged.

## CI and delivery

Verify from `backend/`: `php artisan test --filter=V2PageTreePermissionsSidebarMigrationTest`, `php artisan test --filter=AdminSidebarManagementTest`, `vendor/bin/pint --test tests/Feature/V2PageTreePermissionsSidebarMigrationTest.php`.

Require green **exact-head** Repository Policy, FOODEX V2 Isolation, Required CI Gate, Laravel tests, Pint/PHPStan/security/OpenAPI, localization AR/EN and real MySQL/Redis installation/upgrade/recovery checks before isolated merge. Record PR and merge SHA in issue #2729. Do not close until outstanding isolated staging real-browser mobile/desktop/RTL-LTR/accessibility evidence is accepted; never imply production verification.
