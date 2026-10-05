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

13. **Van App has full FOODEX operational/runtime parity.**
    - Applications sidebar and versioned download.
    - Mobile Settings and App Versions.
    - Preview/Review parity.
    - Runtime Inspector.
    - Push, maintenance and force-update.
    - release/distribution identity.
    - no Van-only shadow administration flow.

14. **The Van/Field Operations program executes as an isolated feature train until final promotion.**
    - production bugs/hotfixes continue on `main` without waiting;
    - Van child PRs target the dedicated integration branch;
    - relevant `main` fixes are regularly absorbed into the train;
    - only the final convergence lane may promote the full train to `main`;
    - repository AUTO-HANDOFF state, not chat, is the execution authority.

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

### 4.6 Tabs-first, low-scroll information architecture

FOODEX operational screens must prefer **tabs and progressive disclosure** over long vertically stacked pages.

Core rule:

> If one page contains more than one substantial function, dataset or workflow area, split those concerns into clear tabs before adding more vertical sections.

Examples:

- Van profile: Overview | Routes | Visits | Orders | Wallet | Remittances;
- Customer 360: Finance | Orders | Addresses | Field Activity | Collections;
- Territory management: Overview | Geometry | Schedule | Vans | Routing Rules | History;
- Route detail: Overview | Stops | Orders | Collections | Exceptions;
- Van detail: Overview | Assignments | Routes | Location | Maintenance | History;
- Finance collection area: Wallets | Collections | Remittances | Reconciliation.

Avoid screens that require excessive scrolling through many large cards.

### 4.7 Card discipline

Cards must be used to create hierarchy, not to fill space.

Rules:

- do not repeat the same card in several pages unless it serves a different actionable purpose;
- every metric/card must have a clear reason to exist;
- do not create oversized cards for small values;
- compact metrics may use a summary strip rather than separate large cards;
- analytics cards should be sized by importance and information density;
- secondary information belongs in tabs, expandable panels, drawers or detail views rather than permanent large cards;
- do not duplicate a full operational module as a card elsewhere.

Each data concept should have one **canonical home**.

Other screens may show:

- a small summary;
- a badge/count;
- a contextual link;
- a compact preview;

but should not recreate the same full card/module.

### 4.8 Scroll-budget rule

Scrolling is allowed when the underlying content is naturally list-like, but long scroll must not be the default information architecture.

Use:

- tabs for separate functions;
- pagination for large datasets;
- filters/search for discovery;
- drawers/modals for short contextual actions;
- dedicated detail screens for complex workflows.

Avoid:

- dashboard pages made of many stacked cards;
- full forms mixed with large tables on the same scroll;
- repeating summary cards before every tab;
- several independent modules stacked vertically when tabs would be clearer.

### 4.9 Global usability and visual-density standard

In addition to FOODEX identity, every new screen must satisfy international UI/UX and usability principles:

- clear visual hierarchy;
- low cognitive load;
- restrained visual density;
- meaningful whitespace;
- readable typography;
- predictable alignment;
- consistent spacing rhythm;
- consistent component sizing;
- accessible contrast;
- keyboard/focus usability on Dashboard;
- mobile touch targets;
- responsive behavior;
- clear primary/secondary actions;
- no oversized buttons/cards without functional reason;
- no undersized dense controls that hurt readability;
- no decorative analytics that do not support a decision.

The screen should communicate the most important task immediately without making the operator scan unnecessary content.

### 4.10 Navigation and screen governance

A feature is not considered implemented merely because backend/API code exists.

A user-facing feature is complete only when it is reachable through an obvious normal entry point such as:

- sidebar/navigation item;
- tab;
- contextual action;
- canonical workflow step.

Hidden deep links and orphaned routes do not satisfy completion.

Before creating any new screen, every worker must perform a **screen/route inventory check**:

1. search existing Dashboard routes/views;
2. search Customer App screens/routes;
3. search Driver App screens/routes;
4. search Van App screens/routes;
5. identify the canonical existing screen for the same business purpose;
6. extend/reuse it when it exists;
7. create a new screen only when no existing canonical screen can own the responsibility.

Duplicate screens for the same business purpose are forbidden.

### 4.11 Server route organization

Server routes must be organized by business module/domain.

Requirements:

- consistent URI prefixes;
- consistent route-name prefixes;
- grouped middleware;
- clear module ownership;
- no random route insertion in unrelated sections;
- no permanent duplicate routes for the same screen;
- aliases only for controlled compatibility/migration;
- route/navigation inventory maintained as the platform grows.

The final route tree should make the platform understandable without reading implementation internals.

### 4.12 Canonical screen ownership

Every substantial feature must identify one canonical screen/module that owns the complete experience.

Examples:

- Live Fleet -> Live Fleet Map;
- Territory settings -> Territories & Coverage;
- Van wallet -> Wallet;
- Remittances -> Remittances;
- Customer field history -> Customer 360 / Field Activity.

Other pages should link to or summarize these modules rather than duplicating them.

### 4.6 UI/UX release gate

A feature is not complete if:

- it is reachable only by hidden/deep routes;
- row actions are not discoverable;
- AR/EN direction breaks;
- financial amounts are ambiguous;
- loading/error/empty/offline states are missing;
- a high-volume list lacks pagination;
- a Dashboard page diverges materially from FOODEX identity;
- a row is cluttered with permanent action buttons instead of the canonical `⋮` pattern where applicable;
- the screen contains multiple substantial functions but uses long scroll instead of tabs;
- the same full card/module is duplicated across multiple screens without a distinct business purpose;
- a new screen duplicates an existing canonical screen;
- the feature is reachable only through a hidden/unstructured route;
- route naming/grouping is inconsistent with its module.

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

## 40A. Van App operational parity with the existing FOODEX application control plane

The Van App is not complete if only `apps/van_app` is created. It must be integrated everywhere FOOD currently manages, previews, distributes, diagnoses and versions Customer/Driver applications.

### 40A.1 Applications sidebar

The Dashboard **Applications** group must expose the Van application in the same first-class way as Customer and Driver.

The target Applications area must include at minimum:

