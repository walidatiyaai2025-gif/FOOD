# FOODEX V2 #2722 / T356R5 — Commercial Sales Control Sidebar migration

**Source:** FOODEX Unified RBAC / ERP / Sidebar discussion T356R5. **Parent:** #2503. **Integration:** `Foodex_V2` only. No `main`, deployment, release or version promotion.

## Placement and canonical authority

The existing `commercial_sales_control` item is **reused**, not replaced with an additional Sidebar item, route, controller, permission or workspace. Destination: **الكتالوج والتسعير → التحكم التجاري والتسعير** / **Catalog & Pricing → Commercial & Pricing Control**. Canonical entry: `admin.commercial.sales-control` (`GET /admin/b2b/commercial/sales-control`) and existing `CommercialDashboardController::salesControl`. Menu group: `catalog`; key: `commercial_sales_control`. Read permission: `catalog.view` on the B2B channel. Save endpoints separately require `catalog.manage`; changing the presentation text does not grant mutations. No new Sidebar security decision or legacy visibility override.

The existing `OperationalTenantScope::allowedStoreIds` / `assertStore` remains responsible for read-only B2B store selection. A Retail store role cannot turn a B2C store into a B2B sales-control context; restricted users cannot acquire access by editing layout or translations. The existing route is used by both locales with its usual AR/EN RTL/LTR responsive Dashboard shell. Non-ERP Sales Control has no separate enterprise module readiness certificate; its canonical catalog permissions, B2B channel and store scope are retained.

## Focused acceptance

`backend/tests/Feature/V2CommercialSalesControlSidebarMigrationTest.php` proves registry and saved default layout place the key once under `catalog`, with the existing route, permission and channel; exact AR/EN group/leaf labels; an authorized Wholesale manager sees one matching Sidebar entry and opens its real HTTP page in AR/EN with RTL/LTR; Retail store actor cannot discover or directly open Wholesale Sales Control (404 to prevent store enumeration); roleless and disabled actors are denied (403). Existing commercial feature tests remain the authority for pricing policies, product selection, B2B store scoping, save permissions and commercial rules.

Field classification: this change introduces **no** new input fields, lookups, IDs, technical keys, forms, records or mutations. Placement is `SIDEBAR` in an existing business domain; desktop/tablet/mobile all use the existing responsive page and one canonical back path to Catalog. End-stage real-browser/accessibility UAT stays **MANUAL_ACCEPTANCE: DEFERRED_TO_V2_FINAL** under #2503/#2750; CI is not a claim of manual UAT completion.

## Customer / Driver / Van impact

- **Customer:** no customer Flutter, public API, product/pricing response, B2B identity or checkout change.
- **Driver:** no Flutter, assignment, GPS, settlement or B2C Driver-only execution change.
- **Van:** no Flutter, route/offers/visits, API or B2B Van-only fulfillment change.

Only Dashboard translation strings and a backend HTTP regression are affected. No distinct mobile implementation Issue is necessary without a changed mobile behavior.

**Merge gate:** exact PR HEAD Repository Policy, V2 Isolation, Laravel full suite, Pint/PHPStan/security/OpenAPI, AR/EN localization, MySQL/Redis fresh-install/upgrade/recovery, and final required-ci-gate all green before merge to `Foodex_V2`. Close #2722 only after complete source-scope and integration verification.
