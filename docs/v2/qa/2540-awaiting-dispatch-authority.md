# V2 #2540 — P210 Awaiting Dispatch | بانتظار التوجيه

## Requirement and source
- Discussion: `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx`, legacy Sidebar matrix **P210**, canonical DOCX paragraph **P199**; parent #2503; integration `Foodex_V2` exclusively.
- Business domain: **Orders & Fulfillment**, queue for orders with an actual authoritative `order_dispatch_states.status=awaiting_dispatch`, not a generic `orders.status=pending`. P209 is a separate pending order queue, now merged at #2539.
- Actor: authenticated store/channel-authorized user with `orders.view` (Operations / Customer Service / Super Admin according to effective authorized role and scope). No permission is granted by seeing a tab.

## Placement — REUSE, no duplicate Sidebar
- Authority: existing Operations → Orders `admin.operations.orders.index` and its `order_operations` Sidebar entry, Orders status/action tabs. `navigation_type=PAGE_TAB`; `parent_surface=Operations > Orders`; `role=orders.view`; `entry=existing authorized order_operations`.
- Existing **Action required** status tab already resolves `action_required=1` to the real `awaiting_dispatch` filter server-side; it shows only non-terminal orders whose dispatch state requires assignment. Rename this one tab to `order_operations.dashboard.exceptions.awaiting_dispatch` (AR: **بانتظار التوجيه**, EN: **Awaiting dispatch**) and mark `data-order-awaiting-dispatch-queue=P210`. Do **not** add a second duplicate queue, HTTP route or Sidebar item.
- Localization and UX: reuse matching AR/EN catalog key, RTL/LTR, `aria-label`, `aria-current=page` and existing scrollable one-line `.status-tabs` on desktop/tablet/320px mobile web. Keep the count from the same server-filtered `actionRequiredCount`, with all navigation and detail/back paths already supported by the canonical page. Preserve the existing CSS/tokens/shell and row-action permission checks.
- The term *Awaiting dispatch* is semantically accurate: the controller's `selectedActionRequired` predicate is not a generic claim that every possible payment/finance exception requires action. Payment/invoice/return exceptions remain separate in the existing exception picker and P211 work.

## Authorization, data and readiness
- `OrderOperationsController@index` validates `action_required` as boolean, computes allowed channels via `availableOperationalChannels($actor, 'orders.view')`, then the `OperationalTenantScope` allowed B2B/B2C stores and optional selected-store check. It derives the count and rows from the same scoped `Order` base query.
- `action_required=1` is applied using `whereExists(order_dispatch_states.status=awaiting_dispatch)` and `whereNotIn(orders.status,[delivered,completed,cancelled,refunded])`, preserving the eligible in-progress states. A terminal order must not be sent for assignment even if stale dispatch rows exist.
- B2B principal orders stay **VAN ONLY**. B2C store orders stay **DRIVER ONLY**. `channel=all` is forbidden even for Super Admin. The view/read filter does not mutate an assignment, order status, finance posting, notification or mobile API.
- Visibility remains a projection of true server-side `orders.view`, active identity/role, active store and scope; disabled, roleless, explicit per-store **DENY**, cross-channel and foreign-store direct URLs fail closed (403/404). Existing independent `orders.dispatch`/`orders.manage` mutation checks remain separate. No new module, entitlement, route, master data, migration or worker-side cache required.
- No live/offline queue illusion: existing paginated Orders read is authoritative on each request. Empty queue, invalid input (422), lost permission (403), and pagination use existing page patterns.

## Executable regression
`backend/tests/Feature/V2AwaitingDispatchQueueAuthorityTest.php` verifies:
1. AR/RTL and EN/LTR tab discovery, single P210 marker, accessible label and active state, exact B2C row and count, excludes assigned, delivered, foreign-store rows.
2. Wholesale principal B2B-only eligible dispatch row, excluding stale delivered and Retail rows; forbids mixed `all` channel and mismatched store.
3. Authorized B2C store operator allowed own queue, direct cross-store/Wholesale forbidden, roleless and inactive actor forbidden, explicit `orders.view` DENY forbidden; no data changes.
4. Twelve-row server pagination retains `action_required=1`, `channel=b2c` and scoped `store_id`. Invalid non-boolean action flag returns HTTP 422 with validation error.
5. Exact last PR SHA must pass PHP syntax/Pint, PHPStan, Composer security, OpenAPI, Laravel full suite, AR/EN localization, V2 Isolation, Repository Policy, full MySQL/Redis deployment acceptance and terminal required-ci-gate before a SHA-guarded merge.

## Customer / Driver / Van impact matrix
- **Customer**: **not affected** — no Flutter screen, authenticated API, status enum, cart, invoice or notification response changes.
- **Driver**: **not affected** — B2C DRIVER ONLY dispatch/assignment and proof lifecycle remain unchanged; this change only renames a Dashboard read-only queue tab.
- **Van**: **not affected** — B2B VAN ONLY, visit/territory/routing, `awaiting_dispatch` exception state and no Driver fallback remain unchanged.
- **Dashboard/Backend**: changed one in-page localized label/ARIA marker in the existing Orders Blade; added source-scoped HTTP regression and this QA trace only.

## Finish conditions
- One issue-scoped branch and one PR, base `Foodex_V2`. Verify complete source mapping and automated acceptance, exact-head Required CI SUCCESS, SHA-guarded merge and integrated destination SHA, then close only #2540. Parent #2503 remains OPEN. Manual browser/physical-device UAT deferred to master V2 final; do not claim screenshots, production install or mobile builds not run.
- **Forbidden:** any direct push/merge to `main`, tag, release, cPanel deployment or production promotion without new formal owner authorization.
