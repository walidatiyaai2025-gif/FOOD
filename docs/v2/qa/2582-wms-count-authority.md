# V2 #2582 / P252 — WMS stock count UI and HTTP authority

Source: FOODEX Unified RBAC / ERP / Sidebar discussion 2026-11-09, matrix `P252` (Procurement & Inventory / الجرد), parent #2503. Integration is **Foodex_V2 only**, never main or production.

## Canonical placement and business journey

- Domain: Procurement & Inventory / inventory cycle counting, authorized store/warehouse inventory staff.
- User goal: inspect count sessions, select actual warehouse stock items, open a count session, enter counted values, reconcile verified differences to canonical inventory and an immutable stock-movement audit.
- **Reuse → Extend**: existing `admin.erp.wms.index?tab=counts&store_id=...&warehouse_id=...` in canonical WMS Workbench; no second count screen, alternate stock ledger, new Sidebar destination or API. Navigation: existing authorized WMS Inventory entry → **Counts/الجرد** PAGE_TAB → authorized count row → exact session. Back path: original WMS tabs/context selector.
- Existing `enterprise_wms.counts.*` locale keys and WMS responsive shell/cards/table and primary button are reused. No new primitives; Arabic/English, RTL/LTR, native inputs and labelled stock count controls retained.
- Field classification: store and warehouse are existing authoritative selectors; product inventory is the current scoped checkbox lookup, quantity is numeric bounded input, request key is machine-managed hidden idempotency field. No internal numeric ID or raw JSON is typed by a business user.

## Fail-closed implementation

- `inventory.view` permits list/detail read only. A mutation form is now rendered only with both operational store `inventory.manage` and WarehouseScope `inventory.manage` for the selected warehouse, and only eligible session rows have a manage link. The backend never relies on hidden UI as permission enforcement.
- Direct POST to `admin.erp.wms.counts.store` and `admin.erp.wms.counts.complete` repeats exact store+warehouse manage check; it rejects inconsistent store/warehouse context and rejects count ID not belonging to the submitted exact store/warehouse (404). Shared warehouse mutation context guard is also used by pre-existing Locations operations without changing their decisions.
- Count detail selection is bound to the currently selected authorized warehouse; broad store scope must not display another warehouse's session lines for a wrong selected context. The count list itself remains bounded, query-scoped by `InventoryCountService::listForStore` and its `WarehouseScope` allowlist.
- Canonical `InventoryCountService` remains source of truth for unique count sessions/idempotency, locks, expected-stock concurrency, reserved stock protection, counted-versus-expected variance, journalized `cycle_count_adjustment` movements and audit. No database/schema change, no weakened module entitlement, no new redundant service.
- Intentional 403/404 concealment and disabled `inventory_wms` lifecycle/readiness/entitlement behavior follow existing policies.

## Executable acceptance

New `backend/tests/Feature/V2WmsInventoryCountAuthorityTest.php` exercises:
1. View-only user can see localized count status/AR-EN RTL-LTR, but no Start/Complete/Manage UI, and forged POST is denied without stock change.
2. Manager sees actual form; start retries idempotently, completion + retry posts one canonical movement and audit; quantity and reserved balance correct.
3. Explicit warehouse scoping rejects same-store foreign warehouse, other B2C store and foreign B2B channel with no writes.
4. Multi-store manager cannot start or complete a session with forged mismatched `store_id`, `warehouse_id` and `count_id`.
5. Multi-role manager with explicit store-level `inventory.manage` deny retains read-only count access but cannot create.
6. Roleless actor, revoked WMS store entitlement and globally disabled module fail closed and preserve stock.

Verify exact PR head: Repository Policy, V2 Isolation, Required CI terminal gate, PHP syntax/Pint/PHPStan/Composer security/OpenAPI, Laravel full suite, AR/EN, MySQL/Redis fresh-install/upgrade/recovery and applicable UI policy tests. Results from another SHA cannot authorize merge. These results are **not yet claimed** by this plan.

## Per-application scope

- **Customer:** no public inventory price/availability or customer order API fields changed. No customer Flutter source modification required.
- **Driver:** no B2C dispatch, wallet, collection, proof or mobile API changed; Driver remains B2C-only.
- **Van:** no B2B consignment, route, offline replay or Van stock API changed; Van remains B2B-only.
- Dashboard WMS counting is internal and server-authorized; no mobile API contracts or APKs changed.

## UI audit and deferred acceptance

The existing WMS shell, tabs, shared theme, mobile-web breakpoints, localization and focusable stock count controls are reused, without a new page or menu. Tests verify effective permission/tenant/warehouse checks and localized UI states. Manual viewport/staging/browser/device UAT is **DEFERRED_TO_V2_FINAL** under #2503/#2750 and must not be claimed as completed. Do not merge before exact-head CI and security acceptance. No production, main, tags or release promotion.