- App Preview / Review;
- Customer App download;
- Driver App download;
- **Van App download**.

The Van entry must use the canonical sidebar, permission, localization and icon system.

Expected route/asset direction:

- route equivalent to `admin.mobile-apps.van.download`;
- versioned asset such as `FOODEX-Van.apk`;
- current authoritative FOODEX version resolution;
- no fixed release version inside the UI.

If Applications later becomes a richer Application Center, Customer/Driver/Van remain peer first-class products.

### 40A.2 Mobile Settings

The current Mobile Settings control plane must support:

- `customer`;
- `driver`;
- **`van`**.

Van settings must use the same environment model:

- development;
- staging;
- production.

Van runtime configuration must support all applicable current contracts:

- display name;
- Android package ID;
- iOS bundle ID if/when shipped;
- published version/build;
- minimum supported version;
- recommended version;
- force update;
- maintenance mode;
- AR/EN maintenance messages;
- distribution/store URLs;
- push configuration compatibility;
- runtime/diagnostic metadata.

Where an existing Driver-only setting is actually fleet-generic, refactor toward a shared Driver/Van policy rather than creating an unrelated duplicate setting.

Location heartbeat/freshness is a primary example.

### 40A.3 App Versions and release identity

Van must enter the same release identity contract as the existing apps.

When Van becomes distributable, release validation must synchronize at minimum:

- root `VERSION`;
- Customer package/runtime identity;
- Driver package/runtime identity;
- Van package/runtime identity;
- Dashboard App Versions;
- CHANGELOG/update notes;
- release registry;
- Dashboard update/distribution manifest;
- APK artifacts/checksums.

A FOODEX release must not contain Customer/Driver artifacts from one SHA and Van from another while presenting them as one release.

### 40A.4 System Update and distribution

Where System Update or release bundles contain mobile/runtime metadata or downloadable application artifacts, Van must participate under the same release rules.

Van must not rely on a manual APK uploaded outside the repository release contract.

### 40A.5 App Preview / Review

The current Preview architecture must extend from:

- Customer;
- Driver;

to:

- Customer;
- Driver;
- **Van**.

Van Preview must reuse the real Van runtime/contracts wherever technically possible.

Van Preview context should support:

- selected Van user;
- territory;
- Van/vehicle assignment;
- warehouse;
- route;
- service date/shift;
- route stops;
- customer visit state;
- load manifest;
- delivery state;
- safe wallet/collection summary;
- AR/EN;
- supported device profile.

Preview must not implement a fake alternative field-sales lifecycle.

### 40A.6 Preview parity rule

Any Dashboard-managed behavior that changes Van App must preserve Preview parity in the same owning PR after the Van Preview foundation exists.

Incomplete states include:

- Van standalone app behaves differently from Preview;
- Preview uses hardcoded fixture logic as live state;
- Preview silently falls behind the production contract.

### 40A.7 Runtime Inspector

The mobile Runtime Inspector contract must recognize Van as a first-class source.

Target app mapping:

- `customer` -> `customer_app`;
- `driver` -> `driver_app`;
- **`van` -> `van_app`**.

Unknown Van events must never fall through and be misclassified as Customer events.

Safe diagnostic context may include:

- category;
- app version/build;
- platform/OS;
- current route/screen;
- channel/store;
- order id;
- invoice id;
- Van assignment id;
- Van/vehicle id where safe;
- field route id;
- route-stop id;
- territory id;
- customer-visit id;
- load-manifest id;
- collection/remittance reference where safe;
- retry/attempt;
- correlation id;
- sanitized metadata.

Existing Inspector redaction rules remain mandatory.

Never export:

- auth tokens;
- passwords/secrets;
- raw sensitive request/response bodies;
- customer email/phone/civil identifiers;
- precise customer address;
- precise GPS coordinates.

Location failures should be diagnosable by category/freshness/route/actor context without leaking precise coordinates into Inspector exports.

### 40A.8 Van runtime diagnostic categories

Van runtime must make at least these classes diagnosable:

- authentication/session;
- route load;
- territory resolution;
- manifest load/pickup;
- field order capture/quote;
- customer visit;
- delivery transition;
- collection posting;
- wallet/remittance;
- live-location heartbeat;
- stale GPS;
- push/notification;
- offline outbox/sync;
- version/schema mismatch.

### 40A.9 Correlation

Van mobile events and APIs should preserve correlation identifiers so an operator can trace:

Van action -> API request -> backend error -> order/route/collection context.

### 40A.10 Inspector Dashboard

System Inspector must support a `van_app` source filter and render Van context in the same FOODEX Inspector UX.

Do not create a separate Van-only troubleshooting application.

### 40A.11 Production diagnostics acceptance

Van production readiness must prove:

- mobile Inspector submission;
- sanitization;
- duplicate suppression;
- application/version identity;
- useful safe route/order/manifest context;
- export/triage through the existing FOOD workflow.

---

## 40B. Persistent handoff and AUTO-HANDOFF execution contract

This program must be runnable from a short repository-owner prompt without reconstructing chat context.

### 40B.1 Coordination umbrella

After owner approval:

1. create one coordination-only umbrella Issue;
2. create/reuse atomic child Issues;
3. link this document as the authoritative plan;
4. record dependencies, ownership fences and completion criteria;
5. do not implement directly on the umbrella.

### 40B.2 Short mission command

The intended execution command is:

```text
FOOD #<VAN_UMBRELLA> AUTO-HANDOFF
```

That one command must be enough.

Workers reconstruct current state from GitHub, not chat.

### 40B.3 Mission behavior

On receiving the mission command, a worker must:

1. fetch umbrella and this plan;
2. reconstruct child Issues/branches/PRs/HEAD/CI;
3. respect valid active ownership;
4. reuse every existing branch/PR;
5. take over red/handoff-ready work under `AGENTS.md`;
6. start only dependency-unblocked, non-conflicting work;
7. own CI on the same PR;
8. merge completed child work into the Van integration target when permitted;
9. update umbrella state;
10. continue to the next safe lane;
11. stop only at mission completion or a genuine external/human gate.

### 40B.4 Machine-readable worker state

