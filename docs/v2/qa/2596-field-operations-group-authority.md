# V2 P266 — Field Operations group authorization (Issue #2596)

Source: `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx`, P266. Parent #2503. Target: `Foodex_V2` only.

## Placement and scope

**Reuse** the existing `field_operations` business group in `AdminNavigation::groupsFor` and existing server-protected Field Operations workspace; do **not** add a second Sidebar, route, module or permission layer. The source P259/#2589 owns the broader nine-route registry evidence; #2596 focuses specifically on *empty-group removal*, *multi-role authorized-child projection*, and *explicit override DENY* of all relevant grants. No shared `AdminNavigation.php` or `FieldOperationsController.php` modifications are made.

- Domain: Fleet & Field Operations. Entity: operational Van/visit/routing records.
- Actors: wholesale roles with effective read/manage grants; no roleless access.
- Goal/workflow: discover permitted business pages from the Field Operations group; normal workbench tabs remain the navigation destination, not `DIRECT_URL_ONLY`.
- Navigation: existing `SIDEBAR` group `field_operations` with `PAGE_TAB`/canonical route children. A group with zero effective children is hidden, not a dead clickable link.
- Layout: unchanged shared responsive Sidebar with AR/EN RTL/LTR, summary keyboard navigation and mobile menu; this PR introduces no new UI.
- Authorization: the same `User::hasPermission` decisions drive existing `routeItemAny`/`routeItemAll` projection and `FieldOperationsController::authorizeAny`/`authorizeAll`. Explicit global DENY overrides inherited role grants. HTTP 403 is verified; no new entitlement bypass.
- Module: this existing operations group is permission-gated, not an ERP module route; module/readiness behavior is unchanged, not falsely asserted as a new enterprise entitlement.
- Data boundaries: existing B2B Van routes and scoped visit read models; no new direct access to foreign store, Retail Driver or customer data. Existing P268/#2598 tests own the cross-store/Van visit lifecycle boundary.

## Automated coverage

`backend/tests/Feature/V2FieldOperationsSidebarGroupAuthorityTest.php`:

1. A Dashboard-capable reports-only actor has no Field Operations group and direct Field Operations routes return 403; registry label is localized AR/EN.
2. Multi-role union of `drivers.b2b.view` and `customers.view` exposes exactly the authorized five links; denied fleet/routing/finance direct access remains 403, AR/EN & RTL/LTR behavior is checked over the actual HTTP page.
3. Explicit per-user global DENY of Van and tracking read removes their links and the entire group, despite previously valid global role grants; direct routes return 403.

Pre-merge gate: full exact-head required Laravel tests, Pint, localization, Repository Policy, V2 Isolation, security and setup/upgrade CI. This file documents the coverage plan, not a claim that CI passed before GitHub Actions completes.

## Customer / Driver / Van impact

- **Customer**: no mobile navigation/API/payload/identity/pricing change — not affected.
- **Driver**: no B2C dispatch, status, location or push contract change — not affected.
- **Van**: no B2B visit, assignment, routing or API contract change — not affected.
- No Flutter or backend runtime changes; no per-app issue generated for this test/documentation-only delta. B2B VAN ONLY / B2C DRIVER ONLY preserved.

Manual staging/browser/device acceptance is deferred to final V2 gate per owner #2503; do not interpret deferred runtime UAT as automated CI success. Do not change `main`, tags, releases or production.
