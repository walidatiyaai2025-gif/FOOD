# V2 #2554 / P224 — Catalog images and content authority

## Placement and business contract

Source: FOODEX Unified RBAC ERP Sidebar discussion (9 Nov 2026), P224. Parent: #2503.

- Pattern: Reuse existing canonical Catalog Management rather than add a duplicate Sidebar destination. The Catalog group exposes the authorized admin.catalog.index; the product and category image/content capabilities belong to its Products and Categories tabs. Retail's existing B2C Products workspace links to category management. The admin.catalog.categories.index compatibility route redirects to the canonical Categories tab and must not become a new navigation authority.
- Domain/entity: Catalog and Pricing / product, category, gallery, media, business description.
- Persona/entry: wholesale catalog operator via Catalog group; B2C_STORE_ADMIN via its assigned-store Products workspace; platform support only through existing explicitly authorized support context.
- Read: GET admin.catalog.index with tab products or categories and authorized store_id requires catalog.view and an effective tenant/store grant. Neither an in-page tab nor a display flag is an authorization grant.
- Product write: PATCH admin.catalog.products.grid-image, admin.catalog.products.images.update, admin.catalog.products.images.destroy and admin.catalog.products.update require catalog.edit and record-owner scope.
- Category write: PATCH admin.catalog.categories.grid-image and admin.catalog.categories.update require catalog.manage and record-owner scope.
- Tenant, store, channel: wholesale/B2B global roles remain inside B2B scope; assigned retail staff operate only on their permitted B2C store. A fabricated support_access=1 query flag cannot grant an unauthorized store. Denied access must fail with HTTP 403 or the intentional record-hiding 404 without persistence.
- Module entitlement/readiness: the existing Catalog workspace is not an ERP lifecycle module. No fictional lifecycle or entitlement toggle is introduced. The current role, active store and tenant context remain authoritative.
- Navigation owner/type: Catalog group, PAGE_TAB and CONTEXT_ACTION. Context b2b_products or b2c_products; selected store query preserved. Back path is the canonical Catalog tab, not an orphaned screen.
- UI classification: category and store values are authoritative Lookups; product/category names and descriptions are legitimate user-entered content; image inputs are uploads, not raw file paths or database IDs.

## Executable acceptance

A new V2CatalogImageContentAuthorityTest exercises real HTTP endpoints, view HTML, and data/filesystem effects:

1. AR and EN own-store catalog image controls on the Products and Categories tabs; no foreign-store product/category leakage.
2. Own-store image and description changes persist; foreign product image, category image and product description actions fail closed even with forged support_access. Foreign image files and records remain intact.
3. Unprivileged and cross-channel B2B/B2C actors cannot browse or mutate foreign-store media.

Existing CatalogImageManagementTest independently verifies gallery primary order/delete, image uploads, authorized product and category controls, and Customer mobile API image URL projection. RetailAdminCatalogAuthorizationTest independently covers cross-store product/category ownership and unauthorized operations.

Existing catalog-management Blade includes AR/EN labels, RTL/LTR shell behavior, responsive category/product cards and gallery dialogs. No new screen or UI runtime is created. Manual browser, responsive, keyboard and device acceptance stays deferred under owner override #2503 and final UX gate #2750; it must not be represented as already completed.

## Cross-surface impact audit

| Surface | Scope and evidence |
| --- | --- |
| Backend | New focused HTTP regression tests only; no route/controller/API contract mutation |
| Dashboard | Reuse existing Catalog Products/Categories tabs, context image-edit actions, gallery modal and authorized store scope |
| Customer | Existing image URLs continue through the unmodified category/product mobile API; covered by existing CatalogImageManagementTest |
| Driver | No API, dispatch, role, screen or payload touched in this tests-and-evidence-only change |
| Van | No API, delivery, territory, role, screen or payload touched in this tests-and-evidence-only change |

No Customer/Driver/Van implementation issue is triggered by this regression-only diff because there is no affected mobile runtime or API contract; B2B=VAN ONLY and B2C=DRIVER ONLY remain untouched.

## Release, CI and ownership

Issue #2554, single branch test/2554-v2-catalog-image-content-route-authority, exactly one PR with base Foodex_V2. Required checks: repository policy, FOODEX V2 isolation, Laravel focused and broader CI, Pint/PHPStan as applicable, AR/EN localization, required-ci-gate and all its required child jobs on the exact final PR HEAD. Hold merge and closure unless green. No main branch changes, production tags, ZIP deployment or publishing. MANUAL_ACCEPTANCE: DEFERRED_TO_V2_FINAL.
