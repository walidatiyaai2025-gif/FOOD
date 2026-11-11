# V2 P251 — Warehouse Transfers: authorized WMS workbench acceptance

**Issue:** #2581 · **Source:** `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx` P251 · **Parent:** #2503 · **Integration branch:** `Foodex_V2` only.

## Placement / user goal

- **Domain:** Procurement & Inventory / enterprise WMS; **Entity:** warehouse transfer and canonical `inventories` / `stock_movements`.
- **Role:** warehouse/inventory manager with effective `inventory.view` / `inventory.manage` in an authorized store/warehouse; ERP operational entitlement and readiness also required.
- **Goal / frequency / data density:** frequent multi-record warehouse stock transfer from source to target with quantity, status and movement trace; bounded transfer list.
- **Existing parent / entry:** Catalog & Supply → **`erp_wms`** (`admin.erp.wms.index`), then the existing **Transfers** tab (`?tab=transfers`); no independent top-level Sidebar destination.
- **Placement decision:** **Reuse → existing WMS workbench tab**, not a duplicate ledger, route or screen. Canonical `EnterpriseWmsController` + `WarehouseTransferService` manage create → dispatch → receive; `admin.enterprise-wms` handles the UI.
- **Navigation type:** PAGE_TAB within the authorized WMS workbench, linked from its business-domain navigation owner; **fallback:** WMS Overview / surrounding tabs, preserving store and warehouse selection.
- **Responsive:** existing Dashboard workbench, warehouse/product lookup rather than raw technical IDs, accessible tab navigation, translated button labels, bounded table, responsive shared styles; no frontend markup, screen or component changed in this PR.
- **RTL/LTR:** existing AR RTL and EN LTR directions verified through rendered authenticated HTTP GET, along with translated transfer title and working create form.

## Server authorization, state and isolation

| Action | Canonical route | Server authority |
| --- | --- | --- |
| View transfers | GET `admin.erp.wms.index?tab=transfers` | active authenticated user; inventory.view, tenant/store/warehouse, entitlement + operational module readiness |
| Create transfer | POST `admin.erp.wms.transfers.store` | inventory.manage; exact source and target warehouse in same authorized store; real product stock in both warehouses; positive quantity and idempotency |
| Dispatch | POST `admin.erp.wms.transfers.dispatch` | inventory.manage + warehouse pair, draft state, available stock; atomic canonical stock decrement + movement + audit |
| Receive | POST `admin.erp.wms.transfers.receive` | inventory.manage + warehouse pair, dispatched state; atomic stock increment + movement + audit |

Unauthorized or cross-store store_id fails **404** at tenant/warehouse boundary; roleless lacks permissions (**403**); unentitled WMS fails at server (**404**). Hidden or disabled presentation cannot create permission. Same-store warehouse scope may be narrowed explicitly to a source-only warehouse; target denial must occur before any transfer write. No cross-store transfer or alternate ledger is introduced. B2B-only VAN and B2C-only DRIVER delivery assignment contracts are unchanged; internal warehouse transfers are not Van dispatch or Driver assignment.

## Acceptance / evidence

- New `backend/tests/Feature/V2WmsTransferWorkbenchAuthorityTest.php` runs a synthetic account/store/warehouse fixture under `RefreshDatabase`.
- Positive: two localized HTML page renders, real transfer form, create idempotency, dispatch & receive idempotency, exact inventory quantities, paired `warehouse_transfer_out` / `warehouse_transfer_in` movements, audit records and list discoverability.
- Negative: cross-store GET and forged POST, mixed-store warehouse pair, narrowed warehouse access, unauthenticated-by-role (roleless) actor, disabled module entitlement; assert no transfer rows or stock movements on denied attempts.
- Existing `EnterpriseWarehouseFoundationTest` covers service idempotency, reservation pressure, scoped warehouse pair and ledger provenance; `EnterpriseWmsWorkbenchTest` covers shared WMS layout and Arabic/English.
- **Module readiness:** CI fixture temporarily sets the manifest's exact certified version to exercise code under test. This does **not** approve or activate production WMS. Real production entitlement and readiness remain governed independently by #2858 and parent #2503.
- **Customer Flutter:** no customer order/cart/API payload or status changes; Customer remains on its existing B2B/B2C routes.
- **Driver Flutter:** no driver dispatch/driver order API, evidence, GPS or notification changes; Driver remains B2C only.
- **Van Flutter:** no Van consignment, trip load, route, visit, sale or dispatch API changes; Van remains B2B only. A future API effect needs a separate V2 mobile issue and tests.
- No migrations, releases, setup ZIP changes, production data writes or changes to main. All fixture writes are rolled back.
- Closure only after exact final PR-head Repository Policy, V2 Isolation and all Required CI jobs are green and PR merges into `Foodex_V2`. `MANUAL_ACCEPTANCE: DEFERRED_TO_V2_FINAL` under #2503; CI is not a claim of human UAT.
