# FOODEX B2B Van Fulfillment Migration — End-to-End Execution Plan

Status: **OWNER-APPROVED TARGET STATE — NOT YET CURRENT RUNTIME**
Umbrella Issue: **#1190**
Decision date: **2026-10-10**
Baseline reviewed: `main@36bbbda71e5fcaf3f424eeeaa6286e98dc72d9a3`

This plan is the execution authority for the owner decision that Wholesale/B2B fulfillment moves completely from Driver operations to Van operations.

It is an amendment to the earlier National Field Operations plan. Where `docs/execution/FOODEX_NATIONAL_FIELD_OPERATIONS_VAN_TERRITORY_PLAN.md` says a Wholesale order may be delivered by a Van **or Driver**, this document supersedes that assumption.

The umbrella Issue **#1190 must remain open after this planning document lands**. It closes only after the final integrated acceptance in this document passes on one production-eligible lineage.

---

## 1. Locked business decision

The fulfillment actor is determined by the **order channel**, not by the source that created the order.

| Order channel | Fulfillment owner | Runtime app |
| --- | --- | --- |
| `b2b` / Wholesale | **Van only** | Van App |
| `b2c` / Retail | **Driver only** | Driver App |

This applies regardless of whether an order originates from:

- Customer App checkout;
- Dashboard order creation;
- Van field order capture;
- import/integration;
- a future approved commerce source.

A B2B order must never fall back to Driver because Van routing is unavailable. Safe failure is an explicit Van dispatch/exception state.

A B2C order must never enter Van fulfillment merely because a territory has an active Van.

---

## 2. What already exists and must be reused

The migration starts from real production foundations, not a greenfield design.

### Routing / Van ownership

Current backend already provides:

- `OrderDispatchState`;
- `OrderVanAssignment`;
- `OrderTerritoryRoutingService`;
- `RoutingPolicyService`;
- Service Territories;
- effective-dated `VanAssignment`;
- manual Van dispatch in `OrderManualDispatchService`;
- routing decision traces and audit;
- Van registry and runtime assignment context.

`OrderTerritoryRoutingService` already persists:

- `current_assignee_type = van`;
- `current_assignee_id = <van_id>`;
- an active `OrderVanAssignment`;
- routing source/reason/decision key;
- explicit `awaiting_dispatch` when a safe assignment cannot be resolved.

### Van App

Current Van production inventory already includes:

- Dashboard;
- Routes;
- Route Map;
- Route Detail;
- Customers;
- Visit Workspace;
- Customer 360;
- Product Catalog;
- Order Builder;
- Order Review;
- Orders;
- Offers;
- Wallet;
- Collection;
- Receipt;
- Remittance;
- Notifications;
- Profile & Settings.

Current Van order capture already reuses `AdminOrderManagementService`, server-authoritative quote/pricing, idempotency and the shared Order model.

### Delivery behavior currently concentrated in Driver

Current `DriverOrderService` already owns mature behavior that must be **ported as shared business rules**, including:

- assignment acceptance;
- pickup;
- out-for-delivery;
- delivered;
- failed delivery;
- retry path;
- collection-before-delivered gate;
- delivery proof image;
- failed-delivery reason/note;
- idempotent transitions;
- status history;
- audit;
- notification hooks;
- invoice/payment summary.

This logic is a behavioral source. Do not copy/paste a second divergent B2B implementation.

---

## 3. Target architecture

### 3.1 Keep order lifecycle canonical

The Order lifecycle remains:

```text
pending
  -> confirmed
  -> preparing
  -> ready
  -> out_for_delivery
  -> delivered

out_for_delivery -> failed
failed -> out_for_delivery | cancelled
pending/confirmed/preparing/ready -> cancelled where currently permitted
```

`accepted` and `picked_up` remain **fulfillment execution states**, not Order statuses.

### 3.2 Separate routing ownership from execution state

Do not overload `OrderVanAssignment.status`.

Its existing routing/ownership status semantics remain:

```text
active | reassigned | ended
```

B2B delivery execution needs its own explicit state tied to the active Van assignment:

```text
assigned
  -> accepted
  -> picked_up
  -> out_for_delivery
  -> delivered | failed
failed -> out_for_delivery when backend permits retry
cancelled terminal when the order/assignment is cancelled
```

Preferred implementation direction:

- extend the Van fulfillment domain with explicit execution state/timestamps linked to `order_van_assignment_id`;
- do not make Flutter the state authority;
- do not reinterpret routing-assignment `status` as delivery state;
- preserve actor/time/source/proof/failure metadata.

Schema shape is implementation-owned, but the distinction above is non-negotiable.

### 3.3 Shared delivery rules, actor-specific scope

Extract/refactor reusable delivery rules from `DriverOrderService` into a backend-authoritative shared delivery execution layer.

The shared rules cover:

- allowed transition calculation;
- order-state synchronization;
- collection-required gate;
- proof requirements;
- failure reason/note validation;
- idempotency fingerprinting;
- status history;
- audit;
- notification events.

Actor adapters remain separate:

- **Driver adapter**: B2C only, backed by `DriverAssignment`;
- **Van adapter**: B2B only, backed by active `OrderVanAssignment` + Van runtime context.

The goal is shared business truth without forcing Driver and Van to share login/session/assignment persistence.

### 3.4 Hard channel boundary

Server-side guards must make these impossible:

- active Driver fulfillment for a B2B order;
- active Van fulfillment for a B2C order;
- manual B2B dispatch to Driver;
- manual B2C dispatch to Van;
- B2B Driver push/deep-link;
- B2C Van push/deep-link.

UI hiding alone is not sufficient.

---

## 4. B2B order journey after migration

### 4.1 Order created

Any B2B order is created through the existing commerce authority.

Creation must remain independent from routing availability.

The order is persisted first with authoritative:

- store;
- channel = `b2b`;
- customer;
- quote/pricing snapshot;
- items;
- invoice/payment rules;
- inventory reservation;
- status/history/audit;
- idempotency.

### 4.2 Automatic Van routing

After successful B2B order creation, the backend invokes the Van routing authority.

Target flow:

```text
B2B Order Created
  -> resolve delivery address / territory
  -> effective routing policy
  -> eligible Van assignment
  -> OrderDispatchState
  -> OrderVanAssignment
  -> Van notification / queue
```

Routing must execute for B2B orders from:

- Customer App;
- Dashboard;
- Van App;
- supported integrations/imports.

Routing should be wired through one reusable order-created routing hook/service rather than duplicated calls in every controller.

Routing failure must not roll back a valid commercial order unless the commercial contract itself is invalid. It results in:

`awaiting_dispatch`

with a deterministic reason such as:

- missing coordinates;
- unmapped address;
- no eligible Van;
- ambiguous Van;
- manual policy mode;
- policy exception;
- Van suspended/unavailable.

### 4.3 Van-created B2B order

A Van creating an order during a visit does **not** get client-authoritative ownership merely because it created the order.

The same routing authority runs.

Normally the active responsible Van/territory should resolve back to that Van. If it does not, backend policy/audit wins and the mismatch becomes visible rather than silently forcing ownership.

### 4.4 Manual exception handling

Authorized Customer Service/Dispatcher may:

- assign a Van;
- reassign a Van;
- clear/return to awaiting dispatch;
- record reason.

For B2B, manual dispatch UI/API must not offer Driver.

A Van reassignment must:

- end/reassign the previous active `OrderVanAssignment`;
- preserve history;
- handle in-progress execution safely;
- never silently move physically loaded/out-for-delivery work without an explicit override flow.

---

## 5. B2C stays Driver-owned

This migration must prove non-regression for Retail.

B2C remains:

```text
B2C Order
  -> Driver assignment
  -> Driver App
  -> accepted
  -> picked_up
  -> out_for_delivery
  -> collection if required
  -> proof
  -> delivered / failed
```

Required isolation:

- Van routing services reject/ignore B2C fulfillment assignment;
- Driver assignment APIs reject B2B;
- Driver App filters/queries/push do not expose B2B;
- Van App assigned-order queries do not expose B2C fulfillment work.

---

## 6. Van App target experience

The Van App becomes the complete B2B fulfillment application, not only field-sales capture.