Every active atomic Issue maintains:

```text
<!-- foodex-worker-state:v1 -->
STATE: WORKING
OWNER: worker-name-or-role
BRANCH: feat/<issue>-stable-name
PR: #<pr>
HEAD: <sha>
HEARTBEAT: <UTC timestamp>
BLOCKER: none
NEXT_ACTION: <exact next repository action>
```

Handoff records:

- Issue;
- branch;
- PR;
- current HEAD;
- completed scope;
- remaining scope;
- CI state;
- exact failing job/test;
- next action;
- real external blocker if any.

### 40B.5 No duplicate work

Forbidden:

- replacement `v2` / `retry` / `final` branches;
- duplicate PR for an existing atomic task;
- duplicate Van App foundation;
- duplicate routing engine;
- duplicate Collection/Wallet engine;
- duplicate fleet-location system;
- fake Preview-only business implementation.

### 40B.6 Dependency-aware worker pool

The umbrella must define safe concurrency by wave.

Shared foundation owners land first when they block dependent work, especially:

- routing contracts;
- collection/custody contracts;
- fleet-location contract;
- Van app bootstrap/design-system foundation;
- app/version/Inspector/shared control-plane parity.

### 40B.7 First-run-green expectation

Each child Issue follows FOOD preflight discipline:

- branch/repository policy;
- syntax/diff integrity;
- formatter/lint;
- static analysis;
- Flutter analyze/tests;
- focused backend tests;
- migration validation;
- runtime/visual QA;
- exact-head CI;
- final artifacts from validated SHA.

### 40B.8 Van-specific CI coverage

Before mission completion, CI/preflight must treat Van as a normal deployable app:

- Van Flutter analyze;
- Van unit/widget tests;
- Android compile/package check;
- version identity;
- Runtime Inspector contract;
- Preview parity where applicable;
- release artifact validation.

### 40B.9 Handoff Definition of Ready

A child may become `worker:ready` only when:

- dependencies are merged;
- no active lane owns conflicting files;
- exact acceptance is written;
- branch name is fixed;
- expected tests are named;
- API/design authorities are named;
- no duplicate implementation exists.

### 40B.10 Handoff Definition of Done

A lane is complete only when:

- acceptance passes;
- exact-head CI is green;
- PR is integrated into the correct target;
- Issue contains branch/PR/SHA/evidence;
- umbrella checkpoint is refreshed;
- the next safe lane is promoted.

---

## 40C. Van control-plane parity completion checklist

The final Van integration gate cannot close until all applicable checks pass:

- [ ] standalone `apps/van_app` exists;
- [ ] Van appears under Dashboard Applications;
- [ ] versioned Van APK download exists;
- [ ] Van is supported in Mobile Settings;
- [ ] Van is supported in App Versions/release identity;
- [ ] Van participates in System Update/distribution rules;
- [ ] Van is supported by App Preview/Review;
- [ ] Van Preview uses real/shared runtime contracts;
- [ ] Runtime Inspector accepts `van_app`;
- [ ] Inspector filters/rendering include Van;
- [ ] sensitive Van/GPS data is redacted;
- [ ] push registration/notifications are supported;
- [ ] maintenance/force-update works;
- [ ] Van live location joins the unified fleet map;
- [ ] release scripts/preflight validate Van;
- [ ] final artifacts come from one validated integrated SHA;
- [ ] AR/EN and RTL/LTR pass;
- [ ] UI/UX matches FOODEX identity;
- [ ] runtime loading/empty/error/offline/stale states are implemented;
- [ ] repository-visible handoff state can fully resume work without chat history.

---

## 40D. Isolated Van / Field Operations feature-train policy

The National Field Operations program must **not** destabilize or delay the current FOODEX production line.

### 40D.1 Dedicated integration branch

After owner approval and creation of the implementation umbrella, create one dedicated long-lived integration branch:

```text
feat/van-field-operations-integration
```

This branch is the only integration target for the new Van / Territory / Routing / Collections program until final convergence.

It is a **feature-train integration target**, not an atomic worker branch.

Do not implement atomic Issues directly on it except through the explicit final/integration lane that owns train composition.

### 40D.2 Child branch target

Atomic implementation Issues under the Van umbrella create/reuse their normal Issue-scoped branches, for example:

```text
feat/<issue>-territory-engine
feat/<issue>-van-app-foundation
feat/<issue>-collection-custody-ledger
feat/<issue>-fleet-map-van-parity
```

Their PR target is:

```text
feat/van-field-operations-integration
```

not `main`.

This keeps the entire new program isolated from production until the owner-approved final integration.

### 40D.3 Production main remains free

`main` remains the authoritative production/release branch for the currently published FOOD platform.

During Van development:

- production bugs continue to be fixed from `main`;
- urgent Customer App bugs continue to be fixed from `main`;
- urgent Driver App bugs continue to be fixed from `main`;
- Dashboard production bugs continue to be fixed from `main`;
- security/hotfix/release work continues normally;
- these fixes do **not** wait for Van work.

The Van feature train must never block a production hotfix.

### 40D.4 Production precedence

If a production Issue and a Van Issue need the same file:

1. production/main work wins;
2. production fix lands on `main` first;
3. Van lane pauses only the conflicting file/scope if necessary;
4. Van integration branch absorbs the production fix;
5. Van lane rebases/merges the updated integration baseline and continues.

Do not delay a production release merely to avoid an integration conflict.

### 40D.5 Main-to-train synchronization

The Van integration branch must regularly absorb `main`.

Required checkpoints:

- before starting a dependency-critical wave;
- after any production hotfix touching shared files;
- before merging a Van child PR that depends on recently changed platform behavior;
- before integrated E2E;
- immediately before final Van-to-main PR.

Preferred direction during development:

```text
main -> feat/van-field-operations-integration
```

Do not routinely merge the Van train back into `main`.

### 40D.6 Integration ownership

Absorbing `main` into the feature train must be coordinated.

Use one integration/composition owner at a time.

That owner is responsible for:

- updating the integration branch;
- resolving cross-lane conflicts;
- running integration smoke tests;
- documenting absorbed `main` SHA;
- recording any adaptation work required by Van child lanes.

