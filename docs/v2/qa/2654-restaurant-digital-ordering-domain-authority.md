# FOODEX V2 — P324 Digital Ordering / Restaurants group (#2654)

**Integration:** `Foodex_V2` ONLY. Source: FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx P324. Parent: #2503.

## Placement / business ownership

- **Domain:** Retail Stores & Channels → Restaurant SaaS / Digital Ordering.
- **Entity:** authorized active B2C Restaurant Store and its digital ordering Site.
- **Personas:** active scoped restaurant admin/operator with valid Retail assignment and effective `smart_ordering.view` or subordinate allowed capability; roleless, inactive, Wholesale-only and retail non-restaurant actors cannot use this domain.
- **User goal/workflow/frequency/data:** daily discovery of restaurant digital ordering workbench, orders, menu, QR, reporting, integrations and operational settings without dead links or typing technical Site/Store IDs.
- **Placement:** REUSE the existing `stores` business-domain group, child `smart_ordering`, label `smart_ordering.nav_title`. No second Restaurant Sidebar group or duplicate QR/Analytics links. Existing responsive `_smart-ordering-workspace-nav.blade.php` implements horizontal sibling tabs and `_restaurant-management-launcher.blade.php` orchestrates authorized actions.
- **Canonical entry:** `admin.smart-ordering.index` (GET `/admin/smart-ordering/settings`); navigation_type `SIDEBAR` under Stores & Channels, then `PAGE_TAB` for Operations, Menu, QR, Analytics, Appearance, integrations and growth. Existing Dashboard's Sidebar is the fallback/back route. No new UI/pattern/route.
- **Security authority:** `AdminNavigation::restaurantSaasItem` + `SmartOrderingEnterpriseEntitlementBridge::enabledStoreIds` + `RestaurantSaasStoreAccess::allowedStoreIds` and per-store `User::hasPermission`. Only stores with active Retail scope, Restaurant business profile, explicit `STORE:smart_ordering` entitlement, and effective per-store permission are valid.
- **Localization:** existing `smart_ordering.nav_title` AR/EN keys and Dashboard `dir=rtl/ltr`. Responsive desktop/320–1280 px and accessibility manual device audit: `DEFERRED_TO_V2_FINAL`; no claim of visual UAT.

## Executable acceptance evidence

`backend/tests/Feature/V2RestaurantDigitalOrderingDomainAuthorityTest.php` uses real CoreReferenceSeeder roles, Restaurant-SaaS entitlement fixture, tenant-filtered Dashboard routes and HTML responses. Tests one canonical entry (not duplicated QR/Analytics Sidebar nodes), AR and EN web page and shared workspace tabs; module-entitlement missing/revoked, Retail non-restaurant/B2B profiles, unrelated entitled restaurant's store ID, explicit user global DENY, inactive and roleless users. Existing `RestaurantSaasNavigationConvergenceTest` and `B2cProfileWorkspaceIsolationTest` cover complete sub-workspace navigation and cross-profile routes.

**Important remaining source audit:** the existing navigation compositor loops eligible restaurant Store IDs, but chooses the first permission using `AdminNavigation::hasPermissionInScope` (potentially from *another* Retail store) instead of intersecting that permission with the same restaurant-entitled Store IDs. This could show a dead Sidebar entry for a mixed-store actor even though the direct HTTP controller correctly fails closed. Before closing P324, reproduce with an explicit cross-store permission-only fixture, then change the shared runtime file only after #2523/#3246 `AdminNavigation.php` file ownership is released. Keep Issue OPEN if unresolved; a green additive test alone does not certify all authorization parity.

## Cross-surface impact matrix

- **Laravel Backend:** additive Feature test and source-to-route QA only; no changed API, migrations, permissions or new SaaS capability.
- **Dashboard:** reuse existing UI, tabs and lookup-driven controls; role/store/entitlement navigation and HTTP tests.
- **Customer Flutter:** no changed public customer API, menu/checkout data contract, identity or payment handling.
- **Driver Flutter:** no changed B2C dispatch, delivery, wallet or order-status contract.
- **Van Flutter:** no changed B2B-only van allocation, visits or direct-sale contract. B2B VAN ONLY / B2C DRIVER ONLY unchanged.

No mobile application implementation issue is triggered by unchanged runtime contracts.

**Required merge gates:** exact head Repository Policy, V2 Isolation, PHP lint/Pint/PHPStan/security, full Laravel, AR/EN, MySQL/Redis Fresh Install/Upgrade/Recovery, terminal required-ci-gate all green. Never main, release, tag, APK publication or production. Complete source functionality must be evidenced before Issue closure, with manual end-stage V2 UAT deferred by #2503.
