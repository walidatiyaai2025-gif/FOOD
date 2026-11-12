# FOODEX V2 — P324 Digital Ordering / Restaurants group (#2654)

**Integration:** `Foodex_V2` ONLY. Source: FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx P324. Parent: #2503.

## Placement / business ownership

- **Domain:** Retail Stores & Channels → Restaurant SaaS / Digital Ordering.
- **Entity:** authorized active B2C Restaurant Store and its digital ordering Site.
- **Personas:** active scoped restaurant admin/operator with valid Retail assignment and effective `smart_ordering.view` or subordinate allowed capability; roleless, inactive, Wholesale-only and retail non-restaurant actors cannot use this domain.
- **User goal/workflow/frequency/data:** daily discovery of restaurant digital ordering workbench, orders, menu, QR, reporting, integrations and operational settings without dead links or typing technical Site/Store IDs.
- **Placement:** REUSE the existing `stores` business-domain group, child `smart_ordering`, label `smart_ordering.nav_title`. No second Restaurant Sidebar group or duplicate QR/Analytics links. Existing responsive `_smart-ordering-workspace-nav.blade.php` implements horizontal sibling tabs and `_restaurant-management-launcher.blade.php` orchestrates authorized actions.
- **Canonical entry:** prefer `admin.smart-ordering.index` (GET `/admin/smart-ordering/settings`) only when authorized; otherwise fall back to independently authorized Operations, QR or Analytics route; navigation_type `SIDEBAR` under Stores & Channels, then `PAGE_TAB` for Operations, Menu, QR, Analytics, Appearance, integrations and growth. Existing Dashboard's Sidebar is the fallback/back route. No new UI/pattern/route.
- **Security authority:** `AdminNavigation::restaurantSaasItem` + `SmartOrderingEnterpriseEntitlementBridge::enabledStoreIds` + `OperationalTenantScope::allowedStoreIds` (same candidate/deny authority as direct `RestaurantSaasStoreAccess::allowedStoreIds`). Only stores with active Retail scope, Restaurant business profile, explicit `STORE:smart_ordering` entitlement, and effective per-store permission are valid.
- **Localization:** existing `smart_ordering.nav_title` AR/EN keys and Dashboard `dir=rtl/ltr`. Responsive desktop/320–1280 px and accessibility manual device audit: `DEFERRED_TO_V2_FINAL`; no claim of visual UAT.

## Executable acceptance evidence

`backend/tests/Feature/V2RestaurantDigitalOrderingDomainAuthorityTest.php` uses real CoreReferenceSeeder roles, Restaurant-SaaS entitlement fixture, tenant-filtered Dashboard routes and HTML responses. Tests one canonical entry (not duplicated QR/Analytics Sidebar nodes), AR and EN web page and shared workspace tabs; module-entitlement missing/revoked, Retail non-restaurant/B2B profiles, unrelated entitled restaurant's store ID, explicit user global DENY, inactive and roleless users. Existing `RestaurantSaasNavigationConvergenceTest` and `B2cProfileWorkspaceIsolationTest` cover complete sub-workspace navigation and cross-profile routes.

**Authorization parity fix (P324):** After #2523/#3246 was merged into `Foodex_V2` as `7831447053ac89cd66d9de2a6e4abbabf4fc7f50` and its shared-file ownership released, this original #2654 branch was safely reconciled with a non-force two-parent merge preserving all P193 Sales Quotation work. The Restaurant Sidebar now intersects the exact `SmartOrderingEnterpriseEntitlementBridge::enabledStoreIds` for Restaurant-profile Retail stores with the same `OperationalTenantScope::allowedStoreIds(actor, ability, 'b2c')` used by the direct `RestaurantSaasStoreAccess::allowedStoreIds` endpoint. It does not infer an allowed Restaurant link from permission on a different ordinary Retail store. Any user/store/channel/override DENY is reflected by the same authority, without an independent visibility layer. Links are only advertised when their named route exists.

**Independent capability fallbacks:** An explicit global `smart_ordering.view` DENY makes `admin.smart-ordering.index` fail with HTTP 403, but a valid restaurant `orders.view` grant can still authorize `admin.smart-ordering.operations.index`; the single Sidebar child then correctly points at Operations. Denying all four potential destination permissions on an entitled Restaurant store hides the Restaurant entry, **even when a second non-Restaurant Retail store still has all those abilities**. Regression tests assert both routes return 403 and that the unrelated Retail Store cannot be used as a Restaurant workspace.

**Tests:** `V2RestaurantDigitalOrderingDomainAuthorityTest` now verifies AR/EN RTL/LTR, authorized real HTML, module-disabled / STORE-entitlement revoked / missing, explicit global and store-specific DENY, B2B + B2C mixed roles, cross-Retail-store scope, roleless/inactive, per-route authorized Operations fallback and the new multi-store mismatched-ability anti-bypass. `RestaurantSaasNavigationConvergenceTest` and `B2cProfileWorkspaceIsolationTest` remain unchanged and run as part of full Laravel CI.

**Concurrency:** No worker file overlap: #2523's `AdminNavigation.php` ownership was released by its verified merge and completion before this branch modified that file. No replacement issue, branch or PR was created.

## Cross-surface impact matrix

- **Laravel Backend:** scoped `AdminNavigation` Restaurant link authority projection fix plus real Feature tests; no API endpoint, migration, role permission grant or new SaaS capability.
- **Dashboard:** correct existing Restaurant Sidebar link discoverability, reuse existing routes, tabs and lookup-driven controls; role/store/entitlement and direct HTTP authority tests.
- **Customer Flutter:** no changed public customer API, menu/checkout data contract, identity or payment handling.
- **Driver Flutter:** no changed B2C dispatch, delivery, wallet or order-status contract.
- **Van Flutter:** no changed B2B-only van allocation, visits or direct-sale contract. B2B VAN ONLY / B2C DRIVER ONLY unchanged.

No mobile application implementation issue is triggered by unchanged runtime contracts.

**Required merge gates:** exact head Repository Policy, V2 Isolation, PHP lint/Pint/PHPStan/security, full Laravel, AR/EN, MySQL/Redis Fresh Install/Upgrade/Recovery, terminal required-ci-gate all green. Never main, release, tag, APK publication or production. Complete source functionality must be evidenced before Issue closure, with manual end-stage V2 UAT deferred by #2503.
