# V2 T015R07 / Issue #2765 — Empty legacy role visibility is not an access policy

Source: FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx, Table 15 Row 7. Parent: [#2503](https://github.com/walidatiyaai2025-gif/FOOD/issues/2503). Target branch: `Foodex_V2` exclusively.

## Requirement and implementation boundary

The historical `admin_sidebar_role_visibility` table may contain no rows. Its absence of preferences must **not** deny properly authorized Dashboard navigation or implicitly grant any role. True access comes from `User::hasPermission`, role/store/channel context, `AdminNavigation::groupsFor` canonical filtering, middleware/policies and protected HTTP routes. The existing `AdminSidebarManager::itemVisibleForRoles` defaults to visible only among **previously authorized** routes when no visibility row exists. A saved `is_visible = false` can cosmetically hide an allowed link without changing HTTP grants, and `is_visible = true` cannot create a missing permission.

This issue requires no new page, role, data backfill, legacy table reset, route or configuration. Placement is **Reuse** of the existing Dashboard business-domain Sidebar and existing Catalog/Sidebar-Role-Access routes. No new entry point, permission/entitlement, navigation node or direct URL is introduced.

## Verification cases

New `backend/tests/Feature/V2EmptyRoleVisibilityAuthorizationTest.php`:
1. Empty legacy table: seeded `B2B_ADMIN` with `catalog.view` sees the catalog in canonical authorized navigation and receives HTTP 200, while a roleless active user never sees the Catalog link and gets HTTP 403. Both Arabic RTL and English LTR are covered; route rendering must not auto-create legacy visibility preferences.
2. Persisted visibility rows: `catalog_management=false` hides an authorized link but its real Catalog route stays HTTP 200; `sidebar_role_access=true` never grants `roles.manage` or its HTTP route, which stays 403. Role permissions and user overrides remain unchanged, in both locales.
3. Scope boundaries: B2B Catalog is exercised as a global role. This narrowly scoped legacy-ACL audit changes no B2C store permissions. Separate ongoing V2 tenancy/channel coverage includes #2801, #2779 and #2782; do not use this focused test as proof of comprehensive tenancy acceptance.

Test command: `cd backend && php artisan test --filter=V2EmptyRoleVisibilityAuthorizationTest`. The exact-head GitHub Required CI must additionally pass full Laravel tests, AR/EN localization, security/PHP checks, MySQL/Redis install-upgrade-recovery, and isolation before merge. No test success is asserted here before the CI results exist.

## Impact and governance

| Surface | Impact |
| --- | --- |
| Backend / HTTP authorization | Existing canonical route and permission checks exercised; **no runtime code/API changes** |
| Dashboard | Existing authorized Sidebar presentation and real Catalog / role-admin HTTP guards regression-tested; AR/EN and RTL/LTR |
| Customer app | Not affected — no Flutter/API/auth/dispatch files or payload changes |
| Driver app | Not affected — no Flutter/API/auth/dispatch files or payload changes |
| Van app | Not affected — no Flutter/API/auth/dispatch files or payload changes |

No API contract, mobile routes, dispatch behavior, queue, mail, finance, storage, migration, seed or prod settings change. No new mobile issue is warranted by this test-only scope. User and role records are synthetic and transaction-isolated, not production exports. Cross-tenant / sensitive-action security regressions remain owned by their existing V2 leaf issues.

Manual browser, visual/a11y and final program integration acceptance are deferred to the V2 final program per owner comment on #2503, **not** waived. This audit is limited to proving independence of an empty legacy visibility table, not asserting all role policies or production readiness. No main, release tag, ZIP, deployment or production action.
