# V2 #2755 / AUTH P010 — authorized Sidebar group projection

Source: `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx`, P010. Parent #2503. Integration: **Foodex_V2 only**. No main, releases, tags or production.

## Placement and authority

- Domain: Dashboard navigation and authorization; actor: active, authenticated management user.
- Capability: display a business group only if at least one child destination survives canonical route, role/permission, tenant/store, channel, module/entitlement/readiness and presentation filters.
- Existing parent/surface: canonical Sidebar `admin._sidebar`; navigation type `SIDEBAR`; entry point is an authorized group and its route links, never a newly invented route.
- Placement: **Reuse** the existing `AdminNavigation::groupsFor()` → `AdminSidebarManager::apply()` → `admin._sidebar` pipeline. No new renderer, permissions, tables, API or alternate visibility authority.
- Authorization boundary: `AdminNavigation` produces the permitted links, `AdminSidebarManager` may only hide/arrange/translate them, and the final Blade loop renders only surviving groups. `is_enabled` is a layout preference, **not an authorization grant**. Server middleware/policies still guard direct URL requests.
- Role/route example: B2B_ADMIN with `catalog.view` can see `catalog_management` and reach `admin.catalog.index`; a restricted user lacking that permission cannot gain the route through a visible/enabled layout.
- Empty-group rule: invalid route, role-specific presentation exclusion, or inactive user must result in no empty group. Platform Owner's diagnostic inventory remains subject to valid route and active-account checks and must never override data/finance controls.
- Language/accessibility: existing keys `admin.nav_groups.*` resolve Arabic/English independently; authorized destinations stay identical across locale, and existing Blade uses accessible `<nav>`, `<details>`, labels, directional styling and keyboard behavior. No new UI strings or components.

## Regression coverage

`backend/tests/Feature/V2AuthorizedSidebarGroupProjectionTest.php` verifies:

1. An authorized group with one link disappears after an existing role visibility filter hides that link, while canonical `catalog.view` permission remains unchanged.
2. An unprivileged account does not obtain the catalog link or HTTP catalog permission merely because presentation settings are enabled by default.
3. Missing routes and inactive accounts cannot emit empty Sidebar groups.
4. AR/EN projection preserves exactly the same authorized destination keys; each final group has children and each rendered leaf has a registered named route.

Existing `AdminNavigation`, `AdminSidebarManager`, HTTP and security tests remain authoritative for the full channel/tenant/module/entitlement, cross-store DENY, SoD and readiness matrix; this proof does not assert that UI hiding replaces those guards.

## App impact assessment

| Surface | Impact | Reason |
| --- | --- | --- |
| Backend / Dashboard | Focused regression tests | Already implemented Dashboard-only authorized navigation projection; no new route or business mutation |
| Customer | Not affected | No Flutter files, mobile REST endpoints, authentication or customer prices changed |
| Driver | Not affected | No Driver routes, permissions, mobile API or B2C fulfillment changed |
| Van | Not affected | No Van routes, permissions, mobile API or B2B fulfillment changed |

No mobile follow-up issue is needed for these test-only changes. B2B=Van-only and B2C=Driver-only remain unchanged.

## Acceptance and handoff

Run focused Laravel test, Pint, localization, security policy, and all exact-final-head required CI, including V2 Isolation Gate. Merge **only** into `Foodex_V2` after successful checks; record PR, SHA, status and `MANUAL_ACCEPTANCE: DEFERRED_TO_V2_FINAL` in the Issue. Do not claim device/browser staging execution or close if any underlying P010 requirement is unproven.