### 6.1 Dashboard

Add operational B2B fulfillment truth:

- newly assigned orders;
- active deliveries;
- ready/pickup work;
- collection required;
- failed/retry work;
- routing/assignment exceptions relevant to the logged-in Van;
- quick entry to Orders / Route / Wallet.

No fake KPI values.

### 6.2 Orders — canonical home

Extend the existing canonical Van **Orders** screen; do not create a duplicate Orders module.

Required tabs/filters:

- New / Assigned;
- Active;
- Ready / Pickup;
- Out for Delivery;
- Failed / Retry;
- Completed;
- All.

Exact grouping may adapt to the final UX contract, but all backend states must remain discoverable.

Every row needs:

- order number;
- customer;
- store/channel;
- requested/service date when available;
- total/currency;
- payment/collection requirement;
- execution/order status;
- route/visit context when applicable;
- direct Open action.

### 6.3 Order Detail — required canonical workflow

The Van needs an exact Order Detail experience reachable from:

- Orders;
- Notification;
- Route/Visit;
- Dashboard quick action.

Order Detail must expose, permission-scoped:

- order identity;
- customer;
- phone/address/navigation;
- items/quantities;
- invoice summary;
- grand total;
- payment status/method;
- amount to collect now;
- order status;
- Van execution status;
- timeline/history;
- delivery notes;
- proof status;
- route/visit context.

### 6.4 Delivery actions

Port Driver behavior into Van UX for B2B:

- Accept;
- Picked Up;
- Out for Delivery;
- Delivered;
- Failed;
- Retry when backend allows.

The UI renders only actions returned/allowed by backend state.

### 6.5 Collection / Receipt / Wallet / Remittance

Reuse current Van finance domain.

Delivered must be blocked when authoritative `amount_to_collect_now > 0`.

Flow:

```text
Order Detail
 -> Collection
 -> Receipt
 -> back to Order Detail
 -> Delivered (when balance is clear and proof is valid)
 -> Wallet/Custody
 -> Remittance
```

Do not create a second delivery-money ledger.

### 6.6 Proof and failed delivery

Before Delivered:

- valid proof image is required when current delivery policy requires it;
- upload must be tied to the exact Van/order assignment;
- retry must be idempotent.

Failed:

- configured failure reason required;
- `other` requires note;
- optional/required failure image follows backend policy;
- failure event appears in Dashboard and customer-facing timeline according to safe visibility rules.

### 6.7 Live location

When the Van owns active B2B fulfillment:

- Van location publishing continues through the fleet location authority;
- Dashboard tracking shows Van marker, not Driver;
- stale/offline states are explicit;
- disabling live GPS does not fabricate location or block unrelated operations unless policy requires it.

### 6.8 Notifications and deep links

Van receives B2B events:

- new assignment;
- reassignment;
- order changed/cancelled;
- ready for pickup;
- collection/payment change when operationally relevant;
- retry/action-required events.

Notification opens the exact canonical Van Order Detail.

Push payload must not route B2B work into Driver App.

---

## 7. Driver App target after migration

Driver App remains a first-class B2C product.

Required changes:

- B2B assignments no longer appear;
- B2B notification payloads no longer target Driver;
- wholesale-specific delivery filters/actions are removed or made unreachable when obsolete;
- direct API/deep-link attempts for B2B fail safely;
- B2C active journey, proof, collection and wallet behavior remain intact;
- Driver live tracking continues for B2C.

Do not remove generic shared UI/business code still used by Retail.

---

## 8. Dashboard target

### 8.1 B2B Orders workspace

B2B order management must display:

- assigned Van;
- routing state;
- routing reason/source;
- territory;
- route/visit when linked;
- Van execution status;
- order status;
- collection/proof state;
- stale live-location state.

B2B actions:

- View Order;
- View Routing Decision;
- Assign Van;
- Reassign Van;
- Return to Dispatch Queue when safe;
- View Van;
- View live location;
- View collection/receipt;
- View timeline/proof.

There must be **no Assign Driver action for B2B**.

### 8.2 B2C Orders workspace

Retail keeps Driver actions.

There must be **no Assign Van action for B2C**.

