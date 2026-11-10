# V2 P375 — Sidebar cannot grant what RBAC denies (Issue #2896)

- **Source**: `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx`, P375; parent #2503
- **Integration branch**: `Foodex_V2` only. Do not merge into `main`, tag, publish, deploy, seed live data or alter production
- **Authority**: Laravel HTTP middleware/policies, `User::hasPermission`, `TenantContextResolver`, canonical `AdminNavigation` and module entitlement checks are the source of truth. Sidebar layout, ordering, labels and role-specific visibility are **presentation-only** and may only restrict already-authorized links

## Reuse → Extend decision

**Domain:** Dashboard navigation security, **entity:** Sidebar role visibility and active user, **actor:** any authenticated Dashboard user, **goal:** browse only role/channel/store/module-authorized pages. **Entry point:** existing authorized Sidebar / `ADMIN_SETTINGS` for role admins. **Route:** existing routes and policies, notably `admin.sidebar-access.roles`, `admin.catalog.index`, `admin.b2b.module` and `admin.b2c.module`. **Pattern:** Reuse canonical route-authority projection, extend fail-closed check inside existing `AdminSidebarManager::apply`. No new route, setting, form, module, entitlement, UI component, or independent permission check.

`AdminSidebarManager::apply` now rejects **all** Sidebar links when `user.is_active=false`, before the Platform Owner inventory exception. The exception still remains for **active, internally registered** Platform Owners only. No RBAC data or role-scoped user override is modified by this code.

## Direct-route and negative acceptance

`backend/tests/Feature/V2SidebarNoRbacEscalationTest.php` covers:

1. Active B2B actor sees valid navigation, but the same actor becomes zero-destination immediately when deactivated (including through the direct Sidebar application service, even if stale valid group data is supplied)
2. A forged positive row in `admin_sidebar_role_visibility` and forged enabled/moved layout entry do **not** make `roles.manage` available; the Sidebar does not show that entry, role preview records it as base-denied, and the actual management HTTP route returns **403**
3. B2C inventory user with a forged B2B visible row still cannot open B2B product route; their assigned B2C store route is valid, a foreign store is denied (403/404), in both **AR/EN**
4. Existing active authorized navigation and HTTP route still work with correct AR RTL / EN LTR HTML; protections do not silently remove legitimate pages

The existing V2 tests for stale/dead routes, role and group visibility, modules, maker/checker and multi-role/store isolation remain in place. No CSS/Blade changes are made; existing responsive 320–1280px, keyboard, focus, accessible labels and RTL/LTR contracts are unchanged. Do not claim a browser/visual or screen-reader test was exercised where CI has only HTTP feature assertions.

## Cross-surface impact matrix

| Surface | Impact | Evidence |
| --- | --- | --- |
| Laravel Backend | Affected: defensive Sidebar projection guard | Inactive actor returns no links; actual HTTP RBAC remains authoritative |
| Dashboard | Affected: inactive-user visibility only | Existing Sidebar no longer projects links for inactive actor; no new screen |
| Customer Flutter | Not affected | No mobile API route, authentication payload, entitlement semantics or Dart files changed |
| Driver Flutter | Not affected | No mobile API route, dispatch state, payload, deep-link or Dart files changed |
| Van Flutter | Not affected | No mobile API route, van-only fulfillment, payload, deep-link or Dart files changed |

No mobile work items are generated because no app contract or implementation changes. Separate mobile issues remain required for any future true affected API contract.

## Release / CI evidence

Execute Laravel Feature test `php artisan test --filter=V2SidebarNoRbacEscalationTest`, Pint/PHPStan/Localization, exact-head required-ci-gate, V2 isolation and MySQL/Redis real install/upgrade/recovery jobs. Record actual run URLs and outcome in Issue #2896 and PR. Failures remain blockers; merge only into `Foodex_V2` after **every required** check succeeds. No change to production ZIP artifacts, runtime database schema or release pipeline.
