# V2 #2578 — Sidebar P248: المخزون / الأرصدة

Source: `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx` (Procurement & Inventory matrix P248 — الأرصدة); V2 master #2503. Integration branch: **Foodex_V2 only**.

## Canonical placement and authorization contract

- Domain/entity: Procurement & Inventory → authoritative per-warehouse product stock balance from `inventories`. This is not a second stock ledger, sales availability API or valuation policy.
- Actor: authenticated WMS operator, including an **inventory.view-only** store user. A stock manager with `inventory.manage` may also read, but no stock mutation occurs here.
- User goal: inspect on-hand, reserved and available stock for an authorized warehouse; open the exact balance Record 360; browse more balances without loading the entire inventory.
- Frequency/density: frequent read-only operational list and occasional drill-down; high-growth grid with keyset pagination.
- Entry point: existing authorized Dashboard Sidebar WMS Workbench `admin.erp.wms.index` → **Balances** PAGE_TAB `?tab=balances&store_id=...&warehouse_id=...`. Return/back path: WMS Workbench Overview tab and Record 360 close link.
- Placement: **Reuse → Extend** current WMS tab shell, `WmsReportingService::inventorySummary`, existing `admin._enterprise-wms-record360`, styles and company locale. Compose the smallest read-only tab; no new route, sidebar, API, migration, queue or stock ledger.
- Permission: actual server `OperationalTenantScope::allowedStoreIds/assertStore(...,'inventory.view')`, `WarehouseScope::authorizedWarehouseIds(...,'inventory.view')` + active store/warehouse, `inventory_wms` module, entitlement and readiness. WarehouseScope is applied again by the canonical WmsReportingService query and never replaced by visual hiding.
- Data: the canonical summary selects warehouses matching `store_id` and authorized `warehouse_id`, product name/SKU and on-hand/reserved quantities. Available = quantity minus reserved (3 decimal precision), derived by `WmsReportingService`; no new data writes or cost/finance details on the P248 tab.
- Detail link: existing Record 360 `tab=overview&store_id=...&warehouse_id=...&inventory_id=...`. Before invoking the detail service, the controller now checks that the *inventory row* belongs to the selected context. A manager authorized for two stores cannot forge a detail ID from store B while claiming store A, or a different warehouse within store A.
- Pagination: page size **50**, bounded service fetch **51** for lookahead, ascending keyset on canonical `inventory.id`, next cursor `after_inventory_id`; no OFFSET offset cost, no unbounded table, preserve store+warehouse on next link, no next link if exhausted. Invalid/negative cursor denied by Laravel request validation. Empty state translated.

## UI and interaction acceptance

Existing Admin shell/sidebar, FOODEX tabs and green business actions reused; no new navigation owner or CSS primitive. The read-only balances table has semantic column headings, localized AR/EN product/warehouse/on-hand/reserved/available labels, accessible scoped Record 360 link, aria-labeled next-page navigation, visible empty state, high-growth list contract and existing desktop/tablet/mobile web workbench breakpoints. No form, internal ID input, speculative button, second UI framework or unlocalized enum. RTL/LTR follows application locale. Manual browser viewport/keyboard/device observations are **not claimed**; final independent V2 UX audit #2750 owns those pending acceptance passes.

## Backend / Dashboard / Customer / Driver / Van impact matrix

| Surface | Impact | Evidence |
| --- | --- | --- |
| Backend | **affected** | Existing admin controller adds balances tab, bounded canonical report and exact record-context guard; no API route/schema/domain mutation |
| Dashboard | **affected** | Reuses current authorized WMS tab/navigation shell with `admin._enterprise-wms-balances` and existing Record 360 |
| Customer | **not affected** | No Customer API/Flutter, B2B/B2C pricing/cart/stock-availability contracts changed; read-only admin view |
| Driver | **not affected** | No B2C DRIVER-only dispatch, stock state, collection or proof API code changed |
| Van | **not affected** | No B2B VAN-only allocation/consignment, offline route/replay or warehouse API code changed |

No new mobile issue is needed absent an actual changed mobile API or route. The full backend contract + localization gates are mandatory, with no fabricated Flutter builds.

## Exact-head acceptance tests

Dedicated `backend/tests/Feature/V2WmsBalancesPageAuthorityTest.php` covers:

1. Viewer-only authorized balance tab discovery, AR/EN RTL/LTR and canonical quantity=10/reserved=2/available=8, no stock mutation UI or ledger changes.
2. Explicit same-store warehouse scope, B2C cross-store and B2B channel denial; no foreign product leakage.
3. Dual-store viewer's Record 360 can only open the correctly selected store+warehouse inventory ID; forged store/warehouse/inventory triple 404.
4. 56 inventory rows → bounded 50 first page plus 6 on next; exact keyset cursor, no duplicates/omissions, no stale next link.
5. Empty authorized warehouse presents localized empty state and no pagination link.
6. Roleless actor denied; two store roles plus explicit `inventory.view` deny fail closed.
7. Revoked store entitlement and disabled WMS module fail closed without deleting stock.

Regression check also covers existing WMS Overview/Inventory360, #2584 warehouse catalog, #2582 counts, #2579 lot lifecycle and #2580 adjustments. Exact PR HEAD must pass Repository Policy, V2 Isolation, Backend syntax/Pint/PHPStan/security/OpenAPI, AR/EN, full Laravel tests, real MySQL/Redis clean install/upgrade/recovery and terminal `required-ci-gate`. This document does not claim unobserved success.

## Delivery safety

Issue ownership is public on #2578; branch `feat/2578-v2-wms-authorized-balances`, one PR base `Foodex_V2`, SHA-guarded merge only on exact-head green checks, verify branch cleanup and close only completed leaf. `MANUAL_ACCEPTANCE: DEFERRED_TO_V2_FINAL`; umbrella #2503 and stage-UX #2750 stay open. No merge/push into main, no release, tag or production/cPanel deployment.
