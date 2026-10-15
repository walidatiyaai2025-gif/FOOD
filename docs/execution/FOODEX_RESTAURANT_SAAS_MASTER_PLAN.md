# FOODEX Restaurant SaaS 2.0 — Master Plan

Status: **Architecture / execution authority**  
Parent program: **#1572**  
Blueprint issue: **#1573**  
Baseline: current `main` at blueprint start = `eb8cc8a3e0b5734a3b56a34823d1458123e440ca`  
Predecessor: **#1445 FOODEX Smart Ordering / Restaurant Growth Platform**  
Required UX baseline: **#1560 Menu media, pre-publish review & simplified QR setup**

---

## 1. Executive objective

FOODEX must become a **modular Restaurant SaaS operating platform**, not only a QR menu, online-ordering feature, or internal ERP extension.

A restaurant should be able to:

1. sign up or be provisioned;
2. create its organization, brand and locations;
3. choose a plan and enable only the modules it needs;
4. import/build its menu and brand experience;
5. launch a first-party website/menu/QR ordering experience;
6. run dine-in, pickup, delivery and scheduled ordering;
7. manage tables, reservations/waitlist, kitchen flow and operational capacity;
8. connect external delivery/POS/payment/marketing systems without duplicate data entry;
9. own customer relationships through CRM, loyalty, reviews and lifecycle marketing;
10. understand sales, menu performance, labor, food cost and branch performance;
11. procure/reorder supplies using FOODEX intelligence where applicable;
12. add locations and users without redesigning the system;
13. upgrade/downgrade modules without losing data or breaking unrelated capabilities;
14. export its data and leave the service through a controlled offboarding process.

The system must support an independent restaurant, a multi-location operator, a franchise group and a platform-managed enterprise customer from the same product architecture.

---

## 2. What already exists and MUST be reused

This program is a successor layer, not a rewrite.

### 2.1 Smart Ordering baseline

The closed #1445 program already establishes these reusable foundations:

- tenant-controlled Smart Ordering runtime;
- subdomain/site identity;
- feature entitlements and safe rollout states;
- draft/published site configuration;
- restaurant menu domain;
- categories, menu items, modifiers, extras, variants and availability;
- five storefront templates;
- live preview;
- cart and deterministic checkout;
- dine-in / pickup / delivery ordering;
- dynamic QR and attribution;
- WhatsApp CRM bridge;
- restaurant customer CRM / loyalty / reviews baseline;
- offers, coupons, combos and upsell/cross-sell baseline;
- analytics and funnel attribution;
- FOODEX supply-mapping / estimated-consumption loop;
- security/performance/integration certification.

#1560 additionally establishes the required baseline for:

- menu/category/item media;
- pre-publish review;
- simplified QR presets;
- business-readable QR setup.

**Rule:** Restaurant SaaS 2.0 extends these domains. It does not create `MenuV2`, `OrdersV2`, a second CRM, a second entitlement engine, or a replacement QR engine unless a migration ADR explicitly proves the existing domain cannot satisfy the contract.

### 2.2 Enterprise Core baseline

The Enterprise program under #1487 is the shared platform authority for:

- module registry;
- entitlements;
- data-scope aware authorization;
- events/outbox;
- workflow;
- module health.

Restaurant SaaS modules must consume those capabilities as they become authoritative. Restaurant-specific code must not recreate generic registry, event, health or workflow primitives.

### 2.3 Performance authority

#1488 and `docs/quality/CPANEL_PERFORMANCE_CONTRACT.md` remain authoritative for performance release gating.

Restaurant SaaS cannot declare a feature production-ready merely because functional tests pass. Each module must prove its cPanel-safe query, memory, queue and page/runtime behavior.

---

## 3. Product model and tenancy hierarchy

FOODEX should expose a business hierarchy while preserving current authoritative tenant/store/customer records.

Target logical hierarchy:

~~~text
FOODEX Platform
└── SaaS Account / Organization
    ├── Brand A
    │   ├── Location 1
    │   ├── Location 2
    │   └── Location N
    └── Brand B
        └── Location(s)
~~~

This is a **logical control-plane model**, not permission to duplicate existing tenant truth.

### 3.1 Mapping rule

Before adding a new persistent identity table, implementation must map the concept against existing FOODEX:

- tenant/customer/account identity;
- store/location;
- branch;
- Smart Ordering restaurant site;
- B2B/B2C boundaries;
- user and role scope.

