# V2 P007 / Issue #2752 — Authorized Sidebar route discovery follows real access

Source: FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx, paragraph P007; parent [#2503](https://github.com/walidatiyaai2025-gif/FOOD/issues/2503). Integration: `Foodex_V2` ONLY.

## Requirement and placement

A Dashboard page link is discoverable automatically as soon as the authenticated actor's real role/permission/channel/route scope permits it; it must disappear when that authority is revoked. Sidebar preferences are **not** a second permission system. This issue reuses existing `AdminNavigation::groupsFor`, `User::hasPermission`, B2B Catalog and the protected Dashboard HTTP routes: **Reuse → existing business-domain Catalog group / existing canonical destination**. There is no new screen, route, Sidebar key or raw identifier-based user action.

The existing `AdminNavigation` builds `catalog_management` using `catalog.view` and `admin.catalog.index`, then applies cosmetic Sidebar management to the authorized projection. The existing `AdminSidebarManager` treats empty `admin_sidebar_role_visibility` as no presentation override; no legacy table backfill is required to make a role grant effective. A successful HTTP route—not link presence—is authoritative for access.

## Regression / evidence

New `backend/tests/Feature/V2SidebarAuthorizedRouteDiscoveryTest.php` uses seeded real role `B2B_ADMIN`, genuine `roles` pivot attach/detach and authenticated Laravel route requests:
- Starting roleless, active actor: no `catalog_management` link and `admin.catalog.index` responds 403.
- After real role assignment: `catalog.view` resolves true, canonical Sidebar includes `catalog_management` and route responds 200, with Arabic RTL and English LTR. No visibility table rows are introduced.
- After removing the same role: canonical Sidebar no longer offers Catalog and direct route immediately returns 403. No role grant definitions, user overrides or legacy visibility rows are mutated.
- An active B2B role sees its authorized Catalog, not a Retail inventory link or a role-management page it lacks permission for; real `admin.sidebar-access.roles` is 403 and a cross-channel retail inventory route is 403/404 in both languages.

Test command: `cd backend && php artisan test --filter=V2SidebarAuthorizedRouteDiscoveryTest`. This command and the required full exact-head CI must run successfully before merging. No success is claimed by merely creating the tests.

## Scope / impact matrix

| Layer | Classification / proof |
| --- | --- |
| Backend | **Test-only**, authentic Laravel authentication/role grants and protected HTTP 200/403; no API or middleware edit |
| Dashboard | Existing authorized Sidebar and Catalog route, AR/EN + RTL/LTR and explicit denial paths; no new UI |
| Customer | No Flutter, API, auth, notifications or payload changes: **not affected** |
| Driver | No Flutter, API, dispatch, wallet, session or payload changes: **not affected** |
| Van | No Flutter, API, B2B routing, dispatch, identity or payload changes: **not affected** |

No user/store/tenant data migration or role reconfiguration is shipped; all test actors are synthetic and transaction-isolated. Related cross-app, denied-override and all-tenant matrices remain within the existing V2 security/mobile workstreams rather than duplicated here.

Required CI: Repository Policy, V2 Isolation, Backend syntax/Pint/PHPStan/security/OpenAPI, full Laravel tests, Arabic/English localization and MySQL/Redis fresh-install/upgrade/recovery, with terminal `required-ci-gate` success on PR final head. Only merge to `Foodex_V2`. Per owner #2503, manual browser/a11y and final integrated program UAT are deferred to V2 final acceptance—not waived. This focused P007 verification does not claim production readiness. Never promote to main, release or deploy.
