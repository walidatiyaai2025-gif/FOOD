# FOODEX V2 #2660 / P330 — Administration, Security and Settings

## Source and adaptive placement
Source: FOODEX Unified RBAC/ERP/Sidebar Discussion (2026-11-09), paragraph P330; parent #2503. Base/merge target Foodex_V2 exclusively.

- Domain: Administration and Security. Entities: existing Administration Hub, Sidebar Role Access/Layout, notifications and owner-only module inventory.
- Roles: Super Admin, Platform Owner, correctly delegated roles.manage actor; never infer rights from a visible group.
- Goal/workflow: reach an authorized administration destination; periodic governance work, low-density group navigation.
- Existing parent/route: current Administration group in AdminNavigation::groupsFor and existing admin.administration.index and admin.sidebar-access.roles routes.
- Placement type: **Reuse** existing shared Dashboard navigation/shell; no new Sidebar group, page, independent visibility authorization or alternate module registry.
- Navigation type: SIDEBAR group; owner: Administration; children are separately role/scope/route-authorized. The group header itself is not a navigable, privileged action.
- Fallback/back path: existing Administration Hub / Admin landing; no direct-URL-only feature introduced.
- Desktop/mobile/tablet: unchanged canonical responsive components with correct AR/RTL and EN/LTR rendering, focus and accessible navigation.

## Verification and data safety
New backend/tests/Feature/V2AdministrationGroupAuthorityTest.php verifies:
- Super Admin sees registered, nonduplicated canonical Administration child routes.
- Delegated roles.manage exposes legitimate Sidebar configuration, not Platform Owner module administration.
- Roleless and inactive actors cannot obtain the group; a roleless direct Sidebar Role Access HTTP request is forbidden.
- Existing Administration Hub renders AR/EN with matching directionality.

No runtime controller, RBAC role definition, policy, Blade, route, financial SoD, database, or other worker-owned file is modified. Existing entitlement/module gating remains with its authoritative backend. No screenshots or unperformed manual UAT are claimed.

## Customer / Driver / Van classification
Customer: no changed mobile API, storefront, account or authorization contract.
Driver: no B2C dispatch, wallet, session or route change.
Van: no B2B visit, route, assignment, stock or settlement change.
B2B VAN ONLY / B2C DRIVER ONLY is preserved. The patch consists strictly of a test and documentation for the existing Dashboard.

## Integration gate
The Issue/PR carries exact-head Policy, V2 Isolation, full Laravel + AR/EN + security + deployment recovery Required CI evidence and the actual merge/closure decision. Manual acceptance is DEFERRED_TO_V2_FINAL under #2503. This QA note does not pretend CI passed, that modules are certified, or that production was deployed. No changes to main, release tags or production.
