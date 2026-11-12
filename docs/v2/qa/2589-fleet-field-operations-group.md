# P259 — Fleet & Field Operations canonical group (V2 #2589)

Source: `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx`, P259; parent #2503. Owner-locked integration: **Foodex_V2 only**, no `main`, release, APK publication, or production deployment.

## Placement & navigation decision

- **Domain:** Fleet & Van / Field Operations. **Entity:** existing fleet and operational workspaces (not a new persisted model). **Role:** management dashboard actor with an *effective* backend permission for the destination; no group-level privilege.
- **User goal/frequency:** operations staff discover fleet, Van assignments, visits, territories, routing, collection finance as part of regular supervised B2B work. Platform owners inspect all permitted operations.
- **Placement:** **REUSE** existing `field_operations` top-level business-domain group and existing `AdminNavigation::groupsFor` / `AdminSidebarManager::apply` / `backend/resources/views/admin/_sidebar.blade.php`; do **not** create a second Fleet Sidebar, new root navigation, or direct-URL-only page.
- **Navigation type:** SIDEBAR business group with child routes. Child route/permission checks remain server-side on canonical `FieldOperationsController` actions. The group is visible iff at least one child survives canonical authorization, configured visibility and route-existence filtering.
- **Registry / route contract:** nine routes `field_ops_overview`, `field_ops_fleet`, `field_ops_vans`, `field_ops_assignments`, `field_ops_customers`, `field_ops_visits`, `field_ops_territories`, `field_ops_routing`, `field_ops_finance`. Each belongs to the one `field_operations` group. `routeItemAny` and `routeItemAll` mirror the direct controller permission checks; no independently managed visibility can grant access.
- **Localization:** existing `admin.nav_groups.field_operations` = “الأسطول والعمليات الميدانية” (AR) / “Fleet & Field Operations” (EN), as already approved in merged #2725. Nested labels use existing AR/EN catalogs and root `dir=rtl` or `ltr`. Do not rename it independently or duplicate the P266 “العمليات الميدانية” group.
- **Responsive/accessibility:** existing shared sidebar has semantic `<details><summary>` groups, keyboard `:focus-visible`, small-screen CSS `@media(max-width:440px)`, search and accessible nav controls. No new CSS/markup. Real device/viewport and screen-reader acceptance belongs to final cross-page UX gate #2750; a source assertion is not a screenshot/UAT assertion.
- **Scope:** group is presentation, not new authorization. Only routes with effective access may appear, and direct URL must still enforce its independent backend role/channel/store boundary. User-defined Sidebar hide flags suppress links but never grant a protected action. Any disabled/missing routes are removed by canonical navigation; ERP module readiness must be enforced by destination modules, not guessed at this parent group.
- **Fields:** no business form changes. Existing child screens keep authoritative master-data lookups and typed builders.

## Automated acceptance and security

Dedicated `backend/tests/Feature/V2FleetFieldOperationsGroupAuthorityTest.php` verifies:

1. The one P259 registry group contains precisely nine registered business child destinations and every route exists.
2. A Super Admin sees the one group and all authorized children; AR/EN page HTML has correct direction, localized group title, canonical link and sidebar shell.
3. A non-Super Admin with two separate least-privilege roles sees **only** the effective union of allowed children; denied direct Fleet/Vans/Finance/Visits HTTP endpoints return 403.
4. Inactive, roleless and Retail-only actors cannot acquire Wholesale Territories/Routing/Van links through visibility alone or bypass direct HTTP 403. A Retail actor's legitimate other channel access, if any, remains outside this denial.
5. Existing sidebar `summary` / focus / responsive / accessibility metadata is retained. No new UI primitive or route.
6. The already merged child acceptance slices #2725 (navigation remap), #2601 (Routing HTTP), #2599 (Territories integrity) and #2600 (Routing policy store scope) are reused; this issue does not duplicate or override their implementations.

## Customer / Driver / Van impact

| Surface | Impact | Reason |
| --- | --- | --- |
| Backend | **Affected: automated auth certification** | No new controller/API path, role grant, or data mutation |
| Dashboard | **Affected: existing navigation acceptance** | Same canonical group, AR/EN and responsive shell; new functional regression only |
| Customer | **Not affected** | No storefront, customer session, API routes or Flutter change |
| Driver | **Not affected** | No Retail delivery/dispatch API or Flutter change; B2C is Driver-only |
| Van | **Not affected** | Existing Van actions/routes unchanged; B2B is Van-only; no Flutter/API contract change |

## Required merge evidence

Before integration require exact-head success for Repository Policy, V2 Isolation, FOODEX Required CI terminal gate, Laravel full suite (including P259), PHP/Pint/PHPStan, AR/EN, OpenAPI, security and MySQL/Redis install-upgrade/recovery where scheduled. The disabled-module and error cases of individual child pages are owned by their respective issue, not by this group. Full manual desktop/tablet/mobile and accessibility UAT is `MANUAL_ACCEPTANCE: DEFERRED_TO_V2_FINAL` under #2503/#2750 — do not claim it occurred.

This is a previously implemented UI group with **test-based certification**, not a fresh UI feature. No duplicate route or misleading group-specific permissions were added.
