# V2 P247 — authorized WMS stock movements ledger

**Leaf:** #2577 (P247, Procurement & Inventory), parent #2503. **Target:** `Foodex_V2` only. No release or production activity.

## Placement and runtime authority

- **Domain:** Procurement & Inventory. **Entity:** canonical `stock_movements` / `inventories`. **Actor:** inventory operator with effective `inventory.view` at authorized store and warehouse.
- **Goal:** trace movements, quantity, reason and time for a selected warehouse, with an ordered and bounded 100-row ledger. Frequent read-only workbench task; no finance or stock mutation.
- **Navigation:** Catalog & Supply → existing WMS entry `erp_wms` → **Movements** page tab. Canonical route `GET admin.erp.wms.index?tab=movements`, rendered by `EnterpriseWmsController::index` / `admin.enterprise-wms`. The selected tab retains the store and warehouse context. Back/fallback: WMS Overview tab.
- **Placement decision:** REUSE the existing WMS workbench tab; no duplicate Sidebar item, generic URL-only page, new ledger, controller or route. Keep existing responsive table, visible tab, AR/EN labels, RTL/LTR and empty state. No changes to DOM/UI styles/accessibility. No raw warehouse IDs entered by business users; existing authorized warehouse selector is reused.
- **Effective authorization:** authenticated Laravel Dashboard session, server-side `inventory.view` and `OperationalTenantScope`, authorized warehouse IDs from `WarehouseScope`, WMS store entitlement and exact-version operational readiness from existing module guards. No independent presentation visibility permission.
- **Source ownership invariant:** an authorized inventory ID/warehouse is insufficient to trust a movement row. Every listed `stock_movements.store_id` must equal the store verified for the request. The P247 fix adds this secondary predicate to the existing Movements query; never reassigns or rewrites historical movement records.
- **Denials:** roleless actor: 403; unauthorized store or warehouse: 404; narrowed explicit warehouse scope: 404; disabled WMS store entitlement: 404. No fallback to a broader warehouse, no bypass for Super Admin, and no implicit ERP module certification.

## Implementation / verification

- **Backend:** one narrowly scoped read-query predicate in `app/Http/Controllers/Admin/EnterpriseWmsController.php` prevents cross-store movement provenance from appearing even when the movement points at an authorized `inventory_id`.
- **Dashboard:** existing Movements tab and its role/store-scoped route, bilingual title and table are reused. Positive data and cross-store forged data are asserted from the actual rendered HTTP response and view read-model.
- **Automated feature coverage:** `tests/Feature/V2WmsMovementLedgerStoreIsolationTest.php` uses `RefreshDatabase` with disposable stores, inventories and movements. Positive same-store movement remains visible; a foreign-store movement referencing the **same authorized inventory ID** is excluded; a different-store warehouse is excluded; selected store/warehouse and explicit warehouse scope are denied; roleless and disabled entitlement requests fail closed; English LTR and Arabic RTL render.
- **No unsafe data migration or data-repair presumption:** inconsistent rows are not silently assigned new owners; this test proves a read boundary, not reconciliation of legacy stock.

## Cross-app impact classification

| Surface | Classification | Reason |
| --- | --- | --- |
| Backend | Affected | Read-only Dashboard stock movement query gains a store equality predicate; canonical stock ledger write schema unchanged |
| Dashboard | Affected, no markup change | Existing authorized WMS Movements page now excludes foreign-store movement provenance |
| Customer Flutter | Not affected | No Customer API, order/cart, credit, checkout or auth change |
| Driver Flutter | Not affected | No driver assignment, tracking, evidence, collection or API change; B2C Driver-only invariant preserved |
| Van Flutter | Not affected | No Van load, route, consignment, settlement or API change; B2B Van-only invariant preserved |

New mobile Issues are not appropriate for this read-only Admin Dashboard predicate, because neither API payload nor Flutter behavior changes.

## Gate

Laravel positive/negative feature tests, Repository Policy, V2 Isolation, localization, full Required CI and MySQL install/upgrade/recovery must succeed on the PR exact-head before merge. Merge **only to `Foodex_V2`**, using one branch and one PR. Record exact tested and merged SHAs. Manual/device/staging UAT remains `DEFERRED_TO_V2_FINAL` under #2503; a green CI is not production release permission.
