# V2 #2547 / P217 — الفشل والإرجاع / Failures and returns

**Source:** FOODEX Unified RBAC/ERP/Sidebar Discussion 2026-11-09, P217 (Orders & Fulfillment), parent #2503. **Integration:** Foodex_V2 only. Manual staging/browser/device UAT: **DEFERRED_TO_V2_FINAL** per the owner's November 10 decision.

## Placement decision and navigation contract

| Dimension | Resolved product authority |
|---|---|
| Domain / entity | Orders & Fulfillment / persisted Order and its audit/status history |
| User goal / actor | Operations viewer reviews failed delivery orders and refunded orders within their assigned B2C store or authorized B2B business channel |
| Workflow / density / frequency | Frequent read-only triage and exact-record follow-up, server-paginated operational grid, status tabs, modal timeline and existing context actions |
| Existing parent / route | Operations → Orders in AdminNavigation: key operations / order_operations, canonical GET admin.operations.orders.index |
| Placement / navigation type | Reuse and minimally Extend the existing Orders status-tab row. PAGE_TAB named "الفشل والإرجاع" / "Failures & refunds", backed by existing validated exception filter; no new Sidebar child, standalone page, duplicated route or authorization grant |
| Active context / back path | GET /admin/operations/orders?channel=b2b|b2c&store_id=...&exception=failed_returns&action_required=0#order-tab-orders. All/status tabs clear the exception filter while preserving authorized channel/store; clicking an order opens the existing exact-record detail and Timeline; close returns to Orders workspace |
| Server permission / tenant scope | orders.view with OperationalTenantScope applied to real Order query; unauthorized channel 403, foreign store / record 404; per-user DENY defeats role grants; disabled or roleless actor cannot acquire Orders access from Sidebar placement |
| Existing ERP / entitlement / readiness | Core Orders read ability; no newly invented feature flag, module entitlement, or runtime readiness override; live effective server authorization stays authoritative |
| Mobile browser / AR-EN / accessibility | Existing responsive Dashboard tabs, accessible anchor focus and aria-current selection, existing right Sidebar/drawer, AR RTL and EN LTR labels from matching localization keys; same route and no JS-only authorization or new page shell |

## Real functionality and status semantics

The failure/returns tab uses validated exception value failed_returns. It lists **failed** and **refunded** orders, and *not* unrelated cancelled or delivered orders. The existing cancelled_refunded exception remains available as a separate historical cancellation/refund grouping. Server-side exception summary supplies a count for the new category. This is **not** a new credit/refund operation, financial reversal, restocking request or returned-goods receipt. The existing OrderController status transition authority remains unchanged; any future physical goods-return or credit workflow must have its own domain requirements, ledger/inventory controls, permission and atomic Issue rather than reusing a display filter as write authority.

An existing bug allowed **refunded**, financially terminated orders with stale awaiting_dispatch rows to appear in "Action required" and waiting-to-dispatch operational counts. The backend and read-side dashboard now exclude refunded (as well as other terminal completion/cancellation states) from those queues. **Failed** delivery orders remain eligible for dispatcher review if their actual dispatch state is awaiting_dispatch, because the canonical order transition model permits retry from failed. Treating all failure statuses as irreversible would hide real remediation work.

Refunded orders are terminal for inline status actions; the existing Orders controller no longer offers "Cancel" against a refunded order. There is no new transition, API, webhook, ledger, notification, refund creation, inventory mutation or fulfillment actor change. B2B remains **VAN ONLY**, B2C **DRIVER ONLY**.

## Automated acceptance and cross-application review

New Feature suite: backend/tests/Feature/V2OrderFailureReturnsSidebarHttpContractTest.php

- Real sidebar discovery, AR/EN and RTL/LTR, visible queue link with localized text, accurate scoped failed+refunded counts.
- Actual exception-selected HTTP grid includes only matching failed/refunded orders, scoped to authorized channel and store; no cancelled/delivered/foreign rows.
- All/status-tab navigation clears stale exception and preserves channel/store rather than silently stacking contradictory filters.
- B2C store admin cannot query foreign store / B2B or open another order's Timeline; Super Admin B2B and B2C views remain isolated.
- Refunded orders with stale awaiting_dispatch remain outside actionable/count dashboards, with no inline cancellation action; failed orders with awaiting_dispatch remain visible to operations.
- User-level orders.view DENY, roleless and inactive actor fail closed. View-only orders.view does not gain orders.manage or orders.dispatch mutation capability.
- No new raw DB IDs or status inputs are introduced to the business UI.

| Surface | Impact |
|---|---|
| Dashboard | **Affected:** existing Orders status-tab and backend exception query/counters now have scoped failure/refund triage; no extra navigation tree or page |
| Laravel API / Backend | **Internal read-only service affected; API endpoints and write/status lifecycle contracts NOT changed.** Existing managed order data and workflow state machine remain authoritative |
| Customer Flutter | **Not affected:** existing status and refunds read contract unchanged; no new customer action, deep link, request or response |
| Driver Flutter | **Not affected:** B2C driver failure events, proof capture, notifications, assignment retry semantics unchanged |
| Van Flutter | **Not affected:** B2B Van order execution, visits, returned goods and proof APIs unchanged |

No Customer/Driver/Van implementation Issue is required because none of those apps' actual contracts changes. Existing shared behavior remains covered by backend full tests; no assumption that visual UAT or app builds passed from this scoped PR.

## UI / functional audit

- Reuse current Admin shell, Tajawal typography, brand tokens, responsive tab scroll and existing accessible anchor; no new style primitive or component.
- A real authorized operations route, record query and exact Timeline are used instead of a cosmetic/dead URL.
- Bilingual keys added in ar/en order_operations language catalogs; localization gate checks key parity.
- Roles, channel/store scope, per-user DENY and disabled states are enforced independently of link visibility.
- Query stays within existing indexed orders.status and server-side page limits; aggregate count is computed alongside existing summary scan, not as per-row requests. Both list and count apply the current actor's scope.
- No new form, destructive action, hidden technical input or client-side state authority.

**Closure requires:** one issue, branch fix/2547-v2-failure-refund-operations-queue, one PR into Foodex_V2 with all exact-head required Repository Policy, V2 Isolation, AR/EN Localization, Backend Tests, Fast-Fail Pint/static-analysis/security/OpenAPI, MySQL/Redis fresh install/upgrade/recovery, and terminal required-ci-gate SUCCESS. Verify merge SHA matches the integrated branch and record evidence in Issue; close only when actual technical scope is complete. Parent #2503 stays OPEN for full UAT and signed Setup/Update program acceptance. **No main merge, release, tags, production deployment.**
