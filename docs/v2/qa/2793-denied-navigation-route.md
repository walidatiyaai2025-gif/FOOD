# V2 #2793 — P041 Sidebar denial and authoritative HTTP authorization

**Source:** `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx` P041; parent #2503. Implementation target: `Foodex_V2` only.

## Placement and authority

- Domain: Administration / RBAC governance. Entity: role-based Sidebar administration and user permission overrides.
- Actors: delegated global Security/RBAC operator, B2B operator, Super Admin as the authorized grant/revocation actor.
- Existing entry points: `admin.sidebar-access.roles` (`/admin/sidebar-access`) and `admin.sidebar-layout.index` (`/admin/sidebar-layout`) in the Administration Sidebar group.
- Navigation: **Reuse** existing server-filtered `AdminNavigation::groupsFor`. No new screen, route, permission, Sidebar toggle, separate visibility policy, or frontend JS.
- Canonical route guard: `SidebarManagementController` calls `Gate::authorize('roles.manage')` for read and update; `User::hasPermission` resolves inherited role grants against global DENY override.
- Proven scope: global role `roles.manage`, per-user `user_permission_overrides` with `scope_key=global`. The tests verify **both** loss of effective permission and the real HTTP 403, not merely disappearance from the menu.
- Channel: delegated user also has `B2B_ADMIN` for authenticated wholesale Dashboard access; B2C direct URL still 403. Store-specific overrides are not generalized into global roles; tenant and store policy remains authoritative.
- Module readiness / entitlements: these two administration routes are not ERP module routes and do not depend on an ERP readiness toggle. Their `roles.manage` Gate remains mandatory regardless of global navigation layout settings.
- Finance maker/checker, protected Platform Owner, mobile fulfillment and commercial access untouched.

## Acceptance evidence

`V2DeniedNavigationRouteAuthorizationTest` uses actual seeded permission/role tables and the protected HTTP `admin.security.users.permissions` endpoint to create and remove a single delegated user's personal DENY.

1. Before DENY, a permitted role exposes Sidebar Role Access and Sidebar Layout; GET Role Access succeeds.
2. After DENY, the same user loses both links, both GET routes return 403, and a PUT to the RBAC Sidebar role-visibility route returns 403 **without an unauthorized audit mutation**.
3. Role-template permissions are unchanged, a second member of the same role retains access, and the authorized permissions mutation is audited.
4. Restoring the inherited permission removes the personal DENY and restores the authorized Sidebar entry.
5. A wholesale operator lacking `roles.manage` sees neither link on AR/RTL and EN/LTR Dashboard, cannot access both routes even with a typed URL, and cannot access B2C dashboard directly.
6. `AdministrationHubTest` and `AdminSidebarManagementTest` remain the existing responsive, accessibility, discoverability and role/layout contract tests. No rendering changes or new strings are introduced.

Pass criteria: focused tests, full Laravel test suite, PHP syntax, Pint, static analysis, AR/EN localization, real MySQL/Redis install/upgrade/recovery acceptance, exact-head `FOODEX Required CI Gate`, `Repository Policy`, and `FOODEX V2 Isolation Gate`. Report each result from GitHub rather than assuming success.

## Cross-application impact

| Surface | Impact | Evidence |
| --- | --- | --- |
| Laravel Backend/Dashboard | Tests only | Exercises existing RBAC mutations, existing Sidebar projection, real GET/PUT route guards and audit |
| Customer Flutter | Not affected | No API path, schema, client, customer token, order or contract changed |
| Driver Flutter | Not affected | No driver login, status, assignment, location or API contract changed |
| Van Flutter | Not affected | No Van API, territory, visits, dispatch or fulfillment changed |

No Customer/Driver/Van issue is necessary for regression-only coverage. B2B VAN-only and B2C DRIVER-only hard policies remain unchanged.

## Isolation and handoff

One Issue #2793 / one branch `feat/2793-v2-deny-sidebar-route-authorization` / one PR with base `Foodex_V2`. No `main` push/merge, tag, release artifact or production deploy. Close #2793 only after exact-head checks and verified isolated integration; master #2503 remains open.
