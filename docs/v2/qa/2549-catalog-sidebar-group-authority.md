# V2 #2549 — P219 Catalog business Sidebar group

Source: FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx / P219 (Catalog & Pricing). Parent #2503. Target: `Foodex_V2` **only**.

## Source-to-route placement and authorization

This is a **business-domain group**, not a second Catalog app. Resolve the P219 navigation contract through the existing `AdminNavigation::groupsFor` group key `catalog`, label `admin.nav_groups.catalog`, registry icon and RTL/LTR shell. Reuse → Extend → Compose → New Screen chooses **Reuse** of the existing Catalog Management page and channel-specific Products entry. Children are server-projected: `catalog_management` → `admin.catalog.index` (permission `catalog.view`), and Retail `b2c_products` → the existing `admin.b2c.module` with an assigned store. Existing pricing, lookup, inventory and ERP entries remain individually gated by their own permissions, channel and operational readiness; there is no blanket Catalog group permission. Disabled modules produce no misleading destinations. `catalog` is omitted when there are no authorized children.

The actor's task is to discover and open real Catalog workflows from the Sidebar on desktop, tablet and mobile web. Screen pattern is the existing Catalog data explorer with horizontal Categories/Products tabs, filters and form lookups; business IDs are selected via existing controls, never manually typed by the user. Canonical navigation type: **SIDEBAR** for the Catalog group and **PAGE_TAB** for subordinate Catalog functions. Entry and back path: existing Admin Dashboard shell, Catalog group and in-page tabs. No duplicate route, group or renderer.

The actor/tenant security boundary remains with Laravel controllers and `OperationalTenantScope`: B2B admin has wholesale-only Catalog access; store-assigned B2C admin has only their designated Retail store. Inactive/roleless users have no authorized group or direct route. Presentation-only `admin_sidebar_groups.is_enabled` can hide the group but **cannot grant or revoke `catalog.view`**, or substitute for direct HTTP checks. All groups must have nonempty authorized children; stale or missing routes are suppressed.

## Source-specific automated contract

`backend/tests/Feature/V2CatalogSidebarGroupAuthorityTest.php` verifies actual authorized Catalog group, registered routes, real B2B Catalog HTTP 200 and AR/RTL + EN/LTR rendered group name, Retail own store Products entry/HTTP 200 versus other-store and wholesale-route HTTP 403, roleless omission + direct 403, group presentation disabled without changing RBAC permission, and inactive omission. Existing P010 group projection (#2755), P220 Catalog Management (#2550), P221 Products and P222 Categories (#2552) tests cover their own subordinate authority. This P219 regression owns only a **new dedicated test** plus this QA file; it does not touch any shared navigation, controller, Blade or app code owned by other workers.

## Impact assessment

| Surface | Impact |
| --- | --- |
| Laravel Backend | New HTTP/integration regression, no runtime API, security role or schema changes |
| Dashboard | Existing Catalog Sidebar group and authorized targets, responsive admin shell, AR/EN RTL/LTR; no new page or visual primitive |
| Customer app | Not affected: no catalog API payload, product/channel availability or app route changed |
| Driver app | Not affected: no B2C dispatch, order state or driver role changed |
| Van app | Not affected: no B2B van inventory/order/dispatch route changed |

Preserve wholesale **VAN ONLY**, Retail **DRIVER ONLY**, store privacy, maker/checker and installer/update pathways. UI browser-device accessibility and end-to-end UAT are deferred until final V2 acceptance under owner #2503 and #2750; the current change does not claim visual testing.

## Test and closure rules

Focused command: `cd backend && php artisan test tests/Feature/V2CatalogSidebarGroupAuthorityTest.php`. Require exact-head Repository Policy, FOODEX V2 Isolation, full Laravel tests, PHP/Pint/static analysis, AR/EN, MySQL/Redis Fresh Install/Upgrade/Recovery and final required-ci-gate success before squashed merge into `Foodex_V2`. Record PR, tested SHA, successful CI IDs, merge SHA, release ownership and close only fully integrated leaf. `MANUAL_ACCEPTANCE: DEFERRED_TO_V2_FINAL`. No main, tags, release or production deployment.
