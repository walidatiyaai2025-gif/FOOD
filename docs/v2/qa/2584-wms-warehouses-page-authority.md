# V2 #2584 / P254 — Authorized Warehouses catalog (P243 native source paragraph)

- Source: FOODEX Unified RBAC/ERP/Sidebar discussion (2026-11-09), `P254` (actual DOCX paragraph `P243`). Parent #2503.
- Integration: `Foodex_V2` only. No main changes, release, tag, build publication, production data access or deployment.
- Classification: Procurement & Inventory → Warehouse master, repeated read-only catalog and exact-record navigation. `PAGE_TAB` at the existing `admin.erp.wms.index?tab=warehouses&store_id=...` WMS Workbench. **Reuse → Extend**, no new Sidebar route, DB table, API endpoint, duplicate warehouse model or stock ledger.
- Navigation: existing authorized Inventory / ERP WMS Sidebar → **Warehouses** tab → exact authorized warehouse card → WMS **Stock overview** for that warehouse; canonical Sidebar group owned by separate #2583. The selected store and warehouse come from existing business lookup selectors, never typed technical IDs. Back path: same WMS tabs and context controls.
- Authority: `EnterpriseWmsController::index`, `OperationalTenantScope::allowedStoreIds('inventory.view')` and `WarehouseScope::authorizedWarehouseIds('inventory.view')`; `inventory_wms` entitlement + module readiness. Server denies unauthorized direct `store_id`/`warehouse_id` requests; frontend uses **only** server-filtered `$warehouses` and link to canonical overview. Permission denial returns 403 or concealed 404 according to the existing boundary; disabled module denies 403, store entitlement denies 404.
- Data: canonical `warehouses` read-only list (active, scoped, ordered); no stocks/movements mutation, legal ownership inference or new warehouse-creation workflow. B2C warehouse creation remains owned by authorized Retail workspace; B2B principal-only warehouse creation is prohibited by the current canonical wholesale contract. This issue does not enable a bypass through the generic legacy business management routes.
- UI: existing FOODEX Dashboard shell, `foodex-tabs`, `foodex-viz-card`, `wms-report-card` and existing responsive grid. `data-wms-warehouses` identification, text label plus code, native focusable anchor, `aria-current` for current warehouse, semantic empty state, Arabic RTL/English LTR via locale keys. No new theme/palette/navigation UI. Touch/keyboard inherit shared surfaces; no additional mutation workflow.
- API/contracts: no changes to API v1, orders, inventory movement data, dispatch, permissions, schema or public mobile payloads.

## Automated acceptance evidence

`backend/tests/Feature/V2WmsWarehousesPageAuthorityTest.php` checks:
1. AR/EN authorized warehouse list and canonical exact-record link; active and foreign-store filtering; no ledger mutation.
2. Explicit warehouse scope filtering even inside the same store, direct unauthorized warehouse, cross-store and B2C→B2B cross-channel 404.
3. Two store roles retain authorized view, but explicit store-level inventory.view deny wins (403); roleless 403 and revoked store/module entitlement 404.
4. Disabling WMS module lifecycle denies 403 and retains canonical warehouse records.
5. AR/EN empty warehouse catalog safely renders the shared empty state, with no nonexistent warehouse links.

Additional already-existing regressions: `EnterpriseWmsWorkbenchTest`, `V2WmsLocationsAuthorityTest`, `V2WmsInventoryReportsAccessTest` and `V2WmsReservationActionAuthorityTest` preserve store/warehouse WMS data and mutation contracts. Exact PR HEAD Required CI, Repository Policy, V2 Isolation, Laravel/Pint/PHPStan and localization must all be green before integration. No local run or manual browser audit is claimed by this document.

## App impact classification

- **Customer:** Not affected; no B2C checkout, inventory availability, store/tenant selector or customer API changed.
- **Driver:** Not affected; B2C DRIVER-only routing, wallet, proof, assignment and notifications unchanged.
- **Van:** Not affected; B2B VAN-only consignment/route/offline stock API unchanged.
- Accordingly no Customer/Driver/Van source changes or new app Issue required for this read-only Admin-page catalog.

## UI audit / acceptance notes

- Functional route, role, store, warehouse, entitlement, readiness and AR/EN RTL/LTR assertions are implemented as executable Laravel regressions.
- Layout reuses the WMS card grid with existing mobile breakpoints; no new grid code. CI must evaluate structural/accessibility/localization gates.
- Manual browser viewport and owner-device acceptance are **DEFERRED_TO_V2_FINAL** under #2503; no claim of manual visual audit.
- If tests fail, repair this same branch and PR; leave issue open until exact final-head checks pass and merge to `Foodex_V2` is verified. Never merge to main or release.
