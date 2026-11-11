# V2 #2595 / Sidebar P265 — Van operational movement log

Source: FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx (P265 Fleet & Van). Parent #2503. **Target Foodex_V2 only**; no release, deployment or main promotion.

## Canonical placement and honest data provenance

- Domain: Fleet & Van / Field Operations; entity: Van's Wholesale delivery execution; role: authorized `drivers.b2b.view` viewer with permitted B2B stores; user goal: inspect what operational delivery transitions actually occurred.
- Chosen **Reuse → Extend**, not a new Sidebar or route: Field Operations → Vans → exact Van record `admin.field-operations.vans.show`. Navigation type **CONTEXT_ACTION** from existing Vans list; active domain `field_ops_vans`, same admin shell. Existing fallback/back navigation to Vans index.
- Read model: immutable `order_van_execution_events` joined to canonical `orders` (B2B ONLY), ordered by captured timestamp/id descending. Bounded to most recent **50** rows. Previous/current statuses are translated through canonical AR/EN `order_operations.business_labels`, with localized unknown fallback. A customer-specific B2C Driver event must never be presented as a Van B2B record.
- **No invented GPS tracking:** `fleet_current_locations` retains the *latest* heartbeat per actor and is not a historical route. This feature explicitly labels itself an operational delivery event log, not a map route, speed history, mileage/distance report, or GPS breadcrumb archive. The movement log adds no new telemetry collection, persistent logs, table or API contract.
- Server authority: existing `drivers.b2b.view` HTTP authorization + `b2bStoreIds` scope from `FieldOperationsController`. Both immutable movement rows and existing Van detail B2B order-assignment rows are filtered by **orders.store_id in authorized active Wholesale stores** and `orders.channel = b2b`, before presentation. A Viewer cannot supply a forged store filter to bypass SQL scope.
- UI: existing Van detail responsive `fieldops-card`, focus-order-preserving data table in `table-wrap`, 4 localized table headers, stable labelled section, explicit empty state, no mutations and no raw technical statuses. Works in existing desktop/tablet/mobile-web and AR RTL / EN LTR. No new Admin Hub/Sidebar entry.

## Acceptance

Dedicated `backend/tests/Feature/V2VanMovementLogTest.php` covers:

1. Visible authorized Van movement section, canonical translated statuses in Arabic and English, RTL/LTR document direction and **max 50 rows** from 53 persisted events, no stock/order/dispatch mutation during GET.
2. Two Wholesale stores with same Van: a restricted B2B viewer sees only own-store delivery execution and own B2B order list; other-store order reference hidden.
3. No `drivers.b2b.view` role → HTTP 403. Empty store-safe movement log → localized nontechnical empty state.
4. Existing store and channel scoping remains enforced for related order cards; movements are derived from authoritative event tables only.

Exact-head Repository Policy, V2 Isolation, Laravel backend tests, localization AR/EN and real MySQL/Redis installation/upgrade/recovery, plus terminal `required-ci-gate`, must **all succeed before any squash merge**. Record actual jobs and links on Issue/PR. Do not close #2595 unless P265 navigation/role/tenant/functional acceptance is verified. Existing GDPR/retention/personal-data permissions for any future GPS *history* are separate design and implementation work; do not imply they exist.

## Mobile app impact

- **Customer:** no storefront, order tracking, delivery notification or API changes.
- **Driver:** no B2C-only delivery assignment, proof, wallet or GPS transport changes.
- **Van:** no B2B-only dispatch or mobile order transition changes; dashboard reads already-recorded events, without altering payloads/offline behavior.

No Flutter changes required for an existing server-authoritative, read-only Admin detail view. The business-flow lock B2B → VAN and B2C → DRIVER is unchanged.
