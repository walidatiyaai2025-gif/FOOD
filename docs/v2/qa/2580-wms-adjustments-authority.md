# V2 #2580 — P250 Stock adjustments (التسويات), exact warehouse authority

Source: `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx`, Sidebar matrix P250, Procurement & Inventory; native source paragraph under the Inventory section. Parent #2503, isolated integration `Foodex_V2` only.

## Canonical business implementation

- Actor: authenticated store Inventory Manager for write, Inventory Viewer for read; no cross-tenant/store/channel/warehouse bypass.
- Entry point: existing authorized ERP WMS Sidebar link → `admin.erp.wms.index?tab=adjustments&store_id=...&warehouse_id=...` (PAGE_TAB) → scoped stock/product selection → reason + adjustment classification → canonical adjustment movement. No new route, duplicate Sidebar node, stock ledger or new database entity.
- Placement: **Reuse → Extend** the established WMS Workbench, not a parallel management screen. Existing tabs, responsive grid/table, AR/EN labels, RTL/LTR, focusable native fields and primary action are reused. Warehouse/product selections use server-owned lookups, not free-text internal IDs.
- Read: `inventory.view` with `OperationalTenantScope` and `WarehouseScope`, active store/matching warehouse, enabled `inventory_wms` module/entitlement/readiness. Read-only users can inspect authoritative scoped adjustment movements without the mutation form.
- Write: POST `admin.erp.wms.adjustments.store` requires BOTH `inventory.manage` for submitted operational store AND manage authority for the exact submitted warehouse. `assertWarehouseMutationContext` compares true `warehouse.store_id` to the submitted `store_id`, even for actors assigned to multiple stores. Server refuses mismatches with 404. This is server authorization, not UI-only hiding.
- Data: canonical `InventoryAdjustmentService::post` retains transactional warehouse locks, canonical `inventories.quantity`, `reserved_quantity`, idempotent request key/movement reference, damage/loss nonpositive delta rules, audit event and capture. Replays do not create additional movements; conflicting replays reject. No change to shared financial/business or API contracts.
- Fail closed: roleless 403; explicit store-level manage deny wins even across multiple roles; explicitly blocked same-store warehouse 404; unauthorized B2C→B2B 404; store entitlement revoked 404; module disabled 403; no unauthorized write or ledger changes.
- UI interaction safety: reason required, quantity and classification validated, reserved-stock invariant preserved, server errors exposed through existing WMS form error surface; no new shared UI primitives. Same page shell at desktop and mobile breakpoints.

## Executable acceptance

Added `backend/tests/Feature/V2WmsAdjustmentsAuthorityTest.php` covering:
1. AR/EN RTL/LTR read-only page and no action form; direct forged POST denied and stock unchanged.
2. Manager can submit and repeat same request safely, single canonical stock movement, expected stock quantity and audit, history readback; conflicting replay rejected.
3. Multi-store forged hidden context rejected while legitimate second-store action remains supported.
4. Explicit same-store warehouse scope and cross-channel denial preserve unrelated inventories.
5. Two assigned roles with explicit `inventory.manage` deny retain read but not mutation.
6. Reserved-quantity floor and foreign product cannot be changed.
7. Disabled WMS entitlement/lifecycle and roleless actor fail closed.

Existing relevant regressions: `EnterpriseWmsWorkbenchTest`, `V2WmsInventoryCountAuthorityTest`, `V2WmsReservationActionAuthorityTest`, `V2WmsLocationsAuthorityTest`. CI must verify exact PR HEAD: PHP syntax, Pint, static analysis, Composer security, OpenAPI, AR/EN, full backend tests, real MySQL/Redis installation/upgrade/recovery, repository policy, FOODEX V2 Isolation Gate and terminal `required-ci-gate`. This documentation does not assert that those jobs have run or passed before GitHub reports their final conclusion.

## Backend / Dashboard / Customer / Driver / Van impact matrix

- Backend: **affected**, Admin WMS POST authorization guard; no public API v1/schema/permissions change.
- Dashboard: **affected**, existing WMS Adjustments tab projects real manage authority and hides form for view-only actors.
- Customer: **not affected**, no checkout, cart, pricing or inventory-availability API schema/client code changed. Existing stock truth remains server owned.
- Driver: **not affected**, B2C DRIVER-only dispatch, wallets and delivery proofs unchanged.
- Van: **not affected**, B2B VAN-only consignment, van-route/offline replay and stock payload unchanged.
- No new app Issue required because no API/mobile contract changed; no Flutter edits performed.

## Delivery and deferred UAT

One Issue → one branch `fix/2580-v2-wms-adjustments-authority` → one PR, base `Foodex_V2` only; exact-head green CI before guarded merge; delete merged branch and close leaf only on verified implementation completion. Owner-directed manual staging, browser widths, accessibility and device acceptance remain `MANUAL_ACCEPTANCE: DEFERRED_TO_V2_FINAL` under #2503/#2750, and are not claimed tested here. No main integration, release, tag, APK publication or production deployment.