If an existing authoritative entity already represents the concept, extend/map it rather than creating a competing source of truth.

### 3.2 Scope levels

Every setting/capability must declare one or more explicit scope levels:

- PLATFORM
- ORGANIZATION
- BRAND
- LOCATION
- CHANNEL
- USER

Inheritance must be deterministic. Recommended precedence:

`LOCATION override > BRAND policy > ORGANIZATION default > PLAN default > PLATFORM default`

Some controls can be locked by a parent scope. Example: franchise HQ may lock brand identity and menu sections while allowing location-specific sold-out state.

### 3.3 Franchise requirements

The model must support:

- HQ-controlled menu templates;
- location price/availability overrides;
- locked vs overridable fields;
- central campaigns with local eligibility;
- cross-location guest recognition where policy permits;
- consolidated reporting;
- local manager permissions;
- franchise audit trails;
- brand-wide publish with staged/canary rollout.

---

## 4. SaaS commercial control plane

A restaurant SaaS product is incomplete without a commercial lifecycle.

### 4.1 Account lifecycle

Required lifecycle:

`LEAD -> TRIAL -> ACTIVE -> PAST_DUE -> GRACE -> SUSPENDED -> CANCELED -> OFFBOARDED`

Manual/internal accounts may use separate administrative states, but the same data-retention and entitlement rules apply.

### 4.2 Plans and entitlements

Plans must resolve to entitlements and limits; code must never contain plan-name conditionals such as `if plan == pro`.

Example plan families may be:

- Starter
- Growth
- Pro
- Enterprise

The commercial names may change without changing module code.

Each plan can define:

- enabled modules;
- maximum active locations;
- maximum staff seats where appropriate;
- custom-domain availability;
- advanced analytics;
- integration/API access;
- retention tier;
- support tier;
- optional usage allowances.

### 4.3 Subscription capabilities

Required:

- trial start/end;
- plan selection;
- upgrade/downgrade;
- plan effective dates;
- add-ons;
- location count changes;
- recurring billing abstraction;
- invoice/receipt history;
- billing contact;
- payment-method token/reference only;
- payment failure/dunning;
- grace period;
- cancellation at term or immediate by policy;
- renewal state;
- manual/admin comp plans with audit;
- tax fields/configuration without assuming one country;
- webhook-driven provider state reconciliation;
- billing audit ledger.

### 4.4 Safe suspension

Billing state must never delete business data.

A suspension policy must define per module whether the tenant gets:

- READ_ONLY
- DISABLED
- LIMITED
- FULL

Example: a past-due restaurant may keep access to invoices/data export and a read-only dashboard while new premium actions are disabled after grace. Public ordering behavior must be an explicit business policy, not an accidental exception.

### 4.5 Metering

Usage metering must be separate from analytics and business truth.

Potential meters:

- active locations;
- active staff seats;
- completed online orders;
- outbound campaign messages;
- integration connectors;
- storage/media usage.

Meters must be idempotent, tenant-scoped, auditable and recomputable where possible.

---

## 5. Self-service onboarding and migration

### 5.1 Guided onboarding

A new restaurant should complete a checklist, not contact engineering for routine setup:

1. owner/account verification;
2. organization and brand;
3. first location;
4. business hours/timezone/currency;
5. logo/cover/brand theme;
6. menu import/build;
7. fulfillment modes;
8. payment setup;
9. WhatsApp/contact channels;
10. QR/table setup;
11. staff invitations/roles;
12. launch preview;
13. go-live checks.

The checklist must be resumable and expose completion state.

### 5.2 Data import

Support safe import paths:

- CSV/XLSX menu import;
- structured template upload;
- existing FOODEX catalog linking;
- connector import from supported POS/menu sources;
- controlled migration service for enterprise accounts.

PDF/image extraction can be an optional assisted workflow, but must require human review before publish. Generated/inferred data must never silently become authoritative.

### 5.3 Launch readiness

Each location gets a launch-readiness score/status driven by real gates:

- identity complete;
- opening hours;
- menu published;
- required prices available;
- fulfillment configured;
- payment readiness if online payment enabled;
- QR created where required;
- language completeness;
- legal/contact information;
- delivery rules if enabled;
- at least one authorized operator;
- observability/integration health.

No "Launch" button may bypass failed hard gates.

---

## 6. Module catalog

Every module below must register:

- module key;
- dependencies;
- entitlement key;
- scope;
- health signals;
- permissions;
- events;
- disable behavior;
- migrations/data ownership;
- UI navigation contribution;
- API/webhook contribution;
- performance budget/gate.

### 6.1 Core SaaS modules

1. `restaurant_control_plane`
2. `restaurant_onboarding`
3. `restaurant_billing`
4. `restaurant_multi_location`
5. `restaurant_operator_console`

### 6.2 Experience and commerce

6. `public_menu`
7. `menu_studio`
8. `restaurant_site`
9. `online_ordering`
10. `dine_in_ordering`
11. `pickup`
12. `delivery`
13. `scheduled_orders`
14. `payments`
15. `dynamic_qr`

### 6.3 Front-of-house operations

16. `floor_plan`
17. `reservations`
18. `waitlist`
19. `table_service`
20. `guest_book`

### 6.4 Back-of-house operations

21. `kitchen_display`
22. `kitchen_routing`
23. `capacity_throttling`
24. `inventory`
25. `recipes_food_cost`
26. `purchasing`
27. `waste_stocktake`

### 6.5 Guest growth

28. `restaurant_crm`
29. `loyalty`
30. `gift_cards`
31. `offers`
32. `lifecycle_marketing`
33. `reviews_reputation`
34. `advanced_merchandising`

### 6.6 People and enterprise

35. `restaurant_staff`
36. `shift_scheduling`
37. `time_clock`
38. `franchise_controls`
39. `advanced_analytics`

### 6.7 Ecosystem

40. `delivery_marketplace_connectors`
41. `pos_connectors`
42. `public_api`
43. `webhooks`
44. `integration_marketplace`
45. `custom_domain`
46. `foodex_supply_intelligence`

Not every module must ship in the first commercial release. All must have an explicit state in the gap matrix.

---

## 7. Menu Studio 2.0

The menu experience is the highest-visibility Restaurant SaaS surface and must feel like a premium product.

### 7.1 Builder layout

Target interaction:

- left: menu/category/item tree;
- center: real renderer preview;
- right: context-sensitive properties;
- top: draft state, device/language/location/channel selectors, preview and publish;
- bottom or page tabs: Content / Design / Availability / Ordering / Channels / QR / Publish history.

### 7.2 Content capabilities

- multiple menus;
- categories;
- items;
- variants/sizes;
- modifier groups;
- nested modifier rules only where pricing logic supports them safely;
- extras/add-ons;
- combos/bundles;
- removable ingredients;
- calories/nutrition;
- allergen and dietary tags;
- prep-time hints;
- featured/bestseller/new;
- branch/location applicability;
- channel applicability;
- dine-in/pickup/delivery applicability;
- daypart schedules;
- sold-out and temporary snooze;
- price source and override visibility.

### 7.3 Media library

Central restaurant media library:

- logo;
- restaurant cover;
- menu cover;
- category cover;
- item hero;
- item gallery;
- campaign media.

Required behaviors:

- MIME/size validation;
- tenant isolation;
- replace/remove;
- crop and focal point metadata;
- responsive derivatives;
- lazy loading;
- placeholders;
- compression;
- orphan cleanup policy;
- asset provenance;
- CDN-ready storage abstraction without requiring CDN in the first deployment.

### 7.4 Publish model

Required states:

`DRAFT -> VALIDATED -> SCHEDULED(optional) -> PUBLISHED -> ARCHIVED`

Publish must be atomic by revision.

Capabilities:

- preview unpublished revision with authorized token;
- validation before publish;
- localized completeness warnings;
- scheduled publish;
- rollback to prior revision;
- compare draft vs published;
- audit who published and why;
- cache invalidation keyed by revision;
- no partially published tree.

### 7.5 Channel publishing

The canonical menu must be able to project to:

- FOODEX public site;
- QR;
- first-party ordering;
- future delivery marketplace connectors;
- POS connector where supported.

Channel-specific constraints belong in adapters, not in the canonical menu model.

---

## 8. Restaurant website and public guest experience

Each tenant can expose a mobile-first branded public experience.

### 8.1 Required surface

- branded home/hero;
- menus;
- category navigation;
- search/filter;
- item details;
- offers;
- branch selector;
- hours/open state;
- fulfillment selection;
- cart/checkout;
- order tracking;
- contact/about;
- policy/legal pages;
- feedback/reviews entry where enabled.

### 8.2 Design system

Retain five high-quality template families, but evolve them through a constrained token system:

- approved typography sets;
- brand colors;
- corner/radius density;
- card style;
- category navigation style;
- hero/section selection;
- light/dark where template supports it.

No unrestricted HTML/CSS page builder in the initial SaaS product.

### 8.3 SEO/discovery

Public pages should support:

- custom domain when entitled;
- canonical URLs;
- metadata;
- OpenGraph;
- sitemap;
- robots controls;
- structured restaurant/menu data where technically valid;
- stable item/menu URLs;
- redirect policy on slug changes;
- analytics consent controls.

### 8.4 Accessibility

Target WCAG-aligned behavior:

- semantic controls;
- keyboard navigation where applicable;
- focus states;
- sufficient contrast;
- reduced-motion behavior;
- accessible modal/sheet focus management;
- meaningful alt text strategy.

Accessibility is a gate, not polish.

---

## 9. Ordering, checkout and payments

Keep FOODEX Order as canonical business truth.

### 9.1 Supported modes

- dine-in;
- pickup;
- delivery;
- scheduled/pre-order.

Future modes such as curbside/drive-thru can be represented by fulfillment policy rather than separate order engines.

### 9.2 Checkout

- guest checkout;
- optional verified customer identity;
- authoritative server pricing;
- tax/service/delivery fee rules;
- coupons/offers;
- tips where market/business policy supports them;
- order notes;
- item/modifier revalidation;
- idempotency;
- duplicate-submit protection;
- clear failure recovery.

### 9.3 Payment abstraction

FOODEX must use a provider-adapter boundary:

- authorize;
- capture;
- void;
- refund;
- partial refund;
- payment status reconciliation;
- webhook verification;
- token/reference storage only;
- provider correlation ID;
- settlement/reconciliation export.

Raw card data must remain outside FOODEX wherever hosted/tokenized provider flows can minimize PCI scope.

### 9.4 Reconciliation

Restaurant finance views need:

- order total;
- payment status;
- refund state;
- fee/commission fields when known;
- settlement state;
- provider mismatch detection;
- downloadable/exportable reconciliation.

---

## 10. Tables, reservations and guest flow

This is a major gap between ordering software and full restaurant operations.

### 10.1 Floor plan

- location floor/area;
- table number/name;
- seats/capacity;
- combine/split metadata;
- active/inactive;
- QR binding;
- visual floor-plan editor;
- occupancy state.

### 10.2 Reservations

- date/time;
- party size;
- table/area preference;
- guest profile;
- notes/preferences;
- source;
- status lifecycle;
- confirmation/reminder hooks;
- cancellation;
- optional deposit policy;
- no-show tracking;
- pacing/turn-time limits.

### 10.3 Waitlist

- quoted wait;
- party size;
- SMS/WhatsApp notification hooks;
- seated/cancelled/no-show;
- convert waitlist to reservation/table.

### 10.4 Guest profile

Reservation and order identity should contribute to one restaurant-scoped guest profile subject to consent and identity rules.

Do not merge identities across restaurant tenants simply because contact details match.

---

## 11. Kitchen and fulfillment operations

### 11.1 Kitchen Display System (KDS)

Required domain concepts:

- kitchen station;
- routing rules by item/category/course;
- ticket;
- item preparation status;
- bump/recall;
- timers;
- course/fire behavior;
- priority;
- void/cancel propagation;
- ready state;
- audit trail.

### 11.2 Capacity control

Restaurant can configure:

- max concurrent orders;
- preparation lead time;
- channel throttling;
- temporary ordering pause;
- per-channel or global busy mode.

The storefront must present a safe customer message and updated promise time rather than accepting impossible demand.

### 11.3 Offline/resilience boundary

Browser/cloud KDS can be resilient to temporary connectivity loss using local cache/outbox only where correctness can be proven.

True offline POS/order capture is a separate edge capability and must not be falsely claimed until an edge synchronization design is implemented and certified.

---

## 12. Delivery and omnichannel integration hub

FOODEX needs an adapter-first connector platform.

### 12.1 Connector contract

Every connector should implement a subset of:

- menu push/pull;
- price sync;
- availability/snooze;
- opening hours;
- order ingest;
- order accept/reject;
- status updates;
- cancellation;
- courier/dispatch status;
- payment metadata;
- reconciliation metadata.

### 12.2 Reliability contract

- signed/authenticated callbacks;
- idempotency;
- dedupe;
- retries/backoff;
- dead-letter handling;
- per-connector health;
- last successful sync;
- manual retry;
- correlation IDs;
- tenant-safe logs;
- no silent data loss.

