# V2 #2551 · P221 — Products: canonical Sidebar-to-HTTP authorization

**Source:** `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx`, P221, Catalog & Pricing; parent #2503. **Only integration target:** `Foodex_V2` (no `main`, release, tags or production).

## Placement and user journey

- **Domain / entity:** Catalog & Pricing / Products (existing wholesale and retail catalog).
- **User goal / role:** An authorized B2B catalog administrator opens Wholesale Products; an authorized B2C store administrator opens its own Retail Products. Neither may inspect a forbidden store, channel or inactive identity.
- **Business frequency / density / pattern:** Recurrent catalog work, tabular product management inside the existing channel workspace.
- **Placement:** **Reuse**, not a new route or Sidebar tree. Existing `AdminNavigation::groupsFor` Catalog group (`catalog`) contains `b2b_products` and `b2c_products`; route-specific controllers render the existing Dashboard shell, product data and actions. Existing `catalog_management` remains the canonical shared product/category management surface. Do not duplicate CRUD, tabs or permission grants.
- **Navigation owner / type / entry:** Catalog & Pricing / SIDEBAR; directly accessible from authorized global navigation and the standard Dashboard workspace. Back/fallback: Catalog group and channel Dashboard; no direct-URL-only journey.
- **Desktop / tablet / mobile web:** Existing shared Dashboard shell and responsive workspace retained; no new UI primitive, layout, assets, keyboard, focus or touch behavior. Browser viewport/a11y UAT remains scheduled at the final V2 gate, not falsely certified by these route tests.
- **Locale:** `admin.b2b_sidebar.product_management` for B2B and `admin.b2c_workspace.modules.products` for B2C; AR RTL / EN LTR tested via real HTTP.

## Exact route and access contract

| Channel | Sidebar item | Route | Parameters | Required server permission | Scope |
| --- | --- | --- | --- | --- | --- |
| Wholesale B2B | `catalog / b2b_products` | `admin.b2b.module` | `module=products` | `catalog.view` | Authenticated active global B2B role and wholesale catalog |
| Retail B2C | `catalog / b2c_products` | `admin.b2c.module` | `module=products, store_id=<assigned retail store>` | `catalog.view` in selected store | B2C store-role assignment, active retail business profile and exact-store boundary |
| Shared catalog management | `catalog / catalog_management` | `admin.catalog.index` | `tab=products` as needed | `catalog.view` with effective controller store scope | Existing product editing/tabs, covered by #2550 and catalog controller tests |

The Sidebar item and HTTP action are separately checked; visibility never constitutes authorization. `B2bWorkspaceController::MODULE_PERMISSIONS` and `B2cWorkspaceController::MODULE_PERMISSIONS` independently require `catalog.view`, while `B2cWorkspaceController::workspaceContext` resolves assigned store and denies foreign-store navigation. Unsupported workspace module keys are HTTP 404 rather than fictional products pages. No ERP-specific module readiness flag is invented for the existing Products module; if the channel or user is not authorized, its actual route remains denied. No separate route/middleware/entitlement bypass is introduced.

## Executable regression and existing coverage

New `backend/tests/Feature/V2ProductsAuthorizedSidebarHttpTest.php` checks:

1. Authorized global `B2B_ADMIN` discovers `b2b_products` in the Catalog group, exact route/permission/label, and receives real Products workspace HTTP 200 in Arabic RTL and English LTR.
2. `B2C_STORE_ADMIN` discovers `b2c_products` with the *assigned* `store_id`, receives HTTP 200 in both locales, and receives HTTP 404 when requesting a foreign retail store (intentional non-disclosure via workspace store resolution). Read-only navigation cannot alter the product count.
3. Roleless and inactive identities receive no Product links and HTTP 403; Retail-only actor cannot enter Wholesale Products.
4. Unknown channel module names return HTTP 404 and cannot be passed off as a valid product destination.

Complementary coverage already exists in `AdminNavigationAuthorizationAuditTest`, `RetailAdminCatalogAuthorizationTest`, `AdminCatalogManagementTest` and #2550. No write endpoints, product records or module permissions are changed by this PR. Focused local parity command when a suitable PHP/Laravel environment exists: `cd backend && php artisan test tests/Feature/V2ProductsAuthorizedSidebarHttpTest.php`.

## Cross-surface impact

| Surface | Assessment |
| --- | --- |
| Laravel Backend | New isolated Feature regression against existing protected routes; no controller, middleware, schema, data or API mutation |
| Dashboard | Navigation/HTTP source authority verified against existing product workspaces; no new Blade, design tokens, sidebar entry or translation |
| Customer | **No impact:** no `/api/v1` product payload, pricing, visibility, product catalog data or Customer Flutter route changes; wholesale Customer catalog remains independently authorized |
| Driver | **No impact:** no Driver Flutter code, Retail B2C dispatch, route or collection change |
| Van | **No impact:** no Van Flutter code, B2B wholesale-only van fulfillment, route or inventory API change |

All B2B fulfillment remains **VAN ONLY**, all B2C fulfillment **DRIVER ONLY**. Tenant/channel scopes, SoD, actual catalog ownership and live data are left untouched.

## Acceptance, checks and handoff

Only two new issue-owned files are changed. Do not modify `AdminNavigation.php`, other workers' tests, shared controller or UI files. Source #2550 was already integrated before this claim; actively leased neighbors were not touched.

CI gates must run **on the exact final PR head**: Repository Policy, FOODEX V2 Isolation Gate, Laravel test/Pint/PHPStan, security, AR/EN localization, applicable MySQL/Redis fresh-install/upgrade/recovery and terminal `required-ci-gate`. An open PR or a code commit alone does not satisfy the gate. Merge exclusively to `Foodex_V2` after green checks and authorization parity, then close this leaf only with the proven merge SHA / CI runs.

`MANUAL_ACCEPTANCE: DEFERRED_TO_V2_FINAL` per the explicit #2503 owner decision. Final real-browser responsive/a11y/staging UAT remains on #2503/#2750, not claimed completed here. No production, `main`, release, tag, Setup/Update ZIP build or APK publication is authorized.