Atomic workers should not independently perform competing train-wide merges.

### 40D.7 Production bug discovered while testing Van

If Van testing reveals a defect that also reproduces on the current production/main product:

- classify it as a **platform/production bug**;
- create/reuse the appropriate production Issue;
- fix it on a normal main-targeting production branch;
- merge it into `main`;
- then absorb that fix into the Van integration train.

Do **not** hide a production defect inside the Van-only branch.

This ensures the current published product receives the fix immediately.

### 40D.8 Van-only defect

If a defect exists only because of the new Van/Field Operations work:

- fix it on the existing owning Van child Issue/branch/PR;
- keep it inside the integration train;
- do not create an unrelated main hotfix.

### 40D.9 Shared-platform enhancement discovered by Van work

If Van requires a generic platform enhancement that is safe and independently useful for current production:

classify it explicitly as either:

**A. Production-safe platform prerequisite**
- may be implemented as its own main-targeting Issue;
- must preserve current Customer/Driver/Dashboard behavior;
- lands on `main`;
- is then absorbed by the Van train.

or:

**B. Van-specific prerequisite**
- stays inside the Van train.

Workers must not move code to `main` merely because it is technically shared; production value/risk must be explicit.

### 40D.10 Release identity fence

Van child branches and the Van integration train must not publish normal production release identity merely to test the train.

Until final release lane:

- do not promote a production `VERSION` solely for an internal Van child;
- do not overwrite current Customer/Driver production artifact links with Van-train artifacts;
- do not register an incomplete Van train as a released FOODEX version;
- do not mark Van APK as production published.

Internal CI/test artifacts may be produced, but must be clearly non-production and tied to exact SHA.

### 40D.11 Dedicated test artifacts

The train may produce test-only artifacts such as:

- Van APK from integration head;
- test Dashboard package;
- preview build;
- staging fixtures.

Every artifact must clearly identify:

- integration branch;
- exact commit SHA;
- non-production status.

A test artifact from an old SHA is not valid evidence for a newer train head.

### 40D.12 Final convergence lane

Create one explicit final integration/release Issue after all Van child lanes and the integrated E2E gate pass.

That lane owns the only normal PR:

```text
feat/van-field-operations-integration -> main
```

Before that PR:

1. absorb latest `main`;
2. resolve all drift/conflicts;
3. run full backend/Flutter/migration/security/UI/runtime tests;
4. run Customer regression;
5. run Driver regression;
6. run Dashboard regression;
7. run Van integrated E2E;
8. validate System Inspector/Preview/release parity;
9. validate production-like database migration;
10. prove no current production feature regressed.

Only after the final train is green should the owner-approved release be promoted.

### 40D.13 One-time release principle

The goal is:

**Build isolated -> stabilize isolated -> continuously absorb production fixes -> final integrated validation -> merge once to main -> publish once.**

Avoid partially publishing half of the Van platform unless the owner explicitly splits a production-safe prerequisite.

### 40D.14 Rollback safety

The final release must have a documented rollback/disable strategy.

Prefer configuration/feature activation controls so the new subsystem can be disabled without corrupting:

- existing Customer commerce;
- Driver delivery;
- current Dashboard;
- current finance.

Database migrations must be backward-safe to the extent supported by FOOD deployment policy.

### 40D.15 Feature activation

Even after code merges to `main`, production activation should be explicit.

Recommended controls:

- Van App availability;
- territory routing activation;
- Van live tracking;
- Van collection capability;
- field order capture;
- auto-routing vs manual-routing mode.

The first production release may ship code with selected modules disabled until operational configuration is ready.

### 40D.16 Handoff behavior under isolation

The mission command:

```text
FOOD #<VAN_UMBRELLA> AUTO-HANDOFF
```

must understand the isolation rule automatically:

- Van child PRs target the dedicated integration branch;
- production Issues target `main`;
- production defects always get precedence;
- workers must absorb relevant `main` changes into the train;
- no Van worker may merge directly to `main` before the final convergence lane.

### 40D.17 CI topology

Van child CI validates against the current Van integration base.

The integration branch should also have train-level CI covering:

- backend;
- Customer regression where shared code changed;
- Driver regression where shared code changed;
- Van analyze/tests/build;
- migrations;
- routing;
- collection ledger;
- live fleet;
- Preview;
- Runtime Inspector;
- UI/UX acceptance.

Final main PR repeats release-critical checks against the latest main baseline.

### 40D.18 Conflict prevention

To minimize feature-train pain:

- keep child Issues small;
- define file ownership fences;
- land shared contracts before dependent UI lanes;
- avoid multiple child lanes editing central navigation/version/inspector files simultaneously;
- assign shared control-plane changes to one dedicated parity lane;
- rebase/absorb train changes before modifying a shared file.

### 40D.19 Train health checkpoint

The Van umbrella should always expose:

- current integration branch head;
- latest absorbed main SHA;
- open child PRs;
- child CI state;
- train CI state;
- known conflicts;
- next merge-ready lane;
- production fixes waiting to be absorbed.

This makes handoff safe even when several workers are active.

### 40D.20 Completion condition

The Van program is not ready for main until:

- every required child Issue is complete;
- all child PRs are integrated into the feature train;
- latest production `main` is absorbed;
- no unresolved production fix is missing from the train;
- integrated E2E passes;
- Customer/Driver/Dashboard regressions pass;
- Van Preview/Inspector/release parity passes;
- final production migration/release validation passes;
- owner approves final promotion.

---

## 40E. Wave 0 — Development acceleration foundation

Wave 0 is mandatory before large functional implementation begins. Its purpose is to make later workers faster, more consistent and less likely to create duplicate infrastructure.

### 40E.1 Shared Flutter packages

Create/reuse shared packages for cross-application concerns where duplication would otherwise occur.

Target responsibilities may include:

- FOODEX design system;
- API transport/base client;
- authentication/session primitives;
- localization/RTL helpers;
- money/currency formatting;
- runtime inspector client;
- push/notification primitives;
- live-location heartbeat client;
- network/offline state;
- common loading/empty/error/stale states;
- safe retry/idempotency helpers.

