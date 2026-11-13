# V2 #2571 / P241 — Purchase Orders canonical workbench authority

## Source and placement

- **Source:** FOODEX Unified RBAC/ERP/Sidebar discussion (2026-11-09), P241 / Sidebar 241, Procurement & Inventory. Parent: #2503.
- **Domain:** Procurement; **entity:** purchase order generated from an approved purchase request; **actors:** scoped Wholesale Procurement Buyer and independent Procurement Approver.
- **Goal:** find store-authorized purchase orders, inspect supplier/lines/status and issue or cancel a permitted order without cross-tenant disclosure or shared-role escalation.
- **Workflow:** frequent operational list/detail plus state-changing issue/cancel, bounded pagination (25 rows).
- **Placement decision:** **Reuse** (no new page, Sidebar item or route). Canonical business entry **Catalog & Pricing → Procurement** (`erp_procurement`) → `admin.erp.procurement.index?tab=orders&store_id={authorized-store}`. The Purchase Orders tab is a `PAGE_TAB` within the existing Procurement operational workbench; active store context and other sibling tabs are retained; back path is that same parent workbench.
- **Navigation owner:** `AdminNavigation` via effective `procurement.suppliers.view` and enterprise Procurement readiness; the tab itself independently requires `procurement.purchase_orders.view`. Its visibility **does not grant** a direct-route permission.
- **Placement metadata:** `domain=procurement`, `entity=purchase_order`, `role=buyer/approver`, `workflow_type=operational_list/detail/action`, `frequency=frequent`, `data_density=paginated`, `existing_parent=admin.erp.procurement.index`, `placement_type=tab`, `pattern=Operational Workbench`, `navigation_impact=none`, `desktop/tablet/mobile_web=existing responsive Dashboard`, `rtl_ltr=AR RTL/EN LTR`, `states=empty/draft/issued/cancelled/403/404`.

## Authoritative action matrix

| Capability | Permission | Buyer | Approver | Server authority |
| --- | --- | --- | --- | --- |
| View tab/detail | `procurement.purchase_orders.view` | Yes | Yes | `EnterpriseProcurementController::authorizeTab`, `PurchaseOrderManager::paginate/detail` |
| Create PO from approved request | `procurement.purchase_orders.manage` | Yes | No | `PurchaseOrderManager::createFromApprovedRequest` |
| Issue draft PO | `procurement.purchase_orders.issue` | No | Yes | `PurchaseOrderManager::issue` |
| Cancel permitted PO | `procurement.purchase_orders.manage` | Yes | No | `PurchaseOrderManager::cancel` |

All real actions also require active identity, exact Procurement module lifecycle/version readiness, effective platform/store entitlement where policies apply, authorized company/store context and authoritative server-side order scope. Request submission needs a distinct approver: a maker's self-approval is forbidden. No independent UI visibility override, blanket SUPER_ADMIN security bypass or direct-channel fallback is introduced.

## Executable P241 acceptance

`backend/tests/Feature/V2PurchaseOrdersTabAuthorityTest.php` exercises:

1. Real registered tab/action routes; AR/EN and RTL/LTR; both legitimate seeded actors can reach the same canonical workbench; `tabs` and `can` reflect actual independent view/manage/issue permissions, with normal empty state.
2. Disabled/uncertified Procurement, roleless direct HTTP, foreign Retail store, explicit global purchase-order view DENY: fail closed while an otherwise authorized Suppliers tab remains usable. No new order or mutation side effect.
3. Full real HTTP Purchase Order progression built on existing supplier/request services: Buyer creates/submits a request; an independent Approver approves; Buyer creates a draft PO and can view detail; Buyer is forbidden to issue; foreign Retail context is forbidden even to an issuer; Approver issues; Buyer cancels; persisted order status is checked after each operation. No duplicated Procurement business implementation.

Existing `EnterpriseProcurementWorkbenchTest`, `EnterpriseProcurementFoundationTest`, P240 #2570 and role/permission suites continue to cover broader lifecycle and negative cases. No assertions are weakened; this issue owns only the **P241** tab/action matrix. Security authorization remains in the backend, not just the Sidebar.

## Presentation and app impacts

- **Backend:** issue-specific Laravel HTTP regression; production Controllers, services, permissions, database and routes unchanged.
- **Dashboard:** reuse the Procurement tab/grid/detail/action UI with localized translated labels, existing visible empty states, desktop/tablet/mobile-web responsive workbench and RTL/LTR, no new navigation or CSS. Real browser/screen-reader/mobile-width acceptance deferred to #2503/#2750; not claimed here.
- **Customer app:** **not affected** — no Customer REST schema, checkout, purchase, price, identity or Flutter change.
- **Driver app:** **not affected** — no B2C dispatch, status, wallet, GPS or Flutter change; B2C **DRIVER ONLY** remains.
- **Van app:** **not affected** — no B2B delivery, visit, consignment or Flutter API change; B2B **VAN ONLY** remains.
- **Localization and accessibility:** existing translated labels, status badges, semantic actions, keyboard-reachable forms and common responsive Dashboard shell reused unchanged; automated AR/EN and RTL/LTR render assertions on the actual page. No new label or interactive control to localize.

## Mandatory merge and acceptance gates

One issue-owned branch `test/2571-v2-purchase-orders-authority` and **one PR into `Foodex_V2` only**. Before merge verify exact-final-head Repository Policy, FOODEX V2 Isolation, Required CI terminal `required-ci-gate`, backend syntax/Pint/PHPStan/security/OpenAPI, full Laravel tests, AR/EN localization and MySQL/Redis real fresh-install/upgrade/recovery where applicable. Resolve new failures on this **same** PR and do not bypass gates.

`MANUAL_ACCEPTANCE: DEFERRED_TO_V2_FINAL` under #2503/#2750; this is not a claim of live staging, production or visual-device UAT. No `main`, production, release tag, APK, installer ZIP or deployment changes.
