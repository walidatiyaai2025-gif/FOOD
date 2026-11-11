# FOODEX V2 #2758 / P013 — Effective route decision audit

Source: P013, FOODEX Unified RBAC/ERP/Sidebar Discussion (2026-11-09). Parent: #2503. Integration target: `Foodex_V2` only.

## Current authoritative path and placement

- Domain: authorization, ERP; role: authorized global operator vs roleless/inactive/delegated multi-role actor.
- Entry point: existing `admin.erp.index` and ERP module destinations; **Reuse** current Sidebar, existing ERP Center and HTTP endpoints. No new UI route or independent navigation authorization.
- `EnsureManagementDashboardAccess`: validates user session/active status and `AdminNavigation::canUseDashboard()`; ERP namespace admission also calls `canAccessPlatformErp()`.
- `AdminNavigation::groupsFor()`: projects ERP Center for an active global role, but separately requires effective permission, module readiness and entitlement for operational ERP module links.
- `EnsureErpOperationalReadiness`: fail-closed readiness for named operational ERP module routes (procurement, WMS, finance, treasury, BI). It is not a replacement for controller/store/channel/SoD authorization.
- `TenantContextResolver`: B2B global-role boundary; B2C explicit assigned store and Super Admin support entry.
- No boolean `ERP hub visible` implies operational finance, treasury or inventory capability. Layered policy remains enforced by HTTP service/controller authorization.

## Added executable regression (bounded phase)

`backend/tests/Feature/V2RouteAuthorizationDecisionParityTest.php` tests:
1. Roleless users have no ERP group and get HTTP 403 on ERP hub.
2. An inactive global role cannot resurrect ERP navigation or direct ERP hub authorization.
3. Two unrelated global permissions (reports.view + roles.manage) may reach existing ERP Center navigation but cannot surface finance, treasury or WMS operational module links or Platform Owner modules; direct Finance ERP route returns HTTP 403.

The tests use a seeded isolated Laravel test DB, and do not modify runtime authorization policy, roles, user credentials or production data. This is **not certification of P013 completion**.

## Remaining P013 acceptance before merge/closure

- Enumerate the complete canonical named ERP/other business route matrix: actor, action, permission, effective allow/deny priority, company/tenant/store/channel, entitlement, readiness and SoD.
- Reconcile multi-role, explicit DENY, global versus store grants, cross-store B2C and wholesale B2B, Platform Owner support scope, disabled modules, maker/checker and direct service/API denial with current tests.
- Prove menu-versus-direct-HTTP parity for all relevant AR/EN and RTL/LTR Dashboard surfaces; retain responsive/a11y acceptance.
- Only after verified route decision matrices may a shared server-side decision abstraction replace duplication. Do not weaken existing guards or create a second authorization engine.
- Customer: no runtime/API change in this phase. Driver: no dispatch or wallet runtime change. Van: no routes, visits or fulfillment change. If the eventual decision abstraction changes any shared API/mobile contract, link dedicated V2 app issues and cross-app tests.
- Final exact-head Required CI, security, PHP syntax/Pint/PHPStan, localization, MySQL/Redis install+upgrade+recovery and non-conflicting active ownership must be verified on the eventual release-ready code. Existing test and QA alone do not prove installer package acceptance.
- Manual/staging/UAT is deferred under owner instruction; **code-level acceptance is not waived**. Issue stays open and PR stays draft pending complete implementation/CI.
- Never retarget to `main`, release tags, production, or deploy.

## Ownership fence

This PR owns only the two additive files `backend/tests/Feature/V2RouteAuthorizationDecisionParityTest.php` and `docs/v2/qa/2758-route-authorization-parity.md`. Existing middleware, AdminNavigation, policy, routes, manifests and app code are unchanged to avoid race with concurrent workers.