The mobile applications remain independent products:

- Customer App;
- Driver App;
- Van App.

Shared packages must not collapse their navigation or business journeys into one app.

### 40E.2 Contract-first APIs and generated models

For new Van/territory/routing/collection contracts:

1. define request/response schemas first;
2. keep backend contracts authoritative;
3. generate or mechanically validate Dart models/clients where practical;
4. fail CI on schema/client drift.

Do not repeatedly hand-code the same JSON contract in multiple applications.

### 40E.3 Reusable Dashboard management-grid component

Extract/reuse a canonical FOODEX management-grid pattern based on Catalog Management.

It should provide a consistent implementation path for:

- search;
- server pagination;
- status badges;
- responsive columns;
- empty/loading/error states;
- selection/focus hooks;
- row-level `⋮` actions;
- destructive-action confirmation;
- AR/EN direction.

New field-operations pages should consume this pattern rather than recreating table behavior independently.

### 40E.4 Van application bootstrap

Provide one approved Van scaffold before feature teams expand the app:

- package/application identity;
- environments;
- API base configuration;
- auth/session;
- FOODEX theme;
- AR/EN;
- navigation shell;
- Runtime Inspector;
- push bootstrap;
- location bootstrap;
- offline/network state;
- app version/runtime identity;
- CI/build target.

Later workers extend the scaffold; they do not create alternative Van shells.

### 40E.5 Feature scaffolding

Provide templates/generators or documented skeletons for common units such as:

- backend controller/request/service/test;
- API DTO/schema;
- Flutter repository/state/screen/test;
- Dashboard index/filter/grid/modal;
- permissions/translations;
- inspector context;
- issue/PR acceptance block.

The goal is consistency and speed, not generated business logic.

### 40E.6 Routing simulator

Provide a deterministic routing test harness that can run:

- one address/order;
- a batch;
- historical fixtures;
- future service date;
- capacity scenarios;
- primary/backup failure;
- overrides;
- policy-version comparison.

It must expose the same decision trace as production routing without mutating production records.

### 40E.7 GPS / fleet simulator

Provide a non-production test utility capable of simulating:

- Driver heartbeat;
- Van heartbeat;
- movement along a route;
- stale signal;
- offline actor;
- route deviation;
- actor recovery;
- concurrent Driver/Van map activity.

This allows live-map workers to test without physical vehicles.

### 40E.8 Financial scenario fixtures

Create deterministic fixtures for:

- full collection;
- partial collection;
- customer credit;
- deposit before delivery;
- old-debt collection;
- multi-invoice allocation;
- reversal;
- refund boundary;
- pending/approved/rejected remittance;
- duplicate retry;
- concurrent collection;
- mixed currency rejection.

Financial fixtures must use the real services/contracts, not mock alternate accounting logic.

### 40E.9 Egypt pilot/demo dataset

Provide a reproducible, non-production seed/profile containing representative:

- governorates/areas;
- service Zones;
- warehouses;
- Vans;
- Van users;
- Drivers;
- customers with multiple addresses;
- routes;
- orders;
- collections.

Demo data must be explicitly non-production and safe to clear/reseed.

### 40E.10 Selective CI and affected-area matrix

Extend repository change detection so CI understands at minimum:

- backend field-operations;
- `apps/van_app`;
- shared Flutter packages;
- Driver shared changes;
- Customer shared changes;
- Dashboard field operations;
- routing;
- finance/collections;
- live fleet;
- Preview/Inspector;
- release identity.

Child PRs should run the cheapest sufficient deterministic checks first.

Train-level/final gates still run the broad integrated suite.

### 40E.11 Train drift guard

Add train health validation that reports:

- integration HEAD;
- latest absorbed `main` SHA;
- commits behind/ahead;
- shared files changed on `main`;
- likely conflict areas;
- whether a mandatory sync checkpoint is due.

Do not allow the feature train to drift silently for long periods.

### 40E.12 Worker Issue templates

Van umbrella child Issues must be generated from a standard template containing:

- Parent umbrella;
- exact integration target;
- dependencies;
- owned files/subsystem;
- files/scopes not owned;
- source-of-truth docs;
- UI reference;
- API contracts;
- acceptance criteria;
- required tests;
- CI expectations;
- handoff state block;
- production-vs-Van defect classification rule.

### 40E.13 Architecture ownership map

Maintain one repository-visible map identifying the authoritative owner/lane for shared domains, including:

- routing;
- territory;
- live location;
- Van bootstrap;
- shared Flutter;
- collection/custody;
- remittance;
- mobile control-plane parity;
- Runtime Inspector;
- Preview;
- release identity.

Workers must check this map before editing shared files.

---

## 40F. Additional real-world operational foundations

### 40F.1 Mobile device/session management

Van operations require supportability at device level.

Dashboard should expose safe operational device/session information such as:

- Van user;
- current device registration;
- app version/build;
- platform/OS;
- last login;
- last API activity;
- last successful sync;
- last location heartbeat time;
- notification registration state;
- location-permission/heartbeat health where technically available without exposing sensitive device data;
- active/revoked session state.

Authorized admin actions may include:

- revoke session/device;
- invalidate push token;
- require re-authentication.

Do not expose secrets or device identifiers beyond what is operationally necessary.

### 40F.2 Address data-quality and mapping queue

National routing depends on good address data.

Provide an admin workflow for:

- unmapped addresses;
- missing coordinates;
- low-confidence mapping;
- overlapping-Zone conflict;
- manual pin correction;
- territory assignment;
- bulk import/export/review;
- duplicate/near-duplicate address review;
- map-based correction.

Routing must distinguish:

- customer-entered coordinates;
- admin-confirmed coordinates;
- imported coordinates;
- manually overridden territory.

### 40F.3 Shift lifecycle and end-of-day reconciliation

Driver and especially Van operations need explicit operational shift closure.

A shift may track:

- actor;
- Van/vehicle;
- date/start/end;
- route;
- load manifest;
- loaded orders;
- delivered;
- failed;
- returns;
- expected collections;
- actual collections;
- current custody;
- pending remittance;
- warehouse-return status;
- unresolved exceptions.

