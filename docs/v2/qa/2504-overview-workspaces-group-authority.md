# FOODEX V2 #2504 / P174 — Overview and Workspaces

## Source, placement and navigation
Source: FOODEX Unified RBAC/ERP/Sidebar Discussion (2026-11-09), paragraph P174; parent #2503. Integration is ONLY Foodex_V2.

- Domain: Overview. Entity: authorized workspace destination. Role: platform administrators and channel-authorized operators.
- User goal: reach an operational workspace from a visible, authorized navigation group.
- Workflow: frequent navigation. Data density: low. Existing parent: FOODEX shared Dashboard sidebar and shell.
- Placement decision: **Reuse** current AdminNavigation::groupsFor and AdminSidebarManager. No new route, screen, widget, or duplicate permission model.
- Navigation owner: Overview; type: SIDEBAR group; entry points: existing Enterprise Workspaces, Action Center and channel dashboard children as permitted. Header is not itself a Route/action grant. Normal Dashboard landing/deep link is the back path.
- Permissions: canonical child Route guards plus channel/tenant/store and module/readiness checks, evaluated server-side. Display-only preferences cannot confer access.
- Desktop/tablet/mobile: retain shared responsive Sidebar and accessible FOODEX components. AR uses RTL, EN uses LTR.

## Source-level tests and negative evidence
New backend/tests/Feature/V2OverviewWorkspacesGroupAuthorityTest.php tests:
- Registered unique child links with valid real Laravel route names for authorized Super Admin.
- No owner-only module inventory mixed into Overview group.
- No empty unauthorized group for roleless actors; no navigation for inactive actors.
- A roleless direct request to Enterprise Workspaces is rejected by the canonical HTTP boundary.
- Both supported locale/direction pairs render through the existing Dashboard.

No production files, routes, permissions, migrations, UI components, or master data are changed. The tests do not assert a successful end-user manual browser viewport audit.

## Cross-app impact
Customer: no API payload, login, storefront, pricing, or mobile route changes.
Driver: no B2C dispatch, assignment, location, or session changes.
Van: no B2B visits, territory, routing, or collection changes.
The B2B VAN ONLY / B2C DRIVER ONLY invariant is not modified. No mobile code changes or unrelated child issues are necessary for these additive tests/docs.

## Acceptance tracking
See the single Issue #2504 and its PR for exact tested SHA, CI runs, findings, and merge result. Manual staging/browser/device UAT remains DEFERRED_TO_V2_FINAL under #2503. No green CI or merge is asserted by this document. No main, release, or production action is authorized.
