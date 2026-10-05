# FOODEX National Field Operations, Van App, Territory Routing, Live Fleet & Collections Master Plan

Status: **Proposed authoritative architecture and execution plan — owner review**
Planning Issue: **#932**
Initial market: **Egypt**
Design principle: **Egypt is the first configured market, not a hardcoded platform boundary.**
Repository baseline reviewed: `main@803eca3ca48d011363882d8ca180848728b5fed3`

Related current authorities:
- `docs/architecture/PLATFORM_CUSTOMER_COMMERCE.md`
- `docs/workflows/ORDER_LIFECYCLE.md`
- `docs/workflows/DRIVER_WORKFLOW.md`
- `backend/app/Services/DriverOrderService.php`
- `backend/app/Services/B2bAccountLedgerService.php`
- `backend/resources/views/admin/_brand-components.blade.php`
- `backend/resources/views/admin/catalog-management.blade.php`

---

## 1. Mission

Build one extensible FOODEX field-operations platform that can support nationwide customer distribution, field sales, routing, warehouse pickup, delivery, live fleet visibility, collections, custody wallets and remittance without hardcoding geography, Van ownership, route rules, service days, payment behavior or operating exceptions.

The system must support three distinct mobile applications:

1. **Customer App**
   - customer commerce;
   - checkout;
   - order tracking;
   - payment/collection visibility;
   - collection receipts;
   - delivery-date/serviceability feedback.

2. **Driver App**
   - delivery execution for assigned orders;
   - live location when operationally active;
   - collection at delivery where authorized;
   - Driver custody wallet;
   - remittance to the company/platform.

3. **Van App**
   - a separate third Flutter application under `apps/van_app`;
   - field customer visits;
   - field order capture;
   - customer debt collection;
   - warehouse pickup/load manifest;
   - route execution;
   - delivery;
   - live Van location;
   - collection at order/delivery/customer-account level;
   - custody wallet and remittance.

The Dashboard, Warehouse operations and Finance operations form the administrative control plane for all three apps.

Canonical end-to-end journey:

```text
Customer / Delivery Address
          |
          v
Serviceability + Territory Resolution
          |
          v
Configurable Routing Policy
          |
          +--> Service Date / Window
          +--> Warehouse / Hub
          +--> Eligible Van Pool
          +--> Primary / Backup Assignment
          +--> Capacity / Vehicle Capability
          +--> Operational Exceptions
          |
          v
Planned Route / Assignment
          |
          v
Warehouse Preparation
          |
          v
Load Manifest / Pickup
          |
          v
Van or Driver Distribution
          |
          +--> Full Collection
          +--> Partial Collection
          +--> No Collection Required
          +--> Approved Account Remainder
          |
          v
Collection Custody Wallet
          |
          v
Remittance
          |
          v
Finance Approval + Reconciliation
```

---

## 2. Non-negotiable owner decisions

1. **Van App is a standalone third application.**
   - It is not a Driver App mode.
   - Driver access does not imply Van App access.
   - Van App may include delivery and collection in addition to field-sales functionality.

2. **Driver App remains delivery-focused.**
   - Delivery assignments.
   - Delivery execution.
   - Collection at delivery.
   - Wallet and remittance.
   - No field-sales customer book or field order capture unless explicitly added by a future product decision.

3. **One financial source of truth.**
   - Driver App and Van App use one shared Collection/Custody/Remittance backend domain.
   - Do not create duplicated Driver Wallet and Van Wallet finance engines.

4. **One commerce source of truth.**
   - Reuse existing customers, pricing, orders, invoices, payments and inventory.
   - Do not create Van-specific order/invoice table families.

5. **Territory is the stable routing abstraction; a Van is a replaceable resource.**
   - Do not permanently bind customers to vehicles.
   - Route from delivery address -> service territory -> active policy -> operational assignment.

6. **Routing is backend-authoritative and configuration-driven.**
   - No hardcoded governorate-to-Van mapping.
   - No hardcoded service days.
   - No hardcoded fallback Van.
   - No Flutter-side authoritative routing.

7. **All operational configuration is editable, versioned, effective-dated, auditable and reversible.**
   - Admin can change territories, routing rules, priorities, schedules, capacities, fallback behavior and overrides.
   - Historical orders remain historically correct.

8. **Settlement plan and actual collection are separate.**
   - Expected payment behavior may be selected during order capture.
   - Actual collection exists only when money is really received.

9. **Issued commercial values remain immutable.**
   - Existing FOODEX invoice rules remain authoritative.
   - Partial delivery/return/correction uses explicit revision or adjustment workflows.

10. **Arabic and English are mandatory.**
    - Dashboard + all three apps support AR/EN and RTL/LTR.

11. **FOODEX visual identity is mandatory.**
    - No new subsystem may introduce a separate admin/mobile design language.

12. **Drivers and Vans are visible on one live operational map.**
    - Distinct visual markers.
    - Clear Zone overlays.
    - Clear route overlays.
    - Filters and legend.
    - Same backend live-location authority.

---

## 3. Application and actor boundaries

The model must never assume the same person created, loaded, delivered and collected an order.

An order may contain provenance for:

- order creator;
- Van sales visit actor;
- warehouse picker;
- load receiver;
- delivery actor;
- collection actor;
- remittance actor;
- remittance approver.

