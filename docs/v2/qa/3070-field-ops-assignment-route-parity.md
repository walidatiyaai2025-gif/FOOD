# V2 #3070 — Field Operations Assignments Sidebar / HTTP authority

- Parent: #2503. Integration: **Foodex_V2 only**; no main merge, tag, release or production deployment.
- Source: #2715 discovered the P353/T353R6 Field Operations Assignments visibility/HTTP mismatch.
- Canonical controller: **FieldOperationsController::assignments()**, at **admin.field-operations.assignments**, requires global **drivers.b2b.view**; field_ops.manage is NOT sufficient.
- Canonical UI: existing field_operations Dashboard Sidebar group; no new route, visual primitive, layout or page.
- Placement: **Reuse** existing Field Operations workbench and Sidebar destination. Domain: Field Operations; entity: B2B Van Assignment; persona: authorized wholesale operations/dispatch; goal: review Van assignments; workflow: browse/list; frequency: operational; density: paginated assignments; navigation: SIDEBAR; entry point: existing Dashboard group; permission: drivers.b2b.view; back path: Field Operations Overview.
- Desktop, tablet and mobile-web: reuse shared Dashboard sidebar/drawer, no markup or visual changes; same server-authorized tree and focus/accessibility semantics. AR/EN reuse existing admin.field_operations.assignments, RTL/LTR existing layout.

## Reproduced mismatch and fix

Previously AdminNavigation::groupsFor and config/admin_sidebar.php allowed field_ops_assignments with EITHER drivers.b2b.view OR field_ops.manage. The actual controller required drivers.b2b.view; a pure Field Operations operator could see a link that failed 403. Both navigation and registry now require the exact controller permission.

A separate pre-existing admission constraint was confirmed: field_ops.manage **alone** does not grant Dashboard admission. We deliberately preserve that boundary; widening admin.dashboard_permissions could expose unrelated ERP routes because canAccessPlatformErp considers any global role. The positive Field Operations Overview regression actor therefore holds both field_ops.manage and already-admitted reports.view, while still lacking drivers.b2b.view. An actor with field_ops.manage alone remains forbidden at Dashboard middleware. No route admission or controller guard was widened.

Current Field Operations route audit:
- Overview: OR field_ops.manage, drivers.b2b.view, drivers.tracking.view, customers.view, territories.manage, finance.view.
- Fleet: drivers.tracking.view. Vans: drivers.b2b.view. Assignments: drivers.b2b.view (**fixed**).
- Customers/Visits: BOTH customers.view and drivers.b2b.view.
- Territories/Routing: EITHER territories.manage OR field_ops.manage.
- Finance: finance.view.

These match corresponding current controller guards. No evidence supports broadening unrelated routes.

## Authorization and scope

- Allowed: active global wholesale OPERATIONS actor with drivers.b2b.view; link points to an actual rendered Laravel route.
- Allowed: an active global custom actor with field_ops.manage plus independently authorized reports.view can browse Overview, but cannot see/call Assignments (HTTP 403).
- Denied: an actor with ONLY field_ops.manage cannot bypass existing Dashboard admission: both Overview and Assignments remain HTTP 403.
- Denied: B2C Retail RETAIL_OPERATIONS store-scoped actor has no B2B/Van assignments access, including when another Retail store exists; no silent global permission promotion.
- Controller, not presentation, remains security authority. AdminSidebarManager may hide/rearrange links but never grant them.
- No ERP readiness or module entitlement is invented for this non-ERP route; existing channel/role/store scope remains. No finance posting/SoD grant changed.
- No migration, data mutation, ledger impact, API schema or queue changes.

## Regression / acceptance

New backend/tests/Feature/V2FieldOperationsAssignmentsSidebarAuthorityTest.php proves:
1. Registry and actual route guard consistency.
2. Synthetic field_ops.manage + reports.view actor: Overview HTTP 200, Assignments absent/403, Arabic RTL and English LTR.
3. Pure field_ops.manage-only actor: no implicit Dashboard admission, both HTTP routes forbidden.
4. Authorized wholesale OPERATIONS actor: positive link and HTTP 200 in both locales.
5. Retail scoped actor: negative link and HTTP 403, including foreign-store permission boundary.

Full review requires Laravel feature suite, Pint, PHPStan, AR/EN localization, V2 isolation, repository policy, security and exact-final-head Required CI. Do not report CI green until GitHub runs finish. Existing page-specific viewport/accessibility evidence remains parent #2715/#2750 acceptance; no responsive markup changes in this PR.

## Cross-app impact matrix

| Surface | Impact |
| --- | --- |
| Laravel Backend | Narrower server-side navigation projection; existing Dashboard admission and assignments Controller guards unchanged. |
| Dashboard | Field Operations Sidebar / responsive drawer reflects real HTTP permission; no new page, raw ID, translated literal or UI component. |
| Customer Flutter | **Not affected**: no Customer API, payload, routing, session or screen changes. |
| Driver Flutter | **Not affected**: no B2C Driver API or app changes; no cross-channel dispatch fallback. |
| Van Flutter | **Not affected**: no B2B Van API, token, assignment lifecycle or app changes; B2B VAN ONLY remains. |

Concurrent integration: #2713 PR #3064, #2715 PR #3068 and #2510 PR #3063 contain distinct hunks in AdminNavigation.php. Their workers handed off next-write ownership; preserve those hunks and recheck exact latest base/PR mergeability before integration. Exactly one #3070 branch and PR.

## CI / merge evidence

Populate from GitHub exact-head runs after execution. No main or production work authorized.
