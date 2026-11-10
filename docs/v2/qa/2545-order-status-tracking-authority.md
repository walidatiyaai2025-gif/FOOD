# V2 #2545 / P215 — تتبع الحالات / Order status tracking

Source: unified RBAC/ERP/Sidebar matrix P215, Orders & Fulfillment, owner master #2503. Integration target: **Foodex_V2 ONLY**. Manual browser/staging/device acceptance: **DEFERRED_TO_V2_FINAL** per owner decision.

## Route authority and product placement

- **Domain:** Orders & Fulfillment; **entity:** Order and authoritative Order Status History.
- **Role:** authorized B2C or B2B operational viewer; read ability \`orders.view\` with effective per-store and per-channel scope. An explicit DENY wins over role permissions.
- **Goal:** inspect the state of each order, filter the list by status, open one order's complete event timeline; no additional grant for reading, and no incidental write permission.
- **Workflow type/frequency:** continuous read/search and exact-record inspection; high-frequency operations grid, dense server-paginated table, single-order modal.
- **Existing parent:** Dashboard → Operations → Order Management, navigation key \`operations / order_operations\` in \`AdminNavigation::groupsFor\`.
- **Canonical route:** \`admin.operations.orders.index\` (GET \`/admin/operations/orders?channel=b2c|b2b&status=...\`), with exact-record \`admin.operations.orders.popup\` (GET \`/admin/operations/orders/{order}/popup/timeline\`).
- **Controller / renderer:** \`OrderOperationsController::index\`, \`OrderOperationsController::popup\` and \`OrderOperationsController::orderTimeline\`; \`admin.order-operations\` + \`admin._order-operation-popup\`.
- **Placement:** **Reuse** existing status-tab navigation + exact order Timeline button/modal. Navigation types: \`PAGE_TAB\` and \`CONTEXT_ACTION\`, reached from the existing authorized Orders Sidebar node. New top-level Sidebar item or duplicate route is unjustified. Back path: Orders grid retains selected \`channel\`, \`store_id\`, and \`status\` in query and popup closes to same grid.
- **Server authority:** \`OperationalTenantScope::allowedStoreIds\` scopes query/channel; \`managedOrder(..., 'orders.view')\` checks exact order/store/channel before timeline; unauthorized index 403, unauthorized foreign timeline 404; roleless direct requests cannot gain access from Sidebar layout/metadata.
- **Write isolation:** \`orders.manage\` and \`orders.dispatch\` remain independent from \`orders.view\`; tracking supplies no mutation ability. Permission overrides/denies remain authoritative.
- **Module/readiness:** Core Orders feature is not gated by optional ERP module certification; no new entitlement or feature toggle, so "disabled module" check is inapplicable here. Disabled/ineligible actor fails closed via the existing auth and scope boundary; no fictitious module gate is added.
- **Presentation:** existing responsive Dashboard and mobile-web grid/tab/modal reused without changes at 320–1280+ px. Status labels come from bilingual \`operational_lookups\`; Timeline event headings from localized backend messages. AR RTL / EN LTR and empty timeline fallback use existing components. Visual and device evidence are deferred to the V2 final gate, not claimed from source-only review.

## Regression, cross-app and completion

New focused PHPUnit HTTP contract \`backend/tests/Feature/V2OrderStatusTrackingSidebarHttpContractTest.php\` verifies:
1. AR/EN, RTL/LTR, one authorized Orders Sidebar entry, actual status selection and record Timeline button/route.
2. A real status history event renders in the exact-order modal.
3. Filtered status list includes only matching records in the actor's store.
4. Cross-store/channel status/timeline requests deny and cannot reveal foreign rows.
5. Global user-level \`orders.view\` DENY removes navigation and blocks direct timeline.
6. Read-only \`orders.view\` can inspect statuses without \`orders.manage\`/\`orders.dispatch\`; status mutation remains rejected.
7. Roleless actor cannot browse orders or timeline.

**Customer:** no API/Flutter contract changes; order status tracking API untouched.
**Driver:** no Flutter/API changes; B2C delivery-state source unaffected, Driver-only for B2C still enforced.
**Van:** no Flutter/API changes; B2B Van-only execution/tracking unchanged.
No app-specific follow-up Issue unless an actual cross-app runtime contract impact is discovered.

Closure requires: exact final PR HEAD = green Repository Policy, V2 Isolation, Laravel Backend Tests, AR/EN Localization, Backend Fast-Fail (Pint/static analysis/security/OpenAPI), MySQL/Redis install-upgrade-recovery gate and terminal Required CI. One branch + PR, verified merge solely to \`Foodex_V2\`; no main, release, tag or production. Master #2503 and manual V2 final acceptance remain OPEN.