The same user may perform several steps, but audit must preserve the exact actor for each event.

Every mutation records its source, for example:

- `customer_app`;
- `driver_app`;
- `van_app`;
- `dashboard`;
- `payment_gateway`;
- import/integration/migration source when applicable.

---

## 4. Mandatory FOODEX UI/UX identity contract

### 4.1 Dashboard visual authority

All new Dashboard pages must reuse the current FOODEX brand/component tokens from:

- `backend/resources/views/admin/_brand-components.blade.php`
- `backend/resources/views/admin/_brand.blade.php`

Required reuse includes FOODEX:

- background/surface/ink/muted colors;
- green/dark-green/green-soft hierarchy;
- border/radius/shadow system;
- typography;
- form/control heights;
- touch targets;
- tabs;
- badges;
- alerts;
- modals;
- responsive layout.

No worker may introduce an unrelated admin palette, arbitrary spacing system or bespoke shell.

### 4.2 Canonical management-grid reference

The **Catalog Management products grid** is the visual/interaction reference for all new high-density operational grids.

Applicable pages must follow the same principles:

- one logical record per row;
- compact, readable hierarchy;
- key identity in the first column;
- secondary metadata visually subdued;
- status badges;
- row hover;
- responsive column hiding;
- final **Actions** column;
- circular vertical three-dot **`⋮`** row action trigger;
- row menu for View/Edit/Assign/Approve/Reject/Reverse/etc.;
- destructive actions visually distinct and confirmation-protected;
- no permanent clutter of many action buttons on each row.

This pattern applies at minimum to:

- Territories;
- Territory assignments;
- Routing rules;
- Vans;
- Van users;
- Van assignments;
- Routes;
- Route stops;
- Customer visits;
- Load manifests;
- Van field orders;
- Wallets;
- Collections;
- Remittances;
- Reconciliation;
- Routing exceptions;
- Live-fleet exception lists.

### 4.3 Pagination/search/filter contract

All high-cardinality grids must provide:

- server-side pagination;
- count/total;
- current page highlight using FOODEX green;
- previous/next navigation;
- stable filters;
- search;
- filter state in URL/query where appropriate;
- safe reset when filters change;
- no browser-loading of nationwide datasets.

### 4.4 Dashboard page composition

Canonical page composition:

1. FOODEX page header.
2. Short subtitle.
3. Context actions.
4. Search/filter toolbar.
5. Optional summary/KPI strip.
6. Canonical grid/table.
7. Pagination.
8. Modal/drawer/dedicated editor for complex actions.

### 4.5 Mobile identity

Customer, Driver and Van Apps are separate applications but share one FOODEX mobile design language:

- FOODEX green;
- neutral surface hierarchy;
- consistent typography;
- consistent money formatting;
- same status-chip semantics;
- same action hierarchy;
- same loading/empty/error/offline/stale states;
- same AR/EN behavior;
- same touch-target/accessibility baseline.

Van App must visually read as a FOODEX product, not as a generic CRM or logistics app.

### 4.6 UI/UX release gate

A feature is not complete if:

- it is reachable only by hidden/deep routes;
- row actions are not discoverable;
- AR/EN direction breaks;
- financial amounts are ambiguous;
- loading/error/empty/offline states are missing;
- a high-volume list lacks pagination;
- a Dashboard page diverges materially from FOODEX identity;
- a row is cluttered with permanent action buttons instead of the canonical `⋮` pattern where applicable.

---

## 5. Geography: Egypt first, multi-market ready

### 5.1 Configurable hierarchy

Support a configurable hierarchy such as:

```text
Country
  -> Governorate / Region
      -> City / Markaz
          -> District / Area
              -> Service Territory / Zone
```

Administrative geography aids reporting and navigation, but **Service Territory geometry is the operational routing authority**.

Egypt is the first configured market, not a hardcoded country assumption.

### 5.2 Service Territory

A Service Territory should contain:

- id/code;
- Arabic/English name;
- country;
- optional governorate/city/district references;
- polygon/multipolygon geometry;
- active/serviceability status;
- effective dates;
- priority;
- default service calendar;
- default warehouse/hub;
- tags/capabilities;
- audit metadata.

### 5.3 Address-level routing

Routing belongs to the **delivery address**, not only the customer.

A customer may have several branches in different areas and therefore several territories.

Customer address data should support:

- latitude;
- longitude;
- resolved territory;
- resolution source;
- resolution timestamp;
- serviceability status;
- manual override metadata.

Resolution order:

1. explicit active address override;
2. coordinate point-in-polygon;
3. admin-confirmed mapping when coordinates are unavailable;
4. otherwise unmapped/serviceability exception.

Do not silently infer critical routing from free-text address when confidence is insufficient.

### 5.4 Boundary conflict

When a point qualifies for multiple Zones:

- evaluate explicit priority/configuration;
- preserve candidate set;
- preserve chosen reason;
- expose decision trace;
- never choose non-deterministically.

---

## 6. Configuration Control Plane

This is the central flexibility requirement.

### 6.1 Versioned routing policy

Routing configuration must support:

- draft;
- published;
- retired;
- effective-from;
- effective-until;
- author;
- publisher;
- reason;
- priority/order;
- simulation result;
- previous-version reference.

Only an effective published configuration routes production work.

### 6.2 Configurable conditions

