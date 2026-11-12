# V2 #2575 — Supplier statements (P245) authorized ERP journey

## Source / placement
- Source: `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx`, P245, procurement/inventory, parent #2503.
- **Domain:** Procurement; **entity:** supplier statement / AP aging; **role:** authorized procurement or AP reader; **goal:** review posted supplier invoices and current aging by currency without exposing another store.
- **Canonical destination:** `GET /admin/erp/procurement?tab=statements&store_id={authorized_store}`; entry point is existing `Procurement` business-domain item `erp_procurement`, then existing Statements tab. **Placement: extend existing page**; do not introduce a duplicate Sidebar leaf, controller, API or supplier ledger.
- **Navigation type:** PAGE_TAB within the existing authorized procurement workspace. Returning to the procurement tab list remains unchanged.

## Fixed UX defect
- `SupplierStatementAgingManager::statement()` already returns a bounded, store-scoped, latest-first Laravel `LengthAwarePaginator` with `withQueryString()`.
- Existing Blade displayed the 25 invoices but only output `$rows->links()`; statements are in `$statement`, so a supplier with 26+ handed-off invoices had no way to navigate to the remaining invoices.
- The canonical statements tab now renders `$statement->links()` after the statements grid when `hasPages()`. Links retain the already validated supplier, store, date, as-of, currency and tab filters. No client-side or unscoped query introduced.

## Authority and data
- HTTP `EnterpriseProcurementController::index` checks `procurement.supplier_statements.view` for the selected authorized store and rejects malformed tab/foreign store; `SupplierStatementAgingManager` separately checks procurement module lifecycle and enterprise scope/role permission, then resolves visible supplier by organization/company/store scope. Reading foreign supplier IDs fails closed.
- Supplier invoices are filtered by exact organization, company, store, supplier, handed-off status, selected currency and dates. Aging is computed from bounded authoritative invoices. There are no mutations on this page.
- The supplier selector stays an authoritative lookup, not a freeform database-ID field.
- Backend entitlement / readiness ownership remains unchanged; no authorization bypass or new entitlement.
- **No shared controller edits** while #2845/PR #3206 owns `EnterpriseProcurementController.php`; coordinate any independent controller follow-up rather than risk concurrent changes.

## AR/EN, accessibility, responsive
- Existing translated tab/filter/status/date strings are reused; the paginator is the Laravel-native accessible keyboard-operable component with its built-in ARIA semantics.
- RTL Arabic and LTR English render on the same route. The existing grid is contained in `erp-table-wrap`, while pagination is outside the scroll container so it remains discoverable on mobile web.
- Empty/one-page statements do not produce extraneous pagination UI.

## Automated regression
- `EnterpriseSupplierStatementAgingFoundationTest`: existing manager proof of date window, currency, source status, buckets, module-disabled denial and cross-store isolation.
- Added a true HTTP flow creating 26 handed-off invoices via canonical procurement services and a real receipt; page one has 25, page two one, with filter-preserving links in AR/EN.
- Added negative HTTP tests for foreign store ID, foreign supplier ID and missing statements permission in both locales.
- Required exact-head gates: Repository Policy, FOODEX V2 Isolation Gate, full FOODEX Required CI with Laravel/PHP syntax/Pint/PHPStan, localization, real MySQL/Redis fresh-install/upgrade/recovery and terminal `required-ci-gate`.

## Per-app assessment
- **Customer:** no API change, no app source change; supplier statements are privileged Dashboard-only AP reads.
- **Driver:** no B2C Driver dispatch/payment runtime change; no API or Flutter code.
- **Van:** no B2B Van visits/consignment/settlement runtime change; no API or Flutter code.
- **Dashboard/backend:** one view pager and Laravel read/denial tests, no mutation/schema changes.

## Completion boundaries
- All branch work is isolated from `main`, tags, releases and production.
- Manual browser/device/UAT remains deferred to V2 master #2503 / UX #2750 per the latest owner directive and cannot be claimed from CI.