### 8.3 Dispatch / exception queue

Provide a normal reachable workspace for B2B orders in `awaiting_dispatch`.

It must show why routing failed and the eligible/remediation context.

No hidden route or API-only exception management.

### 8.4 Live Fleet

For an active B2B order:

- map/list resolves Van;
- Van and Driver remain visually distinct;
- order deep link opens the correct B2B order/Van context;
- no false Driver identity.

### 8.5 Customer 360 / Van detail / Finance

Customer 360:
- B2B order timeline shows Van fulfillment actor/events.

Van detail:
- assigned/active/completed B2B orders;
- route/visit/collection context.

Finance:
- collections, receipts, wallet/custody and remittances remain one source of truth.

---

## 9. Customer App target

Customer does not need to know which app the field actor uses, but B2B truth must be accurate.

My Orders / Order Detail / Tracking must:

- show the canonical Order status;
- show B2B fulfillment/tracking based on Van assignment;
- never show a Driver identity for B2B;
- handle awaiting-dispatch state truthfully;
- reflect failed/retry/delivered events;
- show collection/payment/receipt state where product-safe;
- support AR/EN + RTL/LTR;
- preserve exact store/channel identity.

B2C continues Driver-backed tracking.

---

## 10. Backend/API workstreams

### 10.1 Routing trigger integration

Wire B2B routing after successful order creation for every source.

One shared service/event path should own this.

### 10.2 Channel guards

Add service/API tests that enforce:

- `b2b -> van`;
- `b2c -> driver`.

### 10.3 Van assigned-order query

Current Van order list is visit/customer scoped and can miss Customer-App/Dashboard B2B orders.

Add an authoritative assigned-order read path based on active `OrderVanAssignment` / Van runtime identity.

It must include Customer App orders even when no Van visit created them.

### 10.4 Van Order Detail

Add exact assigned B2B order detail endpoint/read model.

### 10.5 Van delivery execution endpoints

Add Van-scoped endpoints for:

- allowed actions;
- transition;
- proof upload;
- failed reason/note;
- retry.

Reuse shared delivery rules.

### 10.6 Notification target resolution

B2B event audience resolves the active Van assignment.

B2C event audience resolves Driver assignment.

### 10.7 Tracking target resolution

B2B order tracking resolves Van fleet location.

B2C resolves Driver location.

### 10.8 Manual dispatch

Server must reject actor/channel mismatch even if a stale UI submits it.

---

## 11. Data migration and backfill

This change is not complete without existing-open-work migration.

### 11.1 Inventory current open assignments

Before activation classify all non-terminal orders:

- channel;
- Order status;
- DriverAssignment state;
- OrderDispatchState;
- OrderVanAssignment;
- territory/address resolution;
- current live actor if any.

### 11.2 B2B backfill

For open B2B orders:

1. preserve history;
2. end/reassign any active Driver B2B assignment with migration reason;
3. create/repair OrderDispatchState;
4. route through current Van policy;
5. create active OrderVanAssignment or explicit `awaiting_dispatch`;
6. notify new Van when assigned;
7. do not rewrite delivered/cancelled historical truth.

### 11.3 B2C cleanup

B2C must not retain active Van fulfillment assignment after cutover.

Resolve any contradictory state explicitly and audit it.

### 11.4 Rollback safety

Migration is versioned and non-destructive.

No database reset.

Historical Driver B2B records remain readable for audit even after Driver can no longer execute new B2B work.

---

## 12. Warehouse / preparation interaction

Wholesale preparation remains part of the B2B order lifecycle.

Required behavior:

- `confirmed/preparing/ready` remain backend order states;
- Van sees when work is ready for pickup;
- pickup execution does not falsely mark `out_for_delivery` before the Van performs that action;
- route/load work must not silently reassign after physical custody;
- cancellation after load follows explicit operational reversal/return rules.

If a full load-manifest module is active, integrate it. Do not create a parallel lightweight pickup truth.

---

## 13. Finance invariants

Non-negotiable:

- invoice/payment truth remains shared;
- Van collection uses current Collection/Custody/Remittance domain;
- collection is idempotent;
- cannot collect above authoritative outstanding;
- Delivered blocked while required collection remains;
- receipts are durable;
- wallet balance is custody truth;
- remittance does not delete collection history;
- B2B account-credit rules remain authoritative;
- cancellation/reversal rules remain explicit and audited.

---

## 14. Security and authorization

Required tests:

- Van A cannot read/execute Van B assigned order;
- Driver cannot execute B2B order;
- Van cannot execute B2C order;
- stale/reassigned Van cannot transition the old assignment;
- notification/deep-link knowing an order ID grants no extra access;
- cross-store/channel tampering returns 403/404 without leakage;
- manual dispatch requires permission and reason;
- financial/proof mutation idempotency enforced.

---

## 15. UI/UX and route authority

All implementation follows repository UI skills and route authority.

Rules:

- reuse the existing Van Orders canonical home;
- create a dedicated Order Detail only if route inventory confirms no canonical exact-record screen exists;
- no hidden/API-only business function;
- no duplicated Driver-style shadow module inside Van;
- every operational action is reachable through normal Van navigation/workflow;
- Dashboard B2B/Van actions use normal B2B order/Field Operations entry points;
- AR/EN and responsive evidence are mandatory;
- Van compact evidence includes 360x800;
- standard mobile evidence includes 430x932;
- changed screens must trigger the owning screenshot workflow.

---

## 16. Observability and audit

At minimum record:

- routing attempted;
- routing assigned/pending reason;
- manual/reassignment actor + reason;
- Van accepted/picked up/out for delivery/delivered/failed;
- proof uploaded;
- collection/receipt;
- remittance;
- stale/revoked assignment attempt;
- migration backfill result;
- Driver B2B rejection after cutover.

System Inspector must expose actionable failures without secrets.

---

## 17. Execution waves

### Wave 0 — Contract and migration guards

- lock channel->actor policy in tests;
- define shared delivery execution contract;
- define schema/migration for Van execution state;
- add backfill dry-run report;
- update OpenAPI contract stubs;
- add feature-flag/cutover controls if required.

### Wave 1 — Backend B2B Van assignment authority

- trigger Van routing for every B2B order source;
- B2B assigned-order read model;
- Order detail;
- manual Van-only B2B dispatch;
- Driver B2B server rejection;
- B2C Van server rejection.

### Wave 2 — Shared delivery execution extraction

- extract reusable rules from DriverOrderService;
- keep B2C Driver behavior green;
- implement Van B2B adapter;
- proof/failure/idempotency/history/audit.

### Wave 3 — Van App full order management

- Dashboard signals;
- Orders filters;
- Order Detail;
- action workflow;
- failure/retry;
- proof;
- collection handoff;
- timeline;
- route/visit context.

### Wave 4 — Notifications + tracking

- Van push/deep-link;
- Customer B2B tracking resolves Van;
- Dashboard Live Fleet resolves Van for B2B;
- stale/offline truth;
- Driver push excludes B2B.

### Wave 5 — Dashboard operations

- B2B Orders Van assignment/actions;
- awaiting-dispatch queue;
- routing decision explainability;
- Van detail order views;
- B2C Driver-only UI;
- Customer 360 timeline.

### Wave 6 — Migration / compatibility

- dry-run inventory report;
- B2B open-order backfill;
- end stale Driver B2B assignment execution;
- create/repair Van dispatch;
- B2C contradictory-state cleanup;
- rollback evidence.

### Wave 7 — Integrated E2E + release

- Customer-created B2B;
- Dashboard-created B2B;
- Van-created B2B;
- failed routing/manual dispatch;
- Van delivery + collection + proof;
- B2C Driver non-regression;
- AR/EN;
- screenshots;
- OpenAPI/docs;
- update bundle/release artifacts;
- exact-head integrated gate.

---

## 18. Atomic implementation lanes

Use child Issues under #1190. Each child owns one branch and one PR.

Recommended lanes:

1. Channel/actor contract + schema + migration dry-run.
2. B2B post-create Smart Routing integration.
3. Van assigned-order/detail APIs + authorization.
4. Shared delivery execution extraction + B2C Driver non-regression.
5. Van B2B delivery execution backend.
6. Van Orders/Order Detail/delivery action UX.
7. Van proof/failure/retry UX + backend.
8. Van collection/receipt/delivered integration.
9. Van notifications/deep-link + push targeting.
10. Van live location / B2B tracking integration.
11. Dashboard B2B Van dispatch + exception queue + timeline.
12. Driver B2B removal/isolation + B2C regression.
13. Customer App B2B Van tracking/status integration.
14. Existing-open-order backfill/cutover/rollback.
15. OpenAPI/docs/release/evidence synchronization.
16. Final integrated E2E closure gate.

Child Issues may close individually. **#1190 must not close until lane 16 passes and the coverage matrix has no unresolved Critical/High row.**

---

## 19. Required final E2E scenarios

### Scenario A — Customer App B2B order

1. Wholesale customer submits order.
2. Order/invoice/payment/inventory reservation succeed.
3. Smart Routing selects responsible Van.
4. Van receives push.
5. Van Orders shows the order without a visit-created dependency.
6. Van accepts.
7. Van picks up when operationally allowed.
8. Van marks out for delivery.
9. Customer/Dashboard track the Van actor.
10. Van collects required amount if any.
11. Receipt/wallet update once.
12. Van uploads proof.
13. Delivered succeeds.
14. Customer, Dashboard, Van, Finance show one consistent truth.
15. Driver App never receives or exposes the order.

### Scenario B — Dashboard-created B2B order

Same fulfillment path; source does not alter Van ownership.

### Scenario C — Van-created B2B order during visit

1. Van starts assigned customer visit.
2. Van creates order.
3. Smart Routing validates/resolves ownership.
4. Visit closes `completed_with_order` using the authoritative order.
5. The same Order enters Van fulfillment lifecycle.
6. Delivery/collection/proof closes normally.

### Scenario D — Routing cannot assign safely

1. Valid B2B order is created.
2. Routing cannot safely resolve Van.
3. Order remains commercially valid.
4. `awaiting_dispatch` appears in normal Dashboard queue.
5. Authorized dispatcher assigns Van with reason.
6. Van receives and completes it.
7. No Driver fallback occurs.

### Scenario E — Failed delivery

1. Van reaches active delivery.
2. selects valid failure reason;
3. note required for `other`;
4. proof/failure evidence persists when required;
5. Order becomes failed;
6. retry returns through allowed state;
7. final delivery succeeds or order is explicitly cancelled.

### Scenario F — B2C non-regression

1. Retail customer creates B2C order.
2. no Van assignment is created;
3. Driver receives assignment;
4. Driver flow completes including collection/proof;
5. Van App never sees it as fulfillment work.

### Scenario G — Cutover existing open B2B

1. pre-cutover open B2B Driver-owned order is identified;
2. old Driver execution is ended with audit;
3. Van routing/backfill resolves assignment or explicit exception;
4. historical Driver record remains readable;
5. only Van can continue execution after cutover.

---

## 20. Umbrella closure gate

Issue #1190 may close only when all of the following are true on a single verified final lineage:

- all required child Issues are closed with merged PRs;
- coverage matrix contains no Critical/High row in `PLANNED`, `TODO`, `UNKNOWN`, `HIDDEN`, `API_ONLY`, `NOT_EVIDENCED` or `BLOCKED`;
- Customer/Dashboard/Van-created B2B scenarios pass;
- safe routing exception scenario passes;
- failed/retry + collection + proof passes;
- B2C Driver non-regression passes;
- open-order migration/backfill passes on production-like data;
- B2B Driver server guards pass;
- B2C Van server guards pass;
- all required Van/Dashboard/Customer screens are normally reachable;
- AR/EN runtime evidence exists;
- responsive evidence exists;
- live tracking evidence proves Van identity for B2B;
- notification/deep-link evidence proves Van targeting;
- financial reconciliation evidence passes;
- OpenAPI/docs/route authority are current;
- release/update-center artifacts are generated from the final lineage;
- Required CI + relevant screenshot/runtime gates are green on the final head.

A green unit-test subset, a working API, or a visually complete Van Orders screen alone is **not** sufficient to close #1190.