The routing rule engine should be extensible and initially support conditions including:

- territory;
- address/customer override;
- store/channel;
- requested/service date;
- weekday/service calendar;
- warehouse/hub;
- customer type/tier;
- order value;
- order weight;
- order volume;
- line/item count;
- product handling/cold-chain requirements;
- vehicle type/capability;
- vehicle capacity;
- Van/rep availability;
- shift;
- current workload;
- cash-custody restriction;
- urgent priority;
- manual dispatch lock.

The model must permit adding future rule types without rebuilding the routing architecture.

### 6.3 Configurable actions

Routing rules may determine/constrain:

- territory;
- service date/window;
- warehouse/hub;
- eligible Van pool;
- primary Van;
- backup Van pool;
- route group;
- priority;
- manual-review requirement;
- blocked/unserviceable result;
- exception queue.

### 6.4 Rule priority

Admin must be able to reorder rules.

No rule priority should be fixed in Flutter or hidden inside controllers.

### 6.5 Simulation before publish

Admin can test a draft routing policy against:

- one address;
- one draft order;
- a batch of historical orders;
- a selected future date.

Simulation returns:

- territory;
- matched rules;
- rejected candidates;
- chosen warehouse;
- chosen Van/pool;
- service date;
- capacity implications;
- warnings/exceptions.

Simulation never mutates production state.

### 6.6 Explainability

Every routed order must have an explainable decision trace:

- policy version;
- territory;
- rules evaluated;
- matches;
- rejected candidates and reasons;
- warehouse;
- service date;
- eligible Vans;
- selected Van/assignment;
- override/fallback;
- actor if manually changed;
- timestamp.

The Dashboard must answer: **Why did this order go to this Van?**

### 6.7 Rollback

Admin can:

- save drafts;
- publish future-dated config;
- compare draft vs published;
- retire config;
- re-publish a previous known-good version;
- preview impacted future/unlocked work.

Rollback must not rewrite locked/historical assignments.

---

## 7. Territory-to-Van assignment

Territory must not permanently point to one vehicle.

Use effective-dated assignments that can include:

- territory;
- eligible Van/Van pool;
- ranking;
- primary/backup designation;
- assigned rep/team;
- service day/window;
- shift;
- date range;
- warehouse/hub;
- capability constraints;
- capacity profile;
- active state.

A territory may have many eligible Vans.
A Van may service many territories.

---

## 8. Van and vehicle model

Van entity:

- internal code;
- plate;
- vehicle type;
- active/maintenance/out-of-service status;
- max weight;
- max volume;
- max orders if used;
- configurable capabilities;
- home warehouse/hub;
- notes.

Rep assignment is not permanent.

Use an effective-dated Van Assignment:

- Van;
- user/rep;
- shift;
- date range;
- route/territory scope;
- capabilities.

If a Van fails:

- future/unlocked work may be replanned;
- loaded work requires explicit transfer/recovery;
- no silent custody reassignment;
- audit preserves original and replacement assignment.

---

## 9. Service calendars and delivery windows

Territory schedules are configurable.

Support:

- selected weekdays;
- every-day;
- alternating schedules;
- effective date ranges;
- blackout dates;
- holiday closures;
- campaign schedules;
- cutoff times;
- lead time;
- delivery windows;
- warehouse preparation cutoff.

Customer App consumes backend-calculated available service dates.

---

## 10. Routing lifecycle

### 10.1 Order sources

Orders may originate from:

- Customer App;
- Van App;
- Dashboard;
- future integrations.

All paths reuse authoritative:

- customer;
- store/channel;
- pricing;
- inventory;
- invoice/payment;
- delivery-address snapshot;
- routing engine.

### 10.2 Planned vs locked assignment

Separate:

**Planned Routing**
- territory resolved;
- service date planned;
- warehouse resolved;
- Van candidate/assignment reserved;
- capacity reserved;
- may still be replanned.

**Locked Route**
- dispatcher/automation finalizes route/load;
- operational assignment becomes fixed;
- later change requires explicit reassignment/transfer workflow and audit.

This avoids chaos when operations need to rebalance work before dispatch.

---

## 11. Capacity planning

Capacity must be configurable per Van/vehicle type.

Potential constraints:

- maximum weight;
- volume;
- number of orders;
- special product capability;
- time/service-window capacity;
- maximum cash exposure.

When capacity is exceeded, routing policy decides whether to:

- use backup Van;
- split future work;
- move to another service window;
- create dispatcher exception;
- require manual resolution.

Do not silently overload a Van.

---

## 12. Customer and address overrides

Support explicit overrides without making them the normal model.

Override may specify:

- exact address;
- exact customer;
- forced territory;
- forced Van/pool;
- forced warehouse;
- service calendar/date behavior;
- effective dates;
- reason;
- approver;
- audit.

Overrides must be visible in routing trace.

---

## 13. Unmapped and unserviceable addresses

An address outside all active Zones must not be guessed.

Create a **Routing Exception** with reason such as:

- unmapped address;
- outside service area;
- conflicting territory;
- no eligible Van;
- no service date;
- no capacity;
- missing coordinates.

Dashboard provides an exception queue using the canonical FOODEX grid.

---

## 14. Customer App impact

Customer App must:

- capture/use address coordinates through the existing address model;
- display whether delivery is serviceable;
- display backend-calculated available delivery dates/windows;
- create orders without choosing a Van;
- show order/payment/collection status;
- show collection receipts;
- show remaining balance where appropriate;
- refresh after a Driver/Van collection;
- optionally expose assigned delivery party after operational assignment is confirmed.

Customer App must not contain routing logic.

---

## 15. Driver App impact

Driver App remains a delivery application.

Required navigation:

- Home;
- Deliveries;
- Wallet;
- Notifications.

Delivery detail must show:

- order/invoice summary;
- payment state;
- amount already paid;
- customer credit applied;
- amount to collect now;
- collected amount;
- remaining amount;
- collection policy.

Delivery completion must become collection-aware:

- **Deliver — No Collection Required**, or
- **Collect & Deliver**.

Driver may support:

- full collection;
- authorized partial collection;
- receipt generation;
- wallet posting;
- remittance submission.

Driver App does not receive Van field-sales features.

Driver live-location service must integrate with the unified fleet map described later.

---

## 16. Van App — standalone third application

Create:

`apps/van_app`

Separate package/application identity.

Suggested FOODEX Van navigation:

- Home;
- Customers;
- Route;
- Wallet;
- More.

### Home

Show operational summary:

- visits today;
- orders captured today;
- orders awaiting preparation;
- ready for pickup;
- loaded orders;
- deliveries today;
- expected collection;
- collected today;
- current custody;
- pending remittance.

Primary actions adapt to workflow state:

- Start Visits;
- Pick Up Today's Load;
- Start Route.

### Customers

Only authorized territory/route customers are shown.

Customer card:

- name;
- address;
- phone;
- last visit;
- last order;
- current outstanding;
- credit status.

Customer detail tabs/actions:

- Overview;
- Orders;
- Outstanding;
- Visits;
- Products;
- Create Order;
- Start Visit;
- Collect Payment;
- Navigation/Call.

### Visits

Visit lifecycle:

- planned;
- started;
- completed_with_order;
- completed_no_order;
- customer_unavailable;
- closed.

No-order reasons must be configurable lookups.

### Field Order Capture

Van user can create a multi-line order.

Backend remains authoritative for:

- price;
- tier;
- MOQ;
- pack/case;
- promotions;
- currency;
- stock/business constraints.

Field user can select:

- requested delivery date;
- address;
- note;
- settlement plan;
- allowed discount/approval action if authorized.

No trusted client unit prices.

### Settlement Plan

Settlement plan is expectation only:

- COD;
- account credit;
- customer balance;
- online;
- mixed;
- future configured methods.

Actual money received is a separate Collection event.

### Collect at order capture

If the customer pays a deposit during the visit:

- create authoritative Payment;
- create Collection;
- increase custody wallet;
- recalculate outstanding;
- issue receipt.

### My Orders

Van user can see orders they captured and their lifecycle.

### Warehouse Pickup / Load Manifest

Van App shows:

- manifest;
- warehouse;
- route/date;
- orders;
- line counts;
- load totals.

Pickup validates:

- order readiness;
- warehouse;
- assignment;
- duplicate pickup;
- load ownership.

Barcode/QR scanning should be allowed as a future-compatible capability.

### Route

Route is customer-stop centric.

A Route Stop may include:

- delivery orders;
- collection-only work;
- sales visit;
- old debt collection;
- several actions together.

Example stop actions:

- Deliver Orders;
- Collect Payment;
- Create New Order;
- Complete Visit.

### Wallet

Same shared custody engine as Driver App.

### More

Potential items:

- My Orders;
- Visit History;
- Load Manifests;
- Notifications;
- Profile;
- Language;
- Diagnostics.

---

## 17. Customer-level collection

Van App may collect money even when there is no active delivery.

Customer collection screen should show:

- total outstanding;
- overdue;
- open invoices;
- today's delivery invoices;
- customer credit position.

Collection allocation policy is configurable.

Possible strategies:

- oldest due first;
- oldest invoice first;
- today's invoices first;
- manual invoice allocation.

Backend performs authoritative allocation.

---

## 18. Partial collection

Example:

- Invoice: 100
- Paid before delivery: 20
- Due: 80
- Collected now: 50
- Remaining: 30

Backend policy decides if delivery may complete.

Possible policy outcomes:

- remainder -> approved account debt;
- block delivery;
- manager override required;
- another configured settlement method.

The UI must always show:

- due before collection;
- amount received;
- remaining after collection;
- resulting settlement state;
- wallet amount to be added.

---

## 19. Shared Collection, Custody Wallet and Remittance domain

### 19.1 Wallet semantics

The wallet is not employee-owned money.

It represents:

**Company cash in employee custody.**

Use an append-only financial ledger.

Do not store an editable authoritative balance.

Derived balance:

Collections
- valid collector reversals
- approved remittances
+/- authorized adjustments.

### 19.2 Actor-neutral design

Do not name the financial domain only after Drivers.

Prefer actor-neutral concepts such as:

- `collection_accounts`;
- `collection_transactions`;
- `collection_allocations`;
- `custody_ledger_entries`;
- `remittances`;
- `remittance_allocations`.

Actor type may identify Driver or Van Rep.

### 19.3 Payment integration

A real customer collection must create/update the authoritative `payments` domain.

For B2B, avoid duplicate financial credit because the current B2B account ledger already includes paid `payments`.