End-of-day close must not silently mark unresolved discrepancies as complete.

Possible final states:

- reconciled;
- closed_with_approved_exception;
- blocked_pending_return;
- blocked_pending_remittance;
- blocked_financial_mismatch.

### 40F.4 Configurable proof-of-service contract

Delivery/collection proof must be policy-driven.

Supported proof types should be extensible and may include:

- customer OTP;
- signature;
- photo;
- receipt acknowledgment;
- note/reason;
- GPS presence confirmation using safe backend validation without exporting precise coordinates to diagnostics.

Policy can depend on:

- channel;
- territory;
- customer;
- order value;
- payment type;
- delivery/collection action;
- risk rule.

Do not hardcode one proof method for all customers.

### 40F.5 Route deviation and GPS health

Live fleet operations should detect operational exceptions without making routing unusably rigid.

Configurable signals may include:

- no heartbeat for configured duration;
- GPS unavailable;
- low accuracy;
- actor far from planned route;
- unexpected prolonged stop;
- missed service window.

Default response should be operational warning/exception unless a specific policy requires stronger enforcement.

Thresholds must be admin-configurable.

### 40F.6 Dispatcher control and manual mode

Auto-routing must never remove operational control.

Admin/dispatcher can:

- assign manually;
- reassign before route lock;
- override after route lock with reason/permission;
- switch a territory/period to manual routing;
- suspend one Van;
- remove one Van from eligibility;
- force backup pool;
- temporarily change service schedule.

All actions are effective-dated/audited where appropriate.

### 40F.7 Pilot rollout and staged activation

Architecture targets national scale, but production activation is staged.

The system must support activation by:

- country;
- governorate;
- territory;
- warehouse;
- Van pool;
- selected Van;
- feature/capability.

Recommended rollout model:

```text
Internal test
  -> selected pilot Zones
  -> one governorate/operational cluster
  -> additional governorates
  -> national expansion
```

The pilot uses the same production architecture; it is not a temporary alternate implementation.

### 40F.8 Operational readiness checklist per Zone

Before activating a Zone, validate:

- geometry approved;
- addresses mapped;
- warehouse configured;
- service calendar configured;
- eligible Van pool configured;
- primary/fallback behavior configured;
- route/collection policy published;
- users/devices ready;
- mobile versions compatible;
- live location healthy;
- finance/remittance policy ready;
- support/operations permissions ready.

### 40F.9 Data migration/import readiness

Existing nationwide customers may require bulk onboarding.

Plan must support controlled import/reconciliation for:

- addresses;
- coordinates;
- territory mapping;
- customer-Van legacy hints if any;
- existing outstanding financial balances;
- Van master data;
- operational users.

Imports must be previewed, validated, idempotent where feasible and auditable.

### 40F.10 Disaster and continuity operations

Operational design must support controlled fallback when:

- maps provider unavailable;
- GPS degraded;
- push unavailable;
- route engine unavailable;
- mobile network degraded;
- one warehouse offline.

Fallbacks must preserve financial and order integrity.

Examples:

- cached assigned route;
- manual dispatch;
- bounded offline drafts;
- server-confirmed financial posting later;
- explicit stale state rather than fake-live state.

---

## 40G. Modular operation, kill switches and editable operating modes

The Van / Field Operations platform must be modular by design.

No major subsystem may become a hidden hard dependency that prevents unrelated FOOD operations from continuing.

### 40G.1 Core principle

Every major capability must have:

- an explicit enable/disable state;
- an operating mode where applicable;
- a safe fallback;
- dependency declaration;
- effective dates when appropriate;
- audit;
- permission-controlled administration;
- clear runtime status.

Disabling a capability must not corrupt data or silently change historical records.

### 40G.2 Routing operating modes

Routing must support at minimum:

```text
MANUAL
AUTOMATIC
HYBRID
```

#### MANUAL

The system may still:

- resolve customer/address/territory;
- calculate serviceability;
- show eligible Vans;
- show capacity/availability;
- show routing recommendations;

but it must not automatically assign the final Van/route.

New work enters a clear queue such as:

**Awaiting Manual Dispatch / في انتظار التوجيه اليدوي**

Authorized dispatcher selects:

- service date;
- warehouse;
- Van;
- route;
- stop sequence if needed.

#### AUTOMATIC

The active published routing policy automatically determines the assignment when all required conditions are satisfied.

If automation cannot make a safe decision, the order goes to an exception/manual queue.

It must never guess unsafe routing data.

#### HYBRID

Automation proposes or assigns routine work while configured exceptions require dispatcher confirmation.

Examples:

- ordinary Zone order -> automatic;
- high-value order -> approval;
- unmapped address -> manual;
- capacity overflow -> manual/backup policy;
- VIP/customer override -> configured handling;
- urgent delivery -> manual confirmation.

The exact hybrid rules are configuration-driven.

### 40G.3 Routing mode scope

Routing mode must be configurable at appropriate scopes rather than one global hardcoded switch only.

Supported scope model should allow overrides such as:

- platform;
- country;
- governorate/region;
- territory;
- warehouse/hub;
- store/channel;
- service date/shift;
- Van pool.

Use deterministic precedence so the effective mode is explainable.

Example:

```text
Platform = AUTOMATIC
Alexandria = AUTOMATIC
Territory A = MANUAL
Friday shift = MANUAL
```

Orders in Territory A follow Manual mode without forcing all Egypt operations to Manual.

### 40G.4 Routing configuration must be editable

Admin must be able to manage without code deployment:

- routing mode;
- rule priority;
- territory mapping;
- service calendars;
- primary Van;
- backup Van/pool;
- warehouse/hub;
- capacity thresholds;
- cutoffs;
- order eligibility;
- fallback actions;
- manual-review conditions;
- route-lock behavior;
- reassignment policy;
- route-deviation thresholds;
- stale GPS thresholds;
- service windows;
- customer/address overrides.

All values must be validated before publish.

### 40G.5 Routing engine disable behavior

If automatic routing is disabled:

- Customer App checkout still works when serviceability rules permit;
- Van App field order capture still works;
- Dashboard order creation still works;
- order/invoice/payment creation does not fail merely because auto-routing is off;
- the order receives a clear routing state such as `awaiting_dispatch`;
- Warehouse preparation can be configured either to wait for dispatch or proceed to a generic preparation queue;
- Dashboard exposes unassigned/routing-pending work clearly.

No unrelated commerce path should return a generic failure because automatic routing is disabled.

### 40G.6 Independent subsystem switches

Provide controlled activation for major modules such as:

- Van App access;
- field customer visits;
- field order capture;
- Van warehouse pickup;
- Van route execution;
- automatic routing;
- manual routing;
- live Van tracking;
- live Driver tracking;
- Zone map overlays;
- collection at delivery;
- customer-level collection;
- partial collection;
- Driver wallet;
- Van wallet;
- remittance submission;
- remittance approval workflow;
- customer collection notifications;
- route deviation warnings;
- shift close;
- direct Van stock sales when introduced later.

Not every switch needs to be a single global boolean; some are policies/modes/scoped configuration.

### 40G.7 Feature dependency registry

Each configurable subsystem must declare dependencies explicitly.

Example:

```text
Van field order capture
  requires: Van authentication + customer scope + pricing/order API
  does not require: automatic routing
  does not require: live GPS

Van delivery
  requires: assignment/route + delivery lifecycle
  does not require: collection when collection policy says none

Collection
  requires: payment/collection service
  does not require: remittance availability

Remittance
  requires: custody ledger
  does not require: automatic routing
```

This dependency registry must be repository-visible and reflected in runtime validation.

### 40G.8 Fail-open vs fail-closed classification

Every feature/config dependency must declare its safe failure behavior.

Examples:

**Fail closed**
- financial posting;
- credit authorization;
- unauthorized customer access;
- duplicate collection;
- invalid currency;
- unsafe invoice mutation.

**Degrade safely / continue**
- automatic routing unavailable -> manual dispatch queue;
- route optimization unavailable -> configured/manual sequence;
- GPS unavailable -> delivery may continue if policy permits, with warning/audit;
- live map unavailable -> list/grid operations still function;
- push unavailable -> in-app/server state remains authoritative;
- Preview unavailable -> production application continues.

Workers must never invent this behavior ad hoc.

### 40G.9 Configuration registry

Use a coherent configuration/control-plane model rather than scattered constants.

Configuration must support:

- stable key/code;
- value/type/schema;
- scope;
- status;
- effective dates;
- default;
- validation;
- published revision;
- actor/time;
- reason/comment;
- audit diff.

Sensitive secrets remain in appropriate protected secret/config storage and are not treated as ordinary editable business settings.

### 40G.10 Draft, publish and rollback

High-impact operational settings must use:

```text
Draft -> Validate/Simulate -> Publish -> Active -> Retire/Rollback
```

Examples:

- routing mode;
- routing rules;
- territory geometry;
- Van eligibility;
- capacity policy;
- service schedule;
- collection policy.

Admin must be able to review changes before activation.

### 40G.11 Effective configuration preview

Dashboard should expose:

**Effective Configuration / الإعداد الفعلي**

for a selected:

- order;
- customer/address;
- territory;
- Van;
- date/shift.

It should explain inherited/overridden settings, for example:

```text
Routing mode: MANUAL
Source: Territory override
Platform default: AUTOMATIC
Effective from: 2026-10-10
```

This prevents hidden configuration surprises.

### 40G.12 Emergency kill switches

Provide permission-controlled emergency controls for operational incidents.

Examples:

- stop automatic routing;
- stop new Van assignments;
- stop field order capture;
- stop cash collection;
- stop remittance submission;
- stop background location publishing;
- disable one Van/user;
- disable one territory;
- disable one warehouse from new routing.

Emergency controls must:

- be audited;
- show clear reason/status;
- avoid deleting existing work;
- define how in-progress work is handled;
- support restoration.

### 40G.13 In-progress work protection

Changing configuration must distinguish:

- new/unlocked work;
- planned work;
- locked route;
- physically loaded inventory;
- out-for-delivery work;
- completed historical work.

A policy change normally affects future/unlocked work.

It must not silently reroute:

- a physically loaded Van;
- an already delivered order;
- historical financial events.

Changes to in-progress work require explicit operator action.

### 40G.14 No fixed business constants

Forbidden unless technically fundamental:

- fixed Egypt-only country logic;
- fixed governorate mappings;
- fixed Van IDs;
- fixed route IDs;
- fixed service weekdays;
- fixed collection limits;
- fixed remittance methods;
- fixed partial-payment thresholds;
- fixed capacity thresholds;
- fixed GPS stale intervals;
- fixed proof requirements;
- fixed automatic-routing precedence hidden in code.

Use stable codes and configurable records/policies.

Technical safety limits may exist in code when necessary to protect the system, but they must not encode changing business decisions.

### 40G.15 Manual override everywhere it is operationally safe

Where an automated result can safely be overridden, authorized admin/dispatcher should have an explicit override action.

Override requires:

- permission;
- reason;
- old value;
- new value;
- effective scope/time;
- audit;
- revalidation.

Financial/security invariants cannot be overridden by generic admin configuration.

### 40G.16 No-control-plane single point of failure

If the configuration UI is temporarily unavailable:

- last valid published configuration continues to operate;
- apps do not switch to arbitrary defaults;
- backend keeps authoritative cached/persisted effective config;
- configuration editing failure does not stop current deliveries/collections unless safety requires it.

### 40G.17 Configuration caching and invalidation

For scale, effective configuration may be cached.

Requirements:

- version/revision-aware cache keys;
- deterministic invalidation on publish;
- safe fallback to authoritative persisted config;
- no stale indefinite business policy;
- observability for current config revision.

### 40G.18 Permissions

Separate permissions should exist for high-impact control-plane actions, such as:

- view routing config;
- edit draft;
- publish routing config;
- change operating mode;
- emergency disable;
- override assignment;
- manage territory;
- manage collection policy;
- manage wallet/remittance policy.

