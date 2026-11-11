# V2 #2585 — Locations (المواقع) / Source P255

## Canonical placement and acceptance contract

- **Source / owner:** FOODEX Unified RBAC/ERP Sidebar Discussion P255, parent #2503; Procurement & Inventory → ERP WMS.
- **Domain / entity:** Warehouse Management; zones, bins and optional inventory-to-bin placement provenance. **Authoritative stock quantity remains in `inventories`; movements remain in `stock_movements`.** No parallel quantity ledger.
- **Actor / user goal:** authorized Warehouse/Inventory viewer inspects the locations grid; an operator with effective `inventory.manage` for the **selected retail store and selected warehouse** creates or updates zone/bin and assigns a bin to existing inventory. Non-manager viewers must never see enabled mutation forms, though their existing read access remains available.
- **Reuse → Extend:** `admin.erp.wms.index?tab=locations&store_id=<id>&warehouse_id=<id>`, `EnterpriseWmsController@index`, `admin.enterprise-wms` → `admin._enterprise-wms-locations`, `WarehouseLocationService`, `WarehouseScope` and `OperationalTenantScope`. **Placement:** existing `PAGE_TAB` under WMS domain with authenticated Sidebar `erp_wms`. No separate “Locations” menu or route added.
- **Interaction pattern:** one responsive tab, selectable context from authoritative Store/Warehouse lookups, two create/update inline forms and a scoped inventory-bin assignment form only for the authorized manage persona; bounded read-only zones/bins/assignments tables for readers, view-to-record link to existing Inventory Record 360. Existing page hierarchy and back navigation retained.
- **Field classification:** warehouse and store are authoritative lookups (context is submitted as hidden *untrusted*, then re-authorized server-side); zone is an active scoped lookup; inventory and bin are authoritative scoped lookups; code/name are legitimate bounded free inputs; status is an Enum from active/inactive options. No user-entered database ID or raw JSON.
- **Permissions and data scope:** GET requires effective `inventory.view` on a live B2C store plus warehouse-specific read entitlement. Form projection is identical to POST authorization: effective `inventory.manage` for store and `WarehouseScope::authorizedWarehouseIds(...,'inventory.manage')` for selected warehouse. Controller POSTs independently re-check `OperationalTenantScope::assertStore`, `WarehouseScope::assertWarehouse`, module lifecycle, entitlement/readiness and submitted zone/bin/inventory ownership; never infer access from hidden menu controls.
- **Multi-role / explicit DENY:** inherited roles combine only within the assigned retail store; an explicit user-level store DENY overrides multi-role manage and removes location forms while retaining read. Warehouse-specific `can_manage=false` denies forms and POST even when store manage is granted.
- **Module readiness:** `inventory_wms` remains *fail-closed* for disabled/uncertified/unhealthy modules under the canonical entitlement/operational readiness gate. This PR does not certify actual live ERP workflows. #2858 owns real ERP operational attestation. Synthetic certified-version setup is used only in isolated Laravel tests.
- **Audit and integrity:** Zone creation, bin creation and inventory-bin assignment persist store/warehouse provenance and audit events; rejected requests are no-ops, assignments do not change authoritative quantity or create stock movements.
- **States:** no store, no warehouse, empty lists, denied read (403/404), denied write (404), validation error, module disabled, active/inactive zone/bin. Existing WMS Blade handles empty/validation state; no new production state or API added.
- **AR/EN and responsive/a11y:** existing translated `enterprise_wms` keys, locale-specific `dir`, semantic forms/labels, context controls, accessible tab `aria-current`, FOODEX green primary actions and responsive CSS at 900px/767px retained. No change to shared navigation, component styling or app assets.

## Issue-specific automated verification

`backend/tests/Feature/V2WmsLocationsAuthorityTest.php` exercises the actual HTTP routes and database:

1. English LTR / Arabic RTL; existing Locations tab; read-only `inventory.view` GET works, three mutation forms absent, direct POST cannot write.
2. Manager POSTs real zone, bin and inventory-bin assignment; audit and provenance verified; stock quantity unchanged and no `stock_movements` introduced; read-back rows visible.
3. Store A ↔ Store B and foreign warehouse ID deny GET and POST, without creating records; narrowed warehouse-specific `can_manage=false` revokes only write.
4. Two active store roles with inherited manage and explicit per-user store DENY: read survives, manage form and POST fail closed.
5. Module entitlement disabled and roleless actors cannot access/mutate locations.

Existing suites cover WMS transfers, counts, reservations, inventory movements, state transitions and operational certification; these remain separate issue scopes.

## Backend / Dashboard / Customer / Driver / Van impact matrix

| Surface | Impact | Evidence |
|---|---|---|
| Laravel Backend | Limited authorization projection change | Canonical WarehouseScope and real POSTs unchanged; full new HTTP/DB regression |
| Dashboard | Scoped Locations view mutation controls hidden for non-managers | Same WMS route, localized forms, layout, read access and responsive CSS |
| Customer Flutter | None | No catalog, checkout, pricing, inventory quantity, API payload or authentication changes |
| Driver Flutter | None | No B2C dispatch, collection, delivery, route, status or API changes |
| Van Flutter | None | No B2B VAN ONLY dispatch, Van stock, loading, replenishment, assignment or mobile API changes |

The three apps need no additional mobile Issue for this change because the only runtime mutation is the server-rendered Dashboard's WMS Locations form visibility, backed by unchanged REST/business services. Existing API contracts and B2B VAN ONLY/B2C DRIVER ONLY remain untouched.

## CI, integration and lifecycle

- Owner lease and exact owned files on #2585; one branch `fix/2585-v2-wms-location-authority`, one PR **base `Foodex_V2` only**. Active #2713 and #2858 workers retain their independent navigation and ERP operational readiness files; do not modify them or mint module attestations.
- Before merge require **exact-final-head** Repository Policy, FOODEX V2 Isolation, PHP syntax/Pint/PHPStan/Composer audit/OpenAPI, AR/EN, Laravel feature/full backend and MySQL/Redis real install/upgrade/recovery tests, plus terminal `required-ci-gate`.
- Any failing test, UI audit, scope or module readiness check must be repaired in the same issue branch. Record actual Git SHA and CI URLs and verify the destination branch after merge.
- `MANUAL_ACCEPTANCE: DEFERRED_TO_V2_FINAL` per #2503. No claim of live deployment or manual staging/device/browser UAT. No changes to `main`, tag, release or production.
