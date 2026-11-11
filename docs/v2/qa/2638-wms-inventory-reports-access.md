# V2 #2638 / Sidebar P308 — Inventory reports (WMS)

Source: FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx / P308; parent #2503. Isolated destination **Foodex_V2 only**. This is an issue-scoped executable evidence and UX correction, not a new inventory ledger, backend reporting engine, or release.

## Canonical placement and route authority

- Domain: Reporting & BI → inventory; source entity: warehouse/inventory; roles: authorized inventory-read operators, store-scoped Retail Inventory and permitted platform operators.
- User goal: inspect current inventory valuation, low/out-of-stock, slow/aging, lot expiry, count variance, adjustment exceptions, transfer performance and demand planning without leaving authorized store or warehouse scope.
- Placement: **Reuse/Extend** existing WMS Workbench inventory reporting tab rather than creating a duplicate admin page or Sidebar. Canonical path: Admin → Inventory/WMS Workbench → **WMS reports** tab; route `admin.erp.wms.index?tab=reports`. Existing inventory/workspace navigation remains authoritative; no new Sidebar item, route, or shadow visibility policy.
- Existing data sources: eight `WmsReportingService` read models and bounded queries, `WarehouseScope` scoped warehouse identities, `OperationalTenantScope` exact store permissions, `inventory_wms` enterprise module/store entitlement, financial valuation joined only to active approved policy. No report creates/updates stock movement or reservation.
- Existing cards resolve real canonical tabs for selected store/warehouse; previously displayed raw `wms_*` read model codes in the user interface. The changed partial replaces them with AR/EN localized **Open report / فتح التقرير**, preserving the existing meaningful report title and deep link.
- Permission: server-side `inventory.view`; unauthorized requests fail 403. Foreign authorized store fails 403, foreign warehouse fails 404, disabled warehouse-module entitlement fails 404. No frontend hiding substitutes for HTTP authorization.
- UI: existing WMS shell, nav context `erp_wms`, FOODEX theme, native focusable anchor cards, grid responsive below 900/767px. No new IDs in forms; AR/EN catalogs, RTL/LTR from document shell. Existing empty and bounded states remain.

## Acceptance / tests

`backend/tests/Feature/V2WmsInventoryReportsAccessTest.php` tests:
1. Real canonical authorized `tab=reports` HTTP response for each language, with all eight report models linked, translated action and title, RTL/LTR, absence of internal read model names.
2. Exact store permission denial and warehouse concealment against a second store.
3. Denial of Customer Service Agent without `inventory.view` and of a formerly authorized inventory user after enterprise WMS store entitlement is revoked.
4. Before/after check for unchanged inventory quantity, reserved quantity and stock movement count.

Existing regression includes `EnterpriseWmsProfessionalCompletionTest` and `EnterpriseWmsReportingFoundationTest`, including query bounds, valuation and report data shape. Exact-head Laravel, localization, security, Policy, V2 Isolation and deployment acceptance CI must pass before merging. No production database was modified.

## Mobile impact (individually assessed)

- **Customer:** not affected; no customer order, stock API, UI/identity payload or price change.
- **Driver:** not affected; B2C DRIVER-only dispatch API and lifecycle unchanged.
- **Van:** not affected; B2B VAN-only routes, trip/consignment data and offline contracts unchanged.

No Flutter source or public API schema changes; new mobile Issue would be inappropriate for this strictly admin-only report UX/test change.

## Scope and closure

This PR adds enforceable acceptance to a pre-existing production WMS reporting route; it does not claim complete historical 177-node target Sidebar tree delivery or any cPanel acceptance. If exact-head CI is queued, leave #2638 **OPEN**. Close only on authenticated evidence that the existing Inventory/WMS navigation is reachable for the intended authorized business actors, exact-head Required CI green, AR/EN functional coverage and accepted P308 source-to-route mapping. Never promote to main, create release tags or deploy.
