# V2 #2548 — P218 Catalog, Pricing & Offers Sidebar group

**Source:** `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx` / P218 (Sidebar); parent #2503.
**Integration:** `Foodex_V2` only. No `main` integration, deployment, production tags or live install.
**Decision:** Reuse the existing canonical `catalog` business group and authorized destinations. No second group/route/renderer and no independent Sidebar permission.

## Placement and discoverability

| Field | Decision |
|---|---|
| domain / entity | Catalog & Pricing / products, lookup values, commercial pricing, eligible coupons |
| actor | B2B Admin (platform wholesale), B2C Store Admin (assigned retail store), otherwise individually permissioned staff |
| goal / workflow | Find and open existing catalog/pricing/offer-management tasks |
| frequency / data density | Daily operations; existing catalog and workspace pages |
| existing parent / navigation owner | `AdminNavigation::groupsFor`, `catalog` in `config/admin_sidebar.php` |
| placement type / navigation type | **Reuse / SIDEBAR** group; no new screen or submenu level |
| screen pattern | Existing Catalog Management and channel workspace screens |
| entry point | Authenticated Dashboard responsive navigation, `catalog` group |
| canonical routes | `admin.catalog.index`; `admin.commercial.sales-control` (B2B); `admin.b2b.module(products)`; `admin.b2c.module(products, store_id)`; existing `admin.coupons.index` when allowed |
| authorization | `catalog.view` / `lookups.view` / `inventory.view` / `coupons.view` and each existing route/controller's channel, store, per-user DENY, feature and ERP readiness rules |
| active context | B2B platform scope; B2C exact assigned and authorized store, not arbitrary first store |
| fallback / back | Existing Dashboard nav and workspace navigation; inaccessible destinations are denied by Laravel HTTP guards (403 or masked 404 for foreign record/store) |
| desktop / tablet / mobile web | Same canonical authorized group rendered by existing shared Sidebar/off-canvas drawer; no extra menu node or viewport-specific security rule |
| RTL/LTR / keyboard | Existing shared accessible navigation; group uses `admin.nav_groups.catalog` resolved to Arabic/English by active locale; no new input controls |
| states | Empty group removed; unauthorized/inactive/roleless links absent; disabled feature/ERP module not exposed as an executable ready workflow |

## Source label and stable routes

The P218 group title is now **الكتالوج والتسعير والعروض** (AR) / **Catalog, Pricing & Offers** (EN), while the original `admin.nav_groups.catalog` translation key, existing group identity, order, routes and privileges are preserved.

The existing `catalog` group includes only authorized existing children according to `AdminNavigation` and the Sidebar registry. `commercial_sales_control` is B2B-only. Store-scoped `b2c_products` carries its server-authorized `store_id`; `b2b_products` must not appear for Retail. `coupons` remains Catalog-owned under its existing `coupons_enabled` store feature and permission checks, without moving unrelated advertising campaigns into Catalog. ERP Procurement/WMS children are subject to existing entitlement and readiness authorizers, not the group heading.

This change **does not expand grants** or implement pricing/promotion workflows owned by child issues. Route/menu parity remains at the existing controllers; Sidebar presentation alone is not an authorization authority.

## Scope and impact matrix

| Surface | Classification | Evidence |
|---|---|---|
| Backend | affected (AR/EN group label and security regression tests only) | `backend/lang/{ar,en}/admin.php`; P218 tests |
| Dashboard | affected (existing group display copy) | `AdminNavigation` group key and routes unchanged; existing responsive shared renderer |
| Customer | not affected | No API, permission/role, mobile navigation or order contract changed |
| Driver | not affected | No API, dispatch state, permission/role or mobile navigation contract changed |
| Van | not affected | No API, stock/visit, route or mobile navigation contract changed |

No distinct mobile issue is created because there is no app-specific runtime or contract change. B2B=VAN ONLY and B2C=DRIVER ONLY remain untouched.

## Verification contract

- `php artisan test --filter=V2CatalogPricingOffersGroupAuthorityTest`: exact AR/EN labels, locale-invariant route order, registered routes, B2B authorized HTTP, Retail store scope and forbidden foreign/B2B context, global DENY overriding inherited catalog role grants, roleless/inactive failure path.
- `php artisan test --filter=V2CommercialSalesControlSidebarMigrationTest`: update pinned AR/EN label assertions without changing destination/permission; preserve #2722 regression.
- Existing `V2CatalogSidebarGroupAuthorityTest`, `V2ProductsAuthorizedSidebarHttpTest`, `V2CouponsSidebarAuthorizationTest`, and `V2SidebarBusinessGroupCollapseTest` cover additional channel, disabled coupon feature, locale/direction and responsive disclosure behavior.
- Confirm exact-head Required CI, localization and V2 isolation checks on PR. This document records planned checks, **not** a claim that local tests or live UI screenshots were executed.

## Acceptance / remaining evidence

PR merge requires complete exact-head required checks on `Foodex_V2` target, review of affected label expectation and absence of overlapping file ownership. The Issue remains open until the P218 owner acceptance (including required visual/mobile-web audit evidence) is confirmed. This PR does not authorize deployment or promotion.