### 12.3 Channel truth

Canonical menu/order truth stays in FOODEX where FOODEX owns the transaction. External-channel orders retain source provenance and external references.

A sync ledger must make divergence visible.

---

## 13. CRM, loyalty, marketing and reputation

Build on #1451/#1452/#1453/#1454 rather than creating new guest databases.

### 13.1 Unified restaurant guest profile

Tenant-scoped profile can aggregate:

- orders;
- reservations;
- visits;
- loyalty;
- feedback;
- campaigns;
- consent;
- preferences;
- service-recovery history.

### 13.2 Segmentation

Deterministic segments:

- new;
- repeat;
- VIP;
- lapsed;
- high AOV;
- frequent;
- category/product affinity;
- branch affinity;
- birthday/anniversary only when voluntarily collected and lawfully usable.

### 13.3 Loyalty

- earn rules;
- reward catalog;
- redemption;
- immutable ledger;
- expiry policy;
- branch scope;
- adjustments with audit;
- race-safe redemption.

### 13.4 Gift cards / stored value

Separate module with:

- issuance;
- redemption;
- balance ledger;
- expiry policy by market;
- fraud controls;
- refunds/void rules;
- multi-location acceptance policy.

Do not implement gift cards as discount coupons.

### 13.5 Lifecycle automation

Event-triggered workflows such as:

- welcome;
- first-order follow-up;
- abandoned checkout where privacy policy permits;
- lapsed customer;
- birthday;
- low-rating service recovery;
- loyalty milestone;
- reservation reminder/no-show recovery.

Channel consent and provider rules remain authoritative.

---

## 14. Workforce

Restaurant SaaS should support daily people operations without immediately becoming a country-specific payroll engine.

### 14.1 Native scope

- employee/staff directory;
- location assignment;
- role/permission assignment;
- shift schedule;
- availability;
- time clock;
- break records;
- manager notes;
- audit of schedule/time edits.

### 14.2 Adapter-first scope

Payroll, tax withholding and statutory labor calculations should initially integrate through export/adapters unless a market-specific payroll project is separately approved.

This avoids coupling the core restaurant product to one jurisdiction.

---

## 15. Inventory, recipes, food cost and purchasing

Expand the existing supply intelligence into operational back-office capability.

### 15.1 Inventory

- ingredient/SKU;
- unit of measure;
- unit conversion;
- location stock;
- receiving;
- transfers;
- stocktake;
- adjustment reason;
- wastage;
- low-stock thresholds;
- lot/expiry hooks where required.

### 15.2 Recipes

A menu item/variant can map to recipe components:

- quantity;
- unit;
- yield;
- waste factor;
- effective date/version.

Completed orders can create **theoretical consumption**.

Actual inventory remains based on stock transactions/stocktakes.

### 15.3 Food cost

Expose:

- recipe cost;
- theoretical food-cost percentage;
- price/margin simulation;
- variance between theoretical and actual consumption where data exists.

### 15.4 Purchasing

- supplier;
- purchase order;
- approval;
- receiving;
- invoice reference;
- reorder suggestion;
- FOODEX supply reorder link where applicable.

Never auto-purchase solely from inferred consumption without explicit approval.

---

## 16. Analytics and restaurant finance

### 16.1 Operator dashboard

Real-time/near-real-time operational KPIs:

- sales;
- orders;
- AOV;
- fulfillment mix;
- current kitchen load;
- open reservations/waitlist;
- top/sold-out items;
- channel health.

### 16.2 Business analytics

- menu conversion;
- item/category performance;
- upsell attach rate;
- repeat rate;
- cohort retention;
- campaign ROI;
- loyalty impact;
- branch comparison;
- daypart;
- cancellation/refund;
- delivery source;
- marketplace vs first-party mix;
- food cost where inventory/recipe enabled;
- labor metrics where workforce enabled.

### 16.3 Multi-location

HQ can filter/compare by:

- organization;
- brand;
- region/group;
- location;
- channel;
- date/time.

Metrics need documented formulas and timezone policy.

### 16.4 Data architecture

Do not run unbounded raw event scans on dashboard load.

Use incremental aggregation/read models appropriate to cPanel/MySQL constraints and measure query budgets under #1488.

---

## 17. Public API, webhooks and integration marketplace

### 17.1 API

Entitled API access should use:

- scoped credentials/OAuth-style app authorization where supported;
- tenant/location scopes;
- rate limits;
- versioning;
- idempotency keys for retriable writes;
- stable public IDs;
- documented errors.

### 17.2 Webhooks

- versioned event schema;
- per-endpoint secret/signature;
- retries;
- replay protection;
- delivery logs;
- disable noisy/broken endpoints;
- manual replay;
- payload PII minimization.

### 17.3 Marketplace

An integration catalog should declare:

- provider;
- capabilities;
- markets;
- required secrets;
- scopes;
- health;
- enable/disable;
- configuration ownership;
- support contact/version.

An integration must be uninstallable without corrupting canonical FOODEX data.

---

## 18. SaaS operator console and support

FOODEX platform operators need a dedicated control plane that is not mixed into restaurant day-to-day pages.

Capabilities:

- organization/brand/location search;
- subscription/billing state;
- effective entitlements;
- runtime state;
- integration health;
- recent errors;
- queue/webhook health;
- publish revision;
- storage/usage summary;
- audit timeline;
- data export/offboarding status;
- support case references;
- safe support session / break-glass workflow with explicit permission and audit;
- emergency stop by module/tenant.

Support tooling must never rely on sharing customer passwords or bypassing audit.

---

## 19. Security, privacy and compliance baseline

Every Restaurant SaaS module must satisfy:

### 19.1 Tenant and authorization

- server-side organization/brand/location scope;
- RBAC plus data-scope restrictions;
- deny-by-default policy;
- cross-tenant enumeration tests;
- safe public identifiers.

### 19.2 Authentication

- MFA-ready admin authentication;
- session/device visibility where platform supports it;
- enterprise SSO hooks for eligible plans;
- secure reset/invite lifecycle.

### 19.3 Secrets and integrations

- encrypted secret storage;
- rotation support;
- no provider secrets in page source/logs;
- verified webhooks;
- SSRF-safe remote fetch/import;
- upload validation.

### 19.4 Privacy

- explicit consent/source tracking for marketing;
- retention policy hooks;
- export;
- delete/anonymize policy where legally/contractually applicable;
- data-processing purpose classification;
- PII minimization in analytics/logs.

### 19.5 Payment scope

Design for PCI scope minimization through hosted/tokenized providers. FOODEX must not claim PCI, SOC 2 or other certification unless independently attained and documented.

### 19.6 Audit

Immutable/auditable records for:

- role/permission changes;
- plan/entitlement changes;
- publish/rollback;
- price/discount changes;
- refunds;
- loyalty/gift-card adjustments;
- inventory adjustments;
- support/break-glass access.

---

## 20. Reliability, backup and disaster recovery

Required platform behavior:

- database backup policy;
- restore drills;
- media backup strategy;
- queue retry/dead-letter;
- webhook replay;
- integration health;
- release rollback;
- revision rollback;
- migration expand/contract discipline;
- tenant-safe error reporting.

Define SLOs for:

- public menu availability;
- checkout;
- order creation;
- admin;
- critical webhook processing.

SLO numbers must be measured against real deployment capability before becoming contractual SLA promises.

---

## 21. Performance architecture

Restaurant SaaS public traffic can be much higher than back-office traffic. The architecture must preserve cheap reads.

### 21.1 Published read model

Public site/menu should resolve from a revision-keyed read model:

`tenant + location + menu_revision + locale + channel`

Goals:

- avoid rebuilding menu trees per request;
- no N+1 queries;
- safe cache invalidation on publish;
- stale-while-revalidate only where business correctness allows;
- immutable revision payloads where practical.

### 21.2 Media

- responsive derivatives;
- lazy loading;
- explicit dimensions;
- optimized formats supported by deployment;
- no full-size admin originals shipped to menu cards.

### 21.3 Search

Large menus:

- indexed/searchable fields;
- bounded page size;
- no loading every item into admin selector;
- debounce client search;
- measured query plan.

### 21.4 Gate

Every new module that adds high-growth list/search/analytics/public traffic must add a benchmark/query budget to the existing performance harness rather than creating a new benchmark system.

---

## 22. Localization, currency, timezone and market readiness

Arabic/English parity is mandatory.

Every restaurant/localized entity must define:

- translatable fields;
- fallback policy;
- completeness status;
- RTL/LTR behavior.

Market configuration must support:

- timezone per location;
- currency;
- decimal/minor-unit policy;
- tax/service-charge configuration;
- date/time format;
- phone/address rules.

