# V2 #2675 — P345 Module Registry authorized navigation / acceptance

**Source:** FOODEX Unified RBAC/ERP/Sidebar discussion (2026-11-09), P345 "سجل الموديولات", Administration domain. **Master:** #2503. **Owner-locked integration:** `Foodex_V2` only.

## Placement and implementation

- **Reuse → Extend → Compose → New Screen:** REUSE. The canonical module registry/control plane already exists. No competing registry, route, Sidebar visibility authority, database migration, or new business workflow is introduced.
- **Domain:** Administration / Settings / enterprise modules. **Entity:** registered enterprise module manifest and lifecycle state. **Actor:** authenticated global Super Admin / permission holder of `platform.manage`, subject to existing authorization. **User goal:** inspect real registry, module dependencies, state, entitlements and operational readiness; transition lifecycle using audited server actions.
- **Navigation:** `ADMIN_SETTINGS`. Open `/admin/settings` (Settings Center) → "Module Control Plane" / "إدارة وحدات المنصة" → `admin.enterprise-modules.index`; open a registered module via `admin.enterprise-modules.show`. Do not create a duplicate Sidebar entry for a Settings capability. An unauthorized role sees a restricted Settings tile rather than a navigable control.
- **Server authority:** `EnterpriseModuleAdministrationController::platformAdmin` calls `Gate::forUser($actor)->authorize('platform.manage')` for index, show and POST lifecycle; unknown registry code is 404; invalid actions are validated. Rendering the registry is **not** authorization to operate an unready ERP module. Operational readiness/entitlements remain separate backend decisions. No direct URL-only bypass.
- **Scope:** platform module control plane; no selection of arbitrary tenant/store. No store-scoped role may acquire platform authority merely by receiving a Sidebar item. The lifecycle service remains the authoritative audit/state machine, not a new role grant.
- **UI:** Reuse `admin.enterprise-modules` with canonical Settings Center navigation, responsive admin mobile-web shell, semantic headings, Arabic/English translations and RTL/LTR. No new CSS / layout component / user-facing language key.

## Automated contract coverage

`backend/tests/Feature/V2ModuleRegistrySidebarAuthorizedContractTest.php` covers:
1. Super Admin discovery via the actual Settings Center tile and reachable registry index/details in AR/EN, RTL/LTR, including mobile-web shell.
2. A FINANCE role, B2B_ADMIN role and roleless authenticated actor receive server-side HTTP 403 for index, details and lifecycle POST. Denied requests do not create module registrations or audit entries.
3. Unknown manifest code returns 404, invalid lifecycle action is rejected, and neither changes registrations nor audit records.
4. An inspectable module may remain operationally **uncertified**; registry visibility never claims operational readiness.

Existing `EnterpriseModuleAdministrationTest` separately covers valid audited lifecycle transitions, server-filtered state counts, bounded queries, and localized details. Existing enterprise module authority and entitlement suites cover permission/readiness on business routes. No production instance, live browser or mobile-device UAT is claimed by automated source tests.

## Customer / Driver / Van impact classification

| Surface | Impact | Evidence / boundary |
| --- | --- | --- |
| Laravel Backend + Dashboard | **Affected: test contract only** | Existing protected web routes, module registry, Settings entry and canonical UI reused; dedicated authenticated HTTP and negative tests added, no new API/DB changes |
| Customer Flutter | **Not affected** | No customer API, account/role/session, price, order, notification or app route change |
| Driver Flutter | **Not affected** | No dispatch, B2C Driver scope, wallet, session, location, notifications or app API change |
| Van Flutter | **Not affected** | No B2B Van visits/routing/sales, session, offline queue or app API change |

The source-only test/doc delta leaves B2B=VAN ONLY, B2C=DRIVER ONLY, tenant isolation and production data untouched. No V2 mobile implementation child is warranted because no app-facing contract changed.

## Exit gates / evidence

- Issue: #2675; branch: `test/2675-v2-module-registry-authorized-navigation`; exactly one PR with base `Foodex_V2`.
- Local command when environment available: `cd backend && php artisan test --filter=V2ModuleRegistrySidebarAuthorizedContractTest`.
- **Required CI is pending until a PR and exact final head exist.** Do not assert success based on a build or on older workflow runs. Enforce Repository Policy, V2 Isolation Gate, Required CI Gate and relevant Laravel/security/localization gates before merge.
- `MANUAL_ACCEPTANCE: DEFERRED_TO_V2_FINAL` under owner decision on #2503; no tag, release, production deployment, or main integration is authorized.
