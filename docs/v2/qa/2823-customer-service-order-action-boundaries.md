# V2 #2823 / T087R03 — Customer Service order action separation

**Source:** `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx`, row T087R03; parent #2503. **Integration:** `Foodex_V2` exclusively. Neither `main` nor production/release artifacts are authorized.

## Placement, actors and server authority

- **Reuse** existing Customer Service dashboard and Operations → Orders grid/New Order Wizard; no new screen or Sidebar item. `admin.operations.orders.index`, `admin.operations.orders.quote`, `admin.operations.orders.update`, `admin.operations.orders.transition`, and `admin.operations.orders.customer-service-owner` remain canonical routes.
- **Roles:** global `CUSTOMER_SERVICE_MANAGER`, `CUSTOMER_SERVICE_SUPERVISOR`, `CUSTOMER_SERVICE_AGENT`; store-scoped `RETAIL_CUSTOMER_SUPPORT`, and roleless negative actor.
- **Independent permissions:** `orders.view`, `orders.create`, `orders.dispatch`, `orders.approve`, `orders.manage`. Agent has view/create/dispatch, but no approve/manage; Supervisor similarly does not inherit approval; Manager has the canonical approval/manage grants. `OrderOperationsController::managedOrder()` guards writes using `OperationalTenantScope`; a Sidebar link or dispatch grant alone does not authorize order editing, transitions, or team management.
- **Workflow:** authorized Customer Service manager can assign follow-up via validated canonical team membership, with transactional persisted assignment and `order.customer_service_owner.changed` audit. Agent is forbidden from changing that assignment. Valid B2B store/channel scope reaches New Order quote validation; a malformed payload gets 422, demonstrating the route is reached without bypassing validation. Wrong Retail channel/store is 404, not an alternate wholesale dispatch path.
- **Scope:** Retail support sees only its store and cannot enter Wholesale Orders, foreign Retail store or wholesale follow-up assignments. B2B fulfillment remains **Van only**; B2C remains **Driver only**. No order lifecycle state transition, finance write, live customer mutation, schema, API, or role/default-grant changes are introduced by the test PR.

## Automated HTTP evidence

`backend/tests/Feature/V2CustomerServiceOrderActionBoundariesTest.php` verifies AR RTL and EN LTR:

1. Role ability matrix + real authorized Orders GET for all three global Customer Service roles.
2. Agent can reach New Order quote validation for canonical wholesale scope; cross-channel/store forged quote is rejected without creating orders.
3. Agent direct PATCH to order update and POST status transition, and attempted team assignment, are all 403 despite view/create/dispatch. Existing status remains pending; Manager's permitted assignment persists and is audited.
4. Retail support may read its own store but not another store or Wholesale Orders, and may not assign wholesale cases; roleless access forbidden; no side effects.

**Test command:** `cd backend && php artisan test --filter=V2CustomerServiceOrderActionBoundariesTest`; also run `CustomerServiceRoleClosureTest`, `OrderOperationsTest` as applicable, Pint, PHPStan, AR/EN and the complete exact-head Required CI Gate (including real MySQL/Redis Fresh Install/Upgrade/Recovery). No local completion claim unless actually run. Independent browser/responsive/keyboard/a11y acceptance remains pending.

## Impact classification

| Surface | Scope |
|---|---|
| Laravel Backend + Dashboard | Affected: new real HTTP authorization regression tests and QA evidence, no runtime code |
| Customer App | Not affected: no customer order API/order payload/Flutter/deeplink changes |
| Driver App | Not affected: no B2C Driver API, route, assignment or GPS changes |
| Van App | Not affected: no B2B Van dispatch/visit, API, payload or state change |

**Merge and closure:** one issue-scoped branch and PR; merge only after the latest PR head and every applicable required workflow/child job pass, base confirmed `Foodex_V2`. Keep issue open if V2 governance requires real-browser independent acceptance. Record exact SHA/CI/merge evidence after verification, never predict success.
