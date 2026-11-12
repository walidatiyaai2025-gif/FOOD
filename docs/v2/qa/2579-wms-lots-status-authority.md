# V2 #2579 / P249 — Authorized lots, batches and expiry lifecycle

Source: `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx`, Sidebar matrix P249 (Procurement & Inventory / الدفعات والصلاحية), parent #2503. **Integration only Foodex_V2**, no main or production.

## Canonical placement

- Domain: Procurement & Inventory, entity: warehouse/product lot and expiry metadata.
- Personas: store-scoped authorized inventory viewer for list, separately authorized inventory manager for create/status transition.
- Goal: inspect real lot/batch identifier, product and expiry; register normalized lot metadata against a known warehouse/product; change status through the canonical active → quarantined/closed, quarantined → active/closed, closed terminal workflow.
- Placement: **Reuse → Extend** existing ERP/WMS Workbench `admin.erp.wms.index?tab=lots&store_id=...&warehouse_id=...`, `PAGE_TAB`. Visible Sidebar path: authorized ERP/WMS → Lots & expiry tab; no new sidebar route, second ledger, duplicate lot table or API. Context is carried by the existing authorized store/warehouse selectors, and products use scoped catalog selections, not typed internal IDs.
- Read: `inventory.view` plus `OperationalTenantScope`, `WarehouseScope`, store `inventory_wms` entitlement and module readiness, with canonical `InventoryLotService::lotsForWarehouse/expiryWindow`. Data and permissions remain server authoritative.
- Write: UI shows create + status forms only when effective `inventory.manage` applies both to the selected store AND warehouse. Direct POST to `admin.erp.wms.lots.store` and `admin.erp.wms.lots.status` calls `assertWarehouseMutationContext` to enforce that submitted `store_id` matches the actual warehouse owner; lot status request additionally matches actual `enterprise_inventory_lots.id/store_id/warehouse_id` (404 on mismatch) before service dispatch. This rejects hidden-form forgery even for a multi-store administrator.
- Lifecycle: current lot status is localized text; UI offers only status transitions supported by the service for an active/quarantined lot, and renders no mutation action for terminal closed lots. `InventoryLotService` remains authoritative; invalid direct transitions still fail on the backend.
- Data integrity: lot metadata and audit are in canonical `enterprise_inventory_lots`/audit_logs; `inventories`, `stock_movements` and reserved quantity are unchanged by metadata lifecycle. No migration, financial or stock movement mutation. Idempotent upsert and same-status retries preserved.

## UI acceptance

Existing FOODEX Dashboard shell, tabs, authorized record table, native form controls, localized AR/EN keys, RTL/LTR `dir`, responsive WMS breakpoints, focusable actions, semantic labels and empty/error surfaces reused. No new color family/theme or modal system. Actual status actions are meaningful for current state; no dead form for closed lots. Manual browser viewport and device acceptance deferred to final V2 audit.

## Tests and security

`backend/tests/Feature/V2WmsLotsStatusAuthorityTest.php`:
1. AR/EN RTL/LTR read-only list and expiry, no create/status UI; forged posts denied.
2. Manager create and retry idempotently, active → quarantined → closed, closed terminal no action; direct reopen rejected; audit events captured without changing stock.
3. Multi-store manager cannot forge submitted store/warehouse/lot ID, while legitimate foreign-store operation is allowed only under a correctly authorized scope.
4. Explicit warehouse restriction in the same store and B2C→B2B cross-channel denial preserve unrelated records.
5. Two roles with explicit store-level `inventory.manage` deny retain read but not mutation.
6. Roleless, revoked WMS store entitlement and disabled module fail closed.

Existing regressions: `EnterpriseWmsWorkbenchTest`, `EnterpriseWmsProfessionalCompletionTest`, `V2WmsAdjustmentsAuthorityTest`, `V2WmsLocationsAuthorityTest`, `V2WmsInventoryCountAuthorityTest`. Exact HEAD CI must prove policy, V2 Isolation, AR/EN, full Laravel tests, PHP syntax/Pint/PHPStan, security/OpenAPI, MySQL/Redis setup/upgrade/recovery and terminal `required-ci-gate`. These results are not assumed before the workflow finishes.

## Surface / app impact matrix

- Backend: **affected**, existing admin lot endpoints' exact store/warehouse/lot context authorization.
- Dashboard: **affected**, existing lots PAGE_TAB read/write projection and terminal state affordance.
- Customer: **not affected**, no public checkout/cart/pricing/availability API or Flutter changes.
- Driver: **not affected**, no B2C DRIVER-only dispatch, collection, wallet or proof changes.
- Van: **not affected**, no B2B VAN-only consignment, route, offline replay or stock API changes.
- No new mobile issues needed since no public schema/endpoint/client runtime change.

One issue → one branch `fix/2579-v2-wms-lots-status-authority` → one PR base `Foodex_V2`. No direct Foodex_V2 commits; no main, tag, release, deployment or cPanel modifications. `MANUAL_ACCEPTANCE: DEFERRED_TO_V2_FINAL` under #2503/#2750; only close leaf when source tests and exact-head required checks are green and Foodex_V2 merge is verified.