Collection posting and custody posting must happen atomically.

### 19.4 Currency

Never combine currencies into one balance.

Custody is scoped by currency and financial/store scope as required.

### 19.5 Remittance

Driver or Van Rep can submit:

- amount;
- method;
- reference;
- optional proof image;
- note.

State may include:

- pending;
- needs_clarification;
- approved;
- rejected;
- reversed if a later correction is required.

Pending remittance does not reduce authoritative custody until approval, but it reduces **available to remit** to prevent duplicate submission.

### 19.6 Remittance allocation

A remittance may allocate to one or more collection transactions so Finance can reconcile exactly which customer money was turned in.

### 19.7 Reversal/refund

Do not delete collections.

Use explicit reversal/adjustment.

If cash is still with the collector and policy permits, a collector reversal may reduce custody.

If cash has already been remitted, customer refund is a company/platform finance action, not a fake Driver/Van wallet subtraction.

---

## 20. Warehouse and load operations

Add a Van fulfillment workspace.

Group work by:

- service date;
- warehouse;
- route;
- Van;
- territory.

Required concepts:

- Ready for Pickup;
- Load Manifest;
- Not Ready;
- Short Pick;
- Loaded;
- Missing/Exception;
- Returned from Route.

### Short pick

If a warehouse cannot fulfill the ordered quantity:

- do not silently alter issued commercial values;
- use explicit adjustment/revision according to the order/invoice stage.

### Load return

Failed delivery/rejected delivery may create a Van Return Manifest.

Returned goods require warehouse acknowledgment before custody/inventory reconciliation is complete.

---

## 21. Delivery, rejection, partial delivery and returns

### Failed/rejected delivery

Order does not become Delivered.

Goods remain operationally in Van/Driver custody until explicit warehouse return/reassignment.

### Partial delivery

Architecture must not assume all lines are always accepted.

If partial delivery is enabled by policy:

- record accepted quantities;
- record returned quantities;
- trigger required commercial revision;
- reconcile inventory.

Do not let mobile apps silently mutate issued invoice lines.

### Post-delivery return

Use an explicit Return/RMA-style flow, not negative collection hacks.

---

## 22. Van inventory future-proofing

Current target model:

**Order first -> Warehouse prepare -> Load -> Delivery.**

Future possibility:

**Direct Van Stock Sales**
- Van carries generic stock without prior orders;
- customer buys directly from Van inventory.

Do not implement this in the first execution wave unless separately approved.

However, domain choices must leave room for future:

- Van stock location;
- transfer to Van;
- direct sale;
- unsold stock return;
- Van stock count.

---

## 23. Unified live fleet map

The Dashboard must provide one operational map for **Drivers and Vans together**.

### 23.1 Shared live-location backend

Driver App and Van App publish location through one location domain/contract.

Location payload should include:

- actor type: Driver / Van;
- actor/user id;
- vehicle/Van id when applicable;
- current assignment/route id;
- latitude/longitude;
- heading when available;
- speed when available;
- accuracy;
- captured timestamp;
- source app;
- online/stale status.

The map must not rely on two unrelated tracking systems.

### 23.2 Distinct markers

Drivers and Vans must be immediately distinguishable.

Required behavior:

- distinct icons/shapes for Driver vs Van;
- visually differentiated but FOODEX-compatible marker treatment;
- selected-state highlight;
- stale/offline state treatment;
- clustering at high zoom-out;
- no dependence on color alone for distinction.

The legend must clearly show:

- Driver;
- Van;
- selected;
- stale/offline;
- route status where applicable.

### 23.3 Filters

Fast map filters:

- Drivers;
- Vans;
- both;
- governorate;
- territory/Zone;
- route;
- warehouse/hub;
- status;
- active/offline/stale;
- assigned/unassigned;
- service date/shift.

### 23.4 Zone overlay

Territory boundaries must be a first-class map layer.

Admin can toggle:

- Zones;
- Zone labels;
- primary Van assignment;
- backup Van assignment;
- route lines;
- route stops;
- warehouses/hubs.

Zone polygons must be clear without obscuring vehicles or route stops.

Selecting a Zone should show:

- Zone name/code;
- service calendar;
- assigned Van pool;
- primary/backup;
- active routes;
- order count;
- exceptions/capacity warning.

### 23.5 Route overlay

Selecting a Driver or Van may show:

- current route;
- completed stops;
- next stop;
- pending stops;
- current position;
- route exceptions.

### 23.6 Map + grid coordination

The map must not be the only way to operate the fleet.

Use a synchronized FOODEX side/grid panel:

- click map marker -> highlight grid row;
- click grid row -> focus map;
- same filters affect both;
- row actions use the canonical `⋮` menu.

### 23.7 Location privacy and retention

Live tracking applies only under authorized operational conditions.

Configuration must define:

- when apps publish location;
- foreground/background policy;
- shift/session boundaries;
- retention period;
- who can view live/history;
- audit for access where required.

Do not expose fleet location to unauthorized customer/admin contexts.

### 23.8 Stale signal

Map must show freshness:

- live/recent;
- stale;
- offline;
- unknown.

Admin should never mistake an old GPS point for a live vehicle position.

---

## 24. Territory design map

Territory administration must be easy and safe.

Required capabilities:

- draw polygon;
- edit vertices;
- duplicate/copy Zone;
- split Zone;
- merge/reassign through controlled workflow;
- activate/deactivate;
- effective-date change;
- assign service calendar;
- assign warehouse;
- assign eligible Vans;
- preview customers/addresses/orders affected;
- validate overlap/gaps;
- simulate routing before publish.

A change should have:

- draft state;
- visual before/after;
- impact summary;
- publish confirmation;
- audit.

---

## 25. National operations Dashboard

Add a top-level operational area such as:

**Field Operations / العمليات الميدانية**

Suggested modules:

- Live Fleet Map;
- Territories & Coverage;
- Routing Policies;
- Routing Exceptions;
- Vans;
- Van Assignments;
- Routes;
- Customer Visits;
- Van Orders;
- Load Manifests;
- Route Returns.

Finance area:

**Driver & Van Collections**

Suggested modules:

- Wallets;
- Collections;
- Remittances;
- Reconciliation;
- Aging/Risk.

---

## 26. Wallet Dashboard

Wallet grid columns should include:

- actor;
- actor type: Driver/Van;
- store/financial scope;
- currency;
- cash in custody;
- pending remittance;
- available to remit;
- collected today;
- oldest unremitted collection;
- custody limit;
- risk/status.

Row menu may include:

- View Wallet;
- View Collections;
- View Remittances;
- Authorized Adjustment;
- Suspend Collection Capability;
- Open Actor Profile.

---

## 27. Order details and Customer 360

Order detail should eventually show:

- order source;
- Van sales rep if applicable;
- visit reference;
- territory;
- routing policy/version;
- planned service date;
- warehouse;
- load manifest;
- delivery actor;
- collection actor;
- amount collected;
- remaining amount;
- payment/collection receipt references;
- route history;
- reassignment/override trace.

Customer 360 should show:

- addresses + resolved territories;
- assigned/current field coverage;
- visits;
- field orders;
- open invoices;
- collections;
- outstanding;
- receipts;
- relevant route/service information.

Customer Support should be able to understand the full story but should not directly edit the custody ledger without explicit finance permission.

---

## 28. Offline and unreliable-network behavior

Van App especially must tolerate weak connectivity.

Offline-safe examples:

- cached route/customer/order viewing;
- draft visit note;
- draft order entry before authoritative quote;
- cached manifest viewing.

Server-confirmation required:

- final price/quote;
- credit approval;
- final collection posting;
- final delivery transition where financial state changes;
- remittance approval.

Financial actions use:

- idempotency keys;
- retry-safe API;
- explicit `Pending Sync` state until server confirmation.

Never display unconfirmed financial activity as final authoritative balance.

---

## 29. Concurrency and idempotency

Examples:

- Driver and Van Rep attempt to collect same invoice;
- user taps Confirm twice;
- network retries after timeout;
- Finance approves remittance twice.

Backend must use:

- authoritative reread;
- locking/transaction control;
- idempotency key;
- uniqueness constraints where appropriate;
- append-only ledger semantics.

No double collection, double payment, double wallet entry or double remittance settlement.

---

## 30. Security and authorization

Every action must validate:

- authenticated user;
- application/capability;
- store/channel;
- territory/customer authorization;
- route/assignment;
- warehouse scope;
- financial scope;
- currency;
- object ownership.

Do not trust mobile-supplied ids as authorization.

Example permissions:

Van:
- `van.login`
- `van.customers.view`
- `van.visits.manage`
- `van.orders.create`
- `van.pickup`
- `van.delivery`
- `collections.collect`
- `wallet.view_self`
- `remittance.create`

Dashboard:
- `field_ops.manage`
- `territories.manage`
- `routing.manage`
- `vans.manage`
- `routes.manage`
- `warehouse.van_fulfillment`
- `collections.view`
- `remittances.approve`
- `collection.adjust`
- `fleet.live_map.view`

The UI hides unavailable actions, but backend authorization remains authoritative.

---

## 31. Notifications

Van user:
- route assigned/changed;
- order approved/rejected;
- order ready for pickup;
- credit block;
- route exception;
- remittance approved/rejected.

Driver:
- delivery assignment;
- collection requirement;
- remittance result.

Customer:
- order confirmed;
- out for delivery;
- collection recorded;
- remaining balance;
- receipt/refund.

Dashboard:
- new Van order;
- route exception;
- vehicle offline/stale if policy requires;
- failed delivery;
- high partial collection;
- custody aging;
- high custody limit;
- pending remittance.

Notifications must be scope-safe and AR/EN.

---

## 32. Reporting

Field Sales:
- visits planned/completed;
- productive visits;
- orders captured;
- sales value;
- average order;
- conversion rate.

Delivery:
- loaded;
- delivered;
- failed;
- returned;
- on-time delivery.

Collection:
- expected;
- collected;
- outstanding;
- partial collections;
- collection by actor/territory/route.

Wallet/Reconciliation:
- opening custody;
- collections;
- reversals;
- approved remittances;
- closing custody.

Territory/Route:
- orders by Zone;
- service performance;
- capacity utilization;
- route exceptions;
- reassignment frequency;
- unmapped addresses.

Fleet:
- active Drivers;
- active Vans;
- stale/offline;
- route completion;
- live operational distribution.

Reporting is derived from authoritative records and is never the financial source of truth.

---

## 33. Audit and history

Audit every material change:

