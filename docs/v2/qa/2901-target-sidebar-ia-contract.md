# V2 P381 — Target Sidebar information architecture contract

Issue: [#2901](https://github.com/walidatiyaai2025-gif/FOOD/issues/2901) · Master: [#2503](https://github.com/walidatiyaai2025-gif/FOOD/issues/2503) · Integration: `Foodex_V2` only.

## Source and architecture decision

- Source paragraph: native DOCX P368 / functional matrix key P381. The 177 row target inventory in `docs/v2/V2_SIDEBAR_ISSUE_MATRIX.json` is the **target**, not an assertion that every target destination already exists.
- Ten intended business domains in the source matrix: Overview; Customer & Sales; Orders & Fulfillment; Catalog & Pricing; Procurement & Inventory; Fleet & Van; Finance & Treasury; Reports & BI; Applications & Restaurant; Administration.
- **Reuse → Extend → Compose → New Screen:** reuse `config/admin_sidebar.php`, `AdminNavigation::groupsFor`, `AdminSidebarManager` and their current authenticated Dashboard surface. The target taxonomy is a cross-Issue migration/approval contract, not authority to introduce a new Sidebar, route or screen here.
- Each target row keeps a unique source ID, dedicated Issue, required business domain and source pointer; do not mark a row delivered just because it has an Issue or label.
- **Rendering depth:** existing three-level contract is business group → page → child page; never add a fourth level. Group order, item owner group and scope are explicit in the runtime registry. Role and layout presentation cannot mint route permissions.
- **Discoverability:** every projected leaf must resolve to an existing named route; the real controller/middleware must independently authorize the actor, store, channel, entitlements and operational readiness. A visible link must never be treated as a server authorization grant. Empty groups are suppressed.

## Verification delivered in this change

`backend/tests/Feature/V2TargetSidebarInformationArchitectureTest.php` exercises:

1. Source matrix integrity: exactly 177 uniquely identified target nodes, each with its own issue, matching URL, typed group/item, non-empty owner domain and source pointer; ten source business domains.
2. Current live Sidebar registry: all current item definitions belong to a configured business group with a stable order and explicit scope.
3. Actual role-projected Sidebar from the production `AdminNavigation` service for SUPER_ADMIN, B2B_ADMIN and CUSTOMER_SERVICE_AGENT in Arabic and English: no duplicate displayed keys, missing route or fourth level.
4. Direct HTTP authorization regression: a no-role user cannot turn registry keys into Catalog or ERP access.

These are automated **structural and security regression** checks. They do not claim that the 177 target leaves have been implemented or visually approved. The existing localization, HTTP/tenant, responsive Sidebar and module-readiness suites remain required.

## Capability and per-app impact

- **Dashboard:** no new destination, screen, role, feature toggle, permission, structural markup or shared navigation source change. Existing responsive Sidebar, screen-reader/focus and AR/RTL + EN/LTR renderer reused; routes are inspected rather than invented.
- **Backend/API:** no application source, schema, HTTP handler or API response change; the test invokes only preexisting read-side navigation/route admission.
- **Customer:** no Flutter code, API contract, login, pricing, notifications or deep link modified; no new app Issue warranted.
- **Driver:** no Flutter code, dispatch route, driver permissions, notification payload or API contract modified.
- **Van:** no Flutter code, visits/routes/assignments, API contract or B2B Van-only fulfillment modified.

## Remaining source acceptance (not inferred from a green CI)

The target 177-node tree and all eight role/persona projections must still be reconciled with leaf feature Issues and individually verified for actual end-to-end destination, permission/scope/module readiness, data, accessibility and responsive AR/EN workflows. The owner-approved final V2 program UX/UAT remains tracked by #2503 and #2750. This task does not approve unresolved target nodes, unblock unready ERP modules or authorize release. If a required acceptance check remains incomplete, keep #2901 open and record the missing evidence explicitly.

## CI and delivery fence

One issue-scoped test/doc branch and one PR against `Foodex_V2`. Require exact-final-head Repository Policy, V2 Isolation Gate, Required CI, Laravel tests, AR/EN and applicable installation/upgrade checks to pass. No push, merge, release, tag, migration or deployment to `main` or production.
