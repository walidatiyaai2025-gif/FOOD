# V2 #2552 · P222 — Categories, existing Catalog page-tab authorization

**Source:** FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx, P222 (Catalog & Pricing). **Parent:** #2503. **Delivery base:** `Foodex_V2` only.

## Canonical placement decision

| Required field | Resolution |
| --- | --- |
| Domain / entity | Catalog & Pricing / product categories |
| Persona and goal | Wholesale catalog manager or store-assigned Retail catalog manager reviews, filters and manages categories |
| Workflow / frequency / density | Repeated operational master-data maintenance / paginated and filterable category grid |
| Existing parent | Existing `admin.catalog.index` Catalog Management screen |
| Placement | **Reuse** the already published `?tab=categories` horizontal page tab; **not** a separate Sidebar item, Laravel renderer or API |
| Pattern and navigation | Existing Catalog business-domain Sidebar, `catalog_management` for B2B, `b2c_products` for Retail, then `categories` in-page tab |
| Scope / permission | Effective `catalog.view` to enter Catalog; scoped `catalog.manage` for category create/update; Retail `store_id` assigned context; forbidden cross-store and opposite-channel |
| Live routes | `admin.catalog.index?tab=categories`, `admin.catalog.categories.store`; `admin.catalog.categories.index` is a compatibility redirect only |
| Permission guards | Runtime Controller/tenant resolver/Gate authoritative, not cosmetic Sidebar visibility; no independent category grant or module-entitlement bypass |
| AR/EN + responsive | Existing `catalog_completion.tabs.categories` translations, Laravel admin shell, direction-aware RTL/LTR, existing grid/filter mobile-web composition |
| User path / back path | Catalog Sidebar → existing Catalog page → Categories tab → other Catalog tabs or Catalog group |

The historical `admin.catalog.categories.index` redirect is not a navigation owner. Additional Sidebar item would violate the duplicate destination prohibition (#1991) and page-tab placement contract. Category name is legitimate business text; category/store relationships come from scoped selector/master data rather than handwritten IDs in the UI.

## Automated regression acceptance

New `backend/tests/Feature/V2CategoryTabAuthorizedScopeTest.php` exercises the **real** Laravel routes and rendered HTML:

1. Active B2B manager sees Catalog group and catalog management entry (`catalog.view`), follows the existing categories tab in Arabic RTL and English LTR, sees the actual category form/filter, and the legacy deep-link redirects to the canonical tab.
2. A B2C store manager sees the existing Retail Products entry, reaches the category tab within their assigned store, creates a category through the real secured POST, but receives HTTP 403 on foreign-store read and write without creating the forbidden category.
3. Roleless and inactive accounts lack the catalog entry and receive direct HTTP 403. A wholesale actor cannot inspect a Retail category tab by guessing a Retail store ID (404 cross-channel non-disclosure).

Existing `AdminCatalogManagementTest`, `RetailAdminCatalogAuthorizationTest`, `RetailAdminProductionDefectsTest` and `NavigationRemediation1991Test` cover broader CRUD, ownership and alias policy. No runtime source/UI duplication is necessary; this task owns two **new dedicated** QA files only and avoids current V2 workers' modified files.

## Backend, Dashboard and mobile impact matrix

| Surface | Classification |
| --- | --- |
| Backend | New focused feature regression of existing route/auth/catalog category mutation; no change in runtime API/data schema |
| Dashboard | Existing Categories tab and AR/EN / RTL/LTR + scoped filters/forms remain authoritative; no new screen, navigation or assets |
| Customer app | Not affected: product category publication, filtering API and mobile route contracts unchanged |
| Driver app | Not affected: no B2C fulfillment, delivery or catalog API code touched |
| Van app | Not affected: no B2B Van sales, inventory/route, authentication or shared API code touched |

B2B → **Van only**, B2C → **Driver only**, tenant isolation and financial controls are unchanged. Final visual browser/device and accessibility acceptance remains deferred to V2-wide master #2503 and UX #2750 by the 10 Nov 2026 owner decision; no screenshot/production claim.

## Exact-head CI and closure

Focused command: `cd backend && php artisan test tests/Feature/V2CategoryTabAuthorizedScopeTest.php`. Require green GitHub Repository Policy, FOODEX V2 Isolation Gate, Backend fast-fail/Pint/static analysis, Laravel tests, AR/EN localization, MySQL/Redis install/upgrade/recovery and **required-ci-gate** on final PR HEAD. Merge only into `Foodex_V2`, record commit and CI IDs in Issue #2552 and close `completed` only after verified merge. `MANUAL_ACCEPTANCE: DEFERRED_TO_V2_FINAL`. No `main`, release/tags or production.
