# V2 #2587 / P257 — Stock reservation and allocation authority

Source: FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx / P257 (Procurement & Inventory). Parent #2503. **Only Foodex_V2**, never main or production.

## Canonical placement: Reuse → Extend

- Domain: Procurement & Inventory, entity: one warehouse's canonical stock reservation, role: authorized warehouse inventory manager or authorized read-only user.
- Goal: inspect on-hand/reserved/available balances, or manage a business-reference-scoped reservation when the actor has an exact store and warehouse inventory.manage permission.
- Workflow: repeated warehouse operations, master-data product lookup and stable business reference (legitimate free-text business reference; no raw internal database IDs).
- Existing route: `admin.erp.wms.index?tab=reservations&store_id=...&warehouse_id=...`, part of the WMS Workbench under canonical Inventory/WMS navigation. Save POST remains `admin.erp.wms.reservations.store`. This is a **PAGE_TAB**, no Sidebar duplication, no new route or parallel stock ledger.
- Data authority: `inventories.reserved_quantity`; `stock_movements` reference-scoped `reserve`/`release` provenance; `StockReservationService` transaction + lock + idempotent reconciliation; `WarehouseScope` and `OperationalTenantScope` server checks. No migration, new API or replicated reservation table.
- UI gap: previously a user with inventory.view but no inventory.manage saw the reservation mutation form. The form is now rendered only when a manager has BOTH operational store inventory.manage and an authorized inventory.manage warehouse in the selected store. The existing POST always repeats its server-side store/warehouse/module checks: cosmetic form hiding is NOT its security boundary.
- Read-only users continue to see the same scoped balances, but no save or release form. AR/EN status and labels already exist in `enterprise_wms`; no new literal statuses. Existing responsive WMS tab, focusable submit button, product lookup and mobile-web grid reused.
- Error states: no inventory/warehouse displays existing empty state; scoped denial uses 404, avoiding store/warehouse existence leakage. Existing Laravel validation for invalid quantity and stable business reference preserved. Read/write routes remain protected by admin middleware and enabled `inventory_wms` module/store entitlement.

## Executable acceptance

`backend/tests/Feature/V2WmsReservationActionAuthorityTest.php`:
1. View-only store role with inventory.view can inspect AR/EN RTL/LTR reservation balances and does **not** see a reservation form. Forged POST fails 404; canonical stock/movement balances remain unchanged.
2. Authorized manager sees the actual form and POST reconciles a reserve, repeated POST doesn't create another reserve movement, zero quantity releases own reservation; audit event present.
3. Even a manager with inventory.manage cannot POST to a different explicitly blocked warehouse in the same store; read path also returns 404; unrelated stock remains unchanged.
4. Cross-store forged POST fails 404 with no other tenant stock change.
5. Disabled WMS store entitlement fails closed on POST and preserves inventory.

Existing `EnterpriseStockReservationFoundationTest` covers row lock/reconciliation, overbooking, indexed lookups and provenance; `EnterpriseWmsWorkbenchTest` covers workbench route and existing AR/EN UI. Required exact-head Policy, V2 Isolation and Laravel/MySQL/Redis/AR-EN/terminal `required-ci-gate` must pass before merge. No results from another commit count.

## Mobile impact matrix

- **Customer:** no checkout/cart/inventory availability API schema or Customer app source changes. Existing order reservation authority remains authoritative; no contract changes.
- **Driver:** B2C DRIVER-only dispatch workflow, wallet and proof unchanged.
- **Van:** B2B VAN-only consignment/route/offline replay and stock APIs unchanged. WMS reservation form is an admin browser surface; no Flutter changes needed.

## Scope and remaining acceptance

Source was inspected on Foodex_V2 and extended without modifying unrelated worker-owned WMS reports partial (#2638/PR #3231), WMS report locales or AdminNavigation. This PR is a bounded UI/action-authorization parity correction, not a claim that all future Sidebar P257 master-data or B2B/B2C ERP entitlements are released. Keep issue OPEN if exact-head CI or full owner acceptance remains unavailable. No main, release, tag or production action.