- territory geometry;
- territory assignment;
- routing policy;
- policy publish/rollback;
- manual reroute;
- route lock/unlock;
- load transfer;
- collection;
- collection reversal;
- remittance;
- remittance approval/rejection;
- authorized financial adjustment.

History must preserve:

- before/after;
- actor;
- time;
- reason;
- source;
- policy version.

---

## 34. Proposed data-domain additions

Exact names may change during implementation, but responsibilities should remain separated.

Geography/routing:

- `service_territories`
- `territory_geometries`
- `territory_service_schedules`
- `routing_policy_versions`
- `routing_rules`
- `routing_overrides`
- `order_routing_assignments`
- `routing_decision_traces`
- `routing_exceptions`

Van/route:

- `vans`
- `van_profiles`
- `van_assignments`
- `field_routes`
- `field_route_stops`
- `customer_visits`
- `van_load_manifests`
- `van_load_manifest_orders`
- `van_return_manifests`

Finance:

- `collection_accounts`
- `collection_transactions`
- `collection_allocations`
- `custody_ledger_entries`
- `remittances`
- `remittance_allocations`

Live fleet:

- live/latest location projection;
- append/history storage according to retention policy;
- actor type/source/freshness.

Do not duplicate:

- orders;
- invoices;
- payments;
- customers;
- inventory;
- authentication identity.

---

## 35. Backend service boundaries

Prefer dedicated domain services instead of extending one large Driver service.

Suggested service responsibilities:

- `TerritoryService`
- `ServiceabilityService`
- `RoutingPolicyService`
- `OrderTerritoryRoutingService`
- `RoutePlanningService`
- `VanAssignmentService`
- `VanVisitService`
- `VanOrderCaptureService`
- `VanLoadService`
- `CollectionService`
- `CustodyLedgerService`
- `RemittanceService`
- `FleetLocationService`

Driver and Van adapters consume shared services.

---

## 36. API direction

Driver API:

- assignments;
- order detail;
- allowed transitions;
- collect;
- wallet;
- remittance;
- live location publish.

Van API:

- home summary;
- authorized customers;
- visits;
- quote;
- order create;
- my orders;
- routes/stops;
- manifests/pickup;
- delivery;
- collect;
- wallet;
- remittance;
- live location publish.

Dashboard APIs/views:

- territories;
- routing policies/simulation;
- assignments;
- routes;
- manifests;
- live fleet;
- wallets;
- collections;
- remittances;
- reconciliation.

All important decisions remain backend-authoritative.

---

## 37. Real-world exception matrix

The implementation plan must explicitly cover:

- customer has several addresses;
- address on boundary of Zones;
- unmapped address;
- Zone changes after historical order;
- customer changes delivery address after order;
- Van full;
- Van maintenance;
- Van failure before load;
- Van failure after load;
- rep absent;
- route reassignment;
- urgent delivery outside normal schedule;
- warehouse cannot fulfill;
- short pick;
- damaged item before dispatch;
- failed delivery;
- rejected delivery;
- partial delivery;
- return to warehouse;
- customer pays deposit before delivery;
- customer pays partial on delivery;
- old invoice collection with no delivery;
- collection across several invoices;
- duplicate tap/network retry;
- simultaneous collection attempts;
- remittance rejected;
- remittance approval retry;
- collector leaves company with outstanding custody;
- mixed currencies;
- live location becomes stale;
- phone GPS unavailable;
- route changes while Van is active.

These must produce explicit states/exceptions, not silent data edits.

---

## 38. Performance and scale

National scope means architecture must assume:

- large customer counts;
- many addresses;
- many Zones;
- many routes;
- many live markers;
- high collection ledger volume.

Requirements:

- server-side pagination;
- indexed lookups;
- spatial indexing where supported;
- bounded live-map payloads;
- viewport/region-aware map queries;
- marker clustering;
- latest-location projection separate from long history;
- asynchronous reporting/materialization where appropriate;
- no all-Egypt dataset in one browser/mobile payload.

---

## 39. Observability

Operational diagnostics should expose:

- routing failures;
- unmapped addresses;
- no-capacity exceptions;
- stale GPS;
- location publish failures;
- duplicate/idempotency rejections;
- collection posting failures;
- wallet reconciliation mismatch;
- remittance mismatch;
- manifest reconciliation failures.

Runtime errors should be attributable to:

- app;
- user/actor;
- route/order;
- policy version;
- request/correlation id.

---

## 40. Testing and acceptance

Minimum automated/acceptance coverage must include:

### Routing
- address inside one Zone;
- overlap priority;
- unmapped address;
- effective-date policy;
- rule reorder;
- primary Van;
- backup Van;
- capacity fallback;
- manual override;
- route lock;
- historical order unaffected by new policy.

### Customer App
- service date from backend;
- route remains invisible/not forgeable;
- collection status updates;
- receipt visible.

### Driver App
- amount due displayed;
- full collection + delivery;
- partial collection policy;
- no-collection delivery;
- wallet increase;
- remittance.

### Van App
- login/capability;
- customer list scope;
- visit;
- multi-line field order;
- authoritative pricing;
- deposit collection;
- order status;
- manifest pickup;
- route stop;
- delivery;
- old-debt collection;
- wallet;
- remittance.

### Warehouse
- ready/load;
- duplicate pickup blocked;
- short pick;
- return manifest.

