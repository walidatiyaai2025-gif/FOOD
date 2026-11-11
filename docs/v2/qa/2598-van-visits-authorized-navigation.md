# FOODEX V2 / #2598 — P268 Van Visits navigation and HTTP authority

## Source and placement
- Source: `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx`, P268; parent #2503.
- Domain: Fleet & Van / Field Operations. Entity: `VanVisit`. Persona: authorized Wholesale/Van operations actor. Goal: discover, review and manage scheduled visits. Workflow: recurring field operations; density: paginated operational grid.
- Existing canonical parent: **Field Operations**. Placement decision: **Reuse** the existing `field_ops_visits` Sidebar entry and `admin.field-operations.visits` GET route (`/admin/field-operations/visits`), rendered by `FieldOperationsController::visits` through the shared Dashboard page. Navigation type: `SIDEBAR`. No duplicate route, page, module, or Sidebar node was created.
- Responsive composition: existing FOODEX field-operations page and shared Dashboard mobile drawer/grid primitives for desktop, tablet, and mobile; locale-sensitive layout for Arabic RTL / English LTR. Exact real-device browser UAT remains deferred to the final V2 acceptance gate.

## Server authority and regression matrix
The existing `AdminNavigation::groupsFor` route item requires **both global** `drivers.b2b.view` and `customers.view`; the canonical GET controller calls `authorizeAll` with the same two permissions. The dedicated feature regression added to this issue verifies:
1. A role with both grants gets the real Sidebar entry and a successful GET in AR/EN with RTL/LTR layout and an actual localized empty-state.
2. A driver-only role, customer-only role, or role with neither grant has neither entry nor HTTP access.
3. Separate roles combining the two grants resolve normally, while an explicit global user-level DENY of `customers.view` removes both entry and GET access without rewriting shared role grants.
4. A Retail store-scoped operations role cannot cross to the Wholesale/Van Visits route; roleless and inactive identities also cannot enter.
5. Read access does **not** permit POST visit creation without `drivers.b2b.manage`.
6. The route and its navigation key are registered; no placeholder/dead-link acceptance.

## Contract boundaries and limits
- Existing visits page reads the canonical Van visits, related customers, orders, assignments and territory keys. This PR **does not change** record queries, database tables, workflow state, API endpoints, grants, module flags or operational readiness.
- `Field Operations` is a canonical operational surface, not a newly enabled ERP module; enterprise entitlement/readiness must never be silently treated as a permission grant. Positive tests establish route/permission parity, **not** proof of any missing module enablement requirement.
- The existing page/controller also populate both B2B and B2C customer lookup data. Before declaring full P268 acceptance, separately verify real record-level tenant/channel/store exposure and Van-only Wholesale / Driver-only Retail boundaries; do not infer data isolation from Sidebar visibility alone. Any verified defect needs an ownership-safe implementation and scoped regression, not a cosmetic test change.
- The current Blade implements existing lookup and localized states. Full screen accessibility/responsive verification and cross-account data-isolation acceptance are **not claimed** by static inspection alone.

## Customer, Driver and Van applicability
- **Customer Flutter**: no source or API payload mutation; customer authorization and order flows unchanged by this test/documentation PR.
- **Driver Flutter**: no source or API payload mutation; B2C Driver-only fulfillment remains the governing contract; no new Driver-facing route.
- **Van Flutter**: no source or API mutation; existing Van visits lifecycle and B2B Van-only fulfillment continue to be server-authoritative; this PR validates only Dashboard entry/read security. Any discovered actual Van mobile impact must be implemented under an issue-scoped V2 task and tested before accepting its relevant source requirement.

## Delivery and evidence
- Branch: `test/2598-v2-van-visits-authorized-navigation`, derived from `Foodex_V2`. Files owned by this issue alone: `backend/tests/Feature/V2VanVisitsAuthorizedSidebarTest.php`, this document.
- Required final-head Repository Policy, V2 Isolation Gate, Backend Laravel tests, localization, installer/upgrade acceptance and Required CI Gate: **must be checked on the final PR head; do not treat this document as a passing result**.
- Do not merge or close #2598 while required CI or source/record-scope acceptance is failing or unknown.
- `MANUAL_ACCEPTANCE: DEFERRED_TO_V2_FINAL` per #2503, not a waiver of automated acceptance.
- Forbidden: merge into `main`, production deployment, tag/release, or editing files leased to #3070/#2715/#2510 and other active V2 workers.

## P268 record-level channel and store isolation hardening (2026-11-11)

Prior P268 checks proved Sidebar/HTTP permission parity but did not exercise persisted mixed-channel visit rows. The actual Van Visits Dashboard route previously paginated all `van_visits`, exposed both B2B/B2C customer lookup options, all stores/order options and accepted B2C Van visit creation. Under locked V2 delivery, B2B is **VAN ONLY** and B2C is **DRIVER ONLY**; the Van visit management surface cannot expose or mutate Retail/Driver records.

Scoped repair on the **same original PR #3087**:
- `FieldOperationsController::visits` reads `customer_type=b2b` only and restricts non-platform operators to their existing authorized B2B store IDs. Wholesale platform operators retain platform-level B2B visibility. Customer selectors contain B2B values only for platform-wide operators; without an authoritative per-store B2B customer mapping, a scoped operator is not shown the platform-wide customer directory.
- Visit-store selector uses active B2B store type and authorized store IDs, orders are B2B-only with authorized stores, visit-route metadata comes only from visible B2B visits; unscoped Van fleet route metadata is visible only to platform-wide operators.
- `storeVisit` rejects store-scoped writers with HTTP 403 until an authoritative B2B customer-to-store ownership relation exists; only platform-authorized Wholesale roles may create, and they may submit `customer_type=b2b` only. Explicit unowned/retail `store_id` is rejected. `transitionVisit` denies legacy B2C visit records, unauthorized B2B stores, unscoped visits for non-platform roles, and B2C order references independently of menu visibility.
- The Van Visits Blade no longer offers the B2C creation choice; server validation is authoritative.
- Two new real Laravel HTTP fixtures in `V2VanVisitsAuthorizedSidebarTest`: persisted B2B own/foreign store visit records and B2C customer/visit rows; scoped AR/EN visible-vs-hidden output, B2C create rejection without mutation, B2C/foreign transition 404 without mutation, permitted own-store B2B transition.
- Existing roleless/partial permission, explicit user DENY, inactive user, Retail-only and read-only mutation assertions remain intact. No migrations, seed defaults, permission grants, production state or Van/Driver/Customer Flutter/API response contracts changed. Customer, Driver, Van apps remain unchanged as this patch affects the Admin Dashboard only; the B2B=Van/B2C=Driver fulfillment contract is reinforced rather than modified.

**Remaining acceptance gate:** run complete exact-final-head Repository Policy, FOODEX V2 Isolation, Laravel/security/AR-EN and real MySQL/Redis fresh-install/upgrade/recovery checks after the branch is reconciled to the latest `Foodex_V2`; inspect failures and original source expectations. Verify real wholesale customer ownership mapping and accessibility if the store-restricted create form needs B2B account selection. Do not claim complete P268 source or merge solely from static reasoning or partial CI. `MANUAL_ACCEPTANCE: DEFERRED_TO_V2_FINAL` under #2503 only for manual UAT, not actual security/functionality.