A user who can view operations must not automatically be able to alter national routing.

### 40G.19 Audit and notifications

High-impact changes should create an operational event/notification, including:

- routing mode changed;
- territory disabled;
- automatic routing stopped;
- collection disabled;
- Van suspended;
- warehouse excluded;
- policy published/rolled back.

The Dashboard should make current non-default/emergency states visible.

### 40G.20 UI requirement

Control-plane pages must make editable behavior understandable.

Use:

- FOODEX identity;
- current status badge;
- Draft/Published indication;
- scope selector;
- effective-date controls;
- clear inherited/default value;
- `⋮` row actions;
- simulation/preview before publish;
- pagination for large configuration sets;
- change history.

Avoid giant forms where all national routing settings are mixed without scope/context.

### 40G.21 Acceptance scenarios

At minimum prove:

1. switch one Territory from Automatic to Manual without affecting other Territories;
2. create a Customer App order while auto-routing is disabled and receive an Awaiting Dispatch state;
3. manually assign that order and continue warehouse/delivery normally;
4. re-enable Automatic and route future eligible work automatically;
5. Hybrid mode auto-routes ordinary orders but sends configured exceptions to manual review;
6. disable live Van tracking without disabling Van order/delivery functions;
7. disable collection while still allowing prepaid/no-collection delivery;
8. disable remittance submission without corrupting existing custody balances;
9. suspend one Van without disabling the territory;
10. change a routing rule through Draft -> Simulation -> Publish;
11. rollback to a previous routing policy;
12. locked/loaded work is not silently changed by a new routing policy;
13. effective-config UI explains why a specific order is Manual/Automatic;
14. emergency switch action is audited;
15. last published configuration remains active if the Dashboard config editor is unavailable.

---

## 41. Proposed execution waves

This master plan should become an umbrella after owner approval.

Do not implement the whole platform in one branch.

### Wave 0 — Engineering acceleration and operational foundation
- configuration registry + modular feature-control foundation;
- manual/automatic/hybrid routing mode framework;
- scoped feature flags / kill switches / dependency registry;
- effective-configuration resolver and audit;
- shared Flutter packages;
- contract-first APIs / generated-model validation;
- canonical Dashboard management-grid component;
- Van application scaffold;
- feature scaffolds/templates;
- routing simulator;
- GPS/fleet simulator;
- financial fixtures;
- Egypt demo/pilot dataset;
- selective CI/affected-area matrix;
- train drift guard;
- worker Issue templates;
- architecture ownership map;
- device/session foundation;
- address-quality workflow;
- shift-close/proof/route-health foundations.

Wave 0 must land before broad parallel feature implementation so later workers consume one approved foundation instead of creating local variants.

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

1. Wave-0 configuration registry, feature controls, dependency/fallback framework.
2. Wave-0 shared mobile/design/API foundation.
3. Wave-0 CI/scaffolding/simulators/worker-template foundation.
4. Geography + Service Territory model.
5. Address-quality/unmapped-address operations.
6. Routing policy/version/simulation engine.
7. Territory Dashboard + map editor.
8. Van/vehicle + assignment management.
9. Route planning + capacity + exception engine.
10. Shared Collection/Custody/Remittance backend.
11. Driver App wallet + Collect & Deliver.
12. Van App foundation/auth/design system.
13. Van customer/visit/order capture.
14. Warehouse load manifest/pickup/return.
15. Van route/delivery.
16. Shift close + route return/reconciliation.
17. Van wallet/remittance integration.
18. Unified live fleet location backend.
19. Unified Driver + Van live map.
20. Device/session supportability + Runtime Inspector parity.
21. Customer App collection/receipt integration.
22. Customer 360/Order Support integration.
23. Finance collections/remittance/reconciliation Dashboard.
24. Reporting/aging/risk.
25. Pilot activation/readiness controls.
26. Integrated national E2E/security/UI/release gate.

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
21. Van/Driver shift close reconciles delivery, returns, collections and custody or produces an explicit unresolved exception.
22. Unmapped/low-quality customer addresses appear in an actionable admin queue rather than silently misrouting.
23. Runtime Inspector can trace Van failures safely without exposing sensitive location/customer data.
24. Device/session state is supportable and an authorized admin can revoke a lost/invalid field session.
25. Routing/GPS/financial simulators and deterministic fixtures reproduce core scenarios without physical field hardware.
26. Feature-train drift guard proves the final train contains the latest required production fixes.
27. Pilot activation can be limited to selected Zones/Vans without code changes.
28. FOODEX UI/UX, grid, pagination, Preview, release and handoff parity all pass on the final integrated head.
29. Routing can be switched Manual/Automatic/Hybrid at a scoped level without code deployment.
30. Disabling automatic routing does not block order creation; work enters an explicit manual-dispatch queue.
31. Independent feature shutdown does not break unrelated application functions and every dependency has a documented fallback.
32. Effective Configuration explains the active value, scope and source for a selected order/territory/Van.
33. Emergency kill switches are permissioned, audited and preserve in-progress/historical integrity.

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

## 45. Implementation defaults and configuration rule

Implementation must not block waiting for product decisions that can safely be represented as configuration.

Use these defaults unless an existing FOOD authority already defines a stricter contract:

- Van App remains standalone.
- Egypt is the initial configured market.
- Routing begins deterministic/rule-based with full manual dispatcher override; advanced optimization may be added later without replacing the routing contract.
- B2C partial collection is disabled by default unless a published policy enables it.
- Remittance methods come from configurable lookup/policy data.
- Live-location publish interval, stale threshold and retention are configuration-driven.
- Van capacity supports extensible dimensions; initial active dimensions are selected from configured operational data rather than fixed in code.
- Direct Van Stock Sales remains future-only until separately activated.
- Pilot activation is territory/Van controlled.
- Any missing value that materially affects financial/security integrity must fail closed or enter an exception queue rather than be guessed.

Workers should implement the configuration surface and safe default behavior rather than asking the owner to hardcode operating choices that the Dashboard can own.

Everything in this document is the proposed implementation baseline for the Van/Field Operations feature train.
