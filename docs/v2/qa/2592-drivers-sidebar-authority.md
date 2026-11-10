# V2 #2592 / P262 — Drivers Sidebar authorization evidence

- Source: `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx`, paragraph P262, parent #2503.
- Integration: `Foodex_V2` **only**. No `main`, release, tag, artifact publishing or production deployment.
- Ownership: issue #2592, branch `test/2592-v2-drivers-sidebar-authority`, one issue-scoped PR.
- Placement: **Reuse**, not a new screen or additional Sidebar node. Domain: Operations; entity: Drivers; persona: Wholesale operator or Retail store manager; user goal: discover authorized drivers and manage an existing store's fleet; workflow: frequently used operational list. Existing parent: authorized Operations Sidebar. Navigation type: `SIDEBAR` to existing workspace. Pattern: current shared Dashboard module grid.

## Canonical route / action / scope

| Dimension | B2C Retail | B2B Wholesale |
| --- | --- | --- |
| Sidebar child key | `b2c_drivers` | `b2b_drivers` |
| Entry / active context | Operations → Drivers; `b2c_drivers` | Operations → Drivers; `b2b_drivers` |
| Route | `admin.b2c.module?module=drivers&store_id=<authorized>` | `admin.b2b.module?module=drivers` |
| Controller | `B2cWorkspaceController::show` | `B2bWorkspaceController::show` |
| Exact read permission | `drivers.b2c.view` on selected Retail store | `drivers.b2b.view` on authorized global Wholesale role |
| Write actions | Existing store/driver actions separately require `drivers.b2c.manage` | Existing B2B management checks; **not** permission to dispatch a B2B order to a Driver |
| Tenant / channel | `TenantContextResolver` selects assigned active Retail store, rejects cross-store; `RETAIL_STORE` profile | B2B global channel authorization and Wholesale scope only |
| Readiness | Existing core workspace, not an ERP optional module; permission and supported business profile gate the route | Existing core workspace; no new ERP entitlement/feature flag introduced |
| UI languages | Existing Arabic/RTL and English/LTR Dashboard shell | Same canonical shell |
| Back path | Operations navigation or authorized Dashboard context | Operations navigation or authorized Dashboard context |

Presentation-level visibility never grants route access. `AdminNavigation::groupsFor` already constructs the permission-scoped B2C Drivers child, but the Retail `$retailB2cKeys` display filter accidentally removed it. P262 restores **only** `b2c_drivers` to that canonical whitelist, after verifying actual scoped HTTP 200; this is discoverability correction, not a new RBAC grant. `B2cWorkspaceController::authorizeModule` / `B2bWorkspaceController::authorizeModule` still re-check server permissions. The B2C controller also checks store scope and business profile. No new alias, alternate renderer, API or control-plane permission is introduced.

## Automated regression

`backend/tests/Feature/V2DriversSidebarAuthorityTest.php` covers:

1. Retail Store A navigation resolves the exact allowed store, its real page returns HTTP 200 and renders only Store A driver names; Store B driver names do not leak. Both `ar`/RTL and `en`/LTR are covered.
2. Retail Store A direct route to Store B is rejected; Retail cannot open Wholesale drivers, and a global Wholesale operator cannot open Retail drivers. Wholesale sidebar targets the pre-existing authorized route.
3. A mobile Driver account does not receive the management Sidebar destination or direct dashboard access. Restaurant-profile Retail workspaces reject the unsupported Drivers module.

Supporting existing contracts: `AdminNavigationAuthorizationAuditTest`, `B2cTenantWorkspaceIsolationTest`, `B2bWholesaleWorkspaceIsolationTest`, `AdminBusinessManagementTest`, and `docs/execution/DASHBOARD_NAVIGATION_DISCOVERABILITY_AUDIT.md`.

Only the existing Dashboard `AdminNavigation` Retail whitelist gains its already scoped `b2c_drivers` child; no Dashboard Blade templates, Sidebar infrastructure architecture, authentication, API schemas or mobile runtime files are changed. Existing shared mobile-web off-canvas navigation, keyboard/focus semantics, responsive breakpoints (320–1280+) and AR/EN components are reused without modification. Real-device/browser layout and accessibility UAT remain **DEFERRED_TO_V2_FINAL** under the owner's #2503 manual acceptance decision; they are **not** claimed as performed.

## Cross-surface impact classification

| Application | Classification | Reason |
| --- | --- | --- |
| Customer Flutter | NOT AFFECTED | No Customer route, product data, API payload, role, deep link, or notification contract changed |
| Driver Flutter | NOT AFFECTED | Management Dashboard read visibility only; Driver authentication, assignment, delivery APIs and app navigation unchanged |
| Van Flutter | NOT AFFECTED | No Van app, live dispatch, route, inventory or assignment behavior touched |

The standing invariant remains **B2B → VAN ONLY; B2C → DRIVER ONLY**. This scoped regression does not change dispatch and introduces no new mobile contract requiring a separate mobile issue.

## Merge / acceptance evidence

Do not close #2592 until the feature regression, Laravel suite, Pint/PHPStan/security, AR/EN, V2 Isolation Gate, Repository Policy and terminal Required CI Gate (including required MySQL/Redis clean install/update/recovery children) are **successful at the final exact PR HEAD**, the PR still bases `Foodex_V2`, and the branch has no overlapping active worker-owned files. Log actual run IDs, SHAs and merge evidence on the issue. Do not substitute unexecuted checks or an in-progress workflow for success.

`MANUAL_ACCEPTANCE: DEFERRED_TO_V2_FINAL` applies only to final staging/browser/device audit, never to required automated CI.