No module should hardcode Kuwait, US or another single-market assumption into business logic.

---

## 23. Module independence and disablement contract

A module is not modular merely because it has a toggle.

For each module, turning it OFF must prove:

1. unrelated routes still load;
2. unrelated jobs still run;
3. unrelated events are not blocked;
4. navigation contribution disappears safely;
5. API returns a defined unavailable response;
6. stored data is preserved;
7. no destructive migration occurs;
8. dependent modules either disable automatically or declare a clear degraded mode;
9. health status explains dependency failure;
10. combined regression remains green.

Dependency cycles are prohibited.

---

## 24. Release gates

No Restaurant SaaS module may enter `main` until its applicable gates pass.

### Gate A — Domain
- one canonical owner of business truth;
- dependencies declared;
- no duplicate engine;
- migration compatible.

### Gate B — Tenancy/RBAC
- org/brand/location scope;
- negative authorization tests;
- audit.

### Gate C — Product UX
- normal navigation;
- no raw IDs/JSON;
- AR/EN;
- RTL/LTR;
- responsive;
- loaded/empty/error/disabled states;
- accessibility.

### Gate D — Performance
- query budget;
- large-data fixture;
- public/cache behavior where relevant;
- queue/job budget;
- cPanel contract.

### Gate E — Security/Privacy
- secrets;
- rate limit;
- validation;
- webhook/file security;
- consent/PII rules.

### Gate F — Reliability
- idempotency;
- retry/reconciliation;
- observability;
- backup/rollback behavior.

### Gate G — Module independence
- OFF-state regression;
- dependency failure;
- unrelated-module regression.

### Gate H — Exact-head repository acceptance
- Required CI;
- Repository Policy;
- applicable visual evidence;
- applicable release/store readiness;
- combined-tree regression.

---

## 25. Execution waves

The waves are dependency order, not a mandate to start all work at once.

### Wave 0 — Control plane and SaaS lifecycle

- SaaS organization/brand/location mapping;
- plans/subscriptions/trials;
- billing/dunning;
- self-service onboarding;
- operator console foundation;
- module entitlement bridge to Enterprise Core.

Exit: a restaurant can be provisioned and commercially entitled without manual DB work.

### Wave 1 — Menu Studio and digital presence

- Menu Studio 2.0 UX;
- media library;
- publish history/rollback/scheduled publish;
- website/SEO/custom-domain hardening;
- import/export.

Exit: restaurant can create, preview, publish and maintain a premium digital presence.

### Wave 2 — Commerce and finance hardening

- checkout/payment adapters;
- split/partial/refund semantics where required;
- reconciliation;
- order capacity/throttling;
- operational order console.

Exit: first-party commerce is financially reconcilable and operationally safe.

### Wave 3 — Front and back of house

Parallel lanes after shared contracts:
- floor plan/tables;
- reservations/waitlist;
- KDS/kitchen routing/capacity;
- staff directory/scheduling/time clock.

Exit: FOODEX supports day-to-day restaurant service, not only online sales.

### Wave 4 — Inventory and supply

- inventory transactions;
- recipes/theoretical usage;
- stocktake/waste;
- food cost;
- purchasing;
- FOODEX supply integration.

Exit: restaurants can connect sales to cost and replenishment.

### Wave 5 — Omnichannel ecosystem

- connector framework;
- delivery marketplaces;
- POS adapters;
- signed webhooks;
- public API;
- integration marketplace/catalog.

Exit: FOODEX becomes the orchestration layer rather than another isolated tablet.

### Wave 6 — Growth and enterprise scale

- unified guest profile;
- gift cards;
- lifecycle automation;
- franchise controls;
- advanced multi-location analytics;
- enterprise SSO hooks;
- data export/offboarding.

Exit: multi-location brands can operate centrally and grow guests across channels.

### Wave 7 — Independent certification / GA

- full E2E matrix;
- large-tenant performance;
- backup/restore evidence;
- security review;
- billing lifecycle scenarios;
- suspension/offboarding scenarios;
- accessibility/localization;
- pilot/canary;
- final combined regression.

---

## 26. Recommended atomic execution lanes

Create atomic Issues only when prerequisites are ready.