### Finance
- collection -> Payment + custody atomically;
- no duplicate B2B financial credit;
- remittance pending/approved/rejected;
- reconciliation;
- reversal.

### Live fleet
- Driver and Van publish location;
- one map shows both;
- marker types visually distinct;
- Zone overlay;
- filtering;
- stale/offline state;
- map/grid selection parity;
- authorization.

### UX
- FOODEX identity;
- Catalog-style grid;
- `⋮` row actions;
- pagination;
- responsive;
- AR RTL;
- EN LTR;
- loading/empty/error/offline states.

### Security
- cross-store isolation;
- cross-route/customer access blocked;
- forged territory/Van id rejected;
- unauthorized finance actions rejected.

---

## 41. Proposed execution waves

This master plan should become an umbrella after owner approval.

Do not implement the whole platform in one branch.

### Wave A — Shared foundations
- geography/territory;
- versioned routing policy;
- routing simulation/explainability;
- actor-neutral collection/custody/remittance;
- fleet location foundation;
- shared FOODEX UI components needed by all later pages.

### Wave B — Driver finance completion
- Driver collection UI;
- Collect & Deliver;
- wallet;
- remittance;
- live Driver map integration.

### Wave C — Van platform foundation
- `apps/van_app`;
- Van auth/capabilities;
- customers;
- visits;
- field order capture.

### Wave D — Van fulfillment and route
- Van/vehicle management;
- routes;
- warehouse prep;
- load manifests;
- pickup;
- delivery;
- route returns.

### Wave E — Territory routing and national dispatch
- address -> Zone;
- calendars;
- Van pools;
- capacity;
- fallback;
- exception queues;
- route lock/reassignment.

### Wave F — Unified live fleet
- Driver + Van live map;
- distinct markers;
- Zones;
- route overlays;
- filters;
- synchronized grid.

### Wave G — Customer/Support/Dashboard integration
- Customer App financial visibility;
- receipts;
- Customer 360;
- Order timeline;
- Support read model.

### Wave H — Reports, hardening and E2E
- national reporting;
- reconciliation;
- performance;
- offline/idempotency;
- security;
- full AR/EN UX;
- integrated E2E gate.

---

## 42. Proposed atomic lane structure

After approval, create an umbrella Issue and child Issues. Suggested lanes:

1. Geography + Service Territory model.
2. Routing policy/version/simulation engine.
3. Territory Dashboard + map editor.
4. Van/vehicle + assignment management.
5. Route planning + capacity + exception engine.
6. Shared Collection/Custody/Remittance backend.
7. Driver App wallet + Collect & Deliver.
8. Van App foundation/auth/design system.
9. Van customer/visit/order capture.
10. Warehouse load manifest/pickup/return.
11. Van route/delivery.
12. Van wallet/remittance integration.
13. Unified live fleet location backend.
14. Unified Driver + Van live map.
15. Customer App collection/receipt integration.
16. Customer 360/Order Support integration.
17. Finance collections/remittance/reconciliation Dashboard.
18. Reporting/aging/risk.
19. Integrated national E2E/security/UI gate.

Each child follows `AGENTS.md`:
- one Issue;
- one owner;
- one branch;
- one PR;
- CI owned to completion;
- no duplicate implementation.

---

## 43. Completion invariant

The platform is not complete merely because Van App opens.

The final integrated acceptance must prove:

1. Admin creates/publishes a service Zone.
2. Admin configures schedule, warehouse and eligible Vans without code changes.
3. Customer address resolves to the Zone.
4. Customer App order or Van-captured order routes using the active policy.
5. Dashboard explains why the Van was selected.
6. Warehouse receives grouped work and prepares a load.
7. Van App receives/picks up the manifest.
8. Unified map shows that Van live, distinct from Drivers, inside the visible Zone overlay.
9. Van executes route/customer stop.
10. Van can create another field order at the customer if authorized.
11. Van or Driver delivers and performs required full/partial collection.
12. Customer sees the collection and receipt.
13. Shared custody wallet updates exactly once.
14. Finance sees the same collection.
15. Actor submits remittance.
16. Finance approves.
17. Wallet/reconciliation closes correctly.
18. Customer 360, Order detail, reports and audit show one consistent financial/operational truth.
19. Admin changes routing policy in the Dashboard, simulates it, publishes it, and future unlocked work follows the new rule without code deployment.
20. Historical locked orders retain their original routing/audit truth.

---

## 44. Explicit non-goals for the first implementation wave

Unless separately approved:

- direct stock-selling from generic Van inventory;
- fully autonomous AI route optimization;
- automatic financial write-off;
- unbounded customer-facing live vehicle tracking;
- silent automatic reassignment after physical load custody transfer.

The architecture must leave room for these future features without forcing them into the initial delivery.

---

## 45. Owner-review checklist

Before implementation Issues are generated, confirm:

- standalone Van App scope;
- initial Egypt geography hierarchy;
- policy for B2C partial collection;
- initial remittance methods;
- initial route scheduling model;
- whether route optimization starts manual/rule-based or includes an optimization engine in phase one;
- initial live-location publish interval/retention/privacy policy;
- whether Van load capacity uses weight, volume, order count or a subset initially;
- whether direct Van stock sales remains future-only.

Everything else in this document should be treated as the proposed platform baseline.
