# V2 P258 — Van loading boundary / non-acceptance evidence

**Issue:** #2588 (P258, Procurement & Inventory) · Parent #2503 · Target: `Foodex_V2` **only**.

## Verified source-versus-runtime boundary

- Canonical WMS entry point: `admin.erp.wms.index?tab=transfers`, backed by `EnterpriseWmsController`, `WarehouseTransferService` and `WarehouseScope`. Its existing transfer lifecycle is **warehouse → warehouse** (draft, dispatched, received), not **warehouse → van**.
- `EnterpriseWarehouseTransfer` stores `source_warehouse_id` / `target_warehouse_id`; its ledger records `warehouse_transfer_out` / `warehouse_transfer_in` on `inventories`. There is no van-target identity in the transfer's audited fingerprint, warehouse scope, or destination stock record.
- `van_inventory_history_documents` / `van_inventory_history_lines` hold **legacy imported records** (migration `2026_10_20_123000_create_van_inventory_history.php`). They do not establish a live, writable, reconciled van stock ledger or authorized dispatch/return workflow.
- Therefore a WMS transfer must not silently accept a supplied `van_id` as though it assigned physical stock to a B2B van.

## This PR's bounded, safe improvement

- Fail closed on `van_id` in all three warehouse-transfer HTTP mutations (create, dispatch, receive), using Laravel's `missing` validator.
- Add `V2VanLoadingAuthorizationContractTest`: an authenticated ERP actor supplying forged van-target metadata must receive a validation error; no WMS transfer, transfer lines, warehouse movement, or corresponding audit event may be created.
- Preserve existing WMS warehouse-to-warehouse transfer semantics and P251 authority. Do **not** add a misleading live `تحميل للفان` link or count a warehouse-to-warehouse movement as a van load.
- No new localized UI wording; framework validation messages already use the selected locale. No new route/permission/API schema.

## Unmet P258 acceptance (issue stays OPEN, PR remains DRAFT)

A future fully implemented Van Loading workflow requires an approved **canonical stock ownership model**, not an ad hoc rebranding of WMS transfers:

1. Resolve the Van loading source and destination: B2B Wholesale store, warehouse inventory/lot/reservations → a specific authorized `van_id` + route/trip/assignment + auditable van balance and reconciliation. Never B2C Driver.
2. Define exclusive maker/checker permissions and explicit inventory/van-scope entitlements, cross-store/store-role/tenant denial, independent approvals for loading/returns, and forced idempotency.
3. Ensure transactional quantity and monetary valuation movements, reversals/returns, negative-stock protection, immutable history, and safe handling of retries, offline Van replay and partial loads.
4. Place a localized, accessible loading workbench under the canonical WMS / Van operations journey (Reuse → Extend → Compose before New Screen); provide real authorized links and route checks, not a dead/sidebar-only link.
5. Build AR/EN, RTL/LTR, responsive and negative/allow/multi-role/stale-state tests; inspect actual staging workflows end-to-end and obtain exact-head CI before declaring P258 accepted.

## Navigation / app impact matrix

| Surface | Current change | Real P258 acceptance path |
| --- | --- | --- |
| Backend & Dashboard | WMS transfer HTTP metadata validation only; no new form or route | Canonical scoped Van Loading command, inventory ledger + contextual tab/action and audit |
| Customer | **Not affected**: no Customer-facing API, pricing, orders or auth contract changed | Reassess only if availability/fulfillment contracts change |
| Driver | **Not affected**: B2C Driver cannot be used for B2B Van fulfillment | No Driver fallback; prohibit cross-channel assignment |
| Van | **No runtime change yet**; Van-app consignment/reconciliation acceptance is separately tracked in #2739 and B2B route authority in #2738 | Linked actual affected Van issue(s) require authoritative load/return/offline parity and same exact-head acceptance |

**Release policy:** no main merge, release tag, APK publish, cPanel deployment or production claim. This increment is **not a completed P258 loading feature** and cannot be merged or used to close #2588 solely because generic CI passes.
