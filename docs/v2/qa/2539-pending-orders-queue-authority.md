# V2 #2539 — P209 Pending Orders | الطلبات المعلقة

## Source and placement authority

- Document: `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx`; Sidebar IA legacy matrix **P209**, actual DOCX paragraph **P198**; parent #2503. Integration branch **Foodex_V2 only**.
- Domain/entity: Orders & Fulfillment / Sales Order. Persona: an authorized platform order manager or B2C store operator; intent: find outstanding `pending` orders for exactly one authorized channel and store, open the existing row/detail and perform only independently permitted actions.
- Decision: **Reuse → existing Orders Operations page**. Navigation owner: **Operations** business group; `navigation_type=PAGE_TAB` under the existing `order_operations` Sidebar item. Canonical route: `admin.operations.orders.index` with `status=pending&action_required=0` and an authorized `channel`, optional `store_id`; anchor `#order-tab-orders`. No second Sidebar, route, controller, queue table or permission.
- The existing status tab now shows exactly **الطلبات المعلقة / Pending orders** (instead of a generic `Pending` caption), using the same `pending` lookup code and count. The tab receives `data-order-pending-queue=P209` and a localized accessible name. All other status labels remain the authoritative operational lookup labels.
- Route is reachable from the same Orders workbench and visible under its status tabs on desktop and responsive Dashboard mobile web. Existing one-line filter bar, horizontally usable status tabs, accessible focus/keyboard semantics, grid, pagination, row 3-dots, drawer and return path are retained.

## Authorization, state, data, readiness

- The Sidebar item is a projection of the existing `orders.view` capability and business scopes; it is **not** an alternate authorization policy. The HTTP controller resolves `availableOperationalChannels` and `OperationalTenantScope::allowedStoreIds` before the pending status filter, so input `store_id` cannot expand a scope.
- The same query restricts **channel** (`b2b` for the single wholesale principal, or `b2c` for assigned active Retail stores) and status `pending`. `channel=all` is forbidden. Store-specific and roleless/explicitly denied accounts cannot see unrelated orders even with a hand-typed URL.
- A disabled user does not receive operational scopes; inherited roles with inactive assignments/stores do not grant view access. No new module or entitlements are enabled. Inactive **lookup statuses** remain readable if historical records require them; the P209 tab does not invent a workflow transition or override operational status policy.
- The link explicitly includes `action_required=0` so it cannot inherit a customer-service default dispatch exception and silently omit otherwise pending orders. All status/actions remain server-authoritative. Editing, dispatch and finance remain independently protected by their existing permissions and workflows.
- Existing server-side `paginate($perPage)->withQueryString()` preserves status, channel, scoped store and page navigation. No unbounded historical listing or client-side-only filtering.
- Empty/pending/data/error states are existing canonical Orders behaviors. No new enqueue, notification, dispatch, finance posting, mutation or asynchronous job is introduced.

## Automated acceptance

`backend/tests/Feature/V2PendingOrdersQueueAuthorityTest.php` proves:
1. AR/RTL and EN/LTR discovery from the existing authorized Operations Sidebar; the explicitly labelled P209 tab has correct active state and exact `pending` rows, excluding another status and another store.
2. Server-side page three of 26 synthetic pending orders; total/per-page/current-page and query-string preservation; no foreign-store or delivered leakage.
3. B2C store admin sees its own pending order, cannot forge foreign-store scope or switch into Wholesale; disabled identities are forbidden, rows untouched.
4. Wholesale principal pending queue remains B2B-only even when a Retail order is also pending; the global `all` channel is forbidden.
5. Roleless and explicitly store-**DENY** users receive HTTP 403, and an unknown status returns JSON 422 before any sensitive data.
6. CI acceptance: PHP syntax + Pint/Static Analysis/Composer audit/OpenAPI, full Laravel suite, AR/EN Localization, V2 Isolation, Repository Policy, real MySQL/Redis install-upgrade-recovery and terminal required-ci-gate on the **last PR SHA**.

## Customer / Driver / Van impact matrix

| Surface | Impact | Evidence |
|---|---|---|
| Backend / Dashboard | **Affected** | Only in-page Orders tab label/ARIA and safe feature regressions; no new HTTP/API contract |
| Customer Flutter | **Not affected** | No Customer route, API, status value, notification, credit, order response or screen code changes |
| Driver Flutter | **Not affected** | No Driver API/assignment/status/proof/notification change; **B2C → Driver ONLY** remains intact |
| Van Flutter | **Not affected** | No Van API/assignment/territory/visit or dispatch change; **B2B → Van ONLY** and `awaiting_dispatch` exception remain intact |

## Delivery and remaining gates

- Keep each issue on exactly one branch/PR targeting `Foodex_V2`. Merge only after all Required Checks reach terminal SUCCESS on the last SHA; close #2539 only after acceptance, merged SHA and destination SHA verification. Parent #2503 must stay open.
- Automated route/permission/localization/pagination checks are mandatory now. Browser/device/staging visual UAT is deferred to the owner-approved final V2 gate and is not falsely claimed by CI.
- No modifications, merge or cherry-pick to `main`; no release/tag/production deployment/cPanel operation.