1. SaaS Account / Organization / Brand / Location control plane
2. Plans, Subscriptions, Billing, Metering & Dunning
3. Self-Service Onboarding, Import & Launch Readiness
4. Menu Studio 2.0 + Media + Revision History
5. Restaurant Site / SEO / Custom Domain hardening
6. Payments, Refunds & Reconciliation
7. Floor Plan, Tables & Table Service
8. Reservations, Waitlist & Guest Book
9. KDS, Kitchen Routing & Capacity
10. Restaurant Staff, Shifts & Time Clock
11. Inventory, Recipe Costing, Waste & Stocktake
12. Purchasing & FOODEX Supply Reorder
13. Delivery/POS Connector Framework
14. Public API, Webhooks & Integration Catalog
15. CRM/Loyalty/Gift Cards/Lifecycle Marketing convergence
16. Franchise / Multi-location Controls & Analytics
17. SaaS Operator Console, Support, Data Lifecycle & Observability
18. Restaurant SaaS Integration Captain / Security / Performance / Pilot / GA

Shared files/contracts get one owner. Parallel lanes must be file/scope disjoint per `AGENTS.md`.

---

## 27. Migration strategy from current Smart Ordering

No big-bang rewrite.

### Step 1 — Inventory existing contracts
Map current SmartOrdering models/services/routes/tables to this plan.

### Step 2 — Add control-plane mappings
Introduce account/brand/location commercial relationships using references to existing authoritative tenant/store/site objects.

### Step 3 — Entitlement convergence
Map Smart Ordering feature flags to Enterprise Core module entitlements with compatibility adapter during migration.

### Step 4 — Incremental UI upgrades
Upgrade the existing menu/admin routes in-place. Do not create hidden replacement screens.

### Step 5 — Add net-new modules
Reservations/KDS/inventory/etc. enter behind disabled-by-default entitlements.

### Step 6 — Pilot
Enable on internal/test restaurants first, then selected pilot locations.

### Step 7 — Deprecate compatibility paths
Only after telemetry and regression prove no consumers remain.

---

## 28. Data portability and offboarding

A credible SaaS product must make exit safe.

Required:

- export restaurant profile/config;
- menu/catalog export;
- customer export subject to privacy policy;
- orders/report export;
- reservation export where enabled;
- loyalty/gift-card ledger export where contract permits;
- media download/reference manifest;
- billing invoices;
- integration disconnect;
- credential/token revocation;
- retention timer;
- deletion/anonymization workflow per policy;
- final audit record.

Cancellation must never silently destroy records required for finance/audit.

---

## 29. Product metrics

Track platform and restaurant success separately.

### SaaS business metrics

- activation rate;
- onboarding completion;
- time to first publish;
- time to first order;
- trial conversion;
- paid retention/churn;
- expansion by added location/module;
- payment failure recovery;
- support volume per active tenant.

### Restaurant outcome metrics

- menu view -> add-to-cart;
- checkout conversion;
- first-party order share;
- repeat order rate;
- AOV;
- upsell attach rate;
- loyalty participation;
- reservation no-show rate where enabled;
- order prep time;
- cancellation/refund;
- inventory variance;
- food cost trend.

Metrics must not be used as substitutes for accounting truth.

---

## 30. Explicit non-goals / sequencing protections

The architecture leaves room for these, but they should not block early SaaS commercialization:

- building proprietary payment hardware;
- building country-specific payroll before market demand is proven;
- claiming full offline POS before edge synchronization exists;
- unrestricted website/page-builder code injection;
- generic accounting ERP duplication where integrations suffice;
- AI autonomy that can change prices, purchase stock or message customers without explicit policy/approval.

---

## 31. Definition of complete Restaurant SaaS

FOODEX Restaurant SaaS 2.0 is complete when a clean tenant can:

- self-onboard without engineer intervention;
- select a plan and receive correct entitlements;
- run multiple brands/locations safely;
- build and publish a premium bilingual menu/site;
- process dine-in/pickup/delivery orders;
- accept/reconcile supported payments;
- manage tables/reservations/waitlist when enabled;
- operate kitchen flow through KDS when enabled;
- manage staff/shifts when enabled;
- track inventory/recipes/purchasing when enabled;
- connect external channels through resilient adapters;
- run CRM/loyalty/growth tools;
- see trustworthy location/enterprise analytics;
- disable any optional module without breaking the rest of the platform;
- survive provider failures without losing canonical orders/data;
- export its data and be safely offboarded;
- pass tenancy, security, privacy, performance, localization, accessibility, backup/restore and exact-head release gates.

That is the bar for calling FOODEX a **Restaurant SaaS platform**, rather than a collection of restaurant features.
