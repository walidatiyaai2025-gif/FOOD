# FOODEX V2 #2544 — P214 Order assignment authority

Target integration: **Foodex_V2 only**. Parent: #2503. Source: actual DOCX P203 / legacy matrix key P214 (Orders & Fulfillment). Manual browser/staging/device acceptance remains **DEFERRED_TO_V2_FINAL**.

## Product placement / source authority

- Domain: Orders & Fulfillment. Entity: Order / dispatch state, not personnel-to-Van assignment.
- Role: authorized B2B and B2C operations actor with store-scoped \`orders.view\` and \`orders.dispatch\` for the mutation.
- User goal: locate an assignable order and assign or withdraw its authorized fulfillment actor.
- Existing parent: Dashboard Operations → Order Management, \`admin.operations.orders.index\` / \`admin.order-operations\`.
- Placement: **Reuse** the existing Orders grid/context action \`data-order-inline-dispatch\`; navigation type \`CONTEXT_ACTION\`. No duplicated Sidebar item, independent permission system, new route or screen.
- Navigation owner: \`AdminNavigation::groupsFor\` → \`operations\` → \`order_operations\`. Clicking the visible authorized entry opens the exact order workspace. The inline dispatch control appears only when \`orders.dispatch\` covers the row's store/channel; disabled/terminal orders are blocked.
- Canonical HTTP: \`GET /admin/operations/orders\` (read), \`PATCH /admin/operations/orders/{order}/dispatch\`, \`DELETE /admin/operations/orders/{order}/dispatch\` (write). The controller uses \`OperationalTenantScope::assertStore\` and \`FulfillmentActorPolicy::assertManualDispatchActor\`. Cross-store requests deliberately return 404 to avoid disclosing order existence; an actor without an operational channel receives 403.
- State: pending/assignable, awaiting_dispatch, assigned, completed/terminal; empty and error conditions use the existing Orders page. No new managed master-data fields, locales or front-end copy.
- Responsive/locale contract: existing Dashboard page re-used unchanged; AR/RTL and EN/LTR served from the same route, desktop/tablet/mobile-web via existing responsive grid, unchanged focus/select semantics and authorized action. Visual/device audit remains a final-program acceptance check, **not** a CI claim.

## Automated regression contract

\`backend/tests/Feature/V2OrderAssignmentsSidebarHttpContractTest.php\` exercises actual Laravel HTTP and rendered page for:

1. Existing single Orders navigation entry and real inline assignment control in Arabic/English.
2. Explicit per-user \`orders.dispatch\` DENY: readable order, hidden mutation, forged PATCH rejected.
3. Out-of-store and cross-channel read/write denial, including DELETE; no dispatch-state mutation.
4. Cross-channel actor rejection: **B2B → VAN only; B2C → DRIVER only**.
5. Roleless actor cannot acquire a route or dispatch entitlement.

No changed API contract, checkout, dispatch state machine, order service, storage/migration, Flutter UI or app permissions. **Customer**: no source/runtime impact; **Driver**: existing B2C-only assignment API untouched; **Van**: existing B2B-only routing/visit API untouched. Shared fulfillment invariant is explicitly exercised at the Dashboard HTTP boundary. No mobile issues are required unless a real app-facing contract change is discovered.

## Closure gates

One implementation branch and PR; exact-head Repository Policy, FOODEX V2 Isolation Gate, Required CI (incl. Laravel, localization, security), merge only to Foodex_V2. No \`main\` or production action. Record final head, CI run links, merge SHA in the Issue. **MANUAL_ACCEPTANCE: DEFERRED_TO_V2_FINAL** under current #2503 owner decision. Do not claim live browser/device testing.
